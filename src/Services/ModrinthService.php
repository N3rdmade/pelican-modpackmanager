<?php

namespace Cosmii02\ModpackManager\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ModrinthService
{
    private const BASE_URL = 'https://api.modrinth.com/v2';

    private PendingRequest $client;

    public function __construct()
    {
        $headers = [
            'User-Agent' => 'pelican-modpack-manager/1.0.0 (contact@example.com)',
            'Accept'     => 'application/json',
        ];

        $token = config('modpack-manager.modrinth_token');
        if (!empty($token)) {
            $headers['Authorization'] = $token;
        }

        $this->client = Http::withHeaders($headers)->baseUrl(self::BASE_URL)->timeout(30);
    }

    /**
     * Search for modpacks on Modrinth.
     */
    public function search(string $query = '', int $page = 0, int $pageSize = 20, array $filters = []): array
    {
        // Facets are AND-ed groups; each inner array is an OR set. Modrinth folds
        // both mod loaders and content categories into the `categories` facet.
        $facets = [['project_type:modpack']];

        if (!empty($filters['gameVersion'])) {
            $facets[] = ['versions:' . $filters['gameVersion']];
        }
        if (!empty($filters['loader'])) {
            $facets[] = ['categories:' . $filters['loader']];
        }
        // Category applies only when the value is a Modrinth slug (Modrinth-provider
        // view). In the combined view the value is a numeric CurseForge category id,
        // which Modrinth can't map — so numeric values are intentionally skipped.
        if (!empty($filters['category']) && !is_numeric($filters['category'])) {
            $facets[] = ['categories:' . $filters['category']];
        }

        $response = $this->client->get('/search', [
            'query'  => $query,
            'facets' => json_encode($facets),
            'limit'  => $pageSize,
            'offset' => $page * $pageSize,
            'index'  => 'downloads',
        ]);

        if ($response->failed()) {
            Log::error('[ModpackManager] Modrinth search failed', ['status' => $response->status(), 'body' => $response->body()]);
            throw new RuntimeException('Modrinth API request failed: ' . $response->status());
        }

        $hits = $response->json('hits', []);

        return array_map(fn (array $hit) => $this->normalizeHit($hit), $hits);
    }

    /**
     * Modpack categories from Modrinth, alphabetical — used to populate the
     * browser's category filter when Modrinth is the active provider. Cached a
     * day. Each entry is ['slug' => string, 'name' => string]; the slug is what
     * the search facet expects. Returns [] on failure; only non-empty results
     * are cached.
     *
     * @return array<int, array{slug:string, name:string}>
     */
    public function getCategories(): array
    {
        $cacheKey = 'modpack-manager:modrinth:categories';

        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        try {
            $response = $this->client->get('/tag/category');

            if ($response->failed()) {
                Log::warning('[ModpackManager] Modrinth categories failed', ['status' => $response->status()]);
                return [];
            }

            $categories = [];
            foreach ($response->json() ?? [] as $c) {
                // Modrinth folds loaders into the same tag list; keep only genuine
                // modpack content categories.
                if (($c['project_type'] ?? null) === 'modpack' && !empty($c['name'])) {
                    $slug = (string) $c['name'];
                    $categories[$slug] = [
                        'slug' => $slug,
                        'name' => ucwords(str_replace('-', ' ', $slug)),
                    ];
                }
            }

            $categories = array_values($categories);
            usort($categories, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

            if (!empty($categories)) {
                Cache::put($cacheKey, $categories, now()->addDay());
            }

            return $categories;
        } catch (Throwable $e) {
            Log::warning('[ModpackManager] Modrinth categories error', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Get a single project by ID or slug.
     */
    public function getProject(string $idOrSlug): array
    {
        $response = $this->client->get("/project/{$idOrSlug}");

        if ($response->failed()) {
            throw new RuntimeException("Modrinth: could not fetch project {$idOrSlug}");
        }

        return $this->normalizeProject($response->json());
    }

    /**
     * Get versions for a project, filtered to server-compatible loaders.
     */
    public function getVersions(string $projectId, array $loaders = ['forge', 'fabric', 'quilt', 'neoforge']): array
    {
        $response = $this->client->get("/project/{$projectId}/version", [
            'loaders' => json_encode($loaders),
        ]);

        if ($response->failed()) {
            throw new RuntimeException("Modrinth: could not fetch versions for project {$projectId}");
        }

        $versions = $response->json([]);

        return array_values(array_map(fn (array $v) => [
            'id'           => $v['id'],
            'name'         => $v['name'],
            'versionNumber'=> $v['version_number'],
            'datePublished'=> $v['date_published'],
            'loaders'      => $v['loaders'] ?? [],
            'gameVersions' => $v['game_versions'] ?? [],
            'downloads'    => $v['downloads'] ?? 0,
            'files'        => array_map(fn (array $f) => [
                'url'      => $f['url'],
                'filename' => $f['filename'],
                'size'     => $f['size'],
                'primary'  => $f['primary'] ?? false,
                'sha1'     => $f['hashes']['sha1'] ?? null,
            ], $v['files'] ?? []),
        ], array_slice($versions, 0, 50)));
    }

    /**
     * Get the primary download URL from a version's files list.
     */
    public function getPrimaryFileUrl(array $files): ?string
    {
        foreach ($files as $file) {
            if ($file['primary']) {
                return $file['url'];
            }
        }

        return $files[0]['url'] ?? null;
    }

    // ─── Normalise ────────────────────────────────────────────────────────────

    private function normalizeHit(array $hit): array
    {
        return [
            'provider'      => 'modrinth',
            'id'            => $hit['project_id'],
            'slug'          => $hit['slug'],
            'name'          => $hit['title'],
            'summary'       => $hit['description'] ?? '',
            'downloadCount' => $hit['downloads'] ?? 0,
            'iconUrl'       => $hit['icon_url'] ?? null,
            'author'        => $hit['author'] ?? 'Unknown',
            'dateModified'  => $hit['date_modified'] ?? null,
            'gameVersions'  => implode(', ', array_slice($hit['versions'] ?? [], 0, 3)),
            // Search hits fold loaders into `categories` alongside content tags.
            'loaders'       => $this->extractLoaders($hit['categories'] ?? []),
            'latestFileId'  => null,
        ];
    }

    private function normalizeProject(array $project): array
    {
        return [
            'provider'      => 'modrinth',
            'id'            => $project['id'],
            'slug'          => $project['slug'],
            'name'          => $project['title'],
            'summary'       => $project['description'] ?? '',
            'downloadCount' => $project['downloads'] ?? 0,
            'iconUrl'       => $project['icon_url'] ?? null,
            'author'        => $project['team'] ?? 'Unknown',
            'dateModified'  => $project['updated'] ?? null,
            'gameVersions'  => null,
            // Projects expose a dedicated `loaders` array; fall back to categories.
            'loaders'       => $this->extractLoaders($project['loaders'] ?? $project['categories'] ?? []),
            'latestFileId'  => null,
        ];
    }

    /**
     * Filter a Modrinth tag list down to the mod loaders and present them as
     * display names (e.g. 'neoforge' → 'NeoForge').
     *
     * @return string[]
     */
    private function extractLoaders(array $tags): array
    {
        static $map = [
            'forge'       => 'Forge',
            'neoforge'    => 'NeoForge',
            'fabric'      => 'Fabric',
            'quilt'       => 'Quilt',
            'liteloader'  => 'LiteLoader',
        ];

        $loaders = [];
        foreach ($tags as $tag) {
            $key = strtolower((string) $tag);
            if (isset($map[$key])) {
                $loaders[$map[$key]] = true;
            }
        }

        return array_keys($loaders);
    }
}
