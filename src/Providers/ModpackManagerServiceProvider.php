<?php

namespace Cosmii02\ModpackManager\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

class ModpackManagerServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Pelican auto-discovers plugin config (`config/modpack-manager.php` is
        // loaded into the `modpack-manager` config key before providers are
        // registered), views (under the `modpack-manager::` namespace),
        // migrations and artisan commands, so none of that is registered here.
        // We only need to wire up our scheduled task.
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
