<?php

namespace JensS\Newsletter\Console;

use Illuminate\Console\Command;
use JensS\Newsletter\Sending\Worker;

class WorkCommand extends Command
{
    protected $signature = 'newsletter:work
        {--max-time=3300 : Seconds to keep working (fits an hourly cron)}
        {--idle=15 : Seconds to wait between checks when the queue is empty}
        {--queue= : Queues to process, comma separated (default: the newsletter queue)}
        {--once : Run maintenance and drain the queue once, then exit}';

    protected $description = 'Start due campaigns and process the newsletter queue for a limited time';

    public function handle(Worker $worker): int
    {
        if ($this->option('once')) {
            $result = $worker->tick((int) $this->option('max-time'), 0);
        } else {
            $result = $worker->run(
                (int) $this->option('max-time'),
                max(1, (int) $this->option('idle')),
                fn (string $line) => $this->line($line),
                $this->option('queue') ?: null,
            );
        }

        if (($result['status'] ?? null) === 'busy') {
            $this->components->warn('Another newsletter worker is running; exiting.');
        }

        return self::SUCCESS;
    }
}
