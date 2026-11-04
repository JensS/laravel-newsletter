<?php

namespace JensS\Newsletter\Sending;

use JensS\Newsletter\Contracts\DeliveryDriver;
use JensS\Newsletter\Enums\CampaignStatus;
use JensS\Newsletter\Enums\DeliveryStatus;
use JensS\Newsletter\Events\DeliveryFailed;
use JensS\Newsletter\Jobs\PrepareCampaignDeliveries;
use JensS\Newsletter\Mailbox\MailboxProcessor;
use JensS\Newsletter\Models\Campaign;
use JensS\Newsletter\Models\Delivery;

/**
 * Housekeeping that makes sending self-healing without a permanent worker:
 * starts due campaigns and recovers work interrupted by killed processes.
 */
final class Maintenance
{
    public function __construct(
        private readonly CampaignDispatcher $dispatcher,
        private readonly DeliveryDriver $driver,
        private readonly MailboxProcessor $mailbox,
    ) {}

    /** @return array{started: int, preparation_resumed: int, interrupted_requeued: int, interrupted_failed: int, redispatched: int, completed: int, mailbox_messages: int} */
    public function run(): array
    {
        return [
            'started' => $this->dispatcher->dispatchDue(),
            'preparation_resumed' => $this->resumeStalledPreparation(),
        ] + $this->recoverInterruptedDeliveries() + [
            'redispatched' => $this->redispatchLostJobs(),
            'completed' => $this->completeFinishedCampaigns(),
            'mailbox_messages' => (int) ($this->mailbox->runIfDue()['checked'] ?? 0),
        ];
    }

    private function resumeStalledPreparation(): int
    {
        $count = 0;

        Campaign::query()
            ->where('status', CampaignStatus::Sending->value)
            ->whereNull('prepared_at')
            ->where('prepare_heartbeat_at', '<', now()->subMinutes(10))
            ->each(function (Campaign $campaign) use (&$count) {
                $won = Campaign::query()
                    ->whereKey($campaign->id)
                    ->where('prepare_heartbeat_at', $campaign->getRawOriginal('prepare_heartbeat_at'))
                    ->update(['prepare_heartbeat_at' => now()]);

                if ($won) {
                    PrepareCampaignDeliveries::dispatch($campaign->id);
                    $count++;
                }
            });

        return $count;
    }

    /** @return array{interrupted_requeued: int, interrupted_failed: int} */
    private function recoverInterruptedDeliveries(): array
    {
        $staleBefore = now()->subMinutes((int) config('newsletter.queue.stale_after_minutes', 30));
        $requeued = $failed = 0;

        Delivery::query()
            ->where('status', DeliveryStatus::Sending->value)
            ->where('claimed_at', '<', $staleBefore)
            ->orderBy('id')
            ->each(function (Delivery $delivery) use (&$requeued, &$failed) {
                if ($this->driver->supportsIdempotency()) {
                    // The provider deduplicates by idempotency key, so retrying cannot double-send.
                    $won = Delivery::query()->whereKey($delivery->id)->where('status', DeliveryStatus::Sending->value)
                        ->update(['status' => DeliveryStatus::Queued->value, 'updated_at' => now()]);

                    if ($won && $this->dispatcher->dispatchDelivery($delivery->id, force: true)) {
                        $requeued++;
                    }

                    return;
                }

                // Without idempotency the message may or may not have left; never risk a duplicate.
                $won = Delivery::query()->whereKey($delivery->id)->where('status', DeliveryStatus::Sending->value)->update([
                    'status' => DeliveryStatus::Failed->value,
                    'failed_at' => now(),
                    'last_error' => 'Interrupted while sending; outcome unknown, not retried to avoid a duplicate.',
                    'updated_at' => now(),
                ]);

                if ($won) {
                    event(new DeliveryFailed($delivery->refresh()));
                    $failed++;
                }
            });

        return ['interrupted_requeued' => $requeued, 'interrupted_failed' => $failed];
    }

    /** Re-queue deliveries whose job was lost (e.g. a cleared jobs table). The claim guard makes duplicates harmless. */
    private function redispatchLostJobs(): int
    {
        $backoff = (array) config('newsletter.queue.backoff', [3600]);
        $threshold = (int) (max(array_map('intval', $backoff ?: [3600])) / 60) + 60;
        $count = 0;

        Delivery::query()
            ->where('status', DeliveryStatus::Queued->value)
            ->where('dispatched_at', '<', now()->subMinutes($threshold))
            ->whereHas('campaign', fn ($q) => $q->where('status', CampaignStatus::Sending->value))
            ->orderBy('id')
            ->limit(1000)
            ->pluck('id')
            ->each(function ($id) use (&$count) {
                if ($this->dispatcher->dispatchDelivery((int) $id, force: true)) {
                    $count++;
                }
            });

        return $count;
    }

    private function completeFinishedCampaigns(): int
    {
        $count = 0;

        Campaign::query()
            ->where('status', CampaignStatus::Sending->value)
            ->whereNotNull('prepared_at')
            ->each(function (Campaign $campaign) use (&$count) {
                if ($this->dispatcher->completeIfFinished($campaign)) {
                    $count++;
                }
            });

        return $count;
    }
}
