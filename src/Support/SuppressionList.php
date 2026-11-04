<?php

namespace JensS\Newsletter\Support;

use JensS\Newsletter\Events\AddressSuppressed;
use JensS\Newsletter\Models\Suppression;

final class SuppressionList
{
    public function add(string $email, string $reason, ?string $source = null, ?string $details = null): Suppression
    {
        $suppression = Suppression::query()->firstOrCreate(
            ['email' => Email::normalize($email)],
            ['reason' => $reason, 'source' => $source, 'details' => $details],
        );

        if ($suppression->wasRecentlyCreated) {
            event(new AddressSuppressed($suppression));
        }

        return $suppression;
    }

    public function remove(string $email): bool
    {
        return Suppression::query()->where('email', Email::normalize($email))->delete() > 0;
    }

    public function contains(string $email): bool
    {
        return Suppression::isSuppressed($email);
    }

    /**
     * @param  list<string>  $emails
     * @return array<string, true>
     */
    public function filter(array $emails): array
    {
        if ($emails === []) {
            return [];
        }

        return Suppression::query()
            ->whereIn('email', array_map(Email::normalize(...), $emails))
            ->pluck('email')
            ->mapWithKeys(fn ($e) => [$e => true])
            ->all();
    }
}
