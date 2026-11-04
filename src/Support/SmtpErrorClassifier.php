<?php

namespace JensS\Newsletter\Support;

/**
 * Classifies SMTP replies the way a careful bulk sender should:
 *
 * - systemic:  the server refuses *us* (auth, quota, rate limit, unreachable).
 *              Pause everything; retrying other recipients makes it worse.
 * - permanent: the server refuses *this recipient* (unknown mailbox ...).
 * - temporary: anything else that is worth retrying later (greylisting, 4xx).
 */
final class SmtpErrorClassifier
{
    public const SYSTEMIC = 'systemic';

    public const PERMANENT = 'permanent';

    public const TEMPORARY = 'temporary';

    private const QUOTA_WORDS = '/(quota|rate.?limit|limit (?:exceeded|reached)|too many|exceeded|sending limit|daily limit|throttl|try again later|sendeLimit|zu viele|spam|blocked|blacklist|blocklist|reputation)/i';

    public static function classify(int $code, string $message): string
    {
        $enhanced = self::enhancedStatus($message);

        // No reply code: connection, TLS or timeout problem.
        if ($code === 0 && ! preg_match('/\b[45]\d\d\b/', $message)) {
            return self::SYSTEMIC;
        }

        // Symfony: 'Expected response code "250" but got code "550", with message "..."'
        if ($code === 0 && preg_match('/got (?:code|an empty response)\s*"?(\d{3})/i', $message, $m)) {
            $code = (int) $m[1];
        } elseif ($code === 0 && preg_match('/\b([45]\d\d)\b/', $message, $m)) {
            $code = (int) $m[1];
        }

        // 421 service not available, 530/534/535 authentication, 454 TLS/auth temporarily unavailable.
        if (in_array($code, [421, 454, 530, 534, 535], true) || str_starts_with((string) $enhanced, '5.7.8') || str_starts_with((string) $enhanced, '4.7.0') && preg_match('/auth/i', $message)) {
            return self::SYSTEMIC;
        }

        // Sender-side quotas and policy blocks, whatever the code says.
        if (preg_match(self::QUOTA_WORDS, $message) && ! self::isRecipientStatus($enhanced)) {
            return self::SYSTEMIC;
        }

        if ($code >= 500 && $code < 600) {
            // 5.2.2 mailbox full is usually temporary in practice.
            return $enhanced === '5.2.2' ? self::TEMPORARY : self::PERMANENT;
        }

        return self::TEMPORARY;
    }

    public static function enhancedStatus(string $message): ?string
    {
        return preg_match('/\b([245]\.\d{1,3}\.\d{1,3})\b/', $message, $m) ? $m[1] : null;
    }

    /** 5.1.x (address) and 5.2.x (mailbox) statuses concern the recipient. */
    private static function isRecipientStatus(?string $enhanced): bool
    {
        return $enhanced !== null && preg_match('/^[45]\.[12]\./', $enhanced) === 1;
    }
}
