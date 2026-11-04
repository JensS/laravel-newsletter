<?php

namespace JensS\Newsletter\Sending;

use JensS\Newsletter\Exceptions\CampaignException;
use JensS\Newsletter\Mail\CampaignMessage;
use JensS\Newsletter\Models\Campaign;
use JensS\Newsletter\Support\CampaignRenderer;
use JensS\Newsletter\Support\Email;
use JensS\Newsletter\Support\Recipient;
use JensS\Newsletter\Support\RenderedMessage;

final class MessageFactory
{
    public function __construct(private readonly CampaignRenderer $renderer) {}

    /** @param  array<string, mixed>  $snapshot */
    public function make(Campaign $campaign, array $snapshot, Recipient $recipient, string $deliveryUuid, bool $test = false): CampaignMessage
    {
        $rendered = $this->renderer->render($snapshot, $recipient, $test ? null : $deliveryUuid);
        $from = (array) ($snapshot['from'] ?? []);
        $address = $from['address'] ?? config('mail.from.address');

        if (! $address) {
            throw new CampaignException('No sender address configured (newsletter.mail.from.address).');
        }

        $domain = config('newsletter.mail.message_id_domain') ?: Email::domain($address) ?: 'localhost';

        $message = new CampaignMessage(
            rendered: $test ? $this->asTest($rendered) : $rendered,
            recipientEmail: $recipient->email,
            recipientName: $recipient->name,
            sender: ['address' => $address, 'name' => $from['name'] ?? null],
            replyToAddress: $snapshot['reply_to'] ?? null,
            messageIdentifier: $deliveryUuid.'@'.$domain,
            listId: $this->listId($snapshot, $domain),
            recipientLocale: $recipient->locale,
        );

        return $message
            ->withHeader('X-Newsletter-Campaign', $campaign->uuid)
            ->withHeader('X-Newsletter-Delivery', $deliveryUuid);
    }

    private function asTest(RenderedMessage $rendered): RenderedMessage
    {
        return new RenderedMessage(
            subject: '[Test] '.$rendered->subject,
            html: $rendered->html,
            text: $rendered->text,
            unsubscribeUrl: $rendered->unsubscribeUrl,
            oneClickUrl: null, // test mails must not trigger one-click unsubscribes
            preferencesUrl: $rendered->preferencesUrl,
        );
    }

    /** @param  array<string, mixed>  $snapshot */
    private function listId(array $snapshot, string $domain): string
    {
        $brand = (string) (($snapshot['branding']['name'] ?? null) ?: config('app.name'));
        $label = preg_replace('/[^A-Za-z0-9 ._-]/', '', $brand);

        return trim($label).' <'.$snapshot['audience'].'.'.$domain.'>';
    }
}
