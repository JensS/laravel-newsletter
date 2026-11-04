<?php

namespace JensS\Newsletter\Tests\Unit;

use JensS\Newsletter\Mailbox\ImapClient;
use JensS\Newsletter\Mailbox\ImapException;
use JensS\Newsletter\Mailbox\MailboxEvent;
use JensS\Newsletter\Mailbox\MessageClassifier;
use JensS\Newsletter\Mailbox\MimePart;
use JensS\Newsletter\Support\SmtpErrorClassifier;
use JensS\Newsletter\Tests\Fixtures\MailboxMessages;
use JensS\Newsletter\Tests\Fixtures\ScriptedImapStream;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MailboxParsingTest extends TestCase
{
    private const UUID = '0b6f5f0e-1c2d-4e5f-8a9b-0c1d2e3f4a5b';

    private function classify(string $raw): MailboxEvent
    {
        return (new MessageClassifier)->classify(MimePart::parse(str_replace("\n", "\r\n", $raw)));
    }

    public function test_postfix_hard_bounce(): void
    {
        $event = $this->classify(MailboxMessages::postfixHardBounce('Gone@Gmail.com', self::UUID));

        $this->assertSame(MailboxEvent::BOUNCE, $event->type);
        $this->assertTrue($event->permanent);
        $this->assertSame('gone@gmail.com', $event->email);
        $this->assertSame('5.1.1', $event->status);
        $this->assertSame(self::UUID, $event->deliveryUuid);
        $this->assertStringContainsString('does not exist', $event->details);
    }

    public function test_delay_notifications_are_soft(): void
    {
        $event = $this->classify(MailboxMessages::delayed('slow@example.net', self::UUID));

        $this->assertSame(MailboxEvent::BOUNCE, $event->type);
        $this->assertFalse($event->permanent);
        $this->assertSame(self::UUID, $event->deliveryUuid, 'found via our Message-ID in the returned message');
    }

    public function test_rejections_because_of_our_reputation_never_suppress_the_recipient(): void
    {
        $event = $this->classify(MailboxMessages::policyRejection('fine@gmail.com'));

        $this->assertSame(MailboxEvent::BOUNCE, $event->type);
        $this->assertFalse($event->permanent);
        $this->assertSame('5.7.26', $event->status);
    }

    public function test_non_standard_qmail_bounce(): void
    {
        $event = $this->classify(MailboxMessages::qmailBounce('nobody@old-provider.example', self::UUID));

        $this->assertSame(MailboxEvent::BOUNCE, $event->type);
        $this->assertTrue($event->permanent);
        $this->assertSame('nobody@old-provider.example', $event->email);
        $this->assertSame(self::UUID, $event->deliveryUuid);
    }

    public function test_base64_encoded_bounce_with_encoded_subject(): void
    {
        $event = $this->classify(MailboxMessages::base64Bounce('closed@web.de'));

        $this->assertSame(MailboxEvent::BOUNCE, $event->type);
        $this->assertTrue($event->permanent);
        $this->assertSame('5.2.1', $event->status);
    }

    public function test_arf_complaint(): void
    {
        $event = $this->classify(MailboxMessages::complaint('angry@hotmail.com', self::UUID));

        $this->assertSame(MailboxEvent::COMPLAINT, $event->type);
        $this->assertSame('angry@hotmail.com', $event->email);
        $this->assertSame(self::UUID, $event->deliveryUuid);
    }

    public function test_auto_replies_unsubscribes_and_human_mail(): void
    {
        $this->assertSame(MailboxEvent::IGNORE, $this->classify(MailboxMessages::autoReply())->type);

        $unsubscribe = $this->classify(MailboxMessages::unsubscribe('abc_DEF-1.sig-2'));
        $this->assertSame(MailboxEvent::UNSUBSCRIBE, $unsubscribe->type);
        $this->assertSame('abc_DEF-1.sig-2', $unsubscribe->token);

        $this->assertSame(MailboxEvent::UNKNOWN, $this->classify(MailboxMessages::human())->type);
    }

    /** @return iterable<string, array{int, string, string}> */
    public static function smtpReplies(): iterable
    {
        yield 'auth failed' => [535, '535 5.7.8 Error: authentication failed', SmtpErrorClassifier::SYSTEMIC];
        yield 'service unavailable' => [421, '421 4.7.0 Too many connections', SmtpErrorClassifier::SYSTEMIC];
        yield 'hourly quota (5xx)' => [550, '550 5.7.1 Sending quota exceeded for this mailbox', SmtpErrorClassifier::SYSTEMIC];
        yield 'rate limit (4xx)' => [451, '451 4.3.2 Rate limit reached, try again later', SmtpErrorClassifier::SYSTEMIC];
        yield 'connection refused' => [0, 'Connection could not be established with host "mx.example": stream_socket_client(): Connection refused', SmtpErrorClassifier::SYSTEMIC];
        yield 'unknown user' => [550, '550 5.1.1 <x@example.com>: Recipient address rejected: User unknown', SmtpErrorClassifier::PERMANENT];
        yield 'mailbox full' => [552, '552 5.2.2 Mailbox full', SmtpErrorClassifier::TEMPORARY];
        yield 'greylisting' => [450, '450 4.2.0 <x@example.com>: Recipient address rejected: Greylisted', SmtpErrorClassifier::TEMPORARY];
        yield 'code in message only' => [0, 'Expected response code "250" but got code "550", with message "550 5.1.1 User unknown".', SmtpErrorClassifier::PERMANENT];
    }

    #[DataProvider('smtpReplies')]
    public function test_smtp_error_classification(int $code, string $message, string $expected): void
    {
        $this->assertSame($expected, SmtpErrorClassifier::classify($code, $message));
    }

    public function test_imap_client_reads_literals_and_flags_messages(): void
    {
        $server = new ScriptedImapStream;
        $uid = $server->add("Subject: Hi\n\nBody with {curly} braces\n");

        $client = new ImapClient($server);
        $client->greeting();
        $client->login('bounce@example.org', 'secret');
        $info = $client->select('INBOX');
        $this->assertSame(['uidvalidity' => 42, 'exists' => 1], $info);
        $this->assertSame([$uid], $client->search('UNSEEN'));

        $raw = $client->fetch($uid);
        $this->assertStringContainsString("Body with {curly} braces", $raw);

        $client->markSeen($uid);
        $this->assertSame([], $client->search('UNSEEN'));
        $this->assertContains('\\Seen', $server->messages[$uid]['flags']);

        $client->move($uid, 'Processed');
        $this->assertSame('Processed', $server->messages[$uid]['folder']);
        $client->logout();
    }

    public function test_imap_client_falls_back_to_copy_and_expunge_and_hides_passwords(): void
    {
        $server = new ScriptedImapStream;
        $server->supportsMove = false;
        $uid = $server->add("Subject: x\n\ny\n");
        $client = new ImapClient($server);
        $client->greeting();
        $client->login('u', 'secret');
        $client->select('INBOX');
        $client->move($uid, 'Done');
        $client->logout();

        $this->assertArrayNotHasKey($uid, $server->messages, 'copied, flagged deleted and expunged');

        $client = new ImapClient(new ScriptedImapStream);
        $client->greeting();

        try {
            $client->login('u', 'wrong-password');
            $this->fail('login should fail');
        } catch (ImapException $e) {
            $this->assertStringNotContainsString('wrong-password', $e->getMessage());
        }
    }
}
