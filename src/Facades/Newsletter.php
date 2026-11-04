<?php

namespace JensS\Newsletter\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \JensS\Newsletter\Support\AudienceRegistry audiences()
 * @method static void registerAudience(string $key, array|\JensS\Newsletter\Contracts\RecipientProvider $config)
 * @method static void registerBlock(string $key, \JensS\Newsletter\Contracts\ContentBlock|string $block)
 * @method static \JensS\Newsletter\Support\BlockRegistry blocks()
 * @method static \JensS\Newsletter\Subscriptions\SubscriptionManager subscriptions()
 * @method static \JensS\Newsletter\Sending\CampaignDispatcher campaigns()
 * @method static \JensS\Newsletter\Subscriptions\MemberLifecycle members()
 * @method static \JensS\Newsletter\Support\SuppressionList suppressions()
 * @method static \JensS\Newsletter\Models\Subscription subscribe(string $audience, ?string $email, array $options = [])
 * @method static \JensS\Newsletter\Models\Subscription|null unsubscribe(string $audience, string $email, string $source = 'api')
 * @method static bool isSubscribed(string $audience, string|\Illuminate\Database\Eloquent\Model $who)
 * @method static \JensS\Newsletter\Models\Campaign draft(array $attributes)
 * @method static \JensS\Newsletter\Models\Campaign send(\JensS\Newsletter\Models\Campaign $campaign)
 * @method static \JensS\Newsletter\Models\Campaign schedule(\JensS\Newsletter\Models\Campaign $campaign, \DateTimeInterface $at)
 * @method static int cancel(\JensS\Newsletter\Models\Campaign $campaign)
 * @method static void forgetMember(\Illuminate\Database\Eloquent\Model|string $member)
 * @method static array exportMember(\Illuminate\Database\Eloquent\Model|string $member)
 * @method static array tick()
 *
 * @see \JensS\Newsletter\Newsletter
 */
class Newsletter extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \JensS\Newsletter\Newsletter::class;
    }
}
