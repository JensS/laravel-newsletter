<?php

namespace JensS\Newsletter\Filament\Concerns;

use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Uses the host's policy for the model when one is registered; otherwise the
 * single gate ability from config('newsletter.authorization.ability').
 */
trait AuthorizesNewsletterAccess
{
    public static function can(string $action, ?Model $record = null): bool
    {
        if (Gate::getPolicyFor(static::getModel())) {
            return parent::can($action, $record);
        }

        return Gate::allows(config('newsletter.authorization.ability', 'manage-newsletters'));
    }

    public static function authorize(string $action, ?Model $record = null): ?Response
    {
        if (Gate::getPolicyFor(static::getModel())) {
            return parent::authorize($action, $record);
        }

        return Gate::inspect(config('newsletter.authorization.ability', 'manage-newsletters'));
    }

    public static function getNavigationGroup(): ?string
    {
        try {
            return \JensS\Newsletter\Filament\NewsletterPlugin::get()->getNavigationGroup();
        } catch (\Throwable) {
            return 'Newsletter';
        }
    }
}
