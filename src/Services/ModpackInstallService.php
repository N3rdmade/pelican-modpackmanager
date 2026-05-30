<?php

namespace Cosmii02\ModpackManager\Services;

use App\Models\Egg;
use App\Models\Server;
use App\Models\User;
use App\Repositories\Daemon\DaemonFileRepository;
use App\Services\Backups\InitiateBackupService;
use App\Services\Servers\ReinstallServerService;
use App\Services\Servers\StartupModificationService;
use Cosmii02\ModpackManager\Models\ModpackInstall;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Orchestrates modpack installation on a Pelican server via the Wings daemon.
 *
 * Strategy:
 *   - CurseForge: if the selected file is (or links to) an official SERVER PACK,
 *     download & extract it directly. Otherwise BUILD a server pack from the
 *     client pack — read manifest.json, have Wings download every mod, and
 *     merge the overrides/ folder.
 *   - Modrinth: a .mrpack only contains an index + overrides, so we always
 *     parse modrinth.index.json and download the server-side files.
 *
 * Wings does all file work (download via pull(), extract via decompressFile()).
 */
class ModpackInstallService
{
    private const ARCHIVE_NAME = 'modpack-download.zip';

    public function __construct(
        private DaemonFileRepository $fileRepo
    ) {}

    public function install(ModpackInstall $record, array $options = []): void
    {
        $server = $record->server;

        if (!$server) {
            throw new RuntimeException('Server not found for modpack install record #' . $record->id);
        }

        $spec = Cache::pull("modpack-manager:install-spec:{$record->id}");

        if (empty($spec) || empty($spec['provider'])) {
            throw new RuntimeException(
                "No install spec found for install #{$record->id} (cache may have expired). Please re-trigger."
            );
        }

        $this->fileRepo->setServer($server);

        try {
            $this->stepSaveConfig($record);
            $this->stepCreateBackup($record, $options);
            $this->stepDeleteFiles($record, $options);

            $plan = $this->resolvePlan($record, $spec);

            $this->stepDownload($record, $plan['archiveUrl']);
            $this->stepExtract($record);
            $this->stepRestoreConfig($record);
            $this->stepAssembleAndMerge($record, $plan, $spec);
            $this->stepFinalize($record);
            $this->stepConfigureLoader($record, $plan);

            $record->update(['status' => 'installed', 'progress' => 100]);
            $record->appendLog('Installation completed successfully.');

        } catch (Throwable $e) {
            Log::error('[ModpackManager] Installation failed', [
                'record' => $record->id,
                'error'  => $e->getMessage(),
                'trace'  => $e->getTraceAsString(),
            ]);

            $record->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            $record->appendLog('FATAL: ' . $e->getMessage());

            throw $e;
        }
    }

    // ─── Plan resolution ────────────────────────────────────────────────────

    /**
     * Decide what to download and how to install it.
     *
     * @return array{mode:string, archiveUrl:string}
     *   mode: 'server_pack' | 'curseforge_build' | 'modrinth'
     */
    private function resolvePlan(ModpackInstall $record, array $spec): array
    {
        if ($spec['provider'] === 'modrinth') {
            $url = $spec['mrpack_url'] ?? null;
            if (empty($url)) {
                throw new RuntimeException('No Modrinth download URL in install spec.');
            }
            $record->appendLog('Modrinth pack — will download server-side files from the index.');
            return ['mode' => 'modrinth', 'archiveUrl' => $url];
        }

        /** @var CurseForgeService $cf */
        $cf     = app(CurseForgeService::class);
        $modId  = (int) $spec['mod_id'];
        $fileId = (int) $spec['file_id'];

        $file = $cf->getFile($modId, $fileId);

        if (!empty($file['isServerPack'])) {
            $record->appendLog('Selected file is already a server pack — installing directly.');
            return ['mode' => 'server_pack', 'archiveUrl' => $cf->getDownloadUrl($modId, $fileId)];
        }

        if (!empty($file['serverPackFileId'])) {
            $serverPackId = (int) $file['serverPackFileId'];
            $record->appendLog("Official server pack found (file #{$serverPackId}) — using it.");
            return ['mode' => 'server_pack', 'archiveUrl' => $cf->getDownloadUrl($modId, $serverPackId)];
        }

        $record->appendLog('No official server pack available — building one from the client pack.');
        return ['mode' => 'curseforge_build', 'archiveUrl' => $cf->getDownloadUrl($modId, $fileId)];
    }

    // ─── Steps ──────────────────────────────────────────────────────────────

    private function stepSaveConfig(ModpackInstall $record): void
    {
        $record->markStepRunning('save_config');
        $record->appendLog('Saving configuration files before install…');

        $saved = [];
        foreach (config('modpack-manager.preserved_files', []) as $filename) {
            try {
                $content = $this->fileRepo->getContent('/' . $filename, 5 * 1024 * 1024);
                $saved[$filename] = (string) $content;
                $record->appendLog("  Saved: {$filename}");
            } catch (Throwable) {
                $record->appendLog("  Skip (not found): {$filename}");
            }
        }

        Cache::put("modpack-manager:config:{$record->id}", $saved, now()->addHours(2));

        $record->update(['progress' => 8]);
        $record->markStepDone('save_config');
        $record->appendLog('Configuration saved.');
    }

    private function stepCreateBackup(ModpackInstall $record, array $options = []): void
    {
        $record->markStepRunning('create_backup');

        if (!($options['create_backup'] ?? true)) {
            $record->appendLog('Skipping backup (disabled for this install).');
            $record->update(['progress' => 16]);
            $record->markStepDone('create_backup');
            return;
        }

        $record->appendLog('Creating a panel backup of the current server…');

        try {
            $name = 'Before modpack install — ' . $record->modpack_name
                  . ' (' . now()->format('Y-m-d H:i') . ')';

            /** @var InitiateBackupService $service */
            $service = app(InitiateBackupService::class);
            $backup  = $service->handle($record->server, $name);

            $record->appendLog('  Backup started on Wings; waiting for it to finish…');

            // Wait for Wings to report completion before we touch any files,
            // otherwise the backup would capture a half-modified server.
            $deadline  = time() + 900; // 15 minutes
            $completed = false;
            while (time() < $deadline) {
                sleep(5);
                $backup->refresh();
                if ($backup->completed_at !== null) {
                    $completed = true;
                    break;
                }
            }

            if (!$completed) {
                $record->appendLog('  WARNING: backup did not finish within 15 min — continuing anyway.');
            } elseif (!$backup->is_successful) {
                $record->appendLog('  WARNING: backup reported a failure — continuing anyway.');
            } else {
                $size = $backup->bytes ? $this->humanBytes((int) $backup->bytes) : 'unknown size';
                $record->appendLog("  Backup completed successfully ({$size}). It's in the Backups tab.");
            }
        } catch (\App\Exceptions\Service\Backup\TooManyBackupsException) {
            $record->appendLog('  WARNING: server backup limit reached — skipping backup. Free a slot or raise the limit.');
        } catch (Throwable $e) {
            $record->appendLog('  WARNING: backup skipped (' . $e->getMessage() . ')');
        }

        $record->update(['progress' => 16]);
        $record->markStepDone('create_backup');
    }

    private function stepDeleteFiles(ModpackInstall $record, array $options): void
    {
        $record->markStepRunning('delete_files');

        if (!($options['delete_existing'] ?? false)) {
            $record->appendLog('Skipping deletion ("Delete existing files" was not enabled).');
            $record->update(['progress' => 24]);
            $record->markStepDone('delete_files');
            return;
        }

        $record->appendLog('Removing old mod/pack directories…');
        foreach (['mods', 'config', 'scripts', 'kubejs', 'defaultconfigs', 'patchouli_books', 'resourcepacks', 'shaderpacks'] as $dir) {
            try {
                $this->fileRepo->deleteFiles('/', [$dir]);
                $record->appendLog("  Deleted: /{$dir}/");
            } catch (Throwable) {
                // Most won't exist; ignore.
            }
        }

        $record->update(['progress' => 24]);
        $record->markStepDone('delete_files');
        $record->appendLog('Old files removed.');
    }

    private function stepDownload(ModpackInstall $record, string $url): void
    {
        $record->markStepRunning('download');
        $record->appendLog('Telling Wings to download the modpack archive…');
        $record->appendLog("  Source: {$url}");

        $this->fileRepo->pull($url, '/', [
            'filename'   => self::ARCHIVE_NAME,
            'foreground' => false,
        ]);

        $deadline = time() + 600;
        $lastSize = -1;
        $stable   = 0;

        while (time() < $deadline) {
            sleep(4);
            $size = $this->remoteFileSize('/', self::ARCHIVE_NAME);

            if ($size === null) {
                $record->appendLog('  …waiting for download to start');
                continue;
            }

            if ($size > 0 && $size === $lastSize) {
                if (++$stable >= 2) {
                    break;
                }
            } else {
                $stable = 0;
                $record->appendLog('  Downloaded ' . $this->humanBytes($size));
                $record->update(['progress' => min(45, 26 + (int) ($size / (8 * 1024 * 1024)))]);
            }

            $lastSize = $size;
        }

        if ($lastSize <= 0) {
            throw new RuntimeException('Download did not complete — archive not found on the server.');
        }

        $record->appendLog('  Download complete: ' . $this->humanBytes($lastSize));
        $record->update(['progress' => 48]);
        $record->markStepDone('download');
    }

    private function stepExtract(ModpackInstall $record): void
    {
        $record->markStepRunning('extract');
        $record->appendLog('Extracting archive on the server…');

        $this->fileRepo->decompressFile('/', self::ARCHIVE_NAME);
        $record->appendLog('  Extraction complete.');

        $record->update(['progress' => 58]);
        $record->markStepDone('extract');
    }

    private function stepRestoreConfig(ModpackInstall $record): void
    {
        $record->markStepRunning('restore_config');
        $record->appendLog('Restoring preserved configuration files…');

        $saved = Cache::get("modpack-manager:config:{$record->id}", []);

        foreach ($saved as $filename => $content) {
            try {
                $this->fileRepo->putContent('/' . $filename, $content);
                $record->appendLog("  Restored: {$filename}");
            } catch (Throwable $e) {
                $record->appendLog("  WARNING: could not restore {$filename}: " . $e->getMessage());
            }
        }

        $record->update(['progress' => 62]);
        $record->markStepDone('restore_config');
    }

    /**
     * The "merge_configs" step does the real assembly work:
     *   - server_pack: just move overrides/ into root (usually none).
     *   - curseforge_build: download every mod from manifest.json + overrides.
     *   - modrinth: download every server-side file from modrinth.index.json + overrides.
     */
    private function stepAssembleAndMerge(ModpackInstall $record, array $plan, array $spec): void
    {
        $record->markStepRunning('merge_configs');

        if ($plan['mode'] === 'curseforge_build') {
            $this->assembleCurseForgeBuild($record, app(CurseForgeService::class));
        } elseif ($plan['mode'] === 'modrinth') {
            $this->assembleModrinth($record);
        } else {
            $record->appendLog('Server pack installed — no assembly required.');
        }

        // Move an extracted overrides/ folder into the server root (all modes).
        $this->mergeOverrides($record);

        $record->update(['progress' => 94]);
        $record->markStepDone('merge_configs');
    }

    private function stepFinalize(ModpackInstall $record): void
    {
        $record->markStepRunning('finalize');
        $record->appendLog('Finalizing…');

        try {
            $this->fileRepo->deleteFiles('/', [self::ARCHIVE_NAME]);
            $record->appendLog('  Removed download archive.');
        } catch (Throwable) {
            // Non-fatal.
        }

        Cache::forget("modpack-manager:config:{$record->id}");

        $record->update(['progress' => 98]);
        $record->markStepDone('finalize');
        $record->appendLog('Done.');
    }

    /**
     * Best-effort: detect the pack's mod loader, switch the server to a matching
     * Forge/NeoForge/Fabric egg, set its MC + loader-version variables, and trigger
     * a reinstall so the egg installs the loader server on top of the files we placed.
     *
     * Never fatal — any problem just logs a warning and leaves the egg unchanged.
     */
    private function stepConfigureLoader(ModpackInstall $record, array $plan): void
    {
        $record->markStepRunning('configure_loader');
        $record->appendLog('Configuring startup / mod loader…');

        try {
            // Self-contained server packs (ServerPackCreator's start.sh, or a Forge/NeoForge
            // MDK run.sh) install AND launch their own loader. For those we just point the
            // server's startup command at the bundled launcher — no egg switch, no reinstall.
            if ($this->configureSelfContainedPack($record)) {
                $record->markStepDone('configure_loader');
                return;
            }

            // Otherwise we assembled the files ourselves (only mods/ + overrides, no launcher),
            // so switch to a matching loader egg and reinstall to install the loader server.
            $this->configureLoaderEggAndReinstall($record, $plan);
        } catch (Throwable $e) {
            $record->appendLog('  WARNING: startup/loader configuration skipped (' . $e->getMessage() . ').');
        }

        $record->markStepDone('configure_loader');
    }

    /**
     * Detect a self-contained server pack and configure the server to launch its bundled
     * installer/launcher. It switches the server to the matching loader egg (so the panel shows
     * the right egg and the correct Java images) but does NOT reinstall — reinstalling would
     * clobber the loader/files the pack already ships. The egg's startup is then overridden to
     * the bundled launcher.
     *
     *   - ServerPackCreator (start.sh + variables.txt): launch via `bash start.sh`. The
     *     ServerStarterJar installs/runs the loader from variables.txt on first boot.
     *   - Forge/NeoForge MDK server pack (run.sh referencing @libraries/.../unix_args.txt):
     *     launch via `bash run.sh nogui`.
     *
     * Returns true if it handled the pack (caller should stop); false to fall through to the
     * loader-egg + reinstall path.
     */
    private function configureSelfContainedPack(ModpackInstall $record): bool
    {
        // Index the server root (case-insensitively).
        $root = [];
        try {
            foreach ($this->fileRepo->getDirectory('/') as $e) {
                $root[strtolower((string) ($e['name'] ?? ''))] = true;
            }
        } catch (Throwable) {
            return false;
        }

        $hasSpc = isset($root['start.sh']) && isset($root['variables.txt']);
        $hasRun = isset($root['run.sh']);

        if (!$hasSpc && !$hasRun) {
            return false;
        }

        $server = $record->server->refresh();

        $loader = $mc = $loaderVer = null;
        $java   = null;
        $startup = null;

        if ($hasSpc) {
            // variables.txt is the authoritative config for a ServerPackCreator pack.
            $vars      = $this->parseVarsTxt((string) $this->fileRepo->getContent('/variables.txt', 1024 * 1024));
            $mc        = $vars['MINECRAFT_VERSION'] ?? null;
            $loader    = isset($vars['MODLOADER']) ? strtolower($vars['MODLOADER']) : null;
            $loaderVer = $vars['MODLOADER_VERSION'] ?? null;
            $java      = isset($vars['RECOMMENDED_JAVA_VERSION']) && is_numeric($vars['RECOMMENDED_JAVA_VERSION'])
                ? (int) $vars['RECOMMENDED_JAVA_VERSION']
                : null;
            $startup   = 'bash start.sh';

            $record->appendLog('  ServerPackCreator pack detected — launching via start.sh.');
            $this->applyServerPackCreatorTweaks($record, $this->normalizeLoader($loader) ?? '');
        } else {
            // Plain Forge/NeoForge MDK server pack with a run.sh launcher.
            ['loader' => $loader, 'version' => $loaderVer, 'mc' => $mc] =
                $this->parseRunSh((string) $this->fileRepo->getContent('/run.sh', 256 * 1024));
            $startup = 'bash run.sh nogui';

            $record->appendLog('  Self-contained server pack detected — launching via run.sh' . ($loader ? " ({$loader})" : '') . '.');
            $this->writeUserJvmArgs($record);
        }

        // Switch the server to the matching loader egg (correct egg + Java image list in the
        // panel) — but do NOT reinstall, so the bundled loader/files survive.
        $loaderKey = $this->normalizeLoader($loader);
        if ($loaderKey) {
            try {
                $this->switchToLoaderEgg($record, $server, $loaderKey, $mc, $loaderVer);
                $server = $server->refresh();
            } catch (Throwable $e) {
                $record->appendLog('  WARNING: could not switch egg (' . $e->getMessage() . '). Leaving the current egg.');
            }
        }

        // Pick the Java image from the (now matching) egg's allowed images, then write the
        // bundled-launcher startup + image directly, overriding any egg defaults. No reinstall.
        $java  = $java ?: $this->javaForMc($mc);
        $image = $this->pickJavaImageForVersion($server, $java);

        $update = ['startup' => $startup];
        if ($image) {
            $update['image'] = $image;
        }
        $server->update($update);

        $detail = trim(($loaderKey ?: ($loader ?: 'unknown loader')) . ' ' . ($loaderVer ?? '') . ($mc ? " / MC {$mc}" : ''));
        $record->appendLog("  Startup set to “{$startup}”" . ($image ? " (image {$image}, Java {$java})" : '') . " — {$detail}.");
        $record->appendLog('  No reinstall — the pack installs/launches its own loader. Stop → Start once so the new Java image is applied.');

        return true;
    }

    /**
     * Switch the server to a Forge/NeoForge/Fabric egg matching the detected loader, set its
     * MC + loader-version variables, and trigger a reinstall. Used only when WE assembled the
     * files (curseforge_build / modrinth) and there is no bundled launcher script.
     */
    private function configureLoaderEggAndReinstall(ModpackInstall $record, array $plan): void
    {
        ['loader' => $loader, 'mc' => $mc, 'version' => $ver] = $this->detectLoader($record, $plan);

        if (!$loader) {
            $record->appendLog('  Could not determine the mod loader (no manifest/index and no launcher). Egg left unchanged.');
            return;
        }

        $record->appendLog("  Detected loader: {$loader}" . ($ver ? " {$ver}" : '') . ($mc ? " (Minecraft {$mc})" : ''));

        $loaderKey = $this->normalizeLoader($loader);
        if (!$loaderKey || $loaderKey === 'quilt') {
            $record->appendLog('  No matching egg for this loader on the panel — set your loader manually.');
            return;
        }

        $server = $record->server->refresh();
        $egg    = $this->switchToLoaderEgg($record, $server, $loaderKey, $mc, $ver);
        if (!$egg) {
            return;
        }

        $server = $server->refresh();
        $image  = $this->pickJavaImageForVersion($server, $this->javaForMc($mc));
        if ($image) {
            $server->update(['image' => $image]);
            $record->appendLog("  Set Java image: {$image}.");
        }

        try {
            app(ReinstallServerService::class)->handle($server->refresh());
            $record->appendLog("  Triggered a server reinstall — the egg is installing the {$loaderKey} server now. Watch the console.");
        } catch (Throwable $e) {
            $record->appendLog('  WARNING: could not auto-trigger reinstall (' . $e->getMessage() . '). Reinstall the server manually to install the loader.');
        }
    }

    /**
     * Switch the server to the egg matching $loader and set its MC + loader-version variables.
     * Does NOT reinstall. Returns the matched egg, or null if no matching egg is installed.
     */
    private function switchToLoaderEgg(ModpackInstall $record, Server $server, string $loader, ?string $mc, ?string $ver): ?Egg
    {
        $egg = $this->findLoaderEgg($loader);
        if (!$egg) {
            $record->appendLog("  No {$loader} egg installed on this panel — egg left unchanged. Install one to get the matching egg.");
            return null;
        }

        if ((int) $server->egg_id === (int) $egg->id) {
            $record->appendLog("  Server already on the “{$egg->name}” egg.");
            return $egg;
        }

        // Seed every variable the egg defines with its default (so required vars validate),
        // then override the MC + loader-version variables.
        $env = [];
        foreach ($egg->variables as $v) {
            $env[$v->env_variable] = (string) ($v->default_value ?? '');
        }

        $applied = [];
        $apply = function (string $key, ?string $val) use (&$env, &$applied) {
            if ($val !== null && $val !== '' && array_key_exists($key, $env)) {
                $env[$key]     = $val;
                $applied[$key] = $val;
            }
        };

        if ($mc) {
            array_key_exists('MC_VERSION', $env)
                ? $apply('MC_VERSION', $mc)
                : $apply('MINECRAFT_VERSION', $mc);
        }

        $loaderVar = match ($loader) {
            'forge'    => 'FORGE_VERSION',
            'neoforge' => 'NEOFORGE_VERSION',
            'fabric'   => 'LOADER_VERSION',
            default    => null,
        };
        if ($loaderVar) {
            $apply($loaderVar, $ver);
        }

        /** @var StartupModificationService $svc */
        $svc = app(StartupModificationService::class);
        $svc->setUserLevel(User::USER_LEVEL_ADMIN);
        $svc->handle($server, ['egg_id' => $egg->id, 'environment' => $env]);

        $record->appendLog("  Switched egg to “{$egg->name}”. Set variables: " . json_encode($applied));

        return $egg;
    }

    /**
     * Normalise a loader name (from variables.txt MODLOADER or a manifest id) to one of
     * forge / neoforge / fabric / quilt, or null if unrecognised. LegacyFabric maps to fabric.
     */
    private function normalizeLoader(?string $loader): ?string
    {
        return match (strtolower(trim((string) $loader))) {
            'forge'                  => 'forge',
            'neoforge'               => 'neoforge',
            'fabric', 'legacyfabric' => 'fabric',
            'quilt'                  => 'quilt',
            default                  => null,
        };
    }

    /**
     * Parse a ServerPackCreator variables.txt (KEY=value, # comments) into a map.
     *
     * @return array<string, string>
     */
    private function parseVarsTxt(string $txt): array
    {
        $out = [];
        foreach (preg_split('/\r?\n/', $txt) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $out[trim($k)] = trim(trim($v), "\"'");
        }
        return $out;
    }

    /**
     * The JVM args we hand modded servers: let the JVM size its heap from the container's
     * cgroup memory limit (the RAM Pelican assigned), instead of a hardcoded -Xmx.
     */
    private const JVM_MEMORY_ARGS = '-Xms128M -XX:MaxRAMPercentage=92.5';

    /**
     * Make a ServerPackCreator pack play nicely with Pelican: non-interactive, let Pelican
     * manage restarts, and let the JVM use the container's allocated memory.
     */
    private function applyServerPackCreatorTweaks(ModpackInstall $record, string $loader): void
    {
        try {
            $txt = (string) $this->fileRepo->getContent('/variables.txt', 1024 * 1024);
            $txt = preg_replace('/^\s*WAIT_FOR_USER_INPUT\s*=.*$/m', 'WAIT_FOR_USER_INPUT=false', $txt);
            $txt = preg_replace('/^\s*RESTART\s*=.*$/m', 'RESTART=false', $txt);
            // Fabric / Quilt / LegacyFabric launch with JAVA_ARGS from variables.txt.
            $txt = preg_replace('/^\s*JAVA_ARGS\s*=.*$/m', 'JAVA_ARGS="' . self::JVM_MEMORY_ARGS . '"', $txt);

            $this->fileRepo->putContent('/variables.txt', $txt);
            $record->appendLog('  Patched variables.txt (non-interactive, Pelican-managed restarts, container-aware memory).');
        } catch (Throwable $e) {
            $record->appendLog('  WARNING: could not patch variables.txt (' . $e->getMessage() . ').');
        }

        // Forge / NeoForge launch via run scripts that read user_jvm_args.txt.
        if (in_array($loader, ['forge', 'neoforge'], true)) {
            $this->writeUserJvmArgs($record);
        }
    }

    private function writeUserJvmArgs(ModpackInstall $record): void
    {
        try {
            $this->fileRepo->putContent(
                '/user_jvm_args.txt',
                "# Managed by Modpack Manager — lets the JVM use the container's allocated memory.\n" . self::JVM_MEMORY_ARGS . "\n"
            );
            $record->appendLog('  Set user_jvm_args.txt to use the container memory (MaxRAMPercentage=92.5).');
        } catch (Throwable $e) {
            $record->appendLog('  WARNING: could not write user_jvm_args.txt (' . $e->getMessage() . ').');
        }
    }

    /**
     * Work out loader/version/MC from a Forge/NeoForge run.sh that references
     * @libraries/net/<vendor>/.../unix_args.txt.
     *
     * @return array{loader:?string, version:?string, mc:?string}
     */
    private function parseRunSh(string $txt): array
    {
        if (preg_match('#libraries/net/neoforged/neoforge/([^/\s]+)/#', $txt, $m)) {
            return ['loader' => 'neoforge', 'version' => $m[1], 'mc' => $this->mcFromNeoforge($m[1])];
        }
        if (preg_match('#libraries/net/minecraftforge/forge/([0-9.]+)-([0-9.]+)#', $txt, $m)) {
            return ['loader' => 'forge', 'version' => $m[2], 'mc' => $m[1]];
        }
        return ['loader' => null, 'version' => null, 'mc' => null];
    }

    /**
     * NeoForge versions track the MC version: 21.1.228 → 1.21.1, 20.4.x → 1.20.4.
     */
    private function mcFromNeoforge(string $ver): ?string
    {
        if (preg_match('/^(\d+)\.(\d+)\./', $ver, $m)) {
            return '1.' . $m[1] . ($m[2] !== '0' ? '.' . $m[2] : '');
        }
        return null;
    }

    /**
     * Pick a Java docker image for a specific major version, preferring one the server's
     * current egg already allows, else falling back to the parkervcp yolks image.
     */
    private function pickJavaImageForVersion(Server $server, int $java): ?string
    {
        try {
            $images = $server->egg?->docker_images ?? [];
            foreach ($images as $label => $img) {
                if (stripos((string) $img, "java_{$java}") !== false
                    || stripos((string) $label, "java {$java}") !== false
                    || stripos((string) $label, "java_{$java}") !== false) {
                    return $img;
                }
            }
        } catch (Throwable) {
            // fall through to the default image
        }

        return "ghcr.io/parkervcp/yolks:java_{$java}";
    }

    /**
     * Work out the mod loader + versions from the files already on the server.
     *
     * @return array{loader:?string, mc:?string, version:?string}
     */
    private function detectLoader(ModpackInstall $record, array $plan): array
    {
        $loader = $mc = $version = null;

        try {
            if ($plan['mode'] === 'modrinth') {
                $index = json_decode((string) $this->fileRepo->getContent('/modrinth.index.json', 5 * 1024 * 1024), true);
                $deps  = is_array($index) ? ($index['dependencies'] ?? []) : [];
                $mc    = $deps['minecraft'] ?? null;

                foreach (['neoforge' => 'neoforge', 'forge' => 'forge', 'fabric-loader' => 'fabric', 'quilt-loader' => 'quilt'] as $key => $type) {
                    if (!empty($deps[$key])) {
                        $loader  = $type;
                        $version = (string) $deps[$key];
                        break;
                    }
                }
            } else {
                // curseforge_build always has manifest.json; some server packs do too.
                $manifest = json_decode((string) $this->fileRepo->getContent('/manifest.json', 5 * 1024 * 1024), true);
                if (is_array($manifest)) {
                    $mc = $manifest['minecraft']['version'] ?? null;
                    $id = $manifest['minecraft']['modLoaders'][0]['id'] ?? null;
                    if (is_string($id) && str_contains($id, '-')) {
                        [$type, $version] = explode('-', $id, 2);
                        $type   = strtolower($type);
                        $loader = in_array($type, ['forge', 'neoforge', 'fabric', 'quilt'], true) ? $type : null;
                    }
                }
            }
        } catch (Throwable) {
            // No manifest / unreadable — caller treats null loader as "leave egg alone".
        }

        return ['loader' => $loader, 'mc' => $mc, 'version' => $version];
    }

    /**
     * Find an installed egg matching the loader, identified by its variable names
     * (robust across differently-named eggs / panels).
     */
    private function findLoaderEgg(string $loader): ?Egg
    {
        foreach (Egg::with('variables')->get() as $egg) {
            $envs = $egg->variables->pluck('env_variable')->map(fn ($e) => strtoupper((string) $e))->all();
            $has  = fn (string $n) => in_array($n, $envs, true);

            $match = match ($loader) {
                'neoforge' => $has('NEOFORGE_VERSION'),
                'forge'    => $has('FORGE_VERSION') && !$has('NEOFORGE_VERSION') && !$has('SPONGE_TYPE'),
                'fabric'   => $has('FABRIC_VERSION') || $has('LOADER_VERSION'),
                default    => false,
            };

            if ($match) {
                return $egg;
            }
        }

        return null;
    }


    private function javaForMc(?string $mc): int
    {
        if (!$mc || !preg_match('/^(\d+)\.(\d+)(?:\.(\d+))?/', $mc, $m)) {
            return 17; // safe default for most modded packs
        }

        $minor = (int) $m[2];
        $patch = (int) ($m[3] ?? 0);

        if ($minor >= 21)  return 21;
        if ($minor === 20) return $patch >= 5 ? 21 : 17;
        if ($minor >= 17)  return 17;

        return 8;
    }

    // ─── Assembly ───────────────────────────────────────────────────────────

    private function assembleCurseForgeBuild(ModpackInstall $record, CurseForgeService $cf): void
    {
        $record->appendLog('Reading manifest.json…');
        $manifest = json_decode((string) $this->fileRepo->getContent('/manifest.json', 5 * 1024 * 1024), true);

        if (!is_array($manifest) || empty($manifest['files'])) {
            throw new RuntimeException('Could not read manifest.json from the client pack.');
        }

        $mcVer  = $manifest['minecraft']['version'] ?? 'unknown';
        $loader = $manifest['minecraft']['modLoaders'][0]['id'] ?? 'unknown';
        $count  = count($manifest['files']);
        $record->appendLog("  Minecraft {$mcVer} / loader {$loader} — {$count} mods to download.");

        try { $this->fileRepo->createDirectory('mods', '/'); } catch (Throwable) {}

        $infos = $cf->getFilesByIds(array_map(fn ($f) => (int) $f['fileID'], $manifest['files']));

        // Resolve each project's type so resource packs / shaders / data packs go to
        // their own folder instead of all landing in /mods.
        $classes = [];
        try {
            $classes = $cf->getModClasses(array_map(fn ($f) => (int) ($f['projectID'] ?? 0), $manifest['files']));
        } catch (Throwable $e) {
            $record->appendLog('  NOTE: could not resolve project types (' . $e->getMessage() . ') — placing everything in /mods.');
        }

        $expected    = [];
        $createdDirs  = ['/mods' => true];
        $folderCounts = [];
        foreach ($manifest['files'] as $f) {
            $fid  = (int) ($f['fileID'] ?? 0);
            $pid  = (int) ($f['projectID'] ?? 0);
            $info = $infos[$fid] ?? null;
            $url  = $info['url'] ?? null;
            $name = $info['name'] ?? null;

            if (empty($url)) {
                try {
                    $url  = $cf->getDownloadUrl($pid, $fid);
                    $name = $name ?: ('mod-' . $fid . '.jar');
                } catch (Throwable $e) {
                    $record->appendLog("  WARN: skipping file #{$fid} (" . $e->getMessage() . ')');
                    continue;
                }
            }

            $name      = $name ?: ('mod-' . $fid . '.jar');
            $folder    = $this->folderForCurseClass($classes[$pid] ?? null);
            $remoteDir = '/' . $folder;

            if (!isset($createdDirs[$remoteDir])) {
                try { $this->fileRepo->createDirectory($folder, '/'); } catch (Throwable) {}
                $createdDirs[$remoteDir] = true;
            }

            $this->fileRepo->pull($url, $remoteDir, ['filename' => $name, 'foreground' => false]);
            $expected[] = ['dir' => $remoteDir, 'name' => $name];
            $folderCounts[$folder] = ($folderCounts[$folder] ?? 0) + 1;
        }

        $breakdown = implode(', ', array_map(fn ($d, $n) => "{$n} → {$d}/", array_keys($folderCounts), $folderCounts));
        $record->appendLog('  Queued ' . count($expected) . ' downloads on Wings' . ($breakdown ? " ({$breakdown})" : '') . '…');
        $this->waitForFiles($record, $expected);

        $note = "Assembled from a CurseForge CLIENT pack by Modpack Manager.\n"
              . "Minecraft version: {$mcVer}\nMod loader: {$loader}\n\n"
              . "Modpack Manager will try to switch this server to a matching loader egg\n"
              . "and reinstall so the loader server is installed automatically. If that\n"
              . "step is skipped (no matching egg), set your loader to the version above.\n";
        try { $this->fileRepo->putContent('/modpack-manager-README.txt', $note); } catch (Throwable) {}
    }

    private function assembleModrinth(ModpackInstall $record): void
    {
        $record->appendLog('Reading modrinth.index.json…');
        $index = json_decode((string) $this->fileRepo->getContent('/modrinth.index.json', 5 * 1024 * 1024), true);

        if (!is_array($index) || empty($index['files'])) {
            throw new RuntimeException('Could not read modrinth.index.json from the .mrpack.');
        }

        $deps = $index['dependencies'] ?? [];
        $record->appendLog('  Dependencies: ' . json_encode($deps));

        $expected   = [];
        $createdDirs = [];
        foreach ($index['files'] as $f) {
            if (($f['env']['server'] ?? 'required') === 'unsupported') {
                continue;
            }

            $path = $f['path'] ?? null;
            $url  = $f['downloads'][0] ?? null;
            if (empty($path) || empty($url)) {
                continue;
            }

            $dir  = trim(str_replace('\\', '/', dirname($path)), '/');
            $name = basename($path);
            $remoteDir = $dir === '' || $dir === '.' ? '/' : '/' . $dir;

            if ($remoteDir !== '/' && !isset($createdDirs[$remoteDir])) {
                try { $this->fileRepo->createDirectory($dir, '/'); } catch (Throwable) {}
                $createdDirs[$remoteDir] = true;
            }

            $this->fileRepo->pull($url, $remoteDir, ['filename' => $name, 'foreground' => false]);
            $expected[] = ['dir' => $remoteDir, 'name' => $name];
        }

        $record->appendLog('  Queued ' . count($expected) . ' file downloads on Wings…');
        $this->waitForFiles($record, $expected);

        $note = "Installed from a Modrinth .mrpack by Modpack Manager.\n"
              . 'Dependencies: ' . json_encode($deps) . "\n\n"
              . "Modpack Manager will try to switch this server to a matching loader egg\n"
              . "and reinstall so the loader server is installed automatically. If that\n"
              . "step is skipped (no matching egg), set your loader per the dependencies above.\n";
        try { $this->fileRepo->putContent('/modpack-manager-README.txt', $note); } catch (Throwable) {}
    }

    /**
     * Map a CurseForge class (project type) ID to the server folder its files belong in.
     * Unknown/mod types fall back to mods/.
     *
     * CurseForge Minecraft class IDs:
     *   6 = Mc Mods · 12 = Resource Packs · 6552 = Shaders · 6945 = Data Packs · 6541 = Customization
     */
    private function folderForCurseClass(?int $classId): string
    {
        return match ($classId) {
            12   => 'resourcepacks',
            6552 => 'shaderpacks',
            6945 => 'datapacks',
            default => 'mods',
        };
    }

    /**
     * Move the contents of an extracted overrides/ folder into the server root.
     */
    private function mergeOverrides(ModpackInstall $record): void
    {
        try {
            $entries = $this->fileRepo->getDirectory('/overrides');
            $names   = collect($entries)->pluck('name')->filter()->values()->all();

            if (empty($names)) {
                return;
            }

            $moves = array_map(fn ($n) => ['from' => "overrides/{$n}", 'to' => $n], $names);
            $this->fileRepo->renameFiles('/', $moves);
            $record->appendLog('  Merged ' . count($names) . ' item(s) from overrides/ into the server root.');
        } catch (Throwable $e) {
            $record->appendLog('  Overrides merge skipped (' . $e->getMessage() . ')');
        }
    }

    // ─── Wings helpers ──────────────────────────────────────────────────────

    /**
     * Wait for queued Wings downloads to land.
     *
     * @param array<int, array{dir:string, name:string}> $expected
     */
    private function waitForFiles(ModpackInstall $record, array $expected, int $maxSeconds = 540): void
    {
        if (empty($expected)) {
            return;
        }

        $byDir = [];
        foreach ($expected as $e) {
            $byDir[$e['dir']][$e['name']] = true;
        }
        $total = count($expected);

        $deadline = time() + $maxSeconds;
        $lastDone = -1;
        $stalls   = 0;

        while (time() < $deadline) {
            sleep(5);

            $done = 0;
            foreach ($byDir as $dir => $names) {
                $present = [];
                try {
                    foreach ($this->fileRepo->getDirectory($dir) as $entry) {
                        if (($entry['size'] ?? 0) > 0) {
                            $present[$entry['name'] ?? ''] = true;
                        }
                    }
                } catch (Throwable) {
                    // dir may not exist yet
                }
                foreach (array_keys($names) as $n) {
                    if (isset($present[$n])) {
                        $done++;
                    }
                }
            }

            if ($done !== $lastDone) {
                $record->appendLog("  Downloaded {$done}/{$total} files…");
                $record->update(['progress' => min(92, 64 + (int) (28 * $done / max(1, $total)))]);
                $lastDone = $done;
                $stalls   = 0;
            } else {
                $stalls++;
            }

            if ($done >= $total) {
                return;
            }

            if ($stalls >= 8) { // ~40s with no progress
                $record->appendLog("  Download progress stalled at {$done}/{$total} — continuing anyway.");
                return;
            }
        }

        $record->appendLog("  Reached time limit waiting for downloads ({$lastDone}/{$total}).");
    }

    private function remoteFileSize(string $dir, string $name): ?int
    {
        try {
            foreach ($this->fileRepo->getDirectory($dir) as $entry) {
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
}
