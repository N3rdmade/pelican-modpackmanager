<?php

namespace Cosmii02\ModpackManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Server;

/**
 * Tracks modpack installations per server.
 *
 * @property int         $id
 * @property int         $server_id
 * @property string      $provider         'curseforge' | 'modrinth'
 * @property string      $modpack_id       External modpack ID
 * @property string      $modpack_name
 * @property string|null $modpack_version
 * @property string|null $modpack_icon_url
 * @property string      $status           'pending' | 'installing' | 'installed' | 'failed'
 * @property array       $steps            Step-by-step progress array
 * @property int         $progress         0–100
 * @property array       $debug_log        Array of debug messages
 * @property string|null $error_message
 */
class ModpackInstall extends Model
{
    protected $table = 'modpack_installs';

    protected $fillable = [
        'server_id',
        'provider',
        'modpack_id',
        'modpack_name',
        'modpack_version',
        'modpack_icon_url',
        'status',
        'steps',
        'progress',
        'debug_log',
        'error_message',
    ];

    protected $casts = [
        'steps'     => 'array',
        'debug_log' => 'array',
        'progress'  => 'integer',
    ];

    // ─── Step definitions ─────────────────────────────────────────────────────

    public const STEPS = [
        'save_config'    => 'Save Configuration',
        'create_backup'  => 'Create Backup',
        'delete_files'   => 'Delete Old Files',
        'download'       => 'Download Modpack',
        'extract'        => 'Extract Files',
        'restore_config' => 'Restore Configuration',
        'merge_configs'  => 'Merge Configs',
        'finalize'       => 'Finalize Installation',
        'configure_loader' => 'Configure Startup / Loader',
    ];

    // Step statuses
    public const STEP_PENDING    = 'pending';
    public const STEP_RUNNING    = 'running';
    public const STEP_DONE       = 'done';
    public const STEP_FAILED     = 'failed';

    // ─── Helpers ──────────────────────────────────────────────────────────────

    public function buildInitialSteps(): array
    {
        $steps = [];
        foreach (array_keys(self::STEPS) as $key) {
            $steps[$key] = self::STEP_PENDING;
        }
        return $steps;
    }

    public function markStepRunning(string $step): void
    {
        $steps = $this->steps ?? $this->buildInitialSteps();
        $steps[$step] = self::STEP_RUNNING;
        $this->update(['steps' => $steps]);
    }

    public function markStepDone(string $step): void
    {
        $steps = $this->steps ?? $this->buildInitialSteps();
        $steps[$step] = self::STEP_DONE;
        $this->update(['steps' => $steps]);
    }

    public function markStepFailed(string $step): void
    {
        $steps = $this->steps ?? $this->buildInitialSteps();
        $steps[$step] = self::STEP_FAILED;
        $this->update(['steps' => $steps]);
    }

    public function appendLog(string $message): void
    {
        $log = $this->debug_log ?? [];
        $log[] = '[' . now()->format('H:i:s') . '] ' . $message;
        $this->update(['debug_log' => $log]);
    }

    // ─── Relationships ────────────────────────────────────────────────────────

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
