<?php

namespace JensS\Newsletter\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;
use JensS\Newsletter\Enums\CampaignStatus;
use JensS\Newsletter\Enums\DeliveryStatus;
use JensS\Newsletter\Exceptions\CampaignException;
use JensS\Newsletter\Models\Concerns\UsesNewsletterTable;
use JensS\Newsletter\Support\CampaignProgress;

/**
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string $audience
 * @property CampaignStatus $status
 * @property string $subject
 * @property string|null $preview_text
 * @property string|null $content_html
 * @property string|null $content_text
 * @property array|null $blocks
 * @property string|null $layout
 * @property array|null $data
 * @property string|null $from_address
 * @property string|null $from_name
 * @property string|null $reply_to
 * @property array|null $snapshot
 * @property \Illuminate\Support\Carbon|null $scheduled_at
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $prepared_at
 * @property string|null $prepare_cursor
 * @property \Illuminate\Support\Carbon|null $prepare_heartbeat_at
 * @property \Illuminate\Support\Carbon|null $finished_at
 * @property \Illuminate\Support\Carbon|null $cancelled_at
 * @property \Illuminate\Support\Carbon|null $paused_at
 * @property string|null $pause_reason
 * @property int $recipient_count
 */
class Campaign extends Model
{
    use UsesNewsletterTable;

    /** Fields that become immutable once sending has started. */
    public const CONTENT_FIELDS = [
        'audience', 'subject', 'preview_text', 'content_html', 'content_text',
        'blocks', 'layout', 'data', 'from_address', 'from_name', 'reply_to', 'snapshot',
    ];

    protected string $baseTable = 'campaigns';

    protected $guarded = ['id'];

    protected $attributes = [
        'status' => 'draft',
    ];

    protected function casts(): array
    {
        return [
            'status' => CampaignStatus::class,
            'blocks' => 'array',
            'data' => 'array',
            'snapshot' => 'array',
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'prepared_at' => 'datetime',
            'prepare_heartbeat_at' => 'datetime',
            'finished_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'paused_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Campaign $campaign) {
            $campaign->uuid ??= (string) Str::uuid();
            $campaign->name ??= $campaign->subject;
        });

        static::updating(function (Campaign $campaign) {
            $original = $campaign->getOriginal('status');
            $original = $original instanceof CampaignStatus ? $original : CampaignStatus::from((string) $original);

            if ($original->isEditable()) {
                return;
            }

            // The snapshot is written exactly once, in the same update that leaves the editable state.
            $dirty = array_intersect(array_keys($campaign->getDirty()), self::CONTENT_FIELDS);

            if ($dirty !== []) {
                throw new CampaignException(
                    'Campaign content is frozen once sending starts; cannot change: '.implode(', ', $dirty)
                );
            }
        });
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class);
    }

    public function createdBy(): MorphTo
    {
        return $this->morphTo();
    }

    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    public function isFrozen(): bool
    {
        return $this->snapshot !== null;
    }

    public function progress(): CampaignProgress
    {
        $counts = $this->deliveries()
            ->toBase()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($n) => (int) $n)
            ->all();

        return new CampaignProgress($this, $counts);
    }

    public function pendingDeliveriesCount(): int
    {
        return $this->deliveries()->whereIn('status', DeliveryStatus::pendingValues())->count();
    }
}
