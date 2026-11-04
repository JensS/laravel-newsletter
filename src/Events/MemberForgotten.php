<?php

namespace JensS\Newsletter\Events;

use Illuminate\Database\Eloquent\Model;

/** Newsletter data for a member/email was erased. */
final class MemberForgotten
{
    public function __construct(
        public readonly ?Model $member,
        public readonly string $email,
    ) {}
}
