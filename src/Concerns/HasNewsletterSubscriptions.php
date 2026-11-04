<?php

namespace JensS\Newsletter\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use JensS\Newsletter\Facades\Newsletter;
use JensS\Newsletter\Models\Subscription;

/**
 * Add to your member/user model:
 *
 *     use JensS\Newsletter\Concerns\HasNewsletterSubscriptions;
 *
 * Membership never implies a subscription. These helpers only act when called.
 *
 * @mixin Model
 */
trait HasNewsletterSubscriptions
{
    public static function bootHasNewsletterSubscriptions(): void
    {
        static::updated(function (Model $member) {
            $column = config('newsletter.members.map.email', 'email');

            if (config('newsletter.members.sync_email_changes', true) && $member->wasChanged($column)) {
                Newsletter::members()->changeEmail($member, (string) $member->getAttribute($column), (string) $member->getOriginal($column));
            }
        });

        static::deleted(function (Model $member) {
            // Soft-deleted members are excluded at send time; hard deletes erase newsletter data.
            if (! method_exists($member, 'isForceDeleting') || $member->isForceDeleting()) {
                Newsletter::members()->forget($member);
            }
        });
    }

    public function newsletterSubscriptions(): MorphMany
    {
        return $this->morphMany(Subscription::class, 'subscribable');
    }

    /** Explicit opt-in, e.g. from the account settings page. */
    public function subscribeToNewsletter(?string $audience = null, string $source = 'account', bool $confirmed = true): Subscription
    {
        return Newsletter::subscriptions()->subscribe($audience ?? config('newsletter.default_audience'), null, [
            'member' => $this,
            'source' => $source,
            'confirmed' => $confirmed,
            'ip' => request()?->ip(),
        ]);
    }

    public function unsubscribeFromNewsletter(?string $audience = null, string $source = 'account'): void
    {
        $audience ??= config('newsletter.default_audience');
        $email = (string) $this->getAttribute(config('newsletter.members.map.email', 'email'));

        Newsletter::subscriptions()->unsubscribe($audience, $email, $source, $this);
    }

    public function isSubscribedToNewsletter(?string $audience = null): bool
    {
        return Newsletter::subscriptions()->isSubscribed($audience ?? config('newsletter.default_audience'), $this);
    }
}
