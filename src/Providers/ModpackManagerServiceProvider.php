<?php

namespace Cosmii02\ModpackManager\Providers;

use Cosmii02\ModpackManager\Console\Commands\CheckModpackUpdatesCommand;
use Illuminate\Console\Scheduling\Schedule;
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

        if ($this->app->runningInConsole()) {
            $this->commands([
                CheckModpackUpdatesCommand::class,
            ]);
        }

        $this->scheduleUpdateChecks();
    }

    /**
     * Register the recurring update check with Pelican's scheduler
     * (driven by the panel's `php artisan schedule:run` cron entry).
     */
    private function scheduleUpdateChecks(): void
    {
        if (!config('modpack-manager.update_checks_enabled', true)) {
            return;
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $event = $schedule->command('modpack-manager:check-updates')
                ->withoutOverlapping()
                ->runInBackground();

            match (config('modpack-manager.update_check_frequency', 'daily')) {
                'hourly'          => $event->hourly(),
                'every_six_hours' => $event->everySixHours(),
                'twice_daily'     => $event->twiceDaily(),
                'weekly'          => $event->weekly(),
                default           => $event->daily(),
            };
        });
    }
}
