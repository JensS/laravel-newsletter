<?php

namespace JensS\Newsletter\Events;

use JensS\Newsletter\Models\Campaign;

/** Content was frozen and recipient preparation started. */
final class CampaignStarted
{
    public function __construct(
        public readonly Campaign $campaign,
    ) {}
}
