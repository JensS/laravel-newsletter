<?php

namespace JensS\Newsletter\Enums;

enum DeliveryStatus: string
{
    /** Delivery record exists and a send job has been queued. */
    case Queued = 'queued';
    /** A worker has claimed the delivery and is handing it to the mailer. */
    case Sending = 'sending';
    /** The mail server / provider accepted the message. This is NOT confirmed delivery. */
    case Accepted = 'accepted';
    /** The provider reported delivery to the recipient's mail server. */
    case Delivered = 'delivered';
    /** Sending failed permanently or retries were exhausted. */
    case Failed = 'failed';
    /** Permanent bounce reported by the provider. */
    case Bounced = 'bounced';
    /** Recipient marked the message as spam. */
    case Complained = 'complained';
    /** Address is on the suppression list. */
    case Suppressed = 'suppressed';
    /** Recipient was no longer eligible at send time (unsubscribed, deleted, ...). */
    case Skipped = 'skipped';
    /** Campaign was cancelled before this delivery was sent. */
    case Cancelled = 'cancelled';

    /** @return list<self> */
    public static function pending(): array
    {
        return [self::Queued, self::Sending];
    }

    /** @return list<string> */
    public static function pendingValues(): array
    {
        return array_map(fn (self $s) => $s->value, self::pending());
    }

    public function isFinal(): bool
    {
        return ! in_array($this, self::pending(), true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Accepted => 'Accepted by provider',
            default => ucfirst($this->value),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Queued, self::Sending => 'gray',
            self::Accepted => 'info',
            self::Delivered => 'success',
            self::Failed, self::Bounced, self::Complained => 'danger',
            self::Suppressed, self::Skipped, self::Cancelled => 'warning',
        };
    }
}
