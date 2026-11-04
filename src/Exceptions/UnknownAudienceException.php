<?php

namespace JensS\Newsletter\Exceptions;

use InvalidArgumentException;

class UnknownAudienceException extends InvalidArgumentException
{
    public static function make(string $key): self
    {
        return new self("Newsletter audience [{$key}] is not configured.");
    }
}
