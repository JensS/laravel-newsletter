<?php

namespace JensS\Newsletter\Support;

final class RenderedMessage
{
    public function __construct(
        public readonly string $subject,
        public readonly string $html,
        public readonly string $text,
        public readonly ?string $unsubscribeUrl,
        public readonly ?string $oneClickUrl,
        public readonly ?string $preferencesUrl,
    ) {}
}
