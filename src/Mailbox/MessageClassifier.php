<?php

namespace JensS\Newsletter\Mailbox;

use JensS\Newsletter\Support\Email;
use JensS\Newsletter\Support\SmtpErrorClassifier;

/**
 * Recognises what arrives in the bounce mailbox:
 * - delivery status notifications (RFC 3464) and common non-standard bounces;
 * - spam complaints in Abuse Reporting Format (RFC 5965);
 * - unsubscribe requests sent to the List-Unsubscribe mailto address;
 * - auto-replies (ignored).
 */
final class MessageClassifier
{
    private const UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    private const BOUNCE_SUBJECT = '/(undeliver|undelivered|delivery (status )?notification|delivery fail|failure notice|returned mail|mail delivery (failed|system)|unzustellbar|nicht zustellbar|zustellung fehlgeschlagen|unzustellbare|non remis|no se puede entregar)/i';

    private const AUTO_REPLY_SUBJECT = '/^(auto(matic)?[ :-]*(reply|response|antwort)|out of (the )?office|abwesen|automatische antwort|urlaub|vacation|absence)/i';

    public function classify(MimePart $message): MailboxEvent
    {
        $subject = (string) $message->header('subject');
        $reportType = strtolower($message->params['report-type'] ?? '');

        if ($reportType === 'feedback-report' || $message->find('message/feedback-report')) {
            return $this->complaint($message);
        }

        if ($reportType === 'delivery-status' || $message->find('message/delivery-status')) {
            return $this->dsn($message);
        }

        if (preg_match('/^\s*unsubscribe\s+([A-Za-z0-9_-]+\.[A-Za-z0-9_-]+)\s*$/i', $subject, $m)) {
            return new MailboxEvent(MailboxEvent::UNSUBSCRIBE, token: $m[1]);
        }

        if ($this->looksLikeBounce($message, $subject)) {
            return $this->heuristicBounce($message);
        }

        if ($this->isAutoReply($message, $subject)) {
            return new MailboxEvent(MailboxEvent::IGNORE, details: 'auto-reply');
        }

        return new MailboxEvent(MailboxEvent::UNKNOWN);
    }

    private function dsn(MimePart $message): MailboxEvent
    {
        $status = $message->find('message/delivery-status');
        $groups = $status ? $this->fieldGroups($status->body) : [];
        array_shift($groups); // per-message fields
        [$uuid, $messageId] = $this->originalIds($message);

        foreach ($groups as $fields) {
            $action = strtolower($fields['action'] ?? '');

            if (! in_array($action, ['failed', 'delayed'], true)) {
                continue;
            }

            $code = $fields['status'] ?? null;
            $diagnostic = $fields['diagnostic-code'] ?? null;
            $email = $this->address($fields['final-recipient'] ?? $fields['original-recipient'] ?? null);
            $permanent = $action === 'failed' && ($code === null || str_starts_with($code, '5'))
                && ! $this->isSenderProblem($code, (string) $diagnostic);

            return new MailboxEvent(
                MailboxEvent::BOUNCE,
                email: $email,
                permanent: $permanent,
                status: $code,
                details: trim(($code ? $code.' ' : '').preg_replace('/^\s*smtp;\s*/i', '', (string) $diagnostic)) ?: null,
                deliveryUuid: $uuid,
                originalMessageId: $messageId,
            );
        }

        // Only "delivered", "relayed" or "expanded" notifications.
        return new MailboxEvent(MailboxEvent::IGNORE, details: 'positive delivery notification');
    }

    private function complaint(MimePart $message): MailboxEvent
    {
        $report = $message->find('message/feedback-report');
        $fields = $report ? ($this->fieldGroups($report->body)[0] ?? []) : [];
        [$uuid, $messageId] = $this->originalIds($message);
        $original = $this->originalMessage($message);

        $email = $this->address($fields['original-rcpt-to'] ?? null)
            ?? $this->address($original?->header('to'));

        return new MailboxEvent(
            MailboxEvent::COMPLAINT,
            email: $email,
            details: 'Feedback-Type: '.($fields['feedback-type'] ?? 'abuse').(isset($fields['user-agent']) ? ' ('.$fields['user-agent'].')' : ''),
            deliveryUuid: $uuid,
            originalMessageId: $messageId,
        );
    }

    private function heuristicBounce(MimePart $message): MailboxEvent
    {
        $text = $message->text();
        [$uuid, $messageId] = $this->originalIds($message);
        $code = SmtpErrorClassifier::enhancedStatus($text);
        $basic = preg_match('/\b([45]\d\d)[ -]/', $text, $m) ? (int) $m[1] : null;

        $permanent = $code !== null ? str_starts_with($code, '5') : ($basic !== null && $basic >= 500);
        $line = $this->diagnosticLine($text);

        if ($permanent && $this->isSenderProblem($code, $line ?? '')) {
            $permanent = false;
        }

        return new MailboxEvent(
            MailboxEvent::BOUNCE,
            email: $this->guessRecipient($text),
            permanent: $permanent,
            status: $code ?? ($basic ? (string) $basic : null),
            details: $line,
            deliveryUuid: $uuid,
            originalMessageId: $messageId,
        );
    }

    /**
     * A rejection because of *our* reputation or policy is not the
     * recipient's fault: never suppress the address for it.
     */
    private function isSenderProblem(?string $code, string $diagnostic): bool
    {
        if ($code !== null && preg_match('/^5\.7\.(1|23|25|26|27|28)$/', $code) && preg_match('/(spam|block|reputation|policy|spf|dkim|dmarc|ptr|reverse|listed|unsolicited|authenticat)/i', $diagnostic)) {
            return true;
        }

        return (bool) preg_match('/(blocked using|listed (at|on|in)|spamhaus|barracuda|sorbs|spamcop|rbl|reputation)/i', $diagnostic);
    }

    private function looksLikeBounce(MimePart $message, string $subject): bool
    {
        $from = strtolower((string) $message->header('from'));

        return str_contains($from, 'mailer-daemon') || str_contains($from, 'postmaster')
            || preg_match(self::BOUNCE_SUBJECT, $subject) === 1;
    }

    private function isAutoReply(MimePart $message, string $subject): bool
    {
        $auto = strtolower((string) $message->header('auto-submitted'));

        return ($auto !== '' && $auto !== 'no')
            || $message->header('x-autoreply') !== null
            || $message->header('x-autorespond') !== null
            || in_array(strtolower((string) $message->header('precedence')), ['auto_reply', 'auto-reply'], true)
            || preg_match(self::AUTO_REPLY_SUBJECT, $subject) === 1;
    }

    /** @return array{0: ?string, 1: ?string} delivery uuid and Message-ID of the returned original */
    private function originalIds(MimePart $message): array
    {
        $original = $this->originalMessage($message);
        $text = $original ? $this->headerText($original) : '';
        $text .= "\n".$message->text();

        $uuid = preg_match('/^X-Newsletter-Delivery:\s*('.self::UUID.')/im', $text, $m) ? strtolower($m[1]) : null;
        $messageId = null;

        if ($original && ($id = $original->header('message-id'))) {
            $messageId = trim($id, '<> ');
        } elseif (preg_match('/^Message-ID:\s*<([^>]+)>/im', $message->text(), $m)) {
            $messageId = $m[1];
        }

        // Our Message-IDs are "<delivery-uuid>@<domain>".
        if ($uuid === null && $messageId !== null && preg_match('/^('.self::UUID.')@/i', $messageId, $m)) {
            $uuid = strtolower($m[1]);
        }

        return [$uuid, $messageId];
    }

    private function originalMessage(MimePart $message): ?MimePart
    {
        foreach ($message->flatten() as $part) {
            if (in_array($part->contentType, ['message/rfc822', 'message/global'], true) && $part->parts !== []) {
                return $part->parts[0];
            }

            if (in_array($part->contentType, ['text/rfc822-headers', 'message/rfc822-headers', 'text/rfc822-header'], true)) {
                $headersOnly = new MimePart;
                $headersOnly->headers = MimePart::parseHeaders($part->body);

                return $headersOnly;
            }
        }

        return null;
    }

    private function headerText(MimePart $part): string
    {
        $lines = [];
        foreach ($part->headers as $name => $values) {
            foreach ($values as $value) {
                $lines[] = $name.': '.$value;
            }
        }

        return implode("\n", $lines);
    }

    /** @return list<array<string, string>> */
    private function fieldGroups(string $body): array
    {
        $groups = [];
        foreach (preg_split("/\n\s*\n/", trim(str_replace("\r", '', $body))) ?: [] as $block) {
            $fields = [];
            foreach (MimePart::parseHeaders($block) as $name => $values) {
                $fields[$name] = $values[0];
            }
            if ($fields !== []) {
                $groups[] = $fields;
            }
        }

        return $groups;
    }

    private function address(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = preg_replace('/^\s*rfc822\s*;\s*/i', '', $value) ?? $value;

        if (preg_match('/<([^>]+@[^>]+)>/', $value, $m)) {
            $value = $m[1];
        }

        $value = trim($value, " <>\t\"'");

        return Email::isValid($value) ? Email::normalize($value) : null;
    }

    private function guessRecipient(string $text): ?string
    {
        foreach (['/Final-Recipient:\s*(?:rfc822;)?\s*(\S+@\S+)/i', '/Original-Recipient:\s*(?:rfc822;)?\s*(\S+@\S+)/i', '/^\s*<(\S+@\S+)>:\s*$/m', '/^\s*To:\s*.*?<?([^\s<>]+@[^\s<>]+)>?\s*$/mi'] as $pattern) {
            if (preg_match($pattern, $text, $m) && ($email = $this->address($m[1]))) {
                return $email;
            }
        }

        return null;
    }

    private function diagnosticLine(string $text): ?string
    {
        if (preg_match('/^.*\b[45]\d\d[ -][^\n]*$/m', $text, $m)) {
            return mb_substr(trim($m[0]), 0, 500);
        }

        return null;
    }
}
