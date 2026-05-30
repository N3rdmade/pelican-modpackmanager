<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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
        Schema::dropIfExists('modpack_installs');
    }
};
