<?php

namespace Cosmii02\ModpackManager\Filament\Server\Pages;

use App\Models\Server;
use Cosmii02\ModpackManager\Jobs\InstallModpackJob;
use Cosmii02\ModpackManager\Models\ModpackInstall;
use Cosmii02\ModpackManager\Services\CurseForgeService;
use Cosmii02\ModpackManager\Services\ModrinthService;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Server-panel page for browsing and installing Minecraft modpacks.
 * URL: /server/{server}/modpacks
 */
class ModpackBrowserPage extends Page
{
    /**
     * A pending/installing record that hasn't advanced in this many seconds is
     * treated as dead (worker crashed/stopped). Longer than the worst-case backup
     * (15 min) + download waits so a slow-but-live install is never killed.
     */
    private const STALE_AFTER_SECONDS = 1200; // 20 minutes

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-archive-box-arrow-down';
    protected static ?string $navigationLabel = 'Modpacks';
    protected static ?string $slug            = 'modpacks';
    protected static ?int    $navigationSort  = 50;
    protected string         $view            = 'modpack-manager::filament.server.pages.modpack-browser-page';

    // ─── State ────────────────────────────────────────────────────────────────

    public string $search    = '';
    public string $provider  = 'curseforge';   // 'curseforge' | 'modrinth'
    public array  $modpacks  = [];
    public bool   $isLoading = false;
    public string $errorMsg  = '';

    // Install modal state
    public bool    $showModal       = false;
    public ?array  $selectedModpack = null;
    public array   $versions        = [];
    public bool    $versionsLoading = false;
    public ?string $selectedVersion = null;
    public bool    $deleteExisting  = false;
    public bool    $createBackup    = true;

    // Installation progress state
    public bool   $isInstalling  = false;
    public int    $installId     = 0;
    public int    $progress      = 0;
    public array  $steps         = [];
    public array  $debugLog      = [];
    public string $installStatus = '';   // 'installing' | 'installed' | 'failed'
    public string $installError  = '';
    public ?int   $installStartedAt = null; // epoch ms — for the elapsed timer
    public int    $installElapsed   = 0;    // frozen elapsed seconds when finished

    // Installed modpack info (current)
    public ?array  $installedModpack   = null;
    public bool    $updateAvailable    = false;
    public ?string $latestVersionLabel = null;

    // ─── Lifecycle ────────────────────────────────────────────────────────────

    public function mount(): void
    {
        $server = $this->getServer();

        // Check for any existing installation record
        $latest = ModpackInstall::where('server_id', $server->id)
            ->orderByDesc('id')
            ->first();

        if ($latest) {
            if (in_array($latest->status, ['installing', 'pending'], true)) {
                if ($this->isRecordStale($latest)) {
                    // The job never progressed (worker not running / crashed before
                    // it could mark the record failed). Auto-recover instead of
                    // locking the page forever, and show the last good install.
                    $this->failStaleRecord($latest);

                    $previous = ModpackInstall::where('server_id', $server->id)
                        ->where('status', 'installed')
                        ->orderByDesc('id')
                        ->first();

                    if ($previous) {
                        $this->applyInstalledInfo($previous);
                    }
                } else {
                    // Resume watching an ongoing install
                    $this->resumeWatchingInstall($latest);
                }
            } elseif ($latest->status === 'installed') {
                $this->applyInstalledInfo($latest);
            }
        }

        // Pre-load popular modpacks (skip if an install is already running)
        if (!$this->isInstalling) {
            $this->loadModpacks();
        }
    }

    // ─── Public properties for view ───────────────────────────────────────────

    public function getPreservedFilesProperty(): array
    {
        return config('modpack-manager.preserved_files', []);
    }

    public function getInstallStepLabels(): array
    {
        return ModpackInstall::STEPS;
    }

    // ─── Actions ──────────────────────────────────────────────────────────────

    /**
     * Called by search input (wire:model.lazy + wire:keydown.enter) and filter changes.
     */
    public function searchModpacks(): void
    {
        $this->loadModpacks();
    }

    public function setProvider(string $provider): void
    {
        $this->provider = $provider;
        $this->search   = '';
        $this->loadModpacks();
    }

    /**
     * Open install/update modal for a given modpack.
     */
    public function openModal(string|int $modpackId): void
    {
        $modpack = collect($this->modpacks)->firstWhere('id', $modpackId);

        if (!$modpack) {
            // Fetch from API if not in current list (e.g., installed pack not in search results)
            try {
                $modpack = $this->fetchSingleModpack($modpackId);
            } catch (Throwable $e) {
                Notification::make()->title('Could not load modpack details.')->danger()->send();
                return;
            }
        }

        $this->selectedModpack  = $modpack;
        $this->versions         = [];
        $this->selectedVersion  = null;
        $this->versionsLoading  = true;
        $this->deleteExisting   = false;
        $this->createBackup     = true;
        $this->showModal        = true;

        $this->loadVersions();
    }

    public function closeModal(): void
    {
        $this->showModal       = false;
        $this->selectedModpack = null;
        $this->versions        = [];
        $this->selectedVersion = null;
    }

    /**
     * Load available versions for the selected modpack.
     */
    public function loadVersions(): void
    {
        if (!$this->selectedModpack) {
            return;
        }

        $this->versionsLoading = true;

        try {
            if ($this->selectedModpack['provider'] === 'curseforge') {
                $service = app(CurseForgeService::class);
                $this->versions = $service->getFiles((int) $this->selectedModpack['id']);
            } else {
                $service = app(ModrinthService::class);
                $this->versions = $service->getVersions($this->selectedModpack['id']);
            }

            if (!empty($this->versions)) {
                $this->selectedVersion = (string) $this->versions[0]['id'];
            }
        } catch (Throwable $e) {
            Notification::make()
                ->title('Could not load versions: ' . $e->getMessage())
                ->warning()
                ->send();
        } finally {
            $this->versionsLoading = false;
        }
    }

    /**
     * Dispatch the installation job.
     */
    public function startInstall(): void
    {
        if (!$this->selectedModpack || !$this->selectedVersion) {
            Notification::make()->title('Please select a version.')->warning()->send();
            return;
        }

        $server   = $this->getServer();
        $provider = $this->selectedModpack['provider'];

        // Build an install "spec" the job uses to resolve the server pack
        // (or build one from the client pack). For Modrinth we resolve the
        // .mrpack URL here since the versions list is already loaded.
        if ($provider === 'curseforge') {
            $spec = [
                'provider' => 'curseforge',
                'mod_id'   => (int) $this->selectedModpack['id'],
                'file_id'  => (int) $this->selectedVersion,
            ];
        } else {
            $version = collect($this->versions)->firstWhere('id', $this->selectedVersion);
            $mrpackUrl = $version ? app(ModrinthService::class)->getPrimaryFileUrl($version['files']) : null;

            if (!$mrpackUrl) {
                Notification::make()->title('No downloadable file found for this version.')->danger()->send();
                return;
            }

            $spec = [
                'provider'   => 'modrinth',
                'project_id' => (string) $this->selectedModpack['id'],
                'version_id' => (string) $this->selectedVersion,
                'mrpack_url' => $mrpackUrl,
            ];
        }

        // Determine version label
        $versionLabel = $this->getVersionLabel($provider, $this->selectedVersion);

        // Create install record
        $record = ModpackInstall::create([
            'server_id'       => $server->id,
            'provider'        => $provider,
            'modpack_id'      => (string) $this->selectedModpack['id'],
            'modpack_name'    => $this->selectedModpack['name'],
            'modpack_version' => $versionLabel,
            'modpack_icon_url'=> $this->selectedModpack['iconUrl'] ?? null,
            'status'          => 'pending',
            'steps'           => (new ModpackInstall())->buildInitialSteps(),
            'progress'        => 0,
            'debug_log'       => [],
        ]);

        // Stash the spec for the queued job (no extra DB column needed).
        \Illuminate\Support\Facades\Cache::put(
            "modpack-manager:install-spec:{$record->id}",
            $spec,
            now()->addHours(2)
        );

        // Capture the name before closeModal() nulls out $selectedModpack.
        $modpackName = $this->selectedModpack['name'];

        $this->closeModal();

        $this->installId       = $record->id;
        $this->isInstalling    = true;
        $this->progress        = 0;
        $this->steps           = $record->steps;
        $this->debugLog        = [];
        $this->installStatus   = 'installing';
        $this->installError    = '';
        $this->installStartedAt = (int) ($record->created_at->valueOf());
        $this->installElapsed   = 0;

        InstallModpackJob::dispatch($record->id, [
            'delete_existing' => $this->deleteExisting,
            'create_backup'   => $this->createBackup,
        ])->onQueue('default');

        Notification::make()
            ->title("Installing {$modpackName}…")
            ->info()
            ->send();
    }

    /**
     * Polled every 2 seconds while installing to update the UI.
     * Called via wire:poll in the Blade view.
     */
    public function pollProgress(): void
    {
        if (!$this->isInstalling || !$this->installId) {
            return;
        }

        $record = ModpackInstall::find($this->installId);

        if (!$record) {
            return;
        }

        $this->progress      = $record->progress;
        $this->steps         = $record->steps ?? [];
        $this->debugLog      = $record->debug_log ?? [];
        $this->installStatus = $record->status;
        $this->installError  = $record->error_message ?? '';

        if (!$this->installStartedAt && $record->created_at) {
            $this->installStartedAt = (int) ($record->created_at->valueOf());
        }

        if (in_array($record->status, ['installed', 'failed'], true)) {
            $this->isInstalling = false;
            $this->installElapsed = $record->created_at
                ? (int) $record->created_at->diffInSeconds($record->updated_at ?? now())
                : 0;

            if ($record->status === 'installed') {
                $this->installedModpack = [
                    'provider' => $record->provider,
                    'id'       => $record->modpack_id,
                    'name'     => $record->modpack_name,
                    'version'  => $record->modpack_version,
                    'iconUrl'  => $record->modpack_icon_url,
                ];
            }

            return;
        }

        // Still pending/installing — if it has stopped progressing, the worker is
        // likely dead. Auto-fail so the page doesn't stay stuck.
        if ($this->isRecordStale($record)) {
            $this->failStaleRecord($record);
            $this->isInstalling  = false;
            $this->installStatus = 'failed';
            $this->installError  = $record->refresh()->error_message ?? 'Install stalled.';
        }
    }

    /**
     * Dismiss the progress view. If the underlying record is still pending/installing
     * (e.g. a stuck job), mark it failed so it doesn't re-lock the page on next load.
     */
    public function cancelInstallView(): void
    {
        if ($this->installId) {
            $record = ModpackInstall::find($this->installId);
            if ($record && in_array($record->status, ['pending', 'installing'], true)) {
                $record->update([
                    'status'        => 'failed',
                    'error_message' => 'Dismissed from the panel.',
                ]);
                $record->appendLog('Dismissed by user from the panel.');
                \Illuminate\Support\Facades\Cache::forget("modpack-manager:install-spec:{$record->id}");
            }
        }

        $this->isInstalling  = false;
        $this->installStatus = '';
        $this->installError  = '';
        $this->installId     = 0;

        // Refresh the installed-pack banner from the latest good install, if any.
        $server   = $this->getServer();
        $installed = ModpackInstall::where('server_id', $server->id)
            ->where('status', 'installed')
            ->orderByDesc('id')
            ->first();

        if ($installed) {
            $this->applyInstalledInfo($installed);
        } else {
            $this->installedModpack = null;
        }

        if (empty($this->modpacks)) {
            $this->loadModpacks();
        }
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function getServer(): Server
    {
        /** @var Server $server */
        $server = Filament::getTenant();
        return $server;
    }

    /**
     * Is this pending/installing record dead (no progress for too long)?
     */
    private function isRecordStale(ModpackInstall $record): bool
    {
        if (!in_array($record->status, ['pending', 'installing'], true)) {
            return false;
        }

        $last = $record->updated_at ?? $record->created_at;

        return $last === null || $last->diffInSeconds(now()) > self::STALE_AFTER_SECONDS;
    }

    /**
     * Mark a stranded record failed and drop its cached install spec.
     */
    private function failStaleRecord(ModpackInstall $record): void
    {
        $record->update([
            'status'        => 'failed',
            'error_message' => $record->error_message
                ?: 'Install stopped making progress — the queue worker may not be running. Marked failed automatically.',
        ]);
        $record->appendLog('Auto-failed: no progress for over ' . (self::STALE_AFTER_SECONDS / 60) . ' minutes.');
        \Illuminate\Support\Facades\Cache::forget("modpack-manager:install-spec:{$record->id}");
    }

    /**
     * Populate the "installed pack" banner state from a record.
     */
    private function applyInstalledInfo(ModpackInstall $record): void
    {
        $this->installedModpack = [
            'provider' => $record->provider,
            'id'       => $record->modpack_id,
            'name'     => $record->modpack_name,
            'version'  => $record->modpack_version,
            'iconUrl'  => $record->modpack_icon_url,
        ];

        $this->computeUpdateState($record);
    }

    /**
     * Compare the installed version against the latest available version to
     * decide whether an update is genuinely available.
     */
    private function computeUpdateState(ModpackInstall $record): void
    {
        $latestLabel = null;

        try {
            if ($record->provider === 'curseforge') {
                $files = app(CurseForgeService::class)->getFiles((int) $record->modpack_id);
                $latestLabel = $files[0]['displayName'] ?? null;
            } else {
                $versions = app(ModrinthService::class)->getVersions($record->modpack_id);
                $latestLabel = $versions[0]['versionNumber'] ?? $versions[0]['name'] ?? null;
            }
        } catch (Throwable) {
            $latestLabel = null; // be conservative: no false "update available"
        }

        $this->latestVersionLabel = $latestLabel;
        $this->updateAvailable = $latestLabel !== null
            && $record->modpack_version !== null
            && trim($latestLabel) !== trim((string) $record->modpack_version);
    }

    private function loadModpacks(): void
    {
        $this->isLoading = true;
        $this->errorMsg  = '';
        $this->modpacks  = [];

        try {
            if ($this->provider === 'curseforge') {
                $service        = app(CurseForgeService::class);
                $this->modpacks = $service->search($this->search);
            } else {
                $service        = app(ModrinthService::class);
                $this->modpacks = $service->search($this->search);
            }
        } catch (Throwable $e) {
            $this->errorMsg = $e->getMessage();
            Log::warning('[ModpackManager] Failed to load modpacks', ['error' => $e->getMessage()]);
        } finally {
            $this->isLoading = false;
        }
    }

    private function fetchSingleModpack(string|int $id): array
    {
        if ($this->provider === 'curseforge') {
            return app(CurseForgeService::class)->getMod((int) $id);
        }

        return app(ModrinthService::class)->getProject((string) $id);
    }

    private function resolveDownloadUrl(array $modpack, string $versionId): string
    {
        if ($modpack['provider'] === 'curseforge') {
            $service = app(CurseForgeService::class);
            return $service->getDownloadUrl((int) $modpack['id'], (int) $versionId);
        }

        // Modrinth: find the file URL within the loaded versions list
        $version = collect($this->versions)->firstWhere('id', $versionId);

        if (!$version) {
            throw new RuntimeException("Version {$versionId} not found in loaded versions list.");
        }

        $url = app(ModrinthService::class)->getPrimaryFileUrl($version['files']);

        if (!$url) {
            throw new RuntimeException('No downloadable file found for this version.');
        }

        return $url;
    }

    private function getVersionLabel(string $provider, string $versionId): string
    {
        $version = collect($this->versions)->firstWhere('id', $versionId);

        if (!$version) {
            return $versionId;
        }

        return $provider === 'modrinth'
            ? ($version['versionNumber'] ?? $version['name'] ?? $versionId)
            : ($version['displayName'] ?? $versionId);
    }

    private function resumeWatchingInstall(ModpackInstall $record): void
    {
        $this->installId     = $record->id;
        $this->isInstalling  = true;
        $this->progress      = $record->progress;
        $this->steps         = $record->steps ?? [];
        $this->debugLog      = $record->debug_log ?? [];
        $this->installStatus = $record->status;
        $this->installError  = $record->error_message ?? '';
        $this->installStartedAt = $record->created_at ? (int) ($record->created_at->valueOf()) : null;
    }

    // ─── Navigation helpers ───────────────────────────────────────────────────

    public static function getNavigationLabel(): string
    {
        return 'Modpacks';
    }

    public function getTitle(): string
    {
        return 'Modpack Browser';
    }
}
