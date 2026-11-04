<?php

namespace JensS\Newsletter\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use JensS\Newsletter\Models\Campaign;
use JensS\Newsletter\Sending\CampaignDispatcher;

/**
 * Prepares one page of recipients, then queues itself for the next page.
 * Small, resumable steps survive hosting limits on execution time.
 */
class PrepareCampaignDeliveries implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    public int $timeout = 120;

    public function __construct(public readonly int $campaignId)
    {
        $this->onConnection(config('newsletter.queue.connection'));
        $this->onQueue(config('newsletter.queue.name', 'newsletter'));
    }

    public function handle(CampaignDispatcher $dispatcher): void
    {
        $campaign = Campaign::query()->find($this->campaignId);

        if ($campaign === null) {
            return;
        }

        if ($dispatcher->prepareNextPage($campaign)) {
            static::dispatch($this->campaignId);
        }
    }
}
