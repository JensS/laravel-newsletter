<?php

namespace JensS\Newsletter\Support;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\HtmlString;
use JensS\Newsletter\Exceptions\CampaignException;
use JensS\Newsletter\Models\Campaign;

final class CampaignRenderer
{
    public const SNAPSHOT_VERSION = 1;

    public function __construct(
        private readonly BlockRegistry $blocks,
        private readonly ViewFactory $views,
    ) {}

    /**
     * Resolve everything that may change over time (blocks, layout, sender,
     * branding) into a plain array. Deliveries are rendered only from this.
     *
     * @return array<string, mixed>
     */
    public function snapshot(Campaign $campaign): array
    {
        $html = (string) $campaign->content_html;
        $explicitText = $campaign->content_text !== null && trim($campaign->content_text) !== ''
            ? (string) $campaign->content_text
            : null;
        $blockTexts = [];

        foreach ((array) $campaign->blocks as $block) {
            $type = $block['type'] ?? null;

            if (! is_string($type) || ! $this->blocks->has($type)) {
                throw new CampaignException("Unknown content block [{$type}].");
            }

            $rendered = $this->blocks->get($type)->render($campaign, (array) ($block['data'] ?? []));
            $html .= "\n".$rendered->html;
            $blockTexts[] = $rendered->text ?? HtmlToText::convert($rendered->html);
        }

        $text = $explicitText === null ? null : trim($explicitText."\n\n".implode("\n\n", $blockTexts));
        $from = config('newsletter.mail.from', []);

        return [
            'version' => self::SNAPSHOT_VERSION,
            'audience' => $campaign->audience,
            'subject' => $campaign->subject,
            'preview_text' => $campaign->preview_text,
            'html' => $html,
            'text' => $text,
            'layout' => $campaign->layout ?: config('newsletter.content.layout', 'newsletter::mail.layout'),
            'text_layout' => config('newsletter.content.text_layout', 'newsletter::mail.layout-text'),
            'from' => [
                'address' => $campaign->from_address ?: ($from['address'] ?? null),
                'name' => $campaign->from_name ?: ($from['name'] ?? null),
            ],
            'reply_to' => $campaign->reply_to ?: config('newsletter.mail.reply_to'),
            'branding' => (array) config('newsletter.branding', []),
            'data' => (array) $campaign->data,
            'frozen_at' => now()->toIso8601String(),
        ];
    }

    /** @param  array<string, mixed>  $snapshot */
    public function render(array $snapshot, Recipient $recipient, ?string $deliveryUuid = null): RenderedMessage
    {
        $audience = (string) $snapshot['audience'];
        $links = $this->links($audience, $recipient->email, $deliveryUuid);
        $branding = (array) ($snapshot['branding'] ?? []);

        $values = array_merge(
            (array) ($snapshot['data'] ?? []),
            $recipient->personalization(),
            [
                'unsubscribe_url' => $links['unsubscribe'],
                'preferences_url' => $links['preferences'],
                'brand_name' => $branding['name'] ?? null,
            ],
        );

        return $this->withLocale($recipient->locale, function () use ($snapshot, $values, $links, $branding, $recipient) {
            $subject = Personalizer::text((string) $snapshot['subject'], $values);
            $preview = Personalizer::text((string) ($snapshot['preview_text'] ?? ''), $values);
            $body = Personalizer::html((string) $snapshot['html'], $values);
            $text = $snapshot['text'] !== null
                ? Personalizer::text((string) $snapshot['text'], $values)
                : HtmlToText::convert($body);

            $viewData = [
                'subject' => $subject,
                'previewText' => $preview,
                'branding' => $branding,
                'unsubscribeUrl' => $links['unsubscribe'],
                'preferencesUrl' => $links['preferences'],
                'recipient' => $recipient,
                'data' => (array) ($snapshot['data'] ?? []),
            ];

            $html = $this->views->make($snapshot['layout'], $viewData + ['content' => new HtmlString($body)])->render();
            $textOut = $this->views->make($snapshot['text_layout'], $viewData + ['content' => $text])->render();

            $this->assertUnsubscribeLink($html, $textOut, $links['unsubscribe']);

            return new RenderedMessage(
                subject: $subject,
                html: $html,
                text: trim($textOut),
                unsubscribeUrl: $links['unsubscribe'],
                oneClickUrl: $links['one_click'],
                preferencesUrl: $links['preferences'],
            );
        });
    }

    /** A placeholder recipient for previews and validation. */
    public function sampleRecipient(?string $email = null, ?string $name = null): Recipient
    {
        return new Recipient('preview', $email ?? 'preview@example.com', $name ?? 'Alex Example');
    }

    /** @return array{unsubscribe: ?string, one_click: ?string, preferences: ?string} */
    public function links(string $audience, string $email, ?string $deliveryUuid = null): array
    {
        if (! Route::has('newsletter.unsubscribe')) {
            return ['unsubscribe' => null, 'one_click' => null, 'preferences' => null];
        }

        $token = LinkToken::unsubscribe($audience, $email, $deliveryUuid);

        return [
            'unsubscribe' => route('newsletter.unsubscribe', ['token' => $token]),
            'one_click' => route('newsletter.unsubscribe.one-click', ['token' => $token]),
            'preferences' => route('newsletter.preferences', ['token' => LinkToken::preferences($email)]),
        ];
    }

    private function assertUnsubscribeLink(string $html, string $text, ?string $url): void
    {
        if (! config('newsletter.content.require_unsubscribe_link', true)) {
            return;
        }

        if ($url === null) {
            throw new CampaignException('Newsletter routes are disabled, so no unsubscribe link can be generated.');
        }

        if (! str_contains($html, e($url)) && ! str_contains($html, $url)) {
            throw new CampaignException('The email layout must render the unsubscribe link ($unsubscribeUrl) in the HTML part.');
        }

        if (! str_contains($text, $url)) {
            throw new CampaignException('The email layout must render the unsubscribe link ($unsubscribeUrl) in the text part.');
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withLocale(?string $locale, callable $callback): mixed
    {
        $app = app();
        $original = $app->getLocale();

        if ($locale === null || $locale === '' || $locale === $original) {
            return $callback();
        }

        $app->setLocale($locale);

        try {
            return $callback();
        } finally {
            $app->setLocale($original);
        }
    }
}
