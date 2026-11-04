<?php

namespace JensS\Newsletter\Support;

final class RenderedBlock
{
    public function __construct(
        public readonly string $html,
        public readonly ?string $text = null,
    ) {}
}
