<?php

namespace JensS\Newsletter\Drivers;

use Illuminate\Contracts\Mail\Factory as MailFactory;
use JensS\Newsletter\Contracts\DeliveryDriver;
use JensS\Newsletter\Exceptions\PermanentDeliveryException;
use JensS\Newsletter\Exceptions\TemporaryDeliveryException;
use JensS\Newsletter\Mail\CampaignMessage;
use JensS\Newsletter\Models\Delivery;
use JensS\Newsletter\Support\SendResult;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Sends through any Laravel mailer (SMTP, Resend, SES, Postmark, ...).
 */
final class MailerDriver implements DeliveryDriver
{
    public function __construct(private readonly MailFactory $mail) {}

    public function send(CampaignMessage $message, Delivery $delivery): SendResult
    {
        try {
            $sent = $this->mail->mailer($this->mailerName())->send($message);
        } catch (TransportExceptionInterface $e) {
            $code = (int) $e->getCode();

            // SMTP 5xx replies are permanent (unknown mailbox, policy rejection ...).
            if ($code >= 500 && $code < 600) {
                throw new PermanentDeliveryException($e->getMessage(), $code, $e);
            }

            throw new TemporaryDeliveryException($e->getMessage(), $code, $e);
        }

        $providerId = null;

        if ($sent !== null) {
            $original = $sent->getOriginalMessage();
            $providerId = $original->getHeaders()->get('X-Resend-Email-ID')?->getBodyAsString()
                ?? $sent->getMessageId();
        }

        return new SendResult($providerId);
    }

    public function supportsIdempotency(): bool
    {
        return false;
    }

    public function name(): string
    {
        $mailer = $this->mailerName() ?? config('mail.default');

        return (string) (config("mail.mailers.{$mailer}.transport") ?? $mailer);
    }

    private function mailerName(): ?string
    {
        return config('newsletter.mail.mailer') ?: null;
    }
}
