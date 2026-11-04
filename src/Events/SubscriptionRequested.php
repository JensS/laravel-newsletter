<?php

namespace JensS\Newsletter\Events;

use JensS\Newsletter\Models\Subscription;

/** A double opt-in subscription was created and the confirmation mail sent. */
final class SubscriptionRequested
{
    public function __construct(
        public readonly Subscription $subscription,
    ) {}
}
