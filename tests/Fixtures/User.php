<?php

namespace JensS\Newsletter\Tests\Fixtures;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use JensS\Newsletter\Concerns\HasNewsletterSubscriptions;

class User extends Authenticatable implements FilamentUser
{
    use HasNewsletterSubscriptions, SoftDeletes;

    protected $table = 'users';

    protected $guarded = [];

    protected $casts = ['approved' => 'boolean', 'weekly_recap' => 'boolean'];

    public function canAccessPanel(Panel $panel): bool
    {
        return true; // panel access; newsletter resources are guarded by the gate
    }
}
