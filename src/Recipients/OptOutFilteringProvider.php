<?php

namespace JensS\Newsletter\Recipients;

use JensS\Newsletter\Contracts\RecipientProvider;
use JensS\Newsletter\Enums\SubscriptionStatus;
use JensS\Newsletter\Models\Subscription;
use JensS\Newsletter\Support\Recipient;
use JensS\Newsletter\Support\RecipientPage;

/**
 * Wraps an application provider and removes recipients who unsubscribed from
 * this audience through a package link, even if the application's own
 * preference was not updated.
 */
final class OptOutFilteringProvider implements RecipientProvider
{
    public function __construct(
        private readonly string $audience,
        private readonly RecipientProvider $inner,
    ) {}

    public function inner(): RecipientProvider
    {
        return $this->inner;
    }

    public function page(?string $cursor, int $limit): RecipientPage
    {
        $page = $this->inner->page($cursor, $limit);

        if ($page->recipients === []) {
            return $page;
        }

        $optedOut = $this->optedOut(array_map(fn (Recipient $r) => $r->email, $page->recipients));

        return new RecipientPage(
            array_values(array_filter($page->recipients, fn (Recipient $r) => ! isset($optedOut[$r->email]))),
            $page->nextCursor,
        );
    }

    public function count(): int
    {
        return $this->inner->count();
    }

    public function find(string $key): ?Recipient
    {
        $recipient = $this->inner->find($key);

        if ($recipient === null || $this->optedOut([$recipient->email]) !== []) {
            return null;
        }

        return $recipient;
    }

    /**
     * @param  list<string>  $emails
     * @return array<string, true>
     */
    private function optedOut(array $emails): array
    {
        return Subscription::query()
            ->forAudience($this->audience)
            ->where('status', SubscriptionStatus::Unsubscribed->value)
            ->whereIn('email', $emails)
            ->pluck('email')
            ->mapWithKeys(fn ($e) => [$e => true])
            ->all();
    }
}
