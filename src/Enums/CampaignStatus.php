<?php

namespace JensS\Newsletter\Enums;

enum CampaignStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Sending = 'sending';
    /** Sending stopped by the reputation guard or an admin; queued deliveries wait. */
    case Paused = 'paused';
    case Sent = 'sent';
    case Cancelled = 'cancelled';

    /** Content can only be edited before sending starts. */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Scheduled], true);
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
