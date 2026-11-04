<?php

namespace JensS\Newsletter\Webhooks;

/**
 * Verifies Svix-style signatures as used by Resend webhooks:
 * base64(HMAC-SHA256(secret, "{id}.{timestamp}.{body}")), secret "whsec_<base64>".
 */
final class SvixSignature
{
    public static function verify(string $secret, string $id, string $timestamp, string $signatureHeader, string $body, int $tolerance = 300, ?int $now = null): bool
    {
        if ($secret === '' || $id === '' || ! ctype_digit($timestamp) || $signatureHeader === '') {
            return false;
        }

        $now ??= time();

        if (abs($now - (int) $timestamp) > $tolerance) {
            return false;
        }

        $key = str_starts_with($secret, 'whsec_') ? base64_decode(substr($secret, 6), true) : $secret;

        if ($key === false) {
            return false;
        }

        $expected = base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", $key, true));

        foreach (preg_split('/\s+/', trim($signatureHeader)) ?: [] as $candidate) {
            [$version, $signature] = array_pad(explode(',', $candidate, 2), 2, '');

            if ($version === 'v1' && hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    /** Create a header value (used in tests and by the generic sender docs). */
    public static function sign(string $secret, string $id, string $timestamp, string $body): string
    {
        $key = str_starts_with($secret, 'whsec_') ? (string) base64_decode(substr($secret, 6)) : $secret;

        return 'v1,'.base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", $key, true));
    }
}
