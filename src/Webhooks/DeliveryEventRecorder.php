<?php

namespace JensS\Newsletter\Webhooks;

use JensS\Newsletter\Enums\DeliveryStatus;
use JensS\Newsletter\Events\DeliveryStatusChanged;
use JensS\Newsletter\Models\Delivery;
use JensS\Newsletter\Models\Suppression;
use JensS\Newsletter\Models\WebhookEvent;
use JensS\Newsletter\Support\Email;
use JensS\Newsletter\Support\SuppressionList;

/**
 * Applies normalised provider events to delivery records. Status only moves
 * "forward" (e.g. a late "delivered" never overwrites "bounced").
 */
final class DeliveryEventRecorder
{
    public const DELIVERED = 'delivered';

    public const BOUNCED = 'bounced';

    public const COMPLAINED = 'complained';

    public const FAILED = 'failed';

    public const ACCEPTED = 'accepted';

    public const SUPPRESSED = 'suppressed';

    private const RANK = [
        'queued' => 0, 'sending' => 1, 'accepted' => 2, 'delivered' => 3,
        'failed' => 3, 'suppressed' => 3, 'bounced' => 4, 'complained' => 5,
    ];

    public function __construct(private readonly SuppressionList $suppressions) {}

    /**
     * Returns false when the event was already processed (replay or provider retry).
     */
    public function claimEvent(string $provider, string $eventId, string $type, array $payload): bool
    {
        $inserted = WebhookEvent::query()->insertOrIgnore([
            'provider' => $provider,
            'event_id' => $eventId,
            'type' => mb_substr($type, 0, 60),
            'payload' => json_encode($payload),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $inserted > 0;
    }

    public function markProcessed(string $provider, string $eventId): void
    {
        WebhookEvent::query()->where('provider', $provider)->where('event_id', $eventId)->update(['processed_at' => now()]);
    }

    /**
     * @param  string  $type  one of the class constants
     * @param  bool|null  $permanent  for bounces: permanent (hard) or transient (soft)
     */
    public function record(?Delivery $delivery, ?string $email, string $type, ?bool $permanent = null, ?string $details = null, string $source = 'webhook'): void
    {
        $email = $email ? Email::normalize($email) : $delivery?->email;

        if ($type === self::BOUNCED && $permanent === false) {
            $this->recordSoftBounce($delivery, $email, $details, $source);

            return;
        }

        if ($delivery !== null) {
            $this->advance($delivery, $type, $details);
        }

        if ($email === null) {
            return;
        }

        if ($type === self::BOUNCED && config('newsletter.suppression.on_permanent_bounce', true)) {
            $this->suppressions->add($email, Suppression::REASON_BOUNCE, $source, $details);
        }

        if ($type === self::COMPLAINED && config('newsletter.suppression.on_complaint', true)) {
            $this->suppressions->add($email, Suppression::REASON_COMPLAINT, $source, $details);
        }

        if ($type === self::SUPPRESSED) {
            $this->suppressions->add($email, Suppression::REASON_BOUNCE, $source, $details ?? 'Suppressed by provider');
        }
    }

    public function findDelivery(?string $providerMessageId = null, ?string $deliveryUuid = null, ?string $messageId = null): ?Delivery
    {
        if ($deliveryUuid) {
            return Delivery::query()->where('uuid', $deliveryUuid)->first();
        }

        if ($providerMessageId) {
            $found = Delivery::query()->where('provider_message_id', $providerMessageId)->first();

            if ($found) {
                return $found;
            }
        }

        if ($messageId) {
            return Delivery::query()->where('message_id', trim($messageId, '<> '))->first();
        }

        return null;
    }

    private function advance(Delivery $delivery, string $type, ?string $details): void
    {
        $to = match ($type) {
            self::ACCEPTED => DeliveryStatus::Accepted,
            self::DELIVERED => DeliveryStatus::Delivered,
            self::BOUNCED => DeliveryStatus::Bounced,
            self::COMPLAINED => DeliveryStatus::Complained,
            self::FAILED => DeliveryStatus::Failed,
            self::SUPPRESSED => DeliveryStatus::Suppressed,
            default => null,
        };

        if ($to === null) {
            return;
        }

        $from = $delivery->status;

        if ((self::RANK[$to->value] ?? 0) <= (self::RANK[$from->value] ?? 0)) {
            return;
        }

        $timestamps = match ($to) {
            DeliveryStatus::Accepted => ['accepted_at' => now()],
            DeliveryStatus::Delivered => ['delivered_at' => now()],
            DeliveryStatus::Bounced => ['bounced_at' => now(), 'bounce_type' => 'permanent'],
            DeliveryStatus::Complained => ['complained_at' => now()],
            DeliveryStatus::Failed => ['failed_at' => now()],
            default => [],
        };

        $updated = Delivery::query()
            ->whereKey($delivery->id)
            ->where('status', $from->value)
            ->update(['status' => $to->value, 'updated_at' => now()] + $timestamps + ($details ? ['last_error' => mb_substr($details, 0, 2000)] : []));

        if ($updated) {
            event(new DeliveryStatusChanged($delivery->refresh(), $from, $to));
        }
    }

    private function recordSoftBounce(?Delivery $delivery, ?string $email, ?string $details, string $source): void
    {
        if ($delivery !== null) {
            Delivery::query()->whereKey($delivery->id)->update([
                'bounce_type' => 'transient',
                'last_error' => $details ? mb_substr($details, 0, 2000) : 'Transient bounce',
                'updated_at' => now(),
            ]);
        }

        $threshold = (int) config('newsletter.suppression.soft_bounce_threshold', 3);

        if ($email === null || $threshold <= 0) {
            return;
        }

        $recent = Delivery::query()
            ->where('email', $email)
            ->where('bounce_type', 'transient')
            ->where('updated_at', '>=', now()->subDays(90))
            ->count();

        if ($recent >= $threshold) {
            $this->suppressions->add($email, Suppression::REASON_BOUNCE, $source, "{$recent} transient bounces");
        }
    }
}
