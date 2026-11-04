<?php

namespace JensS\Newsletter\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use JensS\Newsletter\Enums\DeliveryStatus;
use JensS\Newsletter\Models\Concerns\UsesNewsletterTable;

/**
 * One record per campaign and recipient.
 *
 * @property int $id
 * @property string $uuid
 * @property int $campaign_id
 * @property string $recipient_key
 * @property int|null $subscription_id
 * @property string $email
 * @property string|null $name
 * @property string|null $locale
 * @property array|null $personalization
 * @property DeliveryStatus $status
 * @property int $attempts
 * @property string|null $message_id
 * @property string|null $provider_message_id
 * @property string|null $last_error
 * @property Campaign $campaign
 */
class Delivery extends Model
{
    use UsesNewsletterTable;

    protected string $baseTable = 'deliveries';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => DeliveryStatus::class,
            'personalization' => 'array',
            'dispatched_at' => 'datetime',
            'claimed_at' => 'datetime',
            'accepted_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
            'bounced_at' => 'datetime',
            'complained_at' => 'datetime',
            'anonymized_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function subscribable(): MorphTo
    {
        return $this->morphTo();
    }

    /** Idempotency key handed to providers that support it. Stable across retries. */
    public function idempotencyKey(): string
    {
        return 'newsletter-'.$this->uuid;
    }
}
