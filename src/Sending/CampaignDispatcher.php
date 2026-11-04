<?php

namespace JensS\Newsletter\Sending;

use DateTimeInterface;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use JensS\Newsletter\Enums\CampaignStatus;
use JensS\Newsletter\Enums\DeliveryStatus;
use JensS\Newsletter\Events\CampaignCancelled;
use JensS\Newsletter\Events\CampaignCompleted;
use JensS\Newsletter\Events\CampaignPaused;
use JensS\Newsletter\Events\CampaignResumed;
use JensS\Newsletter\Events\CampaignPrepared;
use JensS\Newsletter\Events\CampaignScheduled;
use JensS\Newsletter\Events\CampaignStarted;
use JensS\Newsletter\Exceptions\CampaignException;
use JensS\Newsletter\Jobs\PrepareCampaignDeliveries;
use JensS\Newsletter\Jobs\SendCampaignDelivery;
use JensS\Newsletter\Models\Campaign;
use JensS\Newsletter\Models\Delivery;
use JensS\Newsletter\Support\AudienceRegistry;
use JensS\Newsletter\Support\CampaignRenderer;
use JensS\Newsletter\Support\ContentInspector;
use JensS\Newsletter\Support\Email;
use JensS\Newsletter\Support\Recipient;
use JensS\Newsletter\Support\SuppressionList;

final class CampaignDispatcher
{
    public function __construct(
        private readonly AudienceRegistry $audiences,
        private readonly CampaignRenderer $renderer,
        private readonly MessageFactory $messages,
        private readonly SuppressionList $suppressions,
        private readonly MailFactory $mail,
    ) {}

    public function schedule(Campaign $campaign, DateTimeInterface $at): Campaign
    {
        if (! $campaign->isEditable()) {
            throw new CampaignException('Only draft or scheduled campaigns can be scheduled.');
        }

        $this->validate($campaign);

        $campaign->forceFill(['status' => CampaignStatus::Scheduled, 'scheduled_at' => Carbon::instance($at)])->save();

        event(new CampaignScheduled($campaign));

        return $campaign;
    }

    public function unschedule(Campaign $campaign): Campaign
    {
        if ($campaign->status === CampaignStatus::Scheduled) {
            $campaign->forceFill(['status' => CampaignStatus::Draft, 'scheduled_at' => null])->save();
        }

        return $campaign;
    }

    /** Start every scheduled campaign that is due. Returns the number started. */
    public function dispatchDue(): int
    {
        $started = 0;

        Campaign::query()
            ->where('status', CampaignStatus::Scheduled->value)
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->get()
            ->each(function (Campaign $campaign) use (&$started) {
                try {
                    $this->start($campaign);
                    $started++;
                } catch (CampaignException $e) {
                    report($e);
                }
            });

        return $started;
    }

    /**
     * Freeze the content and begin preparing deliveries. Safe against double
     * clicks and concurrent schedulers: only one caller can win the transition.
     */
    public function start(Campaign $campaign): Campaign
    {
        if (! $campaign->isEditable()) {
            throw new CampaignException("Campaign [{$campaign->id}] has already been started.");
        }

        $snapshot = $this->validate($campaign);

        $won = Campaign::query()
            ->whereKey($campaign->getKey())
            ->whereIn('status', [CampaignStatus::Draft->value, CampaignStatus::Scheduled->value])
            ->update([
                'status' => CampaignStatus::Sending->value,
                'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                'started_at' => now(),
                'prepare_cursor' => null,
                'prepared_at' => null,
                'prepare_heartbeat_at' => now(),
                'recipient_count' => 0,
                'updated_at' => now(),
            ]);

        $campaign->refresh();

        if (! $won) {
            throw new CampaignException("Campaign [{$campaign->id}] has already been started.");
        }

        event(new CampaignStarted($campaign));

        PrepareCampaignDeliveries::dispatch($campaign->id);

        return $campaign;
    }

    /**
     * Create delivery records for one page of recipients and queue them.
     * Idempotent: re-running a page never creates or queues duplicates.
     *
     * @return bool true when more pages remain
     */
    public function prepareNextPage(Campaign $campaign): bool
    {
        if ($campaign->status !== CampaignStatus::Sending || $campaign->prepared_at !== null) {
            return false;
        }

        $audience = $this->audiences->get($campaign->snapshot['audience'] ?? $campaign->audience);
        $size = max(1, (int) config('newsletter.queue.chunk_size', 200));
        $page = $audience->recipients()->page($campaign->prepare_cursor, $size);

        $this->insertDeliveries($campaign, $page->recipients);
        $this->dispatchUndispatched($campaign, array_map(fn (Recipient $r) => $r->key, $page->recipients));

        $done = $page->nextCursor === null;

        Campaign::query()->whereKey($campaign->id)->update(array_filter([
            'prepare_cursor' => $page->nextCursor ?? $campaign->prepare_cursor,
            'prepare_heartbeat_at' => now(),
            'prepared_at' => $done ? now() : null,
            'recipient_count' => $campaign->deliveries()->count(),
            'updated_at' => now(),
        ], fn ($v) => $v !== null));

        $campaign->refresh();

        if ($done) {
            event(new CampaignPrepared($campaign));
            $this->completeIfFinished($campaign);
        }

        return ! $done;
    }

    /** Mark the campaign as sent once nothing is pending. */
    public function completeIfFinished(Campaign $campaign): bool
    {
        $campaign->refresh();

        if ($campaign->status !== CampaignStatus::Sending || $campaign->prepared_at === null) {
            return false;
        }

        if ($campaign->pendingDeliveriesCount() > 0) {
            return false;
        }

        $won = Campaign::query()
            ->whereKey($campaign->id)
            ->where('status', CampaignStatus::Sending->value)
            ->update(['status' => CampaignStatus::Sent->value, 'finished_at' => now(), 'updated_at' => now()]);

        if ($won) {
            event(new CampaignCompleted($campaign->refresh()));
        }

        return (bool) $won;
    }

    /**
     * Cancel a scheduled or sending campaign. Deliveries already handed to the
     * provider are unaffected; everything still queued is cancelled.
     */
    public function cancel(Campaign $campaign): int
    {
        if (in_array($campaign->status, [CampaignStatus::Sent, CampaignStatus::Cancelled], true)) {
            throw new CampaignException('This campaign can no longer be cancelled.');
        }

        Campaign::query()->whereKey($campaign->id)->update([
            'status' => CampaignStatus::Cancelled->value,
            'cancelled_at' => now(),
            'updated_at' => now(),
        ]);

        $cancelled = Delivery::query()
            ->where('campaign_id', $campaign->id)
            ->where('status', DeliveryStatus::Queued->value)
            ->update(['status' => DeliveryStatus::Cancelled->value, 'updated_at' => now()]);

        event(new CampaignCancelled($campaign->refresh(), $cancelled));

        return $cancelled;
    }

    /** Stop sending; queued deliveries wait until resume(). */
    public function pause(Campaign $campaign, string $reason = 'Paused manually'): bool
    {
        $won = Campaign::query()
            ->whereKey($campaign->id)
            ->where('status', CampaignStatus::Sending->value)
            ->update([
                'status' => CampaignStatus::Paused->value,
                'paused_at' => now(),
                'pause_reason' => mb_substr($reason, 0, 255),
                'updated_at' => now(),
            ]);

        if ($won) {
            event(new CampaignPaused($campaign->refresh(), $reason));
        }

        return (bool) $won;
    }

    /** Continue a paused campaign: re-queues everything that is still waiting. */
    public function resume(Campaign $campaign): int
    {
        $won = Campaign::query()
            ->whereKey($campaign->id)
            ->where('status', CampaignStatus::Paused->value)
            ->update([
                'status' => CampaignStatus::Sending->value,
                'paused_at' => null,
                'pause_reason' => null,
                'prepare_heartbeat_at' => now(),
                'updated_at' => now(),
            ]);

        if (! $won) {
            throw new CampaignException('Only paused campaigns can be resumed.');
        }

        $campaign->refresh();

        if ($campaign->prepared_at === null) {
            PrepareCampaignDeliveries::dispatch($campaign->id);
        }

        $requeued = 0;
        Delivery::query()
            ->where('campaign_id', $campaign->id)
            ->where('status', DeliveryStatus::Queued->value)
            ->orderBy('id')
            ->pluck('id')
            ->each(function ($id) use (&$requeued) {
                if ($this->dispatchDelivery((int) $id, force: true)) {
                    $requeued++;
                }
            });

        event(new CampaignResumed($campaign));
        $this->completeIfFinished($campaign);

        return $requeued;
    }

    /**
     * Send the current (unfrozen) content to test addresses, synchronously.
     *
     * @param  list<string>  $emails
     */
    public function sendTest(Campaign $campaign, array $emails, ?string $name = null): int
    {
        $snapshot = $this->renderer->snapshot($campaign);
        $sent = 0;

        foreach ($emails as $email) {
            if (! Email::isValid($email)) {
                continue;
            }

            $recipient = $this->renderer->sampleRecipient($email, $name);
            $message = $this->messages->make($campaign, $snapshot, $recipient, 'test-'.Str::uuid(), test: true);

            $this->mail->mailer(config('newsletter.mail.mailer') ?: null)->send($message);
            $sent++;
        }

        return $sent;
    }

    /** Render the current content for the preview pane. */
    public function preview(Campaign $campaign, ?Recipient $recipient = null): string
    {
        $snapshot = $campaign->snapshot ?? $this->renderer->snapshot($campaign);

        return $this->renderer->render($snapshot, $recipient ?? $this->renderer->sampleRecipient())->html;
    }

    /**
     * Deliverability warnings for the current content (never blocking).
     *
     * @return list<string>
     */
    public function inspect(Campaign $campaign): array
    {
        $snapshot = $campaign->snapshot ?? $this->renderer->snapshot($campaign);

        try {
            $rendered = $this->renderer->render($snapshot, $this->renderer->sampleRecipient())->html;
        } catch (\Throwable) {
            $rendered = null;
        }

        return ContentInspector::inspect($snapshot, $rendered);
    }

    public function estimateRecipients(Campaign $campaign): int
    {
        return $this->audiences->get($campaign->audience)->recipients()->count();
    }

    /**
     * Ensure the campaign can be rendered and has an audience.
     *
     * @return array<string, mixed>
     */
    private function validate(Campaign $campaign): array
    {
        if (! $this->audiences->has($campaign->audience)) {
            throw new CampaignException("Unknown audience [{$campaign->audience}].");
        }

        if (trim((string) $campaign->subject) === '') {
            throw new CampaignException('A subject is required.');
        }

        if (trim(strip_tags((string) $campaign->content_html)) === '' && empty($campaign->blocks)) {
            throw new CampaignException('The campaign has no content.');
        }

        $snapshot = $this->renderer->snapshot($campaign);
        $this->messages->make($campaign, $snapshot, $this->renderer->sampleRecipient(), (string) Str::uuid());

        return $snapshot;
    }

    /** @param  list<Recipient>  $recipients */
    private function insertDeliveries(Campaign $campaign, array $recipients): void
    {
        if ($recipients === []) {
            return;
        }

        $suppressed = $this->suppressions->filter(array_map(fn (Recipient $r) => $r->email, $recipients));
        $now = now();
        $rows = [];

        foreach ($recipients as $recipient) {
            $rows[] = [
                'uuid' => (string) Str::uuid(),
                'campaign_id' => $campaign->id,
                'recipient_key' => $recipient->key,
                'subscription_id' => $recipient->subscriptionId,
                'subscribable_type' => $recipient->subscribable?->getMorphClass(),
                'subscribable_id' => $recipient->subscribable?->getKey(),
                'email' => $recipient->email,
                'name' => $recipient->name,
                'locale' => $recipient->locale,
                'personalization' => $recipient->attributes === [] ? null : json_encode($recipient->attributes),
                'status' => isset($suppressed[$recipient->email]) ? DeliveryStatus::Suppressed->value : DeliveryStatus::Queued->value,
                'attempts' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // Unique (campaign, recipient) and (campaign, email) make this idempotent.
        Delivery::query()->insertOrIgnore($rows);
    }

    /** @param  list<string>  $keys */
    private function dispatchUndispatched(Campaign $campaign, array $keys): void
    {
        if ($keys === []) {
            return;
        }

        $ids = Delivery::query()
            ->where('campaign_id', $campaign->id)
            ->whereIn('recipient_key', $keys)
            ->where('status', DeliveryStatus::Queued->value)
            ->whereNull('dispatched_at')
            ->pluck('id');

        foreach ($ids as $id) {
            $this->dispatchDelivery((int) $id);
        }
    }

    /** Queue a send job for one delivery unless one was queued already. */
    public function dispatchDelivery(int $deliveryId, bool $force = false): bool
    {
        $query = Delivery::query()->whereKey($deliveryId)->where('status', DeliveryStatus::Queued->value);

        if (! $force) {
            $query->whereNull('dispatched_at');
        }

        if ($query->update(['dispatched_at' => now()]) === 0) {
            return false;
        }

        SendCampaignDelivery::dispatch($deliveryId);

        return true;
    }
}
