<?php

namespace Cosmii02\ModpackManager\Filament\Server\Pages;

use App\Enums\SubuserPermission;
use App\Models\Server;
use App\Models\ServerVariable;
use App\Repositories\Daemon\DaemonFileRepository;
use Cosmii02\ModpackManager\Services\CurseForgeService;
use Cosmii02\ModpackManager\Services\ModrinthService;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Server-panel page for browsing and installing INDIVIDUAL mods or plugins
 * (as opposed to whole modpacks — that's ModpackBrowserPage).
 *
 * The page auto-detects, from the server's egg, whether it runs a mod loader
 * (Forge/NeoForge/Fabric/Quilt) or a plugin platform (Paper/Purpur/Spigot/…):
 *   - modded egg   → "Mods",    installed into  /mods
 *   - plugin egg   → "Plugins", installed into  /plugins
 * The user can flip the mode manually if detection is wrong.
 *
 * Installing a single item is cheap (one Wings download), so — unlike the modpack
 * installer — it runs synchronously in the Livewire request rather than via a
 * queued job. No egg switch, no reinstall.
 *
 * URL: /server/{server}/mods
 */
class ModBrowserPage extends Page
{
    /**
     * Same gate as the Modpacks page: installing/removing content changes server
     * files, so require the reinstall authority. Owner + admins always pass.
     * Change this one line to loosen/tighten.
     */
    private const MANAGE_PERMISSION = SubuserPermission::SettingsReinstall;

    /** Modrinth version loaders to offer per mode. */
    private const MOD_LOADERS    = ['forge', 'neoforge', 'fabric', 'quilt'];
    private const PLUGIN_LOADERS = ['bukkit', 'spigot', 'paper', 'purpur', 'folia', 'sponge'];

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-puzzle-piece';
    protected static ?string $navigationLabel = 'Mods';
    protected static ?string $slug            = 'mods';
    protected string         $view            = 'modpack-manager::filament.server.pages.mod-browser-page';

    /**
     * Sit directly below the Modpacks entry: it uses `navigation_sort` (default 50),
     * so we default to that + 1. Follows the Modpacks page even if an admin moves it.
     */
    public static function getNavigationSort(): ?int
    {
        return (int) config('modpack-manager.navigation_sort', 50) + 1;
    }

    // ─── State ────────────────────────────────────────────────────────────────

    /** 'mods' | 'plugins' — detected from the egg, user-overridable. */
    public string $mode = 'mods';

    public string $search   = '';
    public string $provider = 'all';    // 'all' | 'curseforge' | 'modrinth'

    // Facet filters (best-effort).
    public string $filterVersion = '';  // Minecraft version, e.g. '1.20.1'
    public string $filterLoader  = '';  // loader (mods) / platform (plugins) slug

    /** @var array<int, array<string, mixed>> */
    public array  $items     = [];
    public bool   $isLoading = false;
    public string $errorMsg  = '';

    // Install drawer state
    public bool    $showModal        = false;
    public ?array  $selectedItem     = null;
    public array   $versions         = [];
    public bool    $versionsLoading  = false;
    public ?string $selectedVersion  = null;

    // Installed-files list (jars in the target folder)
    /** @var array<int, array{name:string, sizeLabel:string}> */
    public array $installedFiles = [];

    // Whether the current user may install/remove (drives the UI; enforced server-side too).
    public bool $canManage = false;

    private const COMBINED_PROVIDERS = ['curseforge', 'modrinth'];

    // ─── Authorization / visibility ─────────────────────────────────────────────

    /**
     * Show the page (and its nav entry) only on servers whose egg carries one of
     * the configured required tags (default "minecraft") and to users with the
     * manage permission — same rules as the Modpacks page.
     */
    public static function canAccess(): bool
    {
        $server = Filament::getTenant();

        return parent::canAccess()
            && $server instanceof Server
            && self::eggHasAllowedTag($server)
            && user()?->can(self::MANAGE_PERMISSION, $server);
    }

    private static function eggHasAllowedTag(Server $server): bool
    {
        $required = array_map('strtolower', config('modpack-manager.required_egg_tags', ['minecraft']));

        if (empty($required)) {
            return true;
        }

        foreach ($server->egg?->tags ?? [] as $tag) {
            if (in_array(strtolower(trim((string) $tag)), $required, true)) {
                return true;
            }
        }

        return false;
    }

    private function userCanManage(): bool
    {
        return (bool) user()?->can(self::MANAGE_PERMISSION, $this->getServer());
    }

    private function authorizeManage(): void
    {
        abort_unless($this->userCanManage(), 403);
    }

    // ─── Lifecycle ────────────────────────────────────────────────────────────

    public function mount(): void
    {
        $server = $this->getServer();

        $this->canManage = $this->userCanManage();

        // Auto-detect Mods vs Plugins from the egg, and seed the loader/platform filter.
        $this->mode         = $this->detectMode($server);
        $this->filterLoader = $this->detectLoaderOrPlatform($server) ?? '';

        // Default the version filter to the server's own Minecraft version so the
        // catalogue only shows content compatible with what it actually runs. The
        // user can still override it (incl. "All versions") via the selector.
        $this->filterVersion = $this->detectMcVersion($server) ?? '';

        $this->loadInstalledFiles();
        $this->loadItems();
    }

    // ─── Egg detection ──────────────────────────────────────────────────────────

    /**
     * Decide whether this server runs mods or plugins. A mod-loader version variable
     * is the strongest signal; the egg name/tags are the fallback. Anything else
     * (Paper/Purpur/Spigot/vanilla/proxies) is treated as a plugin platform.
     */
    private static function detectMode(Server $server): string
    {
        $egg  = $server->egg;
        $envs = self::eggEnvVars($egg);

        foreach (['NEOFORGE_VERSION', 'FORGE_VERSION', 'FABRIC_VERSION', 'QUILT_VERSION'] as $var) {
            if (in_array($var, $envs, true)) {
                return 'mods';
            }
        }

        $haystack = self::eggHaystack($egg);
        foreach (['neoforge', 'forge', 'fabric', 'quilt'] as $kw) {
            if (preg_match('/\b' . preg_quote($kw, '/') . '\b/', $haystack)) {
                return 'mods';
            }
        }

        return 'plugins';
    }

    /**
     * Best guess at the specific loader (mods) or platform (plugins) for the default
     * filter, e.g. 'neoforge' or 'paper'. Null when it can't be told.
     */
    private function detectLoaderOrPlatform(Server $server): ?string
    {
        $egg  = $server->egg;
        $envs = $this->eggEnvVars($egg);

        if (in_array('NEOFORGE_VERSION', $envs, true)) return 'neoforge';
        if (in_array('FORGE_VERSION', $envs, true))    return 'forge';
        if (in_array('QUILT_VERSION', $envs, true))    return 'quilt';
        if (in_array('FABRIC_VERSION', $envs, true))   return 'fabric';

        $haystack   = $this->eggHaystack($egg);
        $candidates = $this->mode === 'plugins'
            ? ['purpur', 'paper', 'folia', 'spigot', 'bukkit', 'sponge']
            : ['neoforge', 'forge', 'fabric', 'quilt'];

        foreach ($candidates as $kw) {
            if (preg_match('/\b' . preg_quote($kw, '/') . '\b/', $haystack)) {
                return $kw;
            }
        }

        return null;
    }

    /** @return string[] Upper-cased env-variable names defined by the egg. */
    private static function eggEnvVars($egg): array
    {
        $out = [];
        foreach ($egg?->variables ?? [] as $v) {
            $out[] = strtoupper((string) $v->env_variable);
        }
        return $out;
    }

    /** Lower-cased "name + tags" blob for keyword matching. */
    private static function eggHaystack($egg): string
    {
        return strtolower(trim(($egg?->name ?? '') . ' ' . implode(' ', $egg?->tags ?? [])));
    }

    /**
     * The Minecraft version this server is currently set to, read from its own
     * variable value (falling back to the egg's default) for the usual version
     * variable names. Null when it can't be determined or isn't a plain release
     * number (e.g. "latest"), so browsing isn't force-filtered to a bad value.
     */
    private function detectMcVersion(Server $server): ?string
    {
        $names = ['MC_VERSION', 'MINECRAFT_VERSION', 'VANILLA_VERSION', 'SERVER_VERSION', 'VERSION'];

        // Eager-load explicitly so reading the egg's variables can't trip Pelican's
        // lazy-loading guard (which would otherwise throw and be swallowed below).
        try {
            $server->loadMissing('egg.variables');
        } catch (Throwable) {
            // ignore — we still try what's already loaded
        }

        $egg = $server->egg;
        if (!$egg) {
            return null;
        }

        // Map egg variable id → ENV name, and capture the version-variable defaults.
        $envById  = [];
        $defaults = [];
        foreach ($egg->variables ?? [] as $v) {
            $env = strtoupper((string) $v->env_variable);
            $envById[$v->id] = $env;
            if (in_array($env, $names, true)) {
                $defaults[$env] = trim((string) ($v->default_value ?? ''));
            }
        }

        // The server's actually-set values. Query ServerVariable directly (the same
        // model the installer writes) instead of a relation accessor, so this works
        // regardless of relation naming or lazy-loading being disabled.
        $serverVals = [];
        try {
            foreach (ServerVariable::query()->where('server_id', $server->id)->get() as $sv) {
                $env = $envById[$sv->variable_id] ?? null;
                if ($env !== null) {
                    $serverVals[$env] = (string) $sv->variable_value;
                }
            }
        } catch (Throwable) {
            // fall through to egg defaults
        }

        // Prefer the set value, then the egg default — both in $names priority order.
        foreach ($names as $env) {
            if (isset($serverVals[$env]) && ($mc = $this->cleanMcVersion($serverVals[$env])) !== null) {
                return $mc;
            }
        }
        foreach ($names as $env) {
            if (isset($defaults[$env]) && ($mc = $this->cleanMcVersion($defaults[$env])) !== null) {
                return $mc;
            }
        }

        return null;
    }

    /** Return a plain "1.20.1"-style release from a raw value, or null. */
    private function cleanMcVersion(string $raw): ?string
    {
        $raw = trim($raw);
        return preg_match('/^\d+\.\d+(?:\.\d+)?$/', $raw) ? $raw : null;
    }

    // ─── View helpers ───────────────────────────────────────────────────────────

    /** Human label for the current mode. */
    public function contentNoun(): string
    {
        return $this->mode === 'plugins' ? 'plugin' : 'mod';
    }

    /** The server directory the current mode installs into ('mods' or 'plugins'). */
    private function targetDir(): string
    {
        return $this->mode === 'plugins' ? 'plugins' : 'mods';
    }

    public function getTargetDirLabel(): string
    {
        return '/' . $this->targetDir();
    }

    /**
     * Minecraft versions for the filter. Prefer CurseForge's live list; if that's
     * empty (e.g. no CF API key configured), fall back to Modrinth's public
     * game-version list (needs no key); only if both fail use the static list.
     */
    public function getFilterVersionOptions(): array
    {
        try {
            $versions = app(CurseForgeService::class)->getMinecraftVersions();
        } catch (Throwable) {
            $versions = [];
        }

        if (empty($versions)) {
            try {
                $versions = app(ModrinthService::class)->getMinecraftVersions();
            } catch (Throwable) {
                $versions = [];
            }
        }

        // Last-resort static list (both live sources unavailable). Kept reasonably
        // current — the newest release line plus the historically popular versions —
        // so an offline panel still offers sensible choices.
        $versions = !empty($versions) ? $versions : [
            '26.2', '26.1.2', '26.1',
            '1.21.11', '1.21.8', '1.21.4', '1.21.1', '1.21',
            '1.20.6', '1.20.4', '1.20.1', '1.20',
            '1.19.2', '1.18.2', '1.16.5', '1.12.2', '1.7.10',
        ];

        // Make sure the currently-selected version (e.g. the auto-detected server
        // version) is always present so the selector can render it as chosen.
        if ($this->filterVersion !== '' && !in_array($this->filterVersion, $versions, true)) {
            array_unshift($versions, $this->filterVersion);
        }

        return $versions;
    }

    /** Loader (mods) or platform (plugins) options for the filter. */
    public function getFilterLoaderOptions(): array
    {
        return $this->mode === 'plugins'
            ? ['paper' => 'Paper', 'purpur' => 'Purpur', 'spigot' => 'Spigot', 'bukkit' => 'Bukkit', 'folia' => 'Folia', 'sponge' => 'Sponge']
            : ['forge' => 'Forge', 'neoforge' => 'NeoForge', 'fabric' => 'Fabric', 'quilt' => 'Quilt'];
    }

    private function activeFilters(): array
    {
        return array_filter([
            'gameVersion' => $this->filterVersion,
            'loader'      => $this->filterLoader,
        ], fn ($v) => $v !== '');
    }

    /**
     * Count of the filters that live in the Filters panel (the loader/platform).
     * The Minecraft version has its own top-line selector, so it isn't counted here.
     */
    public function getActiveFilterCount(): int
    {
        return $this->filterLoader !== '' ? 1 : 0;
    }

    // ─── Actions ──────────────────────────────────────────────────────────────

    public function setMode(string $mode): void
    {
        $mode = $mode === 'plugins' ? 'plugins' : 'mods';
        if ($mode === $this->mode) {
            return;
        }

        $this->mode = $mode;
        // Loader/platform slugs and installed folder differ per mode — reset both.
        $this->filterLoader = $this->detectLoaderOrPlatform($this->getServer()) ?? '';
        $this->search       = '';
        $this->loadInstalledFiles();
        $this->loadItems();
    }

    public function searchItems(): void
    {
        $this->loadItems();
    }

    public function applyFilters(): void
    {
        $this->loadItems();
    }

    public function clearFilters(): void
    {
        // Only the panel's own filter (loader/platform). The MC version has its own
        // selector and is left as-is.
        $this->filterLoader = '';
        $this->loadItems();
    }

    public function setProvider(string $provider): void
    {
        $this->provider = in_array($provider, ['all', 'curseforge', 'modrinth'], true) ? $provider : 'all';
        $this->search   = '';
        $this->loadItems();
    }

    /**
     * Open the install drawer for a given item, then load its versions in a
     * follow-up request (wire:init) so the drawer pops instantly.
     */
    public function openModal(string|int $itemId, ?string $provider = null): void
    {
        $this->authorizeManage();

        $item = collect($this->items)->first(
            fn ($m) => (string) ($m['id'] ?? '') === (string) $itemId
                && ($provider === null || ($m['provider'] ?? null) === $provider)
        );

        if (!$item) {
            try {
                $item = $this->fetchSingleItem($itemId, $provider ?? $this->provider);
            } catch (Throwable) {
                Notification::make()->title('Could not load ' . $this->contentNoun() . ' details.')->danger()->send();
                return;
            }
        }

        $this->selectedItem    = $item;
        $this->versions        = [];
        $this->selectedVersion = null;
        $this->versionsLoading = true;
        $this->showModal       = true;
    }

    public function closeModal(): void
    {
        $this->showModal       = false;
        $this->selectedItem    = null;
        $this->versions        = [];
        $this->selectedVersion = null;
    }

    /**
     * Default-select the newest loaded version that fits the server: prefer one
     * matching BOTH the selected Minecraft version and loader/platform, then MC
     * alone, and only fall back to the newest overall when nothing is compatible.
     * Versions arrive newest-first, so the first match is the latest compatible one.
     */
    private function preferredVersionId(): string
    {
        $mc     = strtolower(trim($this->filterVersion));
        $loader = strtolower(trim($this->filterLoader));

        $tags = function (array $v): array {
            // CurseForge encodes MC versions + loader names in gameVersions; Modrinth
            // splits them across gameVersions + loaders. Merge both, lower-cased.
            return array_map(
                fn ($g) => strtolower(trim((string) $g)),
                array_merge($v['gameVersions'] ?? [], $v['loaders'] ?? [])
            );
        };

        if ($mc !== '') {
            // MC + loader.
            if ($loader !== '') {
                foreach ($this->versions as $v) {
                    $t = $tags($v);
                    if (in_array($mc, $t, true) && in_array($loader, $t, true)) {
                        return (string) $v['id'];
                    }
                }
            }
            // MC only.
            foreach ($this->versions as $v) {
                if (in_array($mc, $tags($v), true)) {
                    return (string) $v['id'];
                }
            }
        }

        return (string) $this->versions[0]['id'];
    }

    /**
     * The plain Minecraft release numbers a given version supports, newest first.
     * CurseForge mixes loader names into gameVersions (e.g. ["1.20.1","NeoForge"]);
     * Modrinth lists pure MC versions — either way we keep only "1.20.1"-shaped
     * entries so the drawer can show exactly which MC versions a file is for.
     *
     * @param  array<string, mixed>  $ver
     * @return string[]
     */
    public function supportedMcVersions(array $ver): array
    {
        $out = [];
        foreach ($ver['gameVersions'] ?? [] as $g) {
            $g = trim((string) $g);
            if (preg_match('/^\d+\.\d+(?:\.\d+)?$/', $g)) {
                $out[$g] = true;
            }
        }

        $out = array_keys($out);
        usort($out, 'version_compare');

        return array_reverse($out);
    }

    /**
     * Map of version-id → supported MC versions for the loaded drawer versions,
     * so the view can show the selected version's compatibility reactively.
     *
     * @return array<string, string[]>
     */
    public function versionMcMap(): array
    {
        $map = [];
        foreach ($this->versions as $v) {
            $map[(string) ($v['id'] ?? '')] = $this->supportedMcVersions($v);
        }

        return $map;
    }

    public function loadVersions(): void
    {
        if (!$this->selectedItem) {
            return;
        }

        $this->versionsLoading = true;

        try {
            $id = $this->selectedItem['id'];

            if (($this->selectedItem['provider'] ?? null) === 'curseforge') {
                $this->versions = app(CurseForgeService::class)->getFiles(
                    (int) $id,
                    $this->activeFilters()
                );
            } else {
                $loaders = $this->mode === 'plugins' ? self::PLUGIN_LOADERS : self::MOD_LOADERS;
                $this->versions = app(ModrinthService::class)->getVersions((string) $id, $loaders);
            }

            if (!empty($this->versions)) {
                $this->selectedVersion = $this->preferredVersionId();
            }
        } catch (Throwable $e) {
            Notification::make()->title('Could not load versions: ' . $e->getMessage())->warning()->send();
        } finally {
            $this->versionsLoading = false;
        }
    }

    /**
     * Download the selected version straight into the target folder via Wings.
     * Synchronous: fires the pull, then briefly polls for the file to land.
     */
    public function installItem(): void
    {
        $this->authorizeManage();

        if (!$this->selectedItem || !$this->selectedVersion) {
            Notification::make()->title('Please select a version.')->warning()->send();
            return;
        }

        $provider = $this->selectedItem['provider'] ?? null;
        $name     = $this->selectedItem['name'] ?? ucfirst($this->contentNoun());

        try {
            [$url, $fileName] = $this->resolveDownload();
        } catch (Throwable $e) {
            Notification::make()->title('Could not resolve download: ' . $e->getMessage())->danger()->send();
            return;
        }

        if (empty($url) || empty($fileName)) {
            Notification::make()->title('No downloadable file for this version.')->danger()->send();
            return;
        }

        $dir    = $this->targetDir();
        $server = $this->getServer();

        try {
            $repo = app(DaemonFileRepository::class);
            $repo->setServer($server);

            // Make sure the destination exists (fresh servers may lack it).
            try { $repo->createDirectory($dir, '/'); } catch (Throwable) {}

            $repo->pull($url, '/' . $dir, [
                'filename'   => $fileName,
                'foreground' => false,
            ]);

            // Briefly wait for Wings to finish (single jars are small/fast). If it
            // hasn't settled in time we still report it as started, not failed.
            $landed = $this->waitForFile($repo, $dir, $fileName);
        } catch (Throwable $e) {
            Log::warning('[ModpackManager] Individual install failed', ['error' => $e->getMessage()]);
            Notification::make()
                ->title('Install failed')
                ->body($e->getMessage())
                ->danger()
                ->send();
            return;
        }

        $this->closeModal();
        $this->loadInstalledFiles();

        if ($landed) {
            Notification::make()
                ->title("Installed “{$name}”")
                ->body("Saved to {$this->getTargetDirLabel()}/{$fileName}. Restart the server to load it.")
                ->success()
                ->send();
        } else {
            Notification::make()
                ->title("Downloading “{$name}”…")
                ->body("Wings is fetching it into {$this->getTargetDirLabel()}. It should appear shortly — refresh the list.")
                ->info()
                ->send();
        }
    }

    /**
     * Remove a jar from the target folder. Only names currently in the listed
     * folder are accepted, so this can't be used to delete arbitrary paths.
     */
    public function removeInstalledFile(string $name): void
    {
        $this->authorizeManage();

        $known = collect($this->installedFiles)->pluck('name')->all();
        if ($name === '' || basename($name) !== $name || !in_array($name, $known, true)) {
            Notification::make()->title('That file is no longer present.')->warning()->send();
            $this->loadInstalledFiles();
            return;
        }

        try {
            $repo = app(DaemonFileRepository::class);
            $repo->setServer($this->getServer());
            $repo->deleteFiles('/' . $this->targetDir(), [$name]);
        } catch (Throwable $e) {
            Notification::make()->title('Could not remove file: ' . $e->getMessage())->danger()->send();
            return;
        }

        $this->loadInstalledFiles();

        Notification::make()
            ->title("Removed {$name}")
            ->body('Restart the server for the change to take effect.')
            ->success()
            ->send();
    }

    public function refreshInstalled(): void
    {
        $this->loadInstalledFiles();
    }

    // ─── Data loading ───────────────────────────────────────────────────────────

    private function loadItems(): void
    {
        $this->isLoading = true;
        $this->errorMsg  = '';
        $this->items     = [];

        try {
            $filters = $this->activeFilters();

            $this->items = $this->provider === 'all'
                ? $this->searchAllProviders($this->search, $filters)
                : $this->searchProvider($this->provider, $this->search, $filters);
        } catch (Throwable $e) {
            $this->errorMsg = $e->getMessage();
            Log::warning('[ModpackManager] Failed to load ' . $this->mode, ['error' => $e->getMessage()]);
        } finally {
            $this->isLoading = false;
        }
    }

    /** Round-robin merge of the fast providers; a single provider failing is skipped. */
    private function searchAllProviders(string $query, array $filters, int $perProvider = 12): array
    {
        $buckets = [];

        foreach (self::COMBINED_PROVIDERS as $provider) {
            try {
                $buckets[$provider] = array_slice($this->searchProvider($provider, $query, $filters), 0, $perProvider);
            } catch (Throwable $e) {
                Log::info("[ModpackManager] '{$provider}' {$this->mode} search skipped in combined view", ['error' => $e->getMessage()]);
                $buckets[$provider] = [];
            }
        }

        $merged = [];
        for ($i = 0; $i < $perProvider; $i++) {
            foreach ($buckets as $packs) {
                if (isset($packs[$i])) {
                    $merged[] = $packs[$i];
                }
            }
        }

        return $merged;
    }

    private function searchProvider(string $provider, string $query, array $filters): array
    {
        if ($provider === 'curseforge') {
            $classId = $this->mode === 'plugins' ? CurseForgeService::CLASS_PLUGINS : CurseForgeService::CLASS_MODS;
            return app(CurseForgeService::class)->searchContent($query, $classId, filters: $filters);
        }

        $projectType = $this->mode === 'plugins' ? 'plugin' : 'mod';
        return app(ModrinthService::class)->searchByType($query, $projectType, filters: $filters);
    }

    private function fetchSingleItem(string|int $id, ?string $provider): array
    {
        if ($provider === 'curseforge') {
            return app(CurseForgeService::class)->getMod((int) $id);
        }

        return app(ModrinthService::class)->getProject((string) $id);
    }

    /**
     * Resolve the download URL + filename for the selected version.
     *
     * @return array{0:?string, 1:?string}  [url, fileName]
     */
    private function resolveDownload(): array
    {
        $provider = $this->selectedItem['provider'] ?? null;

        if ($provider === 'curseforge') {
            $file = collect($this->versions)->first(
                fn ($v) => (string) ($v['id'] ?? '') === (string) $this->selectedVersion
            );
            $fileName = $file['fileName'] ?? $file['displayName'] ?? null;
            $url = app(CurseForgeService::class)->getDownloadUrl(
                (int) $this->selectedItem['id'],
                (int) $this->selectedVersion
            );
            return [$url, $fileName];
        }

        // Modrinth: take the primary file (or the first) from the loaded version.
        $version = collect($this->versions)->firstWhere('id', $this->selectedVersion);
        $files   = $version['files'] ?? [];
        $file    = collect($files)->firstWhere('primary', true) ?? ($files[0] ?? null);

        return [$file['url'] ?? null, $file['filename'] ?? null];
    }

    /**
     * Poll the target folder until $fileName appears and its size stops growing,
     * or the short deadline passes. Returns true if the file settled.
     */
    private function waitForFile(DaemonFileRepository $repo, string $dir, string $fileName): bool
    {
        $deadline = time() + 20;
        $lastSize = -1;
        $stable   = 0;

        while (time() < $deadline) {
            usleep(1_800_000); // 1.8s
            $size = $this->remoteFileSize($repo, '/' . $dir, $fileName);

            if ($size === null) {
                continue;
            }
            if ($size > 0 && $size === $lastSize) {
                if (++$stable >= 1) {
                    return true;
                }
            } else {
                $stable = 0;
            }
            $lastSize = $size;
        }

        return $lastSize > 0;
    }

    private function loadInstalledFiles(): void
    {
        $this->installedFiles = [];

        try {
            $repo = app(DaemonFileRepository::class);
            $repo->setServer($this->getServer());
            $entries = $repo->getDirectory('/' . $this->targetDir());
        } catch (Throwable) {
            return; // folder missing / daemon offline — just show nothing
        }

        $files = [];
        foreach ($entries as $entry) {
            $name   = (string) ($entry['name'] ?? '');
            $isFile = (bool) ($entry['file'] ?? true);

            if (!$isFile || !preg_match('/\.jar(\.disabled)?$/i', $name)) {
                continue;
            }

            $files[] = [
                'name'      => $name,
                'sizeLabel' => $this->humanBytes((int) ($entry['size'] ?? 0)),
            ];
        }

        usort($files, fn ($a, $b) => strcasecmp($a['name'], $b['name']));
        $this->installedFiles = $files;
    }

    private function remoteFileSize(DaemonFileRepository $repo, string $dir, string $name): ?int
    {
        try {
            foreach ($repo->getDirectory($dir) as $entry) {
                if (($entry['name'] ?? null) === $name) {
                    return (int) ($entry['size'] ?? 0);
                }
            }
        } catch (Throwable) {
            // ignore
        }

        return null;
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
        if ($bytes >= 1048576)    return round($bytes / 1048576, 1) . ' MB';
        if ($bytes >= 1024)       return round($bytes / 1024, 1) . ' KB';
        return $bytes . ' B';
    }

    private function getServer(): Server
    {
        /** @var Server $server */
        $server = Filament::getTenant();
        return $server;
    }

    // ─── Navigation ───────────────────────────────────────────────────────────

    public static function getNavigationLabel(): string
    {
        // In the server panel the tenant (this server) is already bound when the
        // sidebar is built, so we can reflect the egg: a plugin platform reads
        // "Plugins", a mod loader reads "Mods". Falls back to "Mods" off-tenant.
        $server = Filament::getTenant();

        return ($server instanceof Server && self::detectMode($server) === 'plugins')
            ? 'Plugins'
            : 'Mods';
    }

    public function getTitle(): string
    {
        return $this->mode === 'plugins' ? 'Plugin Browser' : 'Mod Browser';
    }
}
