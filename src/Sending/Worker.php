<?php

namespace JensS\Newsletter\Sending;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Console\Kernel as Artisan;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs maintenance plus a time-boxed queue worker for the newsletter queue.
 * A cache lock ensures only one runs at a time, whichever trigger fired it
 * (hourly cron, scheduler, or HTTP tick).
 */
final class Worker
{
    private const LOCK = 'newsletter:worker';

    public function __construct(
        private readonly Maintenance $maintenance,
        private readonly Cache $cache,
        private readonly Artisan $artisan,
    ) {}

    /**
     * Process work until the queue is empty or the budget is used.
     *
     * @return array<string, mixed>
     */
    public function tick(?int $maxSeconds = null, ?int $maxJobs = null): array
    {
        $maxSeconds ??= (int) config('newsletter.worker.tick_max_seconds', 40);
        $maxJobs ??= (int) config('newsletter.worker.tick_max_jobs', 500);

        return $this->locked($maxSeconds + 30, function () use ($maxSeconds, $maxJobs) {
            if (function_exists('set_time_limit')) {
                @set_time_limit($maxSeconds + 30);
            }

            ignore_user_abort(true);

            $maintenance = $this->maintenance->run();
            $this->drain($maxSeconds, $maxJobs);

            return ['status' => 'ran', 'maintenance' => $maintenance];
        });
    }

    /**
     * Long-running mode for an hourly cron: keep draining (and starting due
     * campaigns) until the time budget is spent.
     *
     * @param  callable(string): void|null  $output
     * @return array<string, mixed>
     */
    public function run(int $maxSeconds, int $idleSleep = 15, ?callable $output = null, ?string $queues = null): array
    {
        return $this->locked($maxSeconds + 60, function () use ($maxSeconds, $idleSleep, $output, $queues) {
            $deadline = time() + $maxSeconds;
            $rounds = 0;

            while (($remaining = $deadline - time()) > 0) {
                $rounds++;
                $summary = $this->maintenance->run();

                if ($output && array_sum($summary) > 0) {
                    $output('Maintenance: '.json_encode($summary));
                }

                $this->drain(min($remaining, 300), 0, $queues);

                if ($deadline - time() > $idleSleep) {
                    sleep($idleSleep);
                } else {
                    break;
                }
            }

            return ['status' => 'ran', 'rounds' => $rounds];
        });
    }

    private function drain(int $maxSeconds, int $maxJobs, ?string $queues = null): void
    {
        if ($maxSeconds <= 0) {
            return;
        }

        $connection = config('newsletter.queue.connection') ?: config('queue.default');

        if ($connection === 'sync') {
            return; // jobs already ran inline
        }

        try {
            $this->artisan->call('queue:work', array_filter([
                'connection' => $connection,
                '--queue' => $queues ?? config('newsletter.queue.name', 'newsletter'),
                '--stop-when-empty' => true,
                '--max-time' => $maxSeconds,
                '--max-jobs' => $maxJobs > 0 ? $maxJobs : null,
                '--sleep' => 1,
                '--memory' => 256,
            ], fn ($v) => $v !== null));
        } catch (Throwable $e) {
            Log::error('Newsletter worker failed: '.$e->getMessage(), ['exception' => $e]);
        }
    }

    /**
     * @param  callable(): array<string, mixed>  $callback
     * @return array<string, mixed>
     */
    private function locked(int $seconds, callable $callback): array
    {
        $lock = $this->cache->lock(self::LOCK, $seconds);

        if (! $lock->get()) {
            return ['status' => 'busy'];
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }
}
