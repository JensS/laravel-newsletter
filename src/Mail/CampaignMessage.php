<?php

namespace JensS\Newsletter\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use JensS\Newsletter\Support\RenderedMessage;

/**
 * A fully rendered, personalised campaign message for one recipient.
 * Sent synchronously from within the package's own queued job.
 */
class CampaignMessage extends Mailable
{
    /** @var array<string, string> */
    public array $extraHeaders = [];

    /**
     * @param  array{address: string, name: ?string}  $sender
     */
    public function __construct(
        public readonly RenderedMessage $rendered,
        public readonly string $recipientEmail,
        public readonly ?string $recipientName,
        public readonly array $sender,
        public readonly ?string $replyToAddress,
        public readonly string $messageIdentifier,
        public readonly ?string $listId = null,
        ?string $recipientLocale = null,
    ) {
        if ($recipientLocale) {
            $this->locale($recipientLocale);
        }
    }

    public function withHeader(string $name, string $value): static
    {
        $this->extraHeaders[$name] = $value;

        return $this;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->sender['address'], $this->sender['name'] ?? null),
            to: [new Address($this->recipientEmail, $this->recipientName)],
            replyTo: $this->replyToAddress ? [new Address($this->replyToAddress)] : [],
            subject: $this->rendered->subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'newsletter::mail.passthrough-html',
            text: 'newsletter::mail.passthrough-text',
        );
    }

    public function headers(): Headers
    {
        return new Headers(
            messageId: $this->messageIdentifier,
            text: $this->headerLines(),
        );
    }

    /**
     * Mailbox-provider headers: RFC 2369 List-Unsubscribe and RFC 8058 one-click.
     *
     * @return array<string, string>
     */
    public function headerLines(): array
    {
        $headers = [];
        $targets = [];

        if ($this->rendered->oneClickUrl) {
            $targets[] = '<'.$this->rendered->oneClickUrl.'>';
        }

        // The mailto variant carries the signed token in the subject, so the
        // mailbox processor can unsubscribe the right address (newsletter:process-mailbox).
        if ($mailto = config('newsletter.mail.unsubscribe_mailto')) {
            $subject = $this->rendered->unsubscribeToken ? 'unsubscribe '.$this->rendered->unsubscribeToken : 'unsubscribe';
            $targets[] = '<mailto:'.$mailto.'?subject='.rawurlencode($subject).'>';
        }

        if ($targets !== []) {
            $headers['List-Unsubscribe'] = implode(', ', $targets);
        }

        if ($this->rendered->oneClickUrl) {
            $headers['List-Unsubscribe-Post'] = 'List-Unsubscribe=One-Click';
        }

        if ($this->listId) {
            $headers['List-Id'] = $this->listId;
        }

        return array_merge($headers, $this->extraHeaders);
    }
}
