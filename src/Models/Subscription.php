<?php

namespace JensS\Newsletter\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use JensS\Newsletter\Enums\SubscriptionStatus;
use JensS\Newsletter\Models\Concerns\UsesNewsletterTable;
use JensS\Newsletter\Support\Email;

/**
 * @property int $id
 * @property string $audience
 * @property string $email
 * @property string|null $name
 * @property string|null $locale
 * @property SubscriptionStatus $status
 * @property string|null $source
 * @property \Illuminate\Support\Carbon|null $consent_at
 * @property \Illuminate\Support\Carbon|null $confirmed_at
 * @property \Illuminate\Support\Carbon|null $unsubscribed_at
 * @property \Illuminate\Support\Carbon|null $confirmation_sent_at
 */
class Subscription extends Model
{
    use UsesNewsletterTable;

    protected string $baseTable = 'subscriptions';

    protected $guarded = ['id'];

    protected $hidden = ['confirmation_token'];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'consent_at' => 'datetime',
            'confirmation_sent_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Subscription $subscription) {
            $subscription->email = Email::normalize($subscription->email);
        });
    }

    public function subscribable(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', SubscriptionStatus::Subscribed->value);
    }

    public function scopeForAudience(Builder $query, string $audience): Builder
    {
        return $query->where('audience', $audience);
    }

    public function isActive(): bool
    {
        return $this->status === SubscriptionStatus::Subscribed;
    }
}
