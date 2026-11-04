<?php

namespace JensS\Newsletter\Support;

/**
 * Deliverability checks for campaign content. These are warnings shown before
 * sending (Filament, newsletter:send); they never block a campaign.
 */
final class ContentInspector
{
    private const SHORTENERS = ['bit.ly', 'tinyurl.com', 't.co', 'goo.gl', 'ow.ly', 'is.gd', 'buff.ly', 'cutt.ly', 'rebrand.ly', 'shorturl.at'];

    /** Make relative links and image sources absolute (relative URLs break in mail clients). */
    public static function absolutize(string $html, string $base): string
    {
        $base = rtrim($base, '/');

        return (string) preg_replace_callback(
            '#\b(href|src)\s*=\s*(["\'])(.*?)\2#i',
            function (array $m) use ($base) {
                $url = trim($m[3]);

                if (str_starts_with($url, '//')) {
                    $url = 'https:'.$url;
                } elseif (str_starts_with($url, '/')) {
                    $url = $base.$url;
                } elseif ($url !== '' && ! preg_match('#^([a-z][a-z0-9+.-]*:|\#|\{\{)#i', $url)) {
                    $url = $base.'/'.$url; // "images/a.png"
                }

                return $m[1].'='.$m[2].$url.$m[2];
            },
            $html,
        );
    }

    /**
     * @param  array<string, mixed>  $snapshot  as produced by CampaignRenderer::snapshot()
     * @param  string|null  $renderedHtml  full rendered message (layout included), for the size check
     * @return list<string>
     */
    public static function inspect(array $snapshot, ?string $renderedHtml = null): array
    {
        $warnings = [];
        $html = (string) ($snapshot['html'] ?? '');
        $subject = (string) ($snapshot['subject'] ?? '');
        $maxKb = (int) config('newsletter.content.max_html_kb', 95);

        $size = strlen($renderedHtml ?? $html);
        if ($maxKb > 0 && $size > $maxKb * 1024) {
            $warnings[] = sprintf('The HTML is %d KB. Gmail clips messages above ~102 KB and hides the unsubscribe footer; shorten the content or link to the website.', (int) ceil($size / 1024));
        }

        if (preg_match_all('#\b(?:href|src)\s*=\s*["\']\s*http://#i', $html) > 0) {
            $warnings[] = 'Some links or images use http:// instead of https://.';
        }

        if (preg_match_all('#\b(?:href|src)\s*=\s*["\']\s*(?!https?:|mailto:|tel:|\#|\{\{)[^"\']+#i', $html) > 0 && ! config('newsletter.content.absolute_urls', true)) {
            $warnings[] = 'Some links or images are relative and will not work in email clients.';
        }

        foreach (self::SHORTENERS as $shortener) {
            if (stripos($html, '//'.$shortener.'/') !== false) {
                $warnings[] = "Avoid URL shorteners such as {$shortener}; spam filters treat them as suspicious.";
                break;
            }
        }

        if (preg_match_all('#<img\b(?![^>]*\balt\s*=)[^>]*>#i', $html) > 0) {
            $warnings[] = 'Some images have no alt text. Many clients block images by default.';
        }

        $text = trim(HtmlToText::convert($html));
        $images = preg_match_all('#<img\b#i', $html);
        if ($images > 0 && mb_strlen($text) < 200) {
            $warnings[] = 'The message is mostly images with little text, which spam filters dislike.';
        }

        if (mb_strlen($subject) > 80) {
            $warnings[] = 'The subject is longer than 80 characters and will be cut off in most inboxes.';
        }

        $letters = preg_replace('/[^\p{L}]/u', '', $subject) ?? '';
        if (mb_strlen($letters) >= 10 && mb_strtoupper($letters) === $letters) {
            $warnings[] = 'The subject is written in capital letters.';
        }

        if (preg_match('/[!?]{2,}|€€|\$\$|\bfree\b|\bgratis\b/iu', $subject)) {
            $warnings[] = 'The subject contains patterns spam filters look for (!!, $$, "free", "gratis").';
        }

        if (empty($snapshot['preview_text'])) {
            $warnings[] = 'No preview text: inboxes will show the first words of the body instead.';
        }

        return $warnings;
    }
}
