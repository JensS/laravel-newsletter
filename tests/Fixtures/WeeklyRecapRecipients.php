<?php

namespace JensS\Newsletter\Tests\Fixtures;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use JensS\Newsletter\Contracts\HandlesUnsubscribes;
use JensS\Newsletter\Recipients\EloquentRecipientProvider;

/** Application-owned preference: approved members with weekly_recap = true. */
class WeeklyRecapRecipients extends EloquentRecipientProvider implements HandlesUnsubscribes
{
    protected function query(): Builder
    {
        return User::query()->where('approved', true)->where('weekly_recap', true);
    }

    public function unsubscribed(string $email, ?Model $member): void
    {
        User::query()->where('email', $email)->update(['weekly_recap' => false]);
    }
}
