<?php

namespace JensS\Newsletter\Events;

use JensS\Newsletter\Models\Delivery;

/** The mailer/provider accepted the message (not a delivery confirmation). */
final class DeliveryAccepted
{
    public function __construct(
        public readonly Delivery $delivery,
    ) {}
}
