<?php

namespace JensS\Newsletter\Sending;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Log;

/**
 * Global pause for all newsletter sending after a systemic mail-server error.
 * Pauses escalate (5, 15, 60, 240 min by default) while the problem persists
 * and reset after the first successful send.
 */
final class CircuitBreaker
{
    private const OPEN_KEY = 'newsletter:breaker:open_until';

    private const LEVEL_KEY = 'newsletter:breaker:level';

    private const REASON_KEY = 'newsletter:breaker:reason';

    public function __construct(private readonly Cache $cache) {}

    /** Seconds until sending may resume, or 0 when closed. */
    public function remaining(): int
    {
        if (! config('newsletter.circuit_breaker.enabled', true)) {
            return 0;
        }

        $until = (int) $this->cache->get(self::OPEN_KEY, 0);

        return max(0, $until - now()->getTimestamp());
    }

    public function isOpen(): bool
    {
        return $this->remaining() > 0;
    }

    public function reason(): ?string
    {
        return $this->isOpen() ? $this->cache->get(self::REASON_KEY) : null;
    }

    /** Open the breaker; returns the pause length in seconds. */
    public function trip(string $reason): int
    {
        if (! config('newsletter.circuit_breaker.enabled', true)) {
            return 60;
        }

        $steps = array_values(array_map('intval', (array) config('newsletter.circuit_breaker.pause_minutes', [5, 15, 60, 240]))) ?: [5];
        $level = (int) $this->cache->get(self::LEVEL_KEY, 0);

        // Several workers may hit the same error at once: only escalate when not already open.
        if ($this->isOpen()) {
            return $this->remaining();
        }

        $seconds = $steps[min($level, count($steps) - 1)] * 60;

        $this->cache->put(self::OPEN_KEY, now()->getTimestamp() + $seconds, $seconds + 60);
        $this->cache->put(self::REASON_KEY, mb_substr($reason, 0, 500), $seconds + 60);
        $this->cache->put(self::LEVEL_KEY, $level + 1, now()->addDay());

        Log::warning("Newsletter sending paused for {$seconds}s: {$reason}");

        return $seconds;
    }

    public function recordSuccess(): void
    {
        if ($this->cache->has(self::LEVEL_KEY)) {
            $this->cache->forget(self::LEVEL_KEY);
        }
    }

    public function reset(): void
    {
        $this->cache->forget(self::OPEN_KEY);
        $this->cache->forget(self::LEVEL_KEY);
        $this->cache->forget(self::REASON_KEY);
    }
}
