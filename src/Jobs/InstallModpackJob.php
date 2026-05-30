<?php

namespace Cosmii02\ModpackManager\Jobs;

use Cosmii02\ModpackManager\Models\ModpackInstall;
use Cosmii02\ModpackManager\Services\ModpackInstallService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class InstallModpackJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800; // 30 min – building a pack downloads many mods
    public int $tries   = 1;    // No retry; installation is not idempotent

    public function __construct(
        public readonly int   $installRecordId,
        public readonly array $options = []
    ) {}

    public function handle(ModpackInstallService $installService): void
    {
        $record = ModpackInstall::find($this->installRecordId);

        if (!$record) {
            Log::warning("[ModpackManager] InstallModpackJob: record #{$this->installRecordId} not found, skipping.");
            return;
        }

        if ($record->status === 'installed') {
            Log::info("[ModpackManager] InstallModpackJob: record #{$this->installRecordId} already installed, skipping.");
            return;
        }

        $record->update(['status' => 'installing']);
        $record->appendLog('Job started on queue worker.');

        $installService->install($record, $this->options);
    }

    public function failed(Throwable $e): void
    {
        $record = ModpackInstall::find($this->installRecordId);

        if ($record) {
            $record->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
            ]);
            $record->appendLog('JOB FAILED: ' . $e->getMessage());
        }

        Log::error("[ModpackManager] InstallModpackJob failed for record #{$this->installRecordId}", [
            'error' => $e->getMessage(),
        ]);
    }
}
