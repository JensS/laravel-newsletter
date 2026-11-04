<?php

namespace JensS\Newsletter\Drivers;

use JensS\Newsletter\Contracts\DeliveryDriver;
use JensS\Newsletter\Exceptions\PermanentDeliveryException;
use JensS\Newsletter\Exceptions\TemporaryDeliveryException;
use JensS\Newsletter\Mail\CampaignMessage;
use JensS\Newsletter\Models\Delivery;
use JensS\Newsletter\Support\SendResult;
use RuntimeException;
use Throwable;

/**
 * Sends through the Resend API directly so that every delivery carries an
 * idempotency key: a retry after an interrupted attempt can never produce a
 * second email. Requires resend/resend-php (or resend/resend-laravel).
 */
final class ResendDriver implements DeliveryDriver
{
    /** @var object|null Resend\Client */
    private ?object $client = null;

    public function send(CampaignMessage $message, Delivery $delivery): SendResult
    {
        $from = $message->sender['name']
            ? sprintf('%s <%s>', addcslashes((string) $message->sender['name'], '"<>'), $message->sender['address'])
            : $message->sender['address'];

        $headers = $message->headerLines();

        $payload = array_filter([
            'from' => $from,
            'to' => [$message->recipientName ? sprintf('%s <%s>', addcslashes($message->recipientName, '"<>'), $message->recipientEmail) : $message->recipientEmail],
            'subject' => $message->rendered->subject,
            'html' => $message->rendered->html,
            'text' => $message->rendered->text,
            'reply_to' => $message->replyToAddress,
            'headers' => $headers,
            'tags' => [
                ['name' => 'newsletter_delivery', 'value' => $delivery->uuid],
                ['name' => 'newsletter_campaign', 'value' => (string) $delivery->campaign_id],
            ],
        ], fn ($v) => $v !== null && $v !== []);

        try {
            $result = $this->client()->emails->send($payload, ['idempotency_key' => $delivery->idempotencyKey()]);
        } catch (Throwable $e) {
            throw $this->classify($e);
        }

        return new SendResult((string) ($result->id ?? '') ?: null);
    }

    public function supportsIdempotency(): bool
    {
        return true;
    }

    public function name(): string
    {
        return 'resend';
    }

    private function client(): object
    {
        if ($this->client !== null) {
            return $this->client;
        }

        if (app()->bound('resend')) {
            return $this->client = app('resend');
        }

        if (! class_exists(\Resend::class)) {
            throw new RuntimeException('The resend driver requires resend/resend-php: composer require resend/resend-php');
        }

        $key = config('services.resend.key') ?: env('RESEND_API_KEY');

        return $this->client = \Resend::client((string) $key);
    }

    private function classify(Throwable $e): Throwable
    {
        $class = get_class($e);

        if (str_ends_with($class, 'ErrorException') && method_exists($e, 'getErrorCode')) {
            $status = (int) $e->getErrorCode();

            // 409: concurrent request with the same idempotency key; 429: rate limited.
            if ($status === 409 || $status === 429 || $status >= 500 || $status === 0) {
                return new TemporaryDeliveryException($e->getMessage(), $status, $e);
            }

            return new PermanentDeliveryException($e->getMessage(), $status, $e);
        }

        return new TemporaryDeliveryException($e->getMessage(), (int) $e->getCode(), $e);
    }
}
