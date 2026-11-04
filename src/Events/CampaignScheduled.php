<?php

namespace JensS\Newsletter\Events;

use JensS\Newsletter\Models\Campaign;

final class CampaignScheduled
{
    public function __construct(
        public readonly Campaign $campaign,
    ) {}
}
