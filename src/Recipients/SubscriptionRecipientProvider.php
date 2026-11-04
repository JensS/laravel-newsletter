<?php

namespace JensS\Newsletter\Recipients;

use JensS\Newsletter\Contracts\MemberResolver;
use JensS\Newsletter\Contracts\RecipientProvider;
use JensS\Newsletter\Models\Subscription;
use JensS\Newsletter\Support\Recipient;
use JensS\Newsletter\Support\RecipientPage;

/**
 * Recipients are active subscriptions of one audience. For member audiences the
 * linked member is resolved (and re-checked for eligibility) every time.
 */
final class SubscriptionRecipientProvider implements RecipientProvider
{
    public function __construct(
        private readonly string $audience,
        private readonly ?MemberResolver $resolver = null,
        private readonly bool $requireMember = false,
    ) {}

    public function page(?string $cursor, int $limit): RecipientPage
    {
        $rows = Subscription::query()
            ->forAudience($this->audience)
            ->active()
            ->when($cursor !== null, fn ($q) => $q->where('id', '>', (int) $cursor))
            ->when($this->resolver !== null, fn ($q) => $q->with('subscribable'))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $recipients = [];
        foreach ($rows as $subscription) {
            if ($recipient = $this->map($subscription)) {
                $recipients[] = $recipient;
            }
        }

        $next = $rows->count() === $limit ? (string) $rows->last()->id : null;

        return new RecipientPage($recipients, $next);
    }

    public function count(): int
    {
        return Subscription::query()->forAudience($this->audience)->active()->count();
    }

    public function find(string $key): ?Recipient
    {
        if (! str_starts_with($key, 'sub:')) {
            return null;
        }

        $subscription = Subscription::query()
            ->forAudience($this->audience)
            ->active()
            ->find((int) substr($key, 4));

        return $subscription ? $this->map($subscription) : null;
    }

    private function map(Subscription $subscription): ?Recipient
    {
        $key = 'sub:'.$subscription->id;

        if ($subscription->subscribable_type !== null && $this->resolver !== null) {
            $member = $subscription->subscribable;

            if ($member === null) {
                return null; // member was deleted
            }

            $resolved = $this->resolver->resolve($member, $key);

            if ($resolved === null) {
                return null;
            }

            return new Recipient(
                key: $key,
                email: $resolved->email,
                name: $resolved->name ?? $subscription->name,
                locale: $resolved->locale ?? $subscription->locale,
                attributes: $resolved->attributes,
                subscribable: $member,
                subscriptionId: $subscription->id,
            );
        }

        if ($this->requireMember) {
            return null; // member audiences only send to linked members
        }

        return new Recipient(
            key: $key,
            email: $subscription->email,
            name: $subscription->name,
            locale: $subscription->locale,
            attributes: (array) ($subscription->metadata['attributes'] ?? []),
            subscriptionId: $subscription->id,
        );
    }
}
