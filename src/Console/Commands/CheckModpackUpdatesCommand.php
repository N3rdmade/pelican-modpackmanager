<?php

namespace Cosmii02\ModpackManager\Console\Commands;

use Cosmii02\ModpackManager\Services\UpdateCheckService;
use Illuminate\Console\Command;

class CheckModpackUpdatesCommand extends Command
{
    protected $signature = 'modpack-manager:check-updates
                            {--no-notify : Check and record updates without sending notifications}';

    protected $description = 'Check installed modpacks for newer versions and notify server owners.';

    public function handle(UpdateCheckService $service): int
    {
        $this->info('Checking installed modpacks for updates…');

        $stats = $service->checkAll(notify: !$this->option('no-notify'));

        $this->table(
            ['Checked', 'Updates available', 'Owners notified', 'Errors'],
            [[$stats['checked'], $stats['updates'], $stats['notified'], $stats['errors']]]
        );

        return self::SUCCESS;
    }
}
