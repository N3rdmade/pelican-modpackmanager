<?php

namespace Cosmii02\ModpackManager\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class CurseForgeService
{
    private const BASE_URL   = 'https://api.curseforge.com/v1';
    private const GAME_ID    = 432;   // Minecraft
    private const CLASS_ID   = 4471;  // Modpacks

    private PendingRequest $client;

    public function __construct()
    {
        $apiKey = config('modpack-manager.curseforge_api_key');

        if (empty($apiKey)) {
            throw new RuntimeException('CurseForge API key is not configured. Please add it in Admin → Plugins → Modpack Manager.');
        }

        $this->client = Http::withHeaders([
            'x-api-key'    => $apiKey,
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        ])->baseUrl(self::BASE_URL)->timeout(30);
    }

    /**
     * Search for modpacks on CurseForge.
     *
     * @return array{id:int, name:string, summary:string, downloadCount:int, logo:array, latestFiles:array, authors:array, dateModified:string}[]
     */
    public function search(string $query = '', int $page = 0, int $pageSize = 20): array
    {
        $response = $this->client->get('/mods/search', [
            'gameId'       => self::GAME_ID,
            'classId'      => self::CLASS_ID,
            'searchFilter' => $query,
            'sortField'    => 2,       // Popularity
            'sortOrder'    => 'desc',
            'index'        => $page * $pageSize,
            'pageSize'     => $pageSize,
        ]);

        if ($response->failed()) {
            Log::error('[ModpackManager] CurseForge search failed', ['status' => $response->status(), 'body' => $response->body()]);
            throw new RuntimeException('CurseForge API request failed: ' . $response->status());
        }

        $data = $response->json('data', []);

        return array_map(fn (array $mod) => $this->normalizeMod($mod), $data);
    }

    /**
     * Get a single mod/pack by ID.
     */
    public function getMod(int $modId): array
    {
        $response = $this->client->get("/mods/{$modId}");

        if ($response->failed()) {
            throw new RuntimeException("CurseForge: could not fetch mod {$modId}");
        }

        return $this->normalizeMod($response->json('data'));
    }

    /**
     * Get the list of release files for a modpack (newest first). Each entry
     * carries `serverPackFileId`, so the installer can resolve the official
     * server pack at install time.
     */
    public function getFiles(int $modId): array
    {
        $response = $this->client->get("/mods/{$modId}/files", [
            'pageSize' => 50,
        ]);

        if ($response->failed()) {
            throw new RuntimeException("CurseForge: could not fetch files for mod {$modId}");
        }

        $files = $response->json('data', []);

        // Newest first.
        usort($files, fn ($a, $b) => strcmp($b['fileDate'] ?? '', $a['fileDate'] ?? ''));

        return array_values(array_map(fn (array $file) => [
            'id'               => $file['id'],
            'displayName'      => $file['displayName'] ?? $file['fileName'] ?? ('File ' . $file['id']),
            'fileName'         => $file['fileName'] ?? '',
            'fileDate'         => $file['fileDate'] ?? null,
            'fileLength'       => $file['fileLength'] ?? 0,
            'isServerPack'     => $file['isServerPack'] ?? false,
            'serverPackFileId' => $file['serverPackFileId'] ?? null,
            'gameVersions'     => $file['gameVersions'] ?? [],
        ], array_slice($files, 0, 40)));
    }

    /**
     * Fetch a single file's metadata (download URL, server-pack linkage, etc.).
     *
     * @return array{id:int, fileName:?string, downloadUrl:?string, isServerPack:bool, serverPackFileId:?int}
     */
    public function getFile(int $modId, int $fileId): array
    {
        $response = $this->client->get("/mods/{$modId}/files/{$fileId}");

        if ($response->failed()) {
            throw new RuntimeException("CurseForge: could not fetch file {$fileId} for mod {$modId}");
        }

        $f = $response->json('data', []);

        return [
            'id'               => (int) ($f['id'] ?? $fileId),
            'fileName'         => $f['fileName'] ?? null,
            'downloadUrl'      => $f['downloadUrl'] ?? null,
            'isServerPack'     => (bool) ($f['isServerPack'] ?? false),
            'serverPackFileId' => $f['serverPackFileId'] ?? null,
        ];
    }

    /**
     * Resolve download URLs + filenames for many files at once (used when
     * assembling a server pack from a client-pack manifest).
     *
     * @param int[] $fileIds
     * @return array<int, array{url:?string, name:?string}>  keyed by fileId
     */
    public function getFilesByIds(array $fileIds): array
    {
        $out = [];

        foreach (array_chunk(array_values(array_unique($fileIds)), 50) as $chunk) {
            $response = $this->client->post('/mods/files', [
                'fileIds' => array_map('intval', $chunk),
            ]);

            if ($response->failed()) {
                Log::warning('[ModpackManager] CurseForge /mods/files batch failed', ['status' => $response->status()]);
                continue;
            }

            foreach ($response->json('data', []) as $f) {
                $id   = (int) ($f['id'] ?? 0);
                $name = $f['fileName'] ?? null;
                $url  = $f['downloadUrl'] ?? null;

                // CurseForge nulls downloadUrl for some files; reconstruct the CDN URL.
                if (empty($url) && $id && $name) {
                    $url = $this->edgeUrl($id, $name);
                }

                if ($id) {
                    $out[$id] = ['url' => $url, 'name' => $name];
                }
            }
        }

        return $out;
    }

    /**
     * Resolve the CurseForge class (project type) for many projects at once,
     * so the installer can route each downloaded file to the right folder
     * (mods / resourcepacks / shaderpacks / …) instead of dumping all in /mods.
     *
     * @param int[] $modIds
     * @return array<int, int>  classId keyed by modId (e.g. 6 = Mc Mods, 12 = Resource Packs)
     */
    public function getModClasses(array $modIds): array
    {
        $out = [];

        foreach (array_chunk(array_values(array_unique(array_map('intval', $modIds))), 100) as $chunk) {
            if (empty($chunk)) {
                continue;
            }

            $response = $this->client->post('/mods', ['modIds' => $chunk]);

            if ($response->failed()) {
                Log::warning('[ModpackManager] CurseForge /mods batch failed', ['status' => $response->status()]);
                continue;
            }

            foreach ($response->json('data', []) as $mod) {
                $id = (int) ($mod['id'] ?? 0);
                if ($id && isset($mod['classId'])) {
                    $out[$id] = (int) $mod['classId'];
                }
            }
        }

        return $out;
    }

    /**
     * Get the download URL for a specific file.
     */
    public function getDownloadUrl(int $modId, int $fileId): string
    {
        $response = $this->client->get("/mods/{$modId}/files/{$fileId}/download-url");

        $url = $response->successful() ? $response->json('data') : null;

        // Fall back to reconstructing the CDN URL from the file record.
        if (empty($url)) {
            $file = $this->getFile($modId, $fileId);
            if (!empty($file['downloadUrl'])) {
                return $file['downloadUrl'];
            }
            if (!empty($file['fileName'])) {
                return $this->edgeUrl($fileId, $file['fileName']);
            }
            throw new RuntimeException("CurseForge: could not get download URL for file {$fileId}");
        }

        return $url;
    }

    /**
     * Reconstruct a forgecdn download URL from a file ID + name.
     */
    private function edgeUrl(int $fileId, string $fileName): string
    {
        $p1 = intdiv($fileId, 1000);
        $p2 = $fileId % 1000;

        return "https://edge.forgecdn.net/files/{$p1}/{$p2}/" . rawurlencode($fileName);
    }

    // ─── Normalise ────────────────────────────────────────────────────────────

    private function normalizeMod(array $mod): array
    {
        return [
            'provider'      => 'curseforge',
            'id'            => $mod['id'],
            'slug'          => $mod['slug'] ?? '',
            'name'          => $mod['name'],
            'summary'       => $mod['summary'] ?? '',
            'downloadCount' => $mod['downloadCount'] ?? 0,
            'iconUrl'       => $mod['logo']['thumbnailUrl'] ?? $mod['logo']['url'] ?? null,
            'author'        => $mod['authors'][0]['name'] ?? 'Unknown',
            'dateModified'  => $mod['dateModified'] ?? null,
            'gameVersions'  => $mod['latestFilesIndexes'][0]['gameVersion'] ?? null,
            'loaders'       => $this->extractLoaders($mod['latestFilesIndexes'] ?? []),
            'latestFileId'  => $mod['mainFileId'] ?? null,
        ];
    }

    /**
     * Collect the distinct mod loaders a pack supports from its latestFilesIndexes.
     * CurseForge encodes the loader as an integer in each index's `modLoader`.
     *
     * @return string[] e.g. ['NeoForge'] or ['Forge', 'Fabric']
     */
    private function extractLoaders(array $indexes): array
    {
        // CurseForge modLoaderType enum.
        static $map = [1 => 'Forge', 3 => 'LiteLoader', 4 => 'Fabric', 5 => 'Quilt', 6 => 'NeoForge'];

        $loaders = [];
        foreach ($indexes as $idx) {
            $name = $map[$idx['modLoader'] ?? 0] ?? null;
            if ($name !== null) {
                $loaders[$name] = true;
            }
        }

        return array_keys($loaders);
    }
}
