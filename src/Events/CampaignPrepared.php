<?php

namespace JensS\Newsletter\Events;

use JensS\Newsletter\Models\Campaign;

/** All delivery records were created and queued. */
final class CampaignPrepared
{
    public function __construct(
        public readonly Campaign $campaign,
    ) {}
}
