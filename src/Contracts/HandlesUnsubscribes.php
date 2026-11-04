<?php

namespace JensS\Newsletter\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Implement on an application RecipientProvider to update your own preference
 * (e.g. set weekly_recap = false) when someone unsubscribes via a package link.
 */
interface HandlesUnsubscribes
{
    public function unsubscribed(string $email, ?Model $member): void;
}
