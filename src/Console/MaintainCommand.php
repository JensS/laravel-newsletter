<?php

namespace JensS\Newsletter\Console;

use Illuminate\Console\Command;
use JensS\Newsletter\Sending\Maintenance;

class MaintainCommand extends Command
{
    protected $signature = 'newsletter:maintain';

    protected $description = 'Start due scheduled campaigns and recover interrupted deliveries';

    public function handle(Maintenance $maintenance): int
    {
        foreach ($maintenance->run() as $step => $count) {
            if ($count > 0 || $this->output->isVerbose()) {
                $this->components->twoColumnDetail(str_replace('_', ' ', $step), (string) $count);
            }
        }

        return self::SUCCESS;
    }
}
