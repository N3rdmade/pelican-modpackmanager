<?php

namespace Cosmii02\ModpackManager\Services;

use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Feed the Beast (modpacks.ch) provider. No API key required.
 *
 * Search returns only pack IDs, so we fan out one detail request per id (via an
 * HTTP pool) to build cards. A version's file list comes from
 * /public/modpack/{packId}/{versionId}; most files carry a direct FTB-hosted
 * `url`, the rest only a CurseForge {project,file} reference that the install
 * service resolves through CurseForgeService.
 */
class FtbService
{
    private const BASE_URL = 'https://api.modpacks.ch/public';

    /** Map an FTB modloader target name to a display name. */
    private const LOADER_NAMES = [
        'forge'    => 'Forge',
        'neoforge' => 'NeoForge',
        'fabric'   => 'Fabric',
        'quilt'    => 'Quilt',
    ];

    private function client()
    {
        return Http::baseUrl(self::BASE_URL)
            ->acceptJson()
            ->withHeaders(['User-Agent' => 'pelican-modpack-manager/1.0'])
            ->timeout(30);
    }

    /**
     * Search modpacks (or list popular ones when the query is empty), then
     * resolve each id to a card via a pooled batch of detail requests.
     */
    public function search(string $query = '', int $limit = 16, array $filters = []): array
    {
        // $filters (MC version / loader / category) are accepted for interface
        // parity with the browsable providers; FTB's search has no facet support.
        $query = trim($query);

        $endpoint = $query === ''
            ? "/modpack/popular/installs/{$limit}"
            : '/modpack/search/' . $limit . '?term=' . rawurlencode($query);

        $response = $this->client()->get($endpoint);

        if ($response->failed()) {
            Log::error('[ModpackManager] FTB search failed', ['status' => $response->status(), 'body' => $response->body()]);
            throw new RuntimeException('FTB API request failed: ' . $response->status());
        }

        $ids = array_slice($response->json('packs', []) ?? [], 0, $limit);

        return $this->fetchPacks($ids);
    }

    /**
     * Resolve a list of pack ids to normalised cards using one pooled batch.
     *
     * @param array<int,int> $ids
     */
    private function fetchPacks(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $responses = Http::pool(fn (Pool $pool) => array_map(
            fn ($id) => $pool->as((string) $id)
                ->baseUrl(self::BASE_URL)
                ->acceptJson()
                ->withHeaders(['User-Agent' => 'pelican-modpack-manager/1.0'])
                ->timeout(30)
                ->get("/modpack/{$id}"),
            $ids
        ));

        $packs = [];
        foreach ($ids as $id) {
            $res = $responses[(string) $id] ?? null;
            if ($res && method_exists($res, 'successful') && $res->successful()) {
                $data = $res->json();
                if (is_array($data) && !empty($data['id'])) {
                    $packs[] = $this->normalizePack($data);
                }
            }
        }

        return $packs;
    }

    /**
     * Single pack by id.
     */
    public function getProject(string $idOrSlug): array
    {
        $response = $this->client()->get('/modpack/' . (int) $idOrSlug);

        if ($response->failed()) {
            throw new RuntimeException("FTB: could not fetch pack {$idOrSlug}");
        }

        return $this->normalizePack($response->json());
    }

    /**
     * Versions for a pack, newest first.
     */
    public function getVersions(string $packId): array
    {
        $response = $this->client()->get('/modpack/' . (int) $packId);

        if ($response->failed()) {
            throw new RuntimeException("FTB: could not fetch pack {$packId}");
        }

        $versions = $response->json('versions', []) ?? [];

        // Newest first by update time.
        usort($versions, fn ($a, $b) => ($b['updated'] ?? 0) <=> ($a['updated'] ?? 0));

        return array_values(array_map(function (array $v) {
            $meta = $this->targetsMeta($v['targets'] ?? []);

            return [
                'id'            => (string) $v['id'],
                'name'          => $v['name'] ?? ('Version ' . $v['id']),
                'versionNumber' => $v['name'] ?? '',
                'displayName'   => trim(($v['name'] ?? '') . ' (' . ($v['type'] ?? 'Release') . ')'),
                'datePublished' => isset($v['updated']) ? date('c', (int) $v['updated']) : null,
                'loaders'       => $meta['loader'] ? [self::LOADER_NAMES[$meta['loader']] ?? ucfirst($meta['loader'])] : [],
                'gameVersions'  => $meta['mc'] ?? '',
            ];
        }, $versions));
    }

    /**
     * The server-installable file list + loader metadata for one version.
     *
     * @return array{files:array<int,array{name:string,dir:string,url:?string,cfProject:?int,cfFile:?int}>, loader:?string, mc:?string, loaderVersion:?string, java:?int}
     */
    public function getVersionFiles(int $packId, int $versionId): array
    {
        $response = $this->client()->get("/modpack/{$packId}/{$versionId}");

        if ($response->failed()) {
            throw new RuntimeException("FTB: could not fetch version {$versionId} of pack {$packId}");
        }

        $data = $response->json();
        $meta = $this->targetsMeta($data['targets'] ?? []);

        $files = [];
        foreach ($data['files'] ?? [] as $f) {
            // Client-only assets are useless on a server.
            if (!empty($f['clientonly'])) {
                continue;
            }

            $name = $f['name'] ?? null;
            if (!$name) {
                continue;
            }

            $dir = trim(str_replace(['\\', './'], ['/', ''], (string) ($f['path'] ?? '')), '/');

            $files[] = [
                'name'      => $name,
                'dir'       => $dir,
                'url'       => !empty($f['url']) ? $f['url'] : null,
                'cfProject' => isset($f['curseforge']['project']) ? (int) $f['curseforge']['project'] : null,
                'cfFile'    => isset($f['curseforge']['file']) ? (int) $f['curseforge']['file'] : null,
            ];
        }

        return [
            'files'         => $files,
            'loader'        => $meta['loader'],
            'mc'            => $meta['mc'],
            'loaderVersion' => $meta['loaderVersion'],
            'java'          => $meta['java'],
        ];
    }

    // ─── Normalise ────────────────────────────────────────────────────────────

    private function normalizePack(array $pack): array
    {
        $meta = $this->targetsMeta($pack['versions'][0]['targets'] ?? []);

        return [
            'provider'      => 'ftb',
            'id'            => (string) $pack['id'],
            'slug'          => (string) $pack['id'],
            'name'          => $pack['name'] ?? 'Unknown',
            'summary'       => $pack['synopsis'] ?? '',
            'downloadCount' => $pack['installs'] ?? 0,
            'iconUrl'       => $this->squareArt($pack['art'] ?? []),
            'author'        => $pack['authors'][0]['name'] ?? 'FTB',
            'dateModified'  => isset($pack['updated']) ? date('c', (int) $pack['updated']) : null,
            'gameVersions'  => $meta['mc'] ?? null,
            'loaders'       => $meta['loader'] ? [self::LOADER_NAMES[$meta['loader']] ?? ucfirst($meta['loader'])] : [],
            'latestFileId'  => isset($pack['versions'][0]['id']) ? (string) $pack['versions'][0]['id'] : null,
        ];
    }

    private function squareArt(array $art): ?string
    {
        foreach ($art as $a) {
            if (($a['type'] ?? null) === 'square' && !empty($a['url'])) {
                return $a['url'];
            }
        }

        return $art[0]['url'] ?? null;
    }

    /**
     * Pull loader / minecraft / loader-version / java major out of a version's targets array.
     *
     * @return array{loader:?string, mc:?string, loaderVersion:?string, java:?int}
     */
    private function targetsMeta(array $targets): array
    {
        $loader = $mc = $loaderVersion = null;
        $java = null;

        foreach ($targets as $t) {
            $type = $t['type'] ?? null;
            $name = strtolower((string) ($t['name'] ?? ''));

            if ($type === 'modloader' && isset(self::LOADER_NAMES[$name])) {
                $loader        = $name;
                $loaderVersion = $t['version'] ?? null;
            } elseif ($type === 'game' && $name === 'minecraft') {
                $mc = $t['version'] ?? null;
            } elseif ($type === 'runtime' && $name === 'java') {
                if (preg_match('/^(\d+)/', (string) ($t['version'] ?? ''), $m)) {
                    $java = (int) $m[1];
                }
            }
        }

        return ['loader' => $loader, 'mc' => $mc, 'loaderVersion' => $loaderVersion, 'java' => $java];
    }
}
