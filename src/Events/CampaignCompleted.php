<?php

namespace JensS\Newsletter\Events;

use JensS\Newsletter\Models\Campaign;

/** No deliveries are pending any more. */
final class CampaignCompleted
{
    public function __construct(
        public readonly Campaign $campaign,
    ) {}
}
