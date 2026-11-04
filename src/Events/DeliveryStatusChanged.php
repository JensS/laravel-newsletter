<?php

namespace JensS\Newsletter\Events;

use JensS\Newsletter\Enums\DeliveryStatus;
use JensS\Newsletter\Models\Delivery;

/** Fired for every status transition, including provider webhooks. */
final class DeliveryStatusChanged
{
    public function __construct(
        public readonly Delivery $delivery,
        public readonly DeliveryStatus $from,
        public readonly DeliveryStatus $to,
    ) {}
}
