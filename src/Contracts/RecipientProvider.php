<?php

namespace JensS\Newsletter\Contracts;

use JensS\Newsletter\Support\Recipient;
use JensS\Newsletter\Support\RecipientPage;

/**
 * Supplies the recipients of an audience. Implementations must only return
 * recipients that are eligible right now.
 */
interface RecipientProvider
{
    /** Return the next page of eligible recipients after the given cursor. */
    public function page(?string $cursor, int $limit): RecipientPage;

    /** Count currently eligible recipients (used for previews; may be approximate). */
    public function count(): int;

    /**
     * Re-resolve one recipient immediately before sending. Return null when the
     * recipient is no longer eligible (deleted, unapproved, opted out ...).
     */
    public function find(string $key): ?Recipient;
}
