<?php

namespace JensS\Newsletter\Events;

use JensS\Newsletter\Models\Delivery;
use Throwable;

final class DeliveryFailed
{
    public function __construct(
        public readonly Delivery $delivery,
        public readonly ?Throwable $exception = null,
    ) {}
}
