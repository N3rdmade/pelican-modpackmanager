<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('modpack_installs', function (Blueprint $table) {
            // Latest version label seen by the scheduled update checker.
            $table->string('latest_version', 255)->nullable()->after('modpack_version');
            // Whether the latest available version differs from what's installed.
            $table->boolean('update_available')->default(false)->after('latest_version');
            // When the update check last ran for this record.
            $table->timestamp('update_checked_at')->nullable()->after('update_available');
            // The version we last sent a notification about (so we notify once per version).
            $table->string('update_notified_version', 255)->nullable()->after('update_checked_at');
        });
    }

    public function down(): void
    {
        Schema::table('modpack_installs', function (Blueprint $table) {
            $table->dropColumn([
                'latest_version',
                'update_available',
                'update_checked_at',
                'update_notified_version',
            ]);
        });
    }
};
