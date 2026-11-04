<?php

namespace JensS\Newsletter;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use JensS\Newsletter\Contracts\ContentBlock;
use JensS\Newsletter\Contracts\RecipientProvider;
use JensS\Newsletter\Models\Campaign;
use JensS\Newsletter\Models\Subscription;
use JensS\Newsletter\Sending\CampaignDispatcher;
use JensS\Newsletter\Sending\Worker;
use JensS\Newsletter\Subscriptions\MemberLifecycle;
use JensS\Newsletter\Subscriptions\SubscriptionManager;
use JensS\Newsletter\Support\AudienceRegistry;
use JensS\Newsletter\Support\BlockRegistry;
use JensS\Newsletter\Support\SuppressionList;

/**
 * Entry point used by the facade. Every method delegates to a focused service
 * that can also be resolved from the container directly.
 */
class Newsletter
{
    public function __construct(
        private readonly AudienceRegistry $audiences,
        private readonly BlockRegistry $blocks,
        private readonly SubscriptionManager $subscriptions,
        private readonly CampaignDispatcher $campaigns,
        private readonly MemberLifecycle $members,
        private readonly SuppressionList $suppressions,
    ) {}

    public function audiences(): AudienceRegistry
    {
        return $this->audiences;
    }

    /** @param  array<string, mixed>|RecipientProvider  $config */
    public function registerAudience(string $key, array|RecipientProvider $config): void
    {
        $this->audiences->register($key, $config);
    }

    /** @param  ContentBlock|class-string<ContentBlock>  $block */
    public function registerBlock(string $key, ContentBlock|string $block): void
    {
        $this->blocks->register($key, $block);
    }

    public function blocks(): BlockRegistry
    {
        return $this->blocks;
    }

    public function subscriptions(): SubscriptionManager
    {
        return $this->subscriptions;
    }

    public function campaigns(): CampaignDispatcher
    {
        return $this->campaigns;
    }

    public function members(): MemberLifecycle
    {
        return $this->members;
    }

    public function suppressions(): SuppressionList
    {
        return $this->suppressions;
    }

    // --- Shortcuts -----------------------------------------------------------

    /** @param  array<string, mixed>  $options */
    public function subscribe(string $audience, ?string $email, array $options = []): Subscription
    {
        return $this->subscriptions->subscribe($audience, $email, $options);
    }

    public function unsubscribe(string $audience, string $email, string $source = 'api'): ?Subscription
    {
        return $this->subscriptions->unsubscribe($audience, $email, $source);
    }

    public function isSubscribed(string $audience, string|Model $who): bool
    {
        return $this->subscriptions->isSubscribed($audience, $who);
    }

    /** @param  array<string, mixed>  $attributes */
    public function draft(array $attributes): Campaign
    {
        return Campaign::query()->create($attributes + ['status' => 'draft']);
    }

    public function send(Campaign $campaign): Campaign
    {
        return $this->campaigns->start($campaign);
    }

    public function schedule(Campaign $campaign, DateTimeInterface $at): Campaign
    {
        return $this->campaigns->schedule($campaign, $at);
    }

    public function cancel(Campaign $campaign): int
    {
        return $this->campaigns->cancel($campaign);
    }

    /** Callable from your account-deletion code. */
    public function forgetMember(Model|string $member): void
    {
        $this->members->forget($member);
    }

    /** @return array<string, mixed> Callable from your data-export code. */
    public function exportMember(Model|string $member): array
    {
        return $this->members->export($member);
    }

    /** @return array<string, mixed> */
    public function tick(): array
    {
        return app(Worker::class)->tick();
    }
}
