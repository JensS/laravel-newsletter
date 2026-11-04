<?php

namespace JensS\Newsletter\Events;

use JensS\Newsletter\Models\Campaign;

final class CampaignCancelled
{
    public function __construct(
        public readonly Campaign $campaign,
        public readonly int $cancelledDeliveries,
    ) {}
}
