<?php

namespace JensS\Newsletter\Events;

use JensS\Newsletter\Models\Delivery;

/** The recipient was not eligible when the message was about to be sent. */
final class DeliverySkipped
{
    public function __construct(
        public readonly Delivery $delivery,
        public readonly string $reason,
    ) {}
}
