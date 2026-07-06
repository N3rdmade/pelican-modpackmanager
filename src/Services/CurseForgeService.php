<?php

namespace Cosmii02\ModpackManager\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class CurseForgeService
{
    private const BASE_URL   = 'https://api.curseforge.com/v1';
    private const GAME_ID    = 432;   // Minecraft
    private const CLASS_ID   = 4471;  // Modpacks

    /** Canonical loader slug → CurseForge modLoaderType enum. */
    private const LOADER_TYPES = [
        'forge'    => 1,
        'fabric'   => 4,
        'quilt'    => 5,
        'neoforge' => 6,
    ];

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
    public function search(string $query = '', int $page = 0, int $pageSize = 20, array $filters = []): array
    {
        $params = [
            'gameId'       => self::GAME_ID,
            'classId'      => self::CLASS_ID,
            'searchFilter' => $query,
            'sortField'    => 2,       // Popularity
            'sortOrder'    => 'desc',
            'index'        => $page * $pageSize,
            'pageSize'     => $pageSize,
        ];

        // Optional facet filters (Minecraft version / loader / modpack category).
        if (!empty($filters['gameVersion'])) {
            $params['gameVersion'] = $filters['gameVersion'];
        }
        if (!empty($filters['loader']) && ($loaderType = self::LOADER_TYPES[$filters['loader']] ?? null)) {
            $params['modLoaderType'] = $loaderType;
        }
        if (!empty($filters['category']) && is_numeric($filters['category'])) {
            $params['categoryId'] = (int) $filters['category']; // a CurseForge category id
        }

        $response = $this->client->get('/mods/search', $params);

        if ($response->failed()) {
            Log::error('[ModpackManager] CurseForge search failed', ['status' => $response->status(), 'body' => $response->body()]);
            throw new RuntimeException('CurseForge API request failed: ' . $response->status());
        }

        $data = $response->json('data', []);

        return array_map(fn (array $mod) => $this->normalizeMod($mod), $data);
    }

    /**
     * All Minecraft release versions known to CurseForge, newest first — used to
     * populate the browser's version filter so it stays current. Cached for a day
     * (this taxonomy changes rarely). Returns [] on any failure so callers can
     * fall back to their own list; only successful non-empty results are cached.
     *
     * @return string[] e.g. ['1.21.1', '1.21', '1.20.6', …]
     */
    public function getMinecraftVersions(): array
    {
        $cacheKey = 'modpack-manager:curseforge:mc-versions';

        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        try {
            $response = $this->client->get('/minecraft/version');

            if ($response->failed()) {
                Log::warning('[ModpackManager] CurseForge minecraft version list failed', ['status' => $response->status()]);
                return [];
            }

            $versions = [];
            foreach ($response->json('data', []) ?? [] as $v) {
                $str = $v['versionString'] ?? null;
                // Numeric releases only — drop snapshots ("23w31a"), pre-releases, etc.
                if (is_string($str) && preg_match('/^\d+\.\d+(\.\d+)?$/', $str)) {
                    $versions[$str] = true;
                }
            }

            $versions = array_keys($versions);
            usort($versions, fn ($a, $b) => version_compare($b, $a)); // newest first

            if (!empty($versions)) {
                Cache::put($cacheKey, $versions, now()->addDay());
            }

            return $versions;
        } catch (Throwable $e) {
            Log::warning('[ModpackManager] CurseForge minecraft version list error', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Modpack categories from CurseForge (children of class 4471), alphabetical —
     * used to populate the browser's category filter. Cached for a day. Each entry
     * is ['id' => int, 'name' => string]. Returns [] on failure; only successful
     * non-empty results are cached.
     *
     * @return array<int, array{id:int, name:string}>
     */
    public function getCategories(): array
    {
        $cacheKey = 'modpack-manager:curseforge:categories';

        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        try {
            $response = $this->client->get('/categories', [
                'gameId'  => self::GAME_ID,
                'classId' => self::CLASS_ID,
            ]);

            if ($response->failed()) {
                Log::warning('[ModpackManager] CurseForge categories failed', ['status' => $response->status()]);
                return [];
            }

            $categories = [];
            foreach ($response->json('data', []) ?? [] as $c) {
                if (isset($c['id'], $c['name'])) {
                    $categories[] = ['id' => (int) $c['id'], 'name' => (string) $c['name']];
                }
            }

            usort($categories, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

            if (!empty($categories)) {
                Cache::put($cacheKey, $categories, now()->addDay());
            }

            return $categories;
        } catch (Throwable $e) {
            Log::warning('[ModpackManager] CurseForge categories error', ['error' => $e->getMessage()]);
            return [];
        }
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

        return array_values(array_map(
            fn (array $file) => $this->normalizeFile($file),
            array_slice($files, 0, 40)
        ));
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

        return $this->normalizeFile($f + ['id' => $fileId]);
    }

    /**
     * Find a server pack uploaded as an additional file for the selected client
     * pack. Some CurseForge projects don't populate serverPackFileId on the
     * client file. Depending on the project, the server file may show up through
     * alternateFileId, a listed parent/alternate relationship, or only on the
     * public "Additional Files" page.
     *
     * @return array|null
     */
    public function findServerPackForFile(int $modId, int $clientFileId, ?array $clientFile = null): ?array
    {
        if (!empty($clientFile['alternateFileId'])) {
            $serverPack = $this->getLinkedServerPack($modId, (int) $clientFile['alternateFileId']);

            if ($serverPack) {
                return $serverPack;
            }
        }

        $response = $this->client->get("/mods/{$modId}/files", [
            'pageSize' => 50,
        ]);

        if ($response->failed()) {
            Log::warning('[ModpackManager] CurseForge server-pack lookup failed', [
                'mod_id' => $modId,
                'file_id' => $clientFileId,
                'status' => $response->status(),
            ]);
            return $this->findServerPackFromAdditionalFilesPage($modId, $clientFileId);
        }

        foreach ($response->json('data', []) as $file) {
            if (!$this->looksLikeServerPack($file)) {
                continue;
            }

            $parentId = (int) ($file['parentProjectFileId'] ?? 0);
            $alternateId = (int) ($file['alternateFileId'] ?? 0);

            if ($parentId === $clientFileId || $alternateId === $clientFileId) {
                return $this->normalizeFile($file);
            }
        }

        return $this->findServerPackFromAdditionalFilesPage($modId, $clientFileId);
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

    private function normalizeFile(array $file): array
    {
        $id = (int) ($file['id'] ?? 0);

        return [
            'id'                  => $id,
            'displayName'         => $file['displayName'] ?? $file['fileName'] ?? ($id ? 'File ' . $id : 'File'),
            'fileName'            => $file['fileName'] ?? null,
            'downloadUrl'         => $file['downloadUrl'] ?? null,
            'fileDate'            => $file['fileDate'] ?? null,
            'fileLength'          => $file['fileLength'] ?? 0,
            'isServerPack'        => (bool) ($file['isServerPack'] ?? false),
            'serverPackFileId'    => $file['serverPackFileId'] ?? null,
            'parentProjectFileId' => $file['parentProjectFileId'] ?? null,
            'alternateFileId'     => $file['alternateFileId'] ?? null,
            // Mix of MC versions and loader names, e.g. ["1.20.1", "NeoForge"].
            'gameVersions'        => $file['gameVersions'] ?? [],
        ];
    }

    private function getLinkedServerPack(int $modId, int $fileId): ?array
    {
        try {
            $file = $this->getFile($modId, $fileId);
        } catch (Throwable $e) {
            Log::info('[ModpackManager] CurseForge linked server-pack lookup skipped', [
                'mod_id' => $modId,
                'file_id' => $fileId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        return $this->looksLikeServerPack($file) ? $file : null;
    }

    private function findServerPackFromAdditionalFilesPage(int $modId, int $clientFileId): ?array
    {
        try {
            $slug = $this->getMod($modId)['slug'] ?? null;
        } catch (Throwable $e) {
            Log::info('[ModpackManager] CurseForge additional-files page lookup skipped', [
                'mod_id' => $modId,
                'file_id' => $clientFileId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        if (!$slug) {
            return null;
        }

        $url = "https://www.curseforge.com/minecraft/modpacks/{$slug}/files/{$clientFileId}/additional-files";

        try {
            $response = Http::timeout(20)->accept('text/html')->get($url);
        } catch (Throwable $e) {
            Log::info('[ModpackManager] CurseForge additional-files page lookup skipped', [
                'mod_id' => $modId,
                'file_id' => $clientFileId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        if ($response->failed()) {
            Log::info('[ModpackManager] CurseForge additional-files page lookup failed', [
                'mod_id' => $modId,
                'file_id' => $clientFileId,
                'status' => $response->status(),
            ]);
            return null;
        }

        preg_match_all('#/minecraft/modpacks/[^"\']+/files/(\d+)#', $response->body(), $matches);

        $candidateIds = array_values(array_unique(array_filter(
            array_map('intval', $matches[1] ?? []),
            fn (int $id) => $id > 0 && $id !== $clientFileId
        )));

        foreach ($candidateIds as $candidateId) {
            $serverPack = $this->getLinkedServerPack($modId, $candidateId);

            if ($serverPack) {
                return $serverPack;
            }
        }

        return null;
    }

    private function looksLikeServerPack(array $file): bool
    {
        if (!empty($file['isServerPack'])) {
            return true;
        }

        $name = strtolower((string) ($file['displayName'] ?? $file['fileName'] ?? ''));

        return $name !== ''
            && (str_contains($name, 'server') || str_contains($name, 'serverpack') || str_contains($name, 'server pack'));
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
