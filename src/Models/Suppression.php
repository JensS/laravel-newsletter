<?php

namespace JensS\Newsletter\Models;

use Illuminate\Database\Eloquent\Model;
use JensS\Newsletter\Models\Concerns\UsesNewsletterTable;
use JensS\Newsletter\Support\Email;

/**
 * @property string $email
 * @property string $reason
 */
class Suppression extends Model
{
    use UsesNewsletterTable;

    public const REASON_BOUNCE = 'bounce';

    public const REASON_COMPLAINT = 'complaint';

    public const REASON_MANUAL = 'manual';

    protected string $baseTable = 'suppressions';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::saving(fn (Suppression $s) => $s->email = Email::normalize($s->email));
    }

    public static function isSuppressed(string $email): bool
    {
        return static::query()->where('email', Email::normalize($email))->exists();
    }
}
