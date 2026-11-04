<?php

namespace JensS\Newsletter\Events;

use JensS\Newsletter\Models\Subscription;

/** A subscription became active (confirmed or single opt-in). */
final class Subscribed
{
    public function __construct(
        public readonly Subscription $subscription,
    ) {}
}
