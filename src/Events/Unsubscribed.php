<?php

namespace JensS\Newsletter\Events;

use JensS\Newsletter\Models\Subscription;

/**
 * A recipient withdrew from an audience. For application-provided audiences,
 * listen to this to update your own preference column.
 */
final class Unsubscribed
{
    public function __construct(
        public readonly Subscription $subscription,
        public readonly string $source,
    ) {}
}
