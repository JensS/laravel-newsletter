<?php

namespace JensS\Newsletter\Support;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\HtmlString;
use JensS\Newsletter\Exceptions\CampaignException;
use JensS\Newsletter\Models\Campaign;
use JensS\Newsletter\Models\Subscription;

final class CampaignRenderer
{
    /** v2: the layout output is frozen too ("shells"), not only the layout name. */
    public const SNAPSHOT_VERSION = 2;

    /** Per-recipient values a layout may print; they become markers in the frozen shell. */
    private const SLOTS = ['subject', 'preview', 'content', 'unsubscribeurl', 'preferencesurl', 'email', 'name', 'firstname', 'locale', 'key'];

    private const MAX_LOCALES = 12;

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

        if (config('newsletter.content.absolute_urls', true)) {
            $html = ContentInspector::absolutize($html, (string) (config('newsletter.branding.url') ?: config('app.url')));
        }

        $text = $explicitText === null ? null : trim($explicitText."\n\n".implode("\n\n", $blockTexts));
        $from = config('newsletter.mail.from', []);

        $snapshot = [
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

        return $snapshot + $this->freezeLayouts($snapshot);
    }

    /**
     * Render the HTML and text layouts once per locale, with unique markers in
     * place of every per-recipient value. Deliveries only substitute values into
     * this output, so deploying new templates never changes a campaign that is
     * already sending.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array{marker: string, default_locale: string, shells: array<string, array{html: string, text: string}>}
     */
    private function freezeLayouts(array $snapshot): array
    {
        $marker = 'nlx'.bin2hex(random_bytes(6));
        $m = fn (string $slot) => $marker.$slot;

        $placeholder = new Recipient(
            key: $m('key'),
            email: $m('email'),
            name: $m('name'),
            locale: $m('locale'),
            firstNameOverride: $m('firstname'),
        );

        $viewData = [
            'subject' => $m('subject'),
            'previewText' => filled($snapshot['preview_text']) ? $m('preview') : '',
            'branding' => $snapshot['branding'],
            'unsubscribeUrl' => $m('unsubscribeurl'),
            'preferencesUrl' => $m('preferencesurl'),
            'recipient' => $placeholder,
            'data' => $snapshot['data'],
        ];

        $default = app()->getLocale();
        $shells = [];

        foreach ($this->locales($snapshot['audience'], $default) as $locale) {
            $shells[$locale] = $this->withLocale($locale, fn () => [
                'html' => $this->views->make($snapshot['layout'], $viewData + ['content' => new HtmlString($m('content'))])->render(),
                'text' => trim($this->views->make($snapshot['text_layout'], $viewData + ['content' => $m('content')])->render()),
            ]);

            $this->assertShell($shells[$locale], $marker);
        }

        return ['marker' => $marker, 'default_locale' => $default, 'shells' => $shells];
    }

    /** @return list<string> */
    private function locales(string $audience, string $default): array
    {
        $fromSubscribers = Subscription::query()
            ->forAudience($audience)
            ->whereNotNull('locale')
            ->distinct()
            ->limit(self::MAX_LOCALES)
            ->pluck('locale')
            ->all();

        $locales = [];
        $translator = app('translator');

        foreach (array_merge([$default, (string) config('app.fallback_locale')], (array) config('newsletter.content.locales', []), $fromSubscribers) as $locale) {
            if (! is_string($locale) || $locale === '') {
                continue;
            }

            // Laravel's translator has no language fallback (de_AT → en, not de). A regional
            // locale without its own translations therefore uses its language's shell.
            $language = strtok(str_replace('-', '_', $locale), '_');
            if ($language !== $locale && ! $translator->hasForLocale('newsletter::messages.footer.unsubscribe', $locale)) {
                $locale = $language;
            }

            $locales[$locale] = true;
        }

        return array_slice(array_keys($locales), 0, self::MAX_LOCALES);
    }

    /** @param  array{html: string, text: string}  $shell */
    private function assertShell(array $shell, string $marker): void
    {
        foreach (['html' => 'HTML', 'text' => 'text'] as $part => $label) {
            if (! str_contains($shell[$part], $marker.'content')) {
                throw new CampaignException("The email layout must output \$content in the {$label} part.");
            }

            if (config('newsletter.content.require_unsubscribe_link', true) && ! str_contains($shell[$part], $marker.'unsubscribeurl')) {
                throw new CampaignException("The email layout must render the unsubscribe link (\$unsubscribeUrl) in the {$label} part.");
            }
        }

        // A layout that transforms a per-recipient value (Str::upper($subject), substr($recipient->name …))
        // would leave a mangled marker behind: refuse rather than send broken output.
        $probe = strtr($shell['html'].$shell['text'], array_fill_keys(array_map(fn ($s) => $marker.$s, self::SLOTS), ''));

        if (stripos($probe, $marker) !== false || stripos($probe, substr($marker, 3)) !== false) {
            throw new CampaignException('The email layout changes per-recipient values (e.g. Str::upper($subject)). Print $subject, $previewText, $unsubscribeUrl, $preferencesUrl and $recipient fields unchanged; use {{ token|fallback }} in the content for conditional text.');
        }
    }

    /** @param  array<string, mixed>  $snapshot */
    public function render(array $snapshot, Recipient $recipient, ?string $deliveryUuid = null): RenderedMessage
    {
        $audience = (string) $snapshot['audience'];
        $links = $this->links($audience, $recipient->email, $deliveryUuid);
        $branding = (array) ($snapshot['branding'] ?? []);

        if (config('newsletter.content.require_unsubscribe_link', true) && $links['unsubscribe'] === null) {
            throw new CampaignException('Newsletter routes are disabled, so no unsubscribe link can be generated.');
        }

        $values = array_merge(
            (array) ($snapshot['data'] ?? []),
            $recipient->personalization(),
            [
                'unsubscribe_url' => $links['unsubscribe'],
                'preferences_url' => $links['preferences'],
                'brand_name' => $branding['name'] ?? null,
            ],
        );

        $subject = Personalizer::text((string) $snapshot['subject'], $values);
        $preview = Personalizer::text((string) ($snapshot['preview_text'] ?? ''), $values);
        $body = Personalizer::html((string) $snapshot['html'], $values);
        $text = $snapshot['text'] !== null
            ? Personalizer::text((string) $snapshot['text'], $values)
            : HtmlToText::convert($body);

        if (isset($snapshot['shells'], $snapshot['marker'])) {
            [$html, $textOut] = $this->fillShell($snapshot, $recipient, $links, $subject, $preview, $body, $text);
        } else {
            // Snapshots from before v2 still render their layout by name.
            [$html, $textOut] = $this->renderLayouts($snapshot, $recipient, $links, $subject, $preview, $body, $text);
        }

        return new RenderedMessage(
            subject: $subject,
            html: $html,
            text: trim($textOut),
            unsubscribeUrl: $links['unsubscribe'],
            oneClickUrl: $links['one_click'],
            preferencesUrl: $links['preferences'],
            unsubscribeToken: $links['token'],
        );
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array{unsubscribe: ?string, one_click: ?string, preferences: ?string, token: ?string}  $links
     * @return array{0: string, 1: string}
     */
    private function fillShell(array $snapshot, Recipient $recipient, array $links, string $subject, string $preview, string $body, string $text): array
    {
        $shell = $this->shellFor($snapshot, $recipient->locale);
        $marker = (string) $snapshot['marker'];

        $raw = [
            'subject' => $subject,
            'preview' => $preview,
            'unsubscribeurl' => (string) $links['unsubscribe'],
            'preferencesurl' => (string) $links['preferences'],
            'email' => $recipient->email,
            'name' => (string) $recipient->name,
            'firstname' => (string) $recipient->firstName(),
            'locale' => (string) $recipient->locale,
            'key' => $recipient->key,
        ];

        $htmlMap = $textMap = [];
        foreach ($raw as $slot => $value) {
            $htmlMap[$marker.$slot] = e($value);
            $textMap[$marker.$slot] = $value;
        }
        $htmlMap[$marker.'content'] = $body;
        $textMap[$marker.'content'] = $text;

        // strtr() replaces in a single pass, so recipient data can never inject a marker.
        return [strtr($shell['html'], $htmlMap), strtr($shell['text'], $textMap)];
    }

    /**
     * Exact locale, then its language ("de" for "de_AT"), then the default.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array{html: string, text: string}
     */
    private function shellFor(array $snapshot, ?string $locale): array
    {
        $shells = (array) $snapshot['shells'];

        foreach (array_filter([$locale, $locale ? strtok(str_replace('-', '_', $locale), '_') : null, $snapshot['default_locale'] ?? null]) as $candidate) {
            if (isset($shells[$candidate])) {
                return $shells[$candidate];
            }
        }

        return reset($shells);
    }

    /**
     * Legacy (v1) path: render the layout views at send time.
     *
     * @param  array<string, mixed>  $snapshot
     * @param  array{unsubscribe: ?string, one_click: ?string, preferences: ?string, token: ?string}  $links
     * @return array{0: string, 1: string}
     */
    private function renderLayouts(array $snapshot, Recipient $recipient, array $links, string $subject, string $preview, string $body, string $text): array
    {
        return $this->withLocale($recipient->locale, function () use ($snapshot, $recipient, $links, $subject, $preview, $body, $text) {
            $viewData = [
                'subject' => $subject,
                'previewText' => $preview,
                'branding' => (array) ($snapshot['branding'] ?? []),
                'unsubscribeUrl' => $links['unsubscribe'],
                'preferencesUrl' => $links['preferences'],
                'recipient' => $recipient,
                'data' => (array) ($snapshot['data'] ?? []),
            ];

            return [
                $this->views->make($snapshot['layout'], $viewData + ['content' => new HtmlString($body)])->render(),
                $this->views->make($snapshot['text_layout'], $viewData + ['content' => $text])->render(),
            ];
        });
    }

    /** A placeholder recipient for previews and validation. */
    public function sampleRecipient(?string $email = null, ?string $name = null): Recipient
    {
        return new Recipient('preview', $email ?? 'preview@example.com', $name ?? 'Alex Example');
    }

    /** @return array{unsubscribe: ?string, one_click: ?string, preferences: ?string, token: ?string} */
    public function links(string $audience, string $email, ?string $deliveryUuid = null): array
    {
        if (! Route::has('newsletter.unsubscribe')) {
            return ['unsubscribe' => null, 'one_click' => null, 'preferences' => null, 'token' => null];
        }

        $token = LinkToken::unsubscribe($audience, $email, $deliveryUuid);

        return [
            'unsubscribe' => route('newsletter.unsubscribe', ['token' => $token]),
            'one_click' => route('newsletter.unsubscribe.one-click', ['token' => $token]),
            'preferences' => route('newsletter.preferences', ['token' => LinkToken::preferences($email)]),
            'token' => $token,
        ];
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
