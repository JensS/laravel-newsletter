<?php

namespace JensS\Newsletter\Events;

use JensS\Newsletter\Models\Campaign;

/** Sending stopped (reputation guard or manual); queued deliveries wait. */
final class CampaignPaused
{
    public function __construct(
        public readonly Campaign $campaign,
        public readonly string $reason,
    ) {}
}
