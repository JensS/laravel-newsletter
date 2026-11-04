<?php

namespace JensS\Newsletter\Support;

/** Small, dependency-free HTML to plain-text conversion for the text part. */
final class HtmlToText
{
    public static function convert(string $html): string
    {
        $html = preg_replace('#<(script|style|head)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;

        // Links: "label (url)" unless the label is the url.
        $html = preg_replace_callback('#<a\b[^>]*href=(["\'])(.*?)\1[^>]*>(.*?)</a>#is', function ($m) {
            $url = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5);
            $label = trim(strip_tags($m[3]));

            if ($label === '' || $label === $url || str_starts_with($url, 'mailto:') && substr($url, 7) === $label) {
                return $url;
            }

            return $label.' ('.$url.')';
        }, $html) ?? $html;

        $html = preg_replace('#<h[1-6]\b[^>]*>(.*?)</h[1-6]>#is', "\n\n$1\n\n", $html) ?? $html;
        $html = preg_replace('#<li\b[^>]*>#i', "\n- ", $html) ?? $html;
        $html = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;
        $html = preg_replace('#<hr\b[^>]*>#i', "\n\n----\n\n", $html) ?? $html;
        $html = preg_replace('#</(p|div|tr|table|ul|ol|blockquote|section|article|h[1-6])>#i', "\n\n", $html) ?? $html;
        $html = preg_replace('#</t[dh]>#i', ' ', $html) ?? $html;

        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00A0}", ' ', $text);
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/ *\n */', "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }
}
