<?php

namespace JensS\Newsletter\Filament\Resources\CampaignResource\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use JensS\Newsletter\Enums\CampaignStatus;
use JensS\Newsletter\Enums\DeliveryStatus;
use JensS\Newsletter\Models\Campaign;

class CampaignProgress extends StatsOverviewWidget
{
    public ?Campaign $record = null;

    protected function getPollingInterval(): ?string
    {
        return $this->record?->status === CampaignStatus::Sending ? '5s' : null;
    }

    protected function getStats(): array
    {
        if ($this->record === null) {
            return [];
        }

        $this->record->refresh();
        $p = $this->record->progress();
        $total = max(1, $p->total());
        $pct = fn (int $n) => round($n / $total * 100, 1).'%';
        $problems = $p->count(DeliveryStatus::Failed) + $p->count(DeliveryStatus::Bounced) + $p->count(DeliveryStatus::Complained);
        $excluded = $p->count(DeliveryStatus::Suppressed) + $p->count(DeliveryStatus::Skipped) + $p->count(DeliveryStatus::Cancelled);

        return [
            Stat::make('Progress', $p->percentage().'%')
                ->description($p->processed().' of '.$p->total().' processed'.($this->record->prepared_at ? '' : ' (still preparing)')),
            Stat::make('Pending', (string) $p->pending())
                ->description($p->count(DeliveryStatus::Sending).' sending right now'),
            Stat::make('Accepted by provider', (string) $p->count(DeliveryStatus::Accepted))
                ->description('Not yet confirmed delivered'),
            Stat::make('Delivered', (string) $p->count(DeliveryStatus::Delivered))
                ->description($pct($p->count(DeliveryStatus::Delivered)).' (reported by provider)')
                ->color('success'),
            Stat::make('Failed / bounced / complaints', (string) $problems)
                ->description($p->count(DeliveryStatus::Failed).' failed · '.$p->count(DeliveryStatus::Bounced).' bounced · '.$p->count(DeliveryStatus::Complained).' complaints')
                ->color($problems > 0 ? 'danger' : 'gray'),
            Stat::make('Not sent', (string) $excluded)
                ->description($p->count(DeliveryStatus::Suppressed).' suppressed · '.$p->count(DeliveryStatus::Skipped).' ineligible · '.$p->count(DeliveryStatus::Cancelled).' cancelled'),
        ];
    }
}
