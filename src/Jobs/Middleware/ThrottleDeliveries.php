<?php

namespace JensS\Newsletter\Jobs\Middleware;

use Closure;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Enforces provider sending limits. Short waits (per-second limits) sleep in
 * place, which suits the single worker typical of shared hosting; longer waits
 * release the job back to the queue without counting as a failure.
 */
final class ThrottleDeliveries
{
    private const MAX_SLEEP_SECONDS = 5;

    public function handle(object $job, Closure $next): mixed
    {
        foreach ($this->limits() as [$key, $max, $decay]) {
            while (RateLimiter::tooManyAttempts($key, $max)) {
                $wait = max(1, RateLimiter::availableIn($key));

                if ($wait > self::MAX_SLEEP_SECONDS) {
                    $job->release($wait);

                    return null;
                }

                sleep($wait);
            }
        }

        foreach ($this->limits() as [$key, , $decay]) {
            RateLimiter::hit($key, $decay);
        }

        return $next($job);
    }

    /** @return list<array{0: string, 1: int, 2: int}> */
    private function limits(): array
    {
        $config = (array) config('newsletter.rate_limit', []);
        $limits = [];

        foreach (['per_second' => 1, 'per_minute' => 60, 'per_hour' => 3600] as $name => $decay) {
            $max = (int) ($config[$name] ?? 0);

            if ($max > 0) {
                $limits[] = ['newsletter-send:'.$name, $max, $decay];
            }
        }

        return $limits;
    }
}
