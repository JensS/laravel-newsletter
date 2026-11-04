<?php

namespace JensS\Newsletter\Jobs\Middleware;

use Closure;
use Illuminate\Support\Facades\RateLimiter;
use JensS\Newsletter\Models\Delivery;
use JensS\Newsletter\Sending\CircuitBreaker;
use JensS\Newsletter\Support\Email;

/**
 * Enforces sending limits before a delivery is attempted:
 *
 * - the global circuit breaker (paused after systemic mail-server errors);
 * - per second / minute / hour / day limits of the mail server;
 * - an optional per-recipient-domain limit, so large mailbox providers do not
 *   see bursts from a sender they do not know yet.
 *
 * Short waits sleep in place (fine for the single worker of shared hosting);
 * longer waits release the job without counting as a failure.
 */
final class ThrottleDeliveries
{
    private const MAX_SLEEP_SECONDS = 5;

    /** Mailbox providers that share infrastructure (and reputation) across domains. */
    private const PROVIDER_GROUPS = [
        'gmail.com' => 'google', 'googlemail.com' => 'google',
        'gmx.de' => 'united-internet', 'gmx.net' => 'united-internet', 'gmx.at' => 'united-internet',
        'gmx.ch' => 'united-internet', 'web.de' => 'united-internet', 'mail.com' => 'united-internet',
        'outlook.com' => 'microsoft', 'outlook.de' => 'microsoft', 'hotmail.com' => 'microsoft',
        'hotmail.de' => 'microsoft', 'live.com' => 'microsoft', 'live.de' => 'microsoft', 'msn.com' => 'microsoft',
        'yahoo.com' => 'yahoo', 'yahoo.de' => 'yahoo', 'ymail.com' => 'yahoo', 'aol.com' => 'yahoo', 'aol.de' => 'yahoo',
        't-online.de' => 't-online', 'magenta.de' => 't-online',
        'icloud.com' => 'apple', 'me.com' => 'apple', 'mac.com' => 'apple',
        'freenet.de' => 'freenet', 'posteo.de' => 'posteo', 'mailbox.org' => 'mailbox.org',
    ];

    public function handle(object $job, Closure $next): mixed
    {
        $breaker = app(CircuitBreaker::class);

        if ($wait = $breaker->remaining()) {
            self::releaseLater($job, $wait);

            return null;
        }

        $limits = $this->limits($job);

        foreach ($limits as [$key, $max]) {
            while (RateLimiter::tooManyAttempts($key, $max)) {
                $wait = max(1, RateLimiter::availableIn($key));

                if ($wait > self::MAX_SLEEP_SECONDS) {
                    self::releaseLater($job, $wait);

                    return null;
                }

                sleep($wait);
            }
        }

        foreach ($limits as [$key, , $decay]) {
            RateLimiter::hit($key, $decay);
        }

        return $next($job);
    }

    /**
     * Release the job and move the delivery's dispatch time to when it will run,
     * so maintenance does not mistake a long wait (daily limit, paused server)
     * for a lost job and queue duplicates.
     */
    public static function releaseLater(object $job, int $seconds): void
    {
        $job->release($seconds);

        if (isset($job->deliveryId)) {
            Delivery::query()->whereKey($job->deliveryId)->update(['dispatched_at' => now()->addSeconds($seconds)]);
        }
    }

    /** Group a recipient domain with its provider's sibling domains. */
    public static function providerKey(string $email): string
    {
        $domain = (string) Email::domain(Email::normalize($email));

        return self::PROVIDER_GROUPS[$domain] ?? $domain;
    }

    /** @return list<array{0: string, 1: int, 2: int}> */
    private function limits(object $job): array
    {
        $config = (array) config('newsletter.rate_limit', []);
        $limits = [];

        foreach (['per_second' => 1, 'per_minute' => 60, 'per_hour' => 3600, 'per_day' => 86400] as $name => $decay) {
            $max = (int) ($config[$name] ?? 0);

            if ($max > 0) {
                $limits[] = ['newsletter-send:'.$name, $max, $decay];
            }
        }

        $perDomain = (int) ($config['per_domain_per_minute'] ?? 0);

        if ($perDomain > 0 && isset($job->deliveryId)) {
            $email = Delivery::query()->whereKey($job->deliveryId)->value('email');

            if (is_string($email)) {
                $limits[] = ['newsletter-send:domain:'.self::providerKey($email), $perDomain, 60];
            }
        }

        return $limits;
    }
}
