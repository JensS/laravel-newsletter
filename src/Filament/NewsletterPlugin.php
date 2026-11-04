<?php

namespace JensS\Newsletter\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;
use JensS\Newsletter\Filament\Resources\CampaignResource;
use JensS\Newsletter\Filament\Resources\SubscriptionResource;
use JensS\Newsletter\Filament\Resources\SuppressionResource;

/**
 * Optional Filament 3 admin:
 *
 *     $panel->plugin(\JensS\Newsletter\Filament\NewsletterPlugin::make());
 */
class NewsletterPlugin implements Plugin
{
    protected ?string $navigationGroup = 'Newsletter';

    protected bool $subscriptions = true;

    protected bool $suppressions = true;

    public static function make(): static
    {
        return app(static::class);
    }

    public static function get(): static
    {
        /** @var static */
        return filament(app(static::class)->getId());
    }

    public function getId(): string
    {
        return 'jenss-newsletter';
    }

    public function navigationGroup(?string $group): static
    {
        $this->navigationGroup = $group;

        return $this;
    }

    public function getNavigationGroup(): ?string
    {
        return $this->navigationGroup;
    }

    public function subscriptions(bool $condition = true): static
    {
        $this->subscriptions = $condition;

        return $this;
    }

    public function suppressions(bool $condition = true): static
    {
        $this->suppressions = $condition;

        return $this;
    }

    public function register(Panel $panel): void
    {
        $panel->resources(array_values(array_filter([
            CampaignResource::class,
            $this->subscriptions ? SubscriptionResource::class : null,
            $this->suppressions ? SuppressionResource::class : null,
        ])));
    }

    public function boot(Panel $panel): void {}
}
