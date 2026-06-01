<?php

namespace Cosmii02\ModpackManager\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * ATLauncher provider. No API key required, but the API sits behind Cloudflare
 * bot-protection that rejects non-browser User-Agents, so every request sends a
 * browser-like UA.
 *
 * Browsing uses the public v1 API (one /packs/full/all call, cached + filtered
 * client-side). The real install manifest — mods, loader, java, config zip —
 * lives in a per-version Configs.json on ATLauncher's CDN, and every mod jar is
 * re-hosted there too, so installs need no CurseForge key.
 */
class ATLauncherService
{
    private const API_URL = 'https://api.atlauncher.com/v1';
    private const CDN_URL  = 'https://download.nodecdn.net/containers/atl/';

    /** Cloudflare rejects unknown UAs; present as a browser. */
    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    private const LOADER_NAMES = [
        'forge'    => 'Forge',
        'neoforge' => 'NeoForge',
        'fabric'   => 'Fabric',
        'quilt'    => 'Quilt',
    ];

    private function client(): PendingRequest
    {
        return Http::withHeaders([
            'User-Agent' => self::UA,
            'Accept'     => 'application/json',
        ])->timeout(40);
    }

    /**
     * Search the (cached) full pack list client-side. Empty query lists newest.
     */
    public function search(string $query = '', int $limit = 24): array
    {
        $packs = $this->allPacks();
        $query = trim(strtolower($query));

        if ($query !== '') {
            $packs = array_filter(
                $packs,
                fn (array $p) => str_contains(strtolower((string) ($p['name'] ?? '')), $query)
            );
        }

        // Newest packs first (higher id).
        usort($packs, fn ($a, $b) => ($b['id'] ?? 0) <=> ($a['id'] ?? 0));

        return array_values(array_map(
            fn (array $p) => $this->normalizePack($p),
            array_slice(array_values($packs), 0, $limit)
        ));
    }

    /**
     * The full public-pack list, cached for 15 minutes.
     *
     * @return array<int,array<string,mixed>>
     */
    private function allPacks(): array
    {
        return Cache::remember('modpack-manager:atlauncher:packs', now()->addMinutes(15), function () {
            $response = $this->client()->get(self::API_URL . '/packs/full/all');

            if ($response->failed()) {
                Log::error('[ModpackManager] ATLauncher pack list failed', ['status' => $response->status()]);
                throw new RuntimeException('ATLauncher API request failed: ' . $response->status());
            }

            return array_values(array_filter(
                $response->json('data', []) ?? [],
                fn ($p) => is_array($p) && ($p['type'] ?? null) === 'public' && !empty($p['versions'])
            ));
        });
    }

    public function getProject(string $idOrSlug): array
    {
        foreach ($this->allPacks() as $p) {
            if ((string) ($p['safeName'] ?? '') === (string) $idOrSlug
                || (string) ($p['id'] ?? '') === (string) $idOrSlug) {
                return $this->normalizePack($p);
            }
        }

        throw new RuntimeException("ATLauncher: pack {$idOrSlug} not found");
    }

    /**
     * Versions for a pack (newest first). Loader is resolved later from the
     * manifest, so it is left empty here to avoid a Configs.json call per row.
     */
    public function getVersions(string $safeName): array
    {
        $pack = collect($this->allPacks())->first(
            fn ($p) => (string) ($p['safeName'] ?? '') === (string) $safeName
                || (string) ($p['id'] ?? '') === (string) $safeName
        );

        if (!$pack) {
            throw new RuntimeException("ATLauncher: pack {$safeName} not found");
        }

        return array_values(array_map(fn (array $v) => [
            'id'            => (string) $v['version'],
            'name'          => $v['version'],
            'versionNumber' => $v['version'],
            'displayName'   => $v['version'] . (isset($v['minecraft']) ? " — MC {$v['minecraft']}" : ''),
            'datePublished' => isset($v['published']) ? date('c', (int) $v['published']) : null,
            'loaders'       => [],
            'gameVersions'  => $v['minecraft'] ?? '',
        ], $pack['versions'] ?? []));
    }

    /**
     * Server-installable manifest for one version, read from the CDN Configs.json.
     *
     * @return array{files:array<int,array{name:string,dir:string,url:string}>, configsUrl:?string, loader:?string, mc:?string, loaderVersion:?string, java:?int}
     */
    public function getInstallManifest(string $safeName, string $version): array
    {
        $base = self::CDN_URL . 'packs/' . $this->encodePath($safeName)
              . '/versions/' . $this->encodePath($version) . '/';

        $response = $this->client()->get($base . 'Configs.json');

        if ($response->failed()) {
            throw new RuntimeException("ATLauncher: could not fetch Configs.json for {$safeName} {$version}");
        }

        $data   = $response->json();
        $loader = $data['loader'] ?? [];

        $loaderType = strtolower((string) ($loader['type'] ?? ''));
        $loaderType = isset(self::LOADER_NAMES[$loaderType]) ? $loaderType : null;

        $files = [];
        foreach ($data['mods'] ?? [] as $mod) {
            // Only server-side, auto-downloadable mod jars.
            if (($mod['server'] ?? true) === false) {
                continue;
            }
            if (!empty($mod['library'])) {
                continue; // loader libraries are installed by the egg
            }
            if (($mod['type'] ?? 'mods') !== 'mods') {
                continue;
            }

            $download = $mod['download'] ?? 'server';
            if ($download === 'browser') {
                continue; // can't fetch programmatically
            }

            $rawUrl = (string) ($mod['url'] ?? '');
            if ($rawUrl === '') {
                continue;
            }

            $url = $download === 'direct'
                ? $rawUrl
                : self::CDN_URL . $this->encodePath($rawUrl);

            $name = $mod['file'] ?? basename(parse_url($rawUrl, PHP_URL_PATH) ?: $rawUrl);

            $files[] = ['name' => $name, 'dir' => 'mods', 'url' => $url];
        }

        return [
            'files'         => $files,
            'configsUrl'    => !empty($data['configs']) ? $base . 'Configs.zip' : null,
            'loader'        => $loaderType,
            'mc'            => $data['minecraft'] ?? ($loader['metadata']['minecraft'] ?? null),
            'loaderVersion' => $loader['metadata']['version'] ?? ($loader['version'] ?? null),
            'java'          => isset($data['java']['min']) ? (int) $data['java']['min'] : null,
        ];
    }

    // ─── Normalise ────────────────────────────────────────────────────────────

    private function normalizePack(array $pack): array
    {
        $versions = $pack['versions'] ?? [];
        $latest   = $versions[0] ?? [];

        return [
            'provider'      => 'atlauncher',
            'id'            => (string) ($pack['safeName'] ?? $pack['id'] ?? ''),
            'slug'          => (string) ($pack['safeName'] ?? ''),
            'name'          => $pack['name'] ?? 'Unknown',
            'summary'       => $pack['description'] ?? '',
            'downloadCount' => 0,
            'iconUrl'       => null,
            'author'        => 'ATLauncher',
            'dateModified'  => isset($latest['published']) ? date('c', (int) $latest['published']) : null,
            'gameVersions'  => $latest['minecraft'] ?? null,
            'loaders'       => [],
            'latestFileId'  => isset($latest['version']) ? (string) $latest['version'] : null,
        ];
    }

    /**
     * Percent-encode each path segment (ATLauncher filenames contain spaces and
     * brackets) while keeping the slashes intact.
     */
    private function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }
}
