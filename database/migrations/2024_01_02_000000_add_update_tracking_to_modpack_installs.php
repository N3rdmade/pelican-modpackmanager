<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Idempotent: re-running on an existing table (e.g. after a plugin re-install that
        // preserved the data) must not fail on columns that are already there.
        Schema::table('modpack_installs', function (Blueprint $table) {
            // Latest version label seen by the scheduled update checker.
            if (!Schema::hasColumn('modpack_installs', 'latest_version')) {
                $table->string('latest_version', 255)->nullable()->after('modpack_version');
            }
            // Whether the latest available version differs from what's installed.
            if (!Schema::hasColumn('modpack_installs', 'update_available')) {
                $table->boolean('update_available')->default(false)->after('latest_version');
            }
            // When the update check last ran for this record.
            if (!Schema::hasColumn('modpack_installs', 'update_checked_at')) {
                $table->timestamp('update_checked_at')->nullable()->after('update_available');
            }
            // The version we last sent a notification about (so we notify once per version).
            if (!Schema::hasColumn('modpack_installs', 'update_notified_version')) {
                $table->string('update_notified_version', 255)->nullable()->after('update_checked_at');
            }
        });
    }

    public function down(): void
    {
        // Intentionally a no-op — see the create migration. Install/update history is
        // preserved across an uninstall / re-install rather than being dropped.
    }
};
