<?php

namespace JensS\Newsletter\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use JensS\Newsletter\Models\Subscription;

class ConfirmSubscription extends Mailable
{
    public function __construct(
        public readonly Subscription $subscription,
        public readonly string $confirmUrl,
        public readonly string $audienceLabel,
    ) {
        if ($subscription->locale) {
            $this->locale($subscription->locale);
        }
    }

    public function envelope(): Envelope
    {
        $from = config('newsletter.mail.from');

        return new Envelope(
            from: ! empty($from['address']) ? new Address($from['address'], $from['name'] ?? null) : null,
            subject: __('newsletter::messages.confirm.subject', ['audience' => $this->audienceLabel]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'newsletter::mail.confirm',
            text: 'newsletter::mail.confirm-text',
            with: ['branding' => config('newsletter.branding')],
        );
    }
}
