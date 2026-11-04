<?php

namespace JensS\Newsletter\Models;

use Illuminate\Database\Eloquent\Model;
use JensS\Newsletter\Models\Concerns\UsesNewsletterTable;

class WebhookEvent extends Model
{
    use UsesNewsletterTable;

    protected string $baseTable = 'webhook_events';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'processed_at' => 'datetime'];
    }
}
