<?php

namespace JensS\Newsletter\Contracts;

use Illuminate\Database\Eloquent\Model;
use JensS\Newsletter\Support\Recipient;

/**
 * Turns a linked member into a recipient. Return null if the member must not
 * receive newsletters right now.
 */
interface MemberResolver
{
    public function resolve(Model $member, string $key): ?Recipient;
}
