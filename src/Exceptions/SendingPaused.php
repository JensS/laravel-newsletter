<?php

namespace JensS\Newsletter\Exceptions;

use RuntimeException;

/** Internal signal: put the job back and try again after $retryAfter seconds. */
final class SendingPaused extends RuntimeException
{
    public function __construct(public readonly int $retryAfter, string $reason = '')
    {
        parent::__construct($reason);
    }
}
