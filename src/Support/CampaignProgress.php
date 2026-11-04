<?php

namespace JensS\Newsletter\Support;

use JensS\Newsletter\Enums\DeliveryStatus;
use JensS\Newsletter\Models\Campaign;

final class CampaignProgress
{
    /** @param  array<string, int>  $counts  delivery status value => count */
    public function __construct(
        public readonly Campaign $campaign,
        public readonly array $counts,
    ) {}

    public function count(DeliveryStatus $status): int
    {
        return $this->counts[$status->value] ?? 0;
    }

    public function total(): int
    {
        return array_sum($this->counts);
    }

    public function pending(): int
    {
        return $this->count(DeliveryStatus::Queued) + $this->count(DeliveryStatus::Sending);
    }

    /** Handed to the provider (accepted or a later provider-reported state). */
    public function handedOff(): int
    {
        return $this->count(DeliveryStatus::Accepted)
            + $this->count(DeliveryStatus::Delivered)
            + $this->count(DeliveryStatus::Bounced)
            + $this->count(DeliveryStatus::Complained);
    }

    public function processed(): int
    {
        return $this->total() - $this->pending();
    }

    public function percentage(): float
    {
        $total = $this->total();

        return $total === 0 ? 0.0 : round($this->processed() / $total * 100, 1);
    }

    /** @return array<string, int> */
    public function toArray(): array
    {
        $out = [];
        foreach (DeliveryStatus::cases() as $status) {
            $out[$status->value] = $this->count($status);
        }

        return $out + ['total' => $this->total(), 'pending' => $this->pending()];
    }
}
