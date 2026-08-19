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

    public int $timeout = 3600; // 60 min – includes backup, download, loader install, and verification
    public int $tries   = 1;    // No retry; installation is not idempotent

    public array $spec = [];

    public function __construct(
        public readonly int   $installRecordId,
        public readonly array $options = [],
        array $spec = []
    ) {
        $this->spec = $spec;
    }

    public function handle(ModpackInstallService $installService): void
    {
        $record = ModpackInstall::find($this->installRecordId);

        if (!$record) {
            Log::warning("[ModpackManager] InstallModpackJob: record #{$this->installRecordId} not found, skipping.");
            return;
        }

        if ($record->status !== 'pending') {
            Log::info("[ModpackManager] InstallModpackJob: record #{$this->installRecordId} is {$record->status}, not pending; skipping queued job.");
            return;
        }

        $record->update(['status' => 'installing']);
        $record->appendLog('Job started on queue worker.');

        $installService->install($record, $this->options, $this->spec);
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
