<?php

namespace JensS\Newsletter\Jobs;

use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use JensS\Newsletter\Exceptions\SendingPaused;
use JensS\Newsletter\Jobs\Middleware\ThrottleDeliveries;
use JensS\Newsletter\Sending\DeliverySender;
use Throwable;

class SendCampaignDelivery implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Temporary failures allowed before giving up (rate-limit releases do not count). */
    public int $maxExceptions;

    public int $timeout = 60;

    public function __construct(public readonly int $deliveryId)
    {
        $this->maxExceptions = max(1, (int) config('newsletter.queue.tries', 5));
        $this->onConnection(config('newsletter.queue.connection'));
        $this->onQueue(config('newsletter.queue.name', 'newsletter'));
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new ThrottleDeliveries];
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return array_map('intval', (array) config('newsletter.queue.backoff', [60, 300, 900]));
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours((int) config('newsletter.queue.retry_until_hours', 48));
    }

    public function handle(DeliverySender $sender): void
    {
        try {
            $sender->send($this->deliveryId);
        } catch (SendingPaused $paused) {
            // Circuit breaker open: try again later without counting a failure.
            ThrottleDeliveries::releaseLater($this, $paused->retryAfter);
        }
    }

    public function failed(?Throwable $e): void
    {
        app(DeliverySender::class)->giveUp($this->deliveryId, $e);
    }
}
