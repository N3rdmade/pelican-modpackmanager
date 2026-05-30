<?php

namespace Cosmii02\ModpackManager\Providers;

use Illuminate\Support\ServiceProvider;

class ModpackManagerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            plugin_path('modpack-manager', 'config/modpack-manager.php'),
            'modpack-manager'
        );
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(
            plugin_path('modpack-manager', 'database/migrations')
        );

        $this->loadViewsFrom(
            plugin_path('modpack-manager', 'resources/views'),
            'modpack-manager'
        );
    }
}
