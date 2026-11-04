<?php

namespace JensS\Newsletter\Mailbox;

/**
 * Minimal, dependency-free MIME parser: enough to read delivery status
 * notifications (RFC 3464), feedback reports (RFC 5965) and simple mails.
 */
final class MimePart
{
    /** @var array<string, list<string>> lower-cased header name => values */
    public array $headers = [];

    public string $contentType = 'text/plain';

    /** @var array<string, string> */
    public array $params = [];

    /** Decoded body (for leaf parts) or raw body (for multiparts). */
    public string $body = '';

    /** @var list<MimePart> */
    public array $parts = [];

    public static function parse(string $raw, int $depth = 0): self
    {
        $part = new self;
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        [$head, $body] = array_pad(preg_split("/\n\n/", $raw, 2) ?: [], 2, '');

        // A part without headers starts with an empty line.
        if (str_starts_with($raw, "\n")) {
            [$head, $body] = ['', substr($raw, 1)];
        }

        $part->headers = self::parseHeaders($head);
        [$part->contentType, $part->params] = self::parseContentType($part->header('content-type') ?? 'text/plain');

        if ($depth > 8) {
            $part->body = $body;

            return $part;
        }

        if (str_starts_with($part->contentType, 'multipart/') && isset($part->params['boundary'])) {
            $part->body = $body;
            foreach (self::splitMultipart($body, $part->params['boundary']) as $chunk) {
                $part->parts[] = self::parse($chunk, $depth + 1);
            }

            return $part;
        }

        $decoded = self::decodeTransfer($body, strtolower((string) $part->header('content-transfer-encoding')));

        if (in_array($part->contentType, ['message/rfc822', 'message/global'], true)) {
            $part->body = $decoded;
            $part->parts[] = self::parse(ltrim($decoded, "\n"), $depth + 1);

            return $part;
        }

        $charset = strtolower($part->params['charset'] ?? 'utf-8');
        if (str_starts_with($part->contentType, 'text/') && ! in_array($charset, ['utf-8', 'us-ascii', 'utf8'], true)) {
            $converted = @mb_convert_encoding($decoded, 'UTF-8', $charset);
            $decoded = is_string($converted) ? $converted : $decoded;
        }

        $part->body = $decoded;

        return $part;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }

    /** @return list<MimePart> this part and all descendants, depth first */
    public function flatten(): array
    {
        $out = [$this];
        foreach ($this->parts as $child) {
            array_push($out, ...$child->flatten());
        }

        return $out;
    }

    public function find(string $contentType): ?self
    {
        foreach ($this->flatten() as $part) {
            if ($part->contentType === $contentType) {
                return $part;
            }
        }

        return null;
    }

    /** All readable text (headers of embedded messages included) for heuristics. */
    public function text(): string
    {
        $out = '';
        foreach ($this->flatten() as $part) {
            if ($part->parts === []) {
                $out .= "\n".$part->body;
            }
        }

        return $out;
    }

    /** @return array<string, list<string>> */
    public static function parseHeaders(string $head): array
    {
        $headers = [];
        $unfolded = preg_replace("/\n[ \t]+/", ' ', trim($head, "\n")) ?? $head;

        foreach (explode("\n", $unfolded) as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))][] = self::decodeHeader(trim($value));
        }

        return $headers;
    }

    /** @return array{0: string, 1: array<string, string>} */
    private static function parseContentType(string $value): array
    {
        $segments = explode(';', $value);
        $type = strtolower(trim((string) array_shift($segments))) ?: 'text/plain';
        $params = [];

        if (preg_match_all('/([a-z0-9_*-]+)\s*=\s*(?:"((?:[^"\\\\]|\\\\.)*)"|([^;\s]+))/i', $value, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $params[strtolower($match[1])] = $match[2] !== '' ? stripslashes($match[2]) : ($match[3] ?? '');
            }
        }

        return [$type, $params];
    }

    /** @return list<string> */
    private static function splitMultipart(string $body, string $boundary): array
    {
        $b = preg_quote($boundary, '/');

        // Everything after the closing delimiter is epilogue.
        if (preg_match('/^--'.$b.'--[ \t]*$/m', $body, $m, PREG_OFFSET_CAPTURE)) {
            $body = substr($body, 0, (int) $m[0][1]);
        }

        $chunks = preg_split('/^--'.$b.'[ \t]*$/m', $body) ?: [];
        array_shift($chunks); // preamble
        $parts = [];

        foreach ($chunks as $chunk) {
            $chunk = preg_replace('/^\n/', '', $chunk, 1) ?? $chunk;
            $chunk = preg_replace('/\n$/', '', $chunk, 1) ?? $chunk;

            if (trim($chunk) !== '') {
                $parts[] = $chunk;
            }
        }

        return $parts;
    }

    private static function decodeTransfer(string $body, string $encoding): string
    {
        return match ($encoding) {
            'base64' => (string) base64_decode(preg_replace('/\s+/', '', $body) ?? '', false),
            'quoted-printable' => quoted_printable_decode(str_replace("\n", "\r\n", $body)),
            default => $body,
        };
    }

    private static function decodeHeader(string $value): string
    {
        if (! str_contains($value, '=?')) {
            return $value;
        }

        $decoded = function_exists('iconv_mime_decode')
            ? @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8')
            : @mb_decode_mimeheader($value);

        return is_string($decoded) ? $decoded : $value;
    }
}
