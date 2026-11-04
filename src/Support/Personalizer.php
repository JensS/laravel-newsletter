<?php

namespace JensS\Newsletter\Support;

/**
 * Replaces {{ token }} and {{ token|fallback }} placeholders. Values are
 * HTML-escaped for HTML output. Content is never compiled as Blade, so editors
 * cannot execute code through campaign content.
 */
final class Personalizer
{
    private const PATTERN = '/\{\{\s*([a-zA-Z0-9_.]+)\s*(?:\|\s*([^}]*?))?\s*\}\}/';

    /** @param  array<string, scalar|null>  $values */
    public static function html(string $content, array $values): string
    {
        return self::replace($content, $values, fn (string $v) => e($v));
    }

    /** @param  array<string, scalar|null>  $values */
    public static function text(string $content, array $values): string
    {
        return self::replace($content, $values, fn (string $v) => $v);
    }

    /** @return list<string> */
    public static function tokens(string $content): array
    {
        preg_match_all(self::PATTERN, $content, $m);

        return array_values(array_unique($m[1]));
    }

    /** @param  array<string, scalar|null>  $values */
    private static function replace(string $content, array $values, callable $escape): string
    {
        return (string) preg_replace_callback(self::PATTERN, function (array $m) use ($values, $escape) {
            $value = $values[$m[1]] ?? null;

            if ($value === null || $value === '') {
                $value = $m[2] ?? '';
            }

            return $escape((string) $value);
        }, $content);
    }
}
