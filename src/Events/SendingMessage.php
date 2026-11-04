<?php

namespace JensS\Newsletter\Events;

use JensS\Newsletter\Mail\CampaignMessage;
use JensS\Newsletter\Models\Delivery;

/** Last chance to modify the message (headers, tags) before it is handed to the driver. */
final class SendingMessage
{
    public function __construct(
        public readonly CampaignMessage $message,
        public readonly Delivery $delivery,
    ) {}
}
