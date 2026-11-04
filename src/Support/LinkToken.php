<?php

namespace JensS\Newsletter\Support;

/**
 * Compact HMAC-signed tokens for login-free unsubscribe and preference links.
 * Tokens do not expire (unsubscribe links must keep working); rotating
 * APP_KEY invalidates them.
 */
final class LinkToken
{
    public const UNSUBSCRIBE = 'u';

    public const PREFERENCES = 'p';

    /** @param  array<string, scalar|null>  $payload */
    public static function make(string $type, array $payload): string
    {
        $json = json_encode(['t' => $type] + $payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $body = self::b64($json);

        return $body.'.'.self::b64(self::sign($body));
    }

    /** @return array<string, scalar|null>|null */
    public static function parse(string $token, string $expectedType): ?array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 2) {
            return null;
        }

        [$body, $signature] = $parts;

        if (! hash_equals(self::b64(self::sign($body)), $signature)) {
            return null;
        }

        $payload = json_decode((string) self::unb64($body), true);

        if (! is_array($payload) || ($payload['t'] ?? null) !== $expectedType) {
            return null;
        }

        return $payload;
    }

    public static function unsubscribe(string $audience, string $email, ?string $deliveryUuid = null): string
    {
        return self::make(self::UNSUBSCRIBE, array_filter([
            'a' => $audience,
            'e' => Email::normalize($email),
            'd' => $deliveryUuid,
        ], fn ($v) => $v !== null));
    }

    public static function preferences(string $email): string
    {
        return self::make(self::PREFERENCES, ['e' => Email::normalize($email)]);
    }

    private static function sign(string $body): string
    {
        $key = hash('sha256', 'jenss/laravel-newsletter|'.config('app.key'), true);

        return substr(hash_hmac('sha256', $body, $key, true), 0, 20);
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function unb64(string $value): string|false
    {
        return base64_decode(strtr($value, '-_', '+/'), true);
    }
}
