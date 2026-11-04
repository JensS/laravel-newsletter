<?php

namespace JensS\Newsletter\Mailbox;

final class MailboxEvent
{
    public const BOUNCE = 'bounce';

    public const COMPLAINT = 'complaint';

    public const UNSUBSCRIBE = 'unsubscribe';

    /** Recognised but irrelevant (auto-replies, delay notices for delivered mail ...). */
    public const IGNORE = 'ignore';

    /** Not something the package understands; left untouched in the mailbox. */
    public const UNKNOWN = 'unknown';

    public function __construct(
        public readonly string $type,
        public readonly ?string $email = null,
        public readonly bool $permanent = true,
        public readonly ?string $status = null,
        public readonly ?string $details = null,
        public readonly ?string $deliveryUuid = null,
        public readonly ?string $originalMessageId = null,
        public readonly ?string $token = null,
    ) {}

    public function handled(): bool
    {
        return $this->type !== self::UNKNOWN;
    }
}
