<?php

namespace Cosmii02\ModpackManager\Services;

use Cosmii02\ModpackManager\Models\ModpackInstall;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Checks installed modpacks against their provider for newer versions and
 * notifies the server owner when an update first appears.
 *
 * The "latest version" logic mirrors the live check in ModpackBrowserPage so
 * the scheduled result and the in-panel banner agree.
 */
class UpdateCheckService
{
    /**
     * Check every server's most-recent installed modpack.
     *
     * @return array{checked:int, updates:int, notified:int, errors:int}
     */
    public function checkAll(bool $notify = true): array
    {
        $stats = ['checked' => 0, 'updates' => 0, 'notified' => 0, 'errors' => 0];

        // One record per server: the newest successful install.
        $records = ModpackInstall::query()
            ->where('status', 'installed')
            ->orderByDesc('id')
            ->get()
            ->unique('server_id');

        foreach ($records as $record) {
            try {
                $result = $this->check($record, $notify);
                $stats['checked']++;
                if ($result['update_available']) {
                    $stats['updates']++;
                }
                if ($result['notified']) {
                    $stats['notified']++;
                }
            } catch (Throwable $e) {
                $stats['errors']++;
                Log::warning('[ModpackManager] Update check failed for install #' . $record->id, [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $stats;
    }

    /**
     * Check a single record, persist the result, and notify once per new version.
     *
     * @return array{update_available:bool, latest:?string, notified:bool}
     */
    public function check(ModpackInstall $record, bool $notify = true): array
    {
        $latest = $this->latestVersionLabel($record);

        $available = $latest !== null
            && $record->modpack_version !== null
            && trim($latest) !== trim((string) $record->modpack_version);

        $record->update([
            'latest_version'    => $latest,
            'update_available'  => $available,
            'update_checked_at' => now(),
        ]);

        $notified = false;

        // Notify only when there's an update AND we haven't already told the
        // owner about *this* version.
        if ($available && $notify && $record->update_notified_version !== $latest) {
            $notified = $this->notifyOwner($record, $latest);
            if ($notified) {
                $record->update(['update_notified_version' => $latest]);
            }
        }

        return ['update_available' => $available, 'latest' => $latest, 'notified' => $notified];
    }

    /**
     * Resolve the latest available version label for a record's provider.
     */
    public function latestVersionLabel(ModpackInstall $record): ?string
    {
        try {
            if ($record->provider === 'curseforge') {
                $files = app(CurseForgeService::class)->getFiles((int) $record->modpack_id);
                return $files[0]['displayName'] ?? null;
            }

            $versions = match ($record->provider) {
                'ftb'        => app(FtbService::class)->getVersions((string) $record->modpack_id),
                'atlauncher' => app(ATLauncherService::class)->getVersions((string) $record->modpack_id),
                default      => app(ModrinthService::class)->getVersions((string) $record->modpack_id),
            };

            return $versions[0]['displayName']
                ?? $versions[0]['versionNumber']
                ?? $versions[0]['name']
                ?? null;
        } catch (Throwable) {
            // Be conservative: a provider hiccup must not produce a false "update".
            return null;
        }
    }

    /**
     * Send a Filament database notification to the server owner.
     */
    private function notifyOwner(ModpackInstall $record, string $latest): bool
    {
        $owner = $record->server?->owner;

        if (!$owner) {
            return false;
        }

        try {
            Notification::make()
                ->title('Modpack update available')
                ->icon('tabler-package')
                ->body(sprintf(
                    '%s has a new version available: %s (you have %s).',
                    $record->modpack_name,
                    $latest,
                    $record->modpack_version ?: 'unknown'
                ))
                ->success()
                ->sendToDatabase($owner, isEventDispatched: true);

            $record->appendLog("Update available: {$latest} — notified owner.");

            return true;
        } catch (Throwable $e) {
            Log::warning('[ModpackManager] Could not send update notification for install #' . $record->id, [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
