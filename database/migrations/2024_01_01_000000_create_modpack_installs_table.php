<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Idempotent: a plugin re-install must never wipe a server's recorded modpack.
        // If the table already exists from a previous install, keep it (and its history)
        // untouched rather than failing on a duplicate CREATE.
        if (Schema::hasTable('modpack_installs')) {
            return;
        }

        Schema::create('modpack_installs', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('server_id')->index();
            $table->string('provider', 20);          // curseforge | modrinth
            $table->string('modpack_id', 100);
            $table->string('modpack_name', 255);
            $table->string('modpack_version', 100)->nullable();
            $table->string('modpack_icon_url', 500)->nullable();
            $table->string('status', 20)->default('pending');
            $table->json('steps')->nullable();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->json('debug_log')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->foreign('server_id')
                  ->references('id')
                  ->on('servers')
                  ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        // Intentionally NOT dropping the table. This table is the plugin's persistent
        // memory of which modpack each server is running; preserving it across an
        // uninstall / re-install is the whole point. To purge it deliberately, drop it
        // by hand:  Schema::dropIfExists('modpack_installs');
    }
};
