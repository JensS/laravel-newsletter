<?php

namespace JensS\Newsletter\Sending;

use Illuminate\Database\QueryException;
use JensS\Newsletter\Contracts\DeliveryDriver;
use JensS\Newsletter\Enums\CampaignStatus;
use JensS\Newsletter\Enums\DeliveryStatus;
use JensS\Newsletter\Events\DeliveryAccepted;
use JensS\Newsletter\Events\DeliveryFailed;
use JensS\Newsletter\Events\DeliverySkipped;
use JensS\Newsletter\Events\DeliveryStatusChanged;
use JensS\Newsletter\Events\SendingMessage;
use JensS\Newsletter\Exceptions\CampaignException;
use JensS\Newsletter\Exceptions\PermanentDeliveryException;
use JensS\Newsletter\Exceptions\SendingPaused;
use JensS\Newsletter\Exceptions\SystemicDeliveryException;
use JensS\Newsletter\Exceptions\TemporaryDeliveryException;
use JensS\Newsletter\Models\Delivery;
use JensS\Newsletter\Support\AudienceRegistry;
use JensS\Newsletter\Support\SuppressionList;
use Throwable;

/**
 * Sends one delivery. Every step is guarded by an atomic status transition so
 * that duplicate jobs, retries and concurrent workers cannot double-send.
 */
final class DeliverySender
{
    public const OUTCOME_SENT = 'sent';

    public const OUTCOME_NOOP = 'noop';

    public const OUTCOME_SKIPPED = 'skipped';

    public const OUTCOME_FAILED = 'failed';

    public function __construct(
        private readonly AudienceRegistry $audiences,
        private readonly MessageFactory $messages,
        private readonly SuppressionList $suppressions,
        private readonly DeliveryDriver $driver,
        private readonly CampaignDispatcher $dispatcher,
        private readonly CircuitBreaker $breaker,
        private readonly ReputationGuard $guard,
    ) {}

    /**
     * @throws TemporaryDeliveryException when the job should be retried later
     * @throws SendingPaused when the job should be released without counting as a failure
     */
    public function send(int $deliveryId): string
    {
        $delivery = Delivery::query()->with('campaign')->find($deliveryId);

        if ($delivery === null) {
            return self::OUTCOME_NOOP;
        }

        $campaign = $delivery->campaign;

        if ($campaign->status === CampaignStatus::Cancelled) {
            $this->transition($delivery, [DeliveryStatus::Queued], DeliveryStatus::Cancelled);

            return self::OUTCOME_NOOP;
        }

        // Paused campaigns keep their deliveries queued; resuming re-dispatches them.
        if ($campaign->status !== CampaignStatus::Sending) {
            return self::OUTCOME_NOOP;
        }

        if ($wait = $this->breaker->remaining()) {
            throw new SendingPaused($wait, (string) $this->breaker->reason());
        }

        if (! $this->claim($delivery)) {
            return self::OUTCOME_NOOP;
        }

        try {
            return $this->deliver($delivery);
        } finally {
            $this->dispatcher->completeIfFinished($campaign);
        }
    }

    /** Mark a delivery failed after its retries are exhausted. */
    public function giveUp(int $deliveryId, ?Throwable $e): void
    {
        $delivery = Delivery::query()->with('campaign')->find($deliveryId);

        if ($delivery === null) {
            return;
        }

        if ($this->transition($delivery, DeliveryStatus::pending(), DeliveryStatus::Failed, [
            'failed_at' => now(),
            'last_error' => $e ? mb_substr($e->getMessage(), 0, 2000) : 'Retries exhausted',
        ])) {
            event(new DeliveryFailed($delivery->refresh(), $e));
        }

        $this->dispatcher->completeIfFinished($delivery->campaign);
    }

    /**
     * Claim a queued delivery, or reclaim an interrupted one when the driver
     * deduplicates via idempotency keys.
     */
    private function claim(Delivery $delivery): bool
    {
        $claimed = Delivery::query()
            ->whereKey($delivery->id)
            ->where('status', DeliveryStatus::Queued->value)
            ->update([
                'status' => DeliveryStatus::Sending->value,
                'claimed_at' => now(),
                'attempts' => $delivery->attempts + 1,
                'updated_at' => now(),
            ]);

        if ($claimed) {
            $delivery->refresh();

            return true;
        }

        return false;
    }

    private function deliver(Delivery $delivery): string
    {
        $campaign = $delivery->campaign;
        $snapshot = (array) $campaign->snapshot;
        $audience = $this->audiences->get($snapshot['audience'] ?? $campaign->audience);

        // Eligibility is re-checked right before sending: unsubscribed, deleted or
        // no longer approved recipients are skipped even though they were queued.
        $recipient = $audience->recipients()->find($delivery->recipient_key);

        if ($recipient === null) {
            return $this->skip($delivery, 'Recipient is no longer eligible');
        }

        if ($this->suppressions->contains($recipient->email)) {
            $this->transition($delivery, [DeliveryStatus::Sending], DeliveryStatus::Suppressed);

            return self::OUTCOME_SKIPPED;
        }

        if ($recipient->email !== $delivery->email) {
            try {
                $delivery->forceFill(['email' => $recipient->email, 'name' => $recipient->name])->save();
            } catch (QueryException) {
                return $this->skip($delivery, 'Address already received this campaign');
            }
        }

        try {
            $message = $this->messages->make($campaign, $snapshot, $recipient, $delivery->uuid);
            event(new SendingMessage($message, $delivery));
            $result = $this->driver->send($message, $delivery);
        } catch (PermanentDeliveryException|CampaignException $e) {
            return $this->fail($delivery, $e);
        } catch (SystemicDeliveryException $e) {
            // The server refuses us, not this recipient: pause everything, keep the delivery queued.
            $this->requeue($delivery, $e);
            $seconds = $this->breaker->trip($e->getMessage());

            throw new SendingPaused($seconds, $e->getMessage());
        } catch (Throwable $e) {
            // Temporary (or unknown) failure: put the delivery back and let the queue retry with backoff.
            $this->requeue($delivery, $e);

            throw $e instanceof TemporaryDeliveryException
                ? $e
                : new TemporaryDeliveryException($e->getMessage(), (int) $e->getCode(), $e);
        }

        $this->transition($delivery, [DeliveryStatus::Sending], DeliveryStatus::Accepted, [
            'accepted_at' => now(),
            'provider' => $this->driver->name(),
            'message_id' => $message->messageIdentifier,
            'provider_message_id' => $result->providerMessageId,
            'last_error' => null,
        ]);

        event(new DeliveryAccepted($delivery->refresh()));
        $this->breaker->recordSuccess();

        return self::OUTCOME_SENT;
    }

    private function requeue(Delivery $delivery, Throwable $e): void
    {
        Delivery::query()->whereKey($delivery->id)->where('status', DeliveryStatus::Sending->value)->update([
            'status' => DeliveryStatus::Queued->value,
            'last_error' => mb_substr($e->getMessage(), 0, 2000),
            'updated_at' => now(),
        ]);
    }

    private function skip(Delivery $delivery, string $reason): string
    {
        $this->transition($delivery, [DeliveryStatus::Sending], DeliveryStatus::Skipped, ['last_error' => $reason]);
        event(new DeliverySkipped($delivery->refresh(), $reason));

        return self::OUTCOME_SKIPPED;
    }

    private function fail(Delivery $delivery, Throwable $e): string
    {
        $this->transition($delivery, [DeliveryStatus::Sending], DeliveryStatus::Failed, [
            'failed_at' => now(),
            'last_error' => mb_substr($e->getMessage(), 0, 2000),
        ]);
        event(new DeliveryFailed($delivery->refresh(), $e));
        $this->guard->evaluate($delivery->campaign);

        return self::OUTCOME_FAILED;
    }

    /**
     * @param  list<DeliveryStatus>  $from
     * @param  array<string, mixed>  $attributes
     */
    private function transition(Delivery $delivery, array $from, DeliveryStatus $to, array $attributes = []): bool
    {
        $previous = $delivery->status;

        $updated = Delivery::query()
            ->whereKey($delivery->id)
            ->whereIn('status', array_map(fn (DeliveryStatus $s) => $s->value, $from))
            ->update(['status' => $to->value, 'updated_at' => now()] + $attributes);

        if ($updated) {
            event(new DeliveryStatusChanged($delivery->refresh(), $previous, $to));
        }

        return (bool) $updated;
    }
}
