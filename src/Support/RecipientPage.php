<?php

namespace JensS\Newsletter\Support;

final class RecipientPage
{
    /**
     * @param  list<Recipient>  $recipients
     * @param  string|null  $nextCursor  null when there are no more pages
     */
    public function __construct(
        public readonly array $recipients,
        public readonly ?string $nextCursor,
    ) {}
}
