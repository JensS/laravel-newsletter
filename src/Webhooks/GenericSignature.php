<?php

namespace JensS\Newsletter\Webhooks;

/**
 * Signature for the provider-neutral webhook:
 *   X-Newsletter-Signature: t=<unix time>,v1=<hex HMAC-SHA256(secret, "<t>.<raw body>")>
 */
final class GenericSignature
{
    public static function verify(string $secret, string $header, string $body, int $tolerance = 300, ?int $now = null): bool
    {
        if ($secret === '' || $header === '') {
            return false;
        }

        $parts = [];
        foreach (explode(',', $header) as $pair) {
            [$k, $v] = array_pad(explode('=', trim($pair), 2), 2, '');
            $parts[$k][] = $v;
        }

        $timestamp = $parts['t'][0] ?? '';

        if (! ctype_digit($timestamp) || abs(($now ?? time()) - (int) $timestamp) > $tolerance) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

        foreach ($parts['v1'] ?? [] as $candidate) {
            if (hash_equals($expected, $candidate)) {
                return true;
            }
        }

        return false;
    }

    public static function sign(string $secret, string $body, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }
}
