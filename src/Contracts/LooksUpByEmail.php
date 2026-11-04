<?php

namespace JensS\Newsletter\Contracts;

use JensS\Newsletter\Support\Recipient;

/**
 * Lets the login-free preference page show application-provided audiences.
 */
interface LooksUpByEmail
{
    /** The eligible recipient with this address, or null. */
    public function findByEmail(string $email): ?Recipient;
}
