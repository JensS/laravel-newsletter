<?php

namespace JensS\Newsletter\Support;

final class SendResult
{
    public function __construct(
        public readonly ?string $providerMessageId = null,
    ) {}
}
