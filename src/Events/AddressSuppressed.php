<?php

namespace JensS\Newsletter\Events;

use JensS\Newsletter\Models\Suppression;

/** An address was added to the global suppression list. */
final class AddressSuppressed
{
    public function __construct(
        public readonly Suppression $suppression,
    ) {}
}
