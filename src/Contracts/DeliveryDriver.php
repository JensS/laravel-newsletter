<?php

namespace JensS\Newsletter\Contracts;

use JensS\Newsletter\Mail\CampaignMessage;
use JensS\Newsletter\Models\Delivery;
use JensS\Newsletter\Support\SendResult;

interface DeliveryDriver
{
    /**
     * Hand one message to the provider. Throw TemporaryDeliveryException for
     * failures worth retrying and PermanentDeliveryException for the rest.
     */
    public function send(CampaignMessage $message, Delivery $delivery): SendResult;

    /**
     * Whether the provider deduplicates by idempotency key. Only then is it safe
     * to retry a delivery whose previous attempt was interrupted mid-send.
     */
    public function supportsIdempotency(): bool;

    public function name(): string;
}
