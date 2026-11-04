<?php

namespace JensS\Newsletter\Support;

final class Email
{
    public static function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public static function isValid(string $email): bool
    {
        return filter_var(self::normalize($email), FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function domain(string $email): ?string
    {
        $at = strrpos($email, '@');

        return $at === false ? null : substr($email, $at + 1);
    }

    /** Irreversible placeholder used when anonymising records. */
    public static function anonymize(string $email): string
    {
        return 'anon-'.substr(hash('sha256', self::normalize($email).config('app.key')), 0, 24).'@invalid';
    }
}
