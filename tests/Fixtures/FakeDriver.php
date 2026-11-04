<?php

namespace JensS\Newsletter\Tests\Fixtures;

use JensS\Newsletter\Contracts\DeliveryDriver;
use JensS\Newsletter\Mail\CampaignMessage;
use JensS\Newsletter\Models\Delivery;
use JensS\Newsletter\Support\SendResult;
use Throwable;

final class FakeDriver implements DeliveryDriver
{
    /** @var list<CampaignMessage> */
    public array $sent = [];

    /** @var list<Throwable> thrown in order, one per send attempt */
    public array $failures = [];

    public bool $idempotent = false;

    public function send(CampaignMessage $message, Delivery $delivery): SendResult
    {
        if ($this->failures !== []) {
            throw array_shift($this->failures);
        }

        $this->sent[] = $message;

        return new SendResult('provider-'.$delivery->uuid);
    }

    public function supportsIdempotency(): bool
    {
        return $this->idempotent;
    }

    public function name(): string
    {
        return 'fake';
    }

    /** @return list<string> */
    public function recipients(): array
    {
        return array_map(fn (CampaignMessage $m) => $m->recipientEmail, $this->sent);
    }
}
