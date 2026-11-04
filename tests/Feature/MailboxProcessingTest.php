<?php

namespace JensS\Newsletter\Tests\Feature;

use JensS\Newsletter\Enums\DeliveryStatus;
use JensS\Newsletter\Enums\SubscriptionStatus;
use JensS\Newsletter\Facades\Newsletter;
use JensS\Newsletter\Mailbox\ImapClient;
use JensS\Newsletter\Mailbox\MailboxProcessor;
use JensS\Newsletter\Models\Subscription;
use JensS\Newsletter\Models\Suppression;
use JensS\Newsletter\Tests\Fixtures\MailboxMessages;
use JensS\Newsletter\Tests\Fixtures\ScriptedImapStream;
use JensS\Newsletter\Tests\TestCase;

class MailboxProcessingTest extends TestCase
{
    private ScriptedImapStream $server;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'newsletter.mailbox.enabled' => true,
            'newsletter.mailbox.host' => 'mx2f80.netcup.net',
            'newsletter.mailbox.username' => 'newsletter@example.org',
            'newsletter.mailbox.password' => 'secret',
            'newsletter.mail.unsubscribe_mailto' => 'newsletter@example.org',
        ]);

        $this->server = new ScriptedImapStream;
        $this->app->resolving(MailboxProcessor::class, fn (MailboxProcessor $p) => $p->using(function () {
            $client = new ImapClient($this->server);
            $client->greeting();
            $client->login('newsletter@example.org', 'secret');

            return $client;
        }));
    }

    private function sendTo(array $emails): \JensS\Newsletter\Models\Campaign
    {
        foreach ($emails as $email) {
            Newsletter::subscribe('newsletter', $email, ['confirmed' => true]);
        }

        $campaign = $this->campaign();
        Newsletter::send($campaign);
        $this->work();

        return $campaign;
    }

    public function test_bounces_complaints_and_mailto_unsubscribes_are_applied(): void
    {
        $campaign = $this->sendTo(['gone@gmail.com', 'slow@example.net', 'angry@hotmail.com', 'leaver@example.net', 'happy@example.net']);
        $uuid = fn (string $email) => $this->delivery($campaign, $email)->uuid;

        $leaverMessage = collect($this->driver->sent)->first(fn ($m) => $m->recipientEmail === 'leaver@example.net');
        preg_match('/subject=unsubscribe%20([^>]+)>/', $leaverMessage->headerLines()['List-Unsubscribe'], $m);
        $token = rawurldecode($m[1]);

        $hard = $this->server->add(MailboxMessages::postfixHardBounce('gone@gmail.com', $uuid('gone@gmail.com')));
        $this->server->add(MailboxMessages::delayed('slow@example.net', $uuid('slow@example.net')));
        $this->server->add(MailboxMessages::complaint('angry@hotmail.com', $uuid('angry@hotmail.com')));
        $this->server->add(MailboxMessages::unsubscribe($token, 'leaver@example.net'));
        $this->server->add(MailboxMessages::autoReply());
        $human = $this->server->add(MailboxMessages::human());

        $this->artisan('newsletter:process-mailbox')->assertSuccessful();

        $this->assertSame(DeliveryStatus::Bounced, $this->delivery($campaign, 'gone@gmail.com')->status);
        $this->assertSame(DeliveryStatus::Accepted, $this->delivery($campaign, 'slow@example.net')->status);
        $this->assertSame('transient', $this->delivery($campaign, 'slow@example.net')->bounce_type);
        $this->assertSame(DeliveryStatus::Complained, $this->delivery($campaign, 'angry@hotmail.com')->status);

        $this->assertEqualsCanonicalizing(['gone@gmail.com', 'angry@hotmail.com'], Suppression::query()->pluck('email')->all());
        $this->assertSame(SubscriptionStatus::Unsubscribed, Subscription::query()->where('email', 'leaver@example.net')->sole()->status);
        $this->assertSame('mailto', Subscription::query()->where('email', 'leaver@example.net')->sole()->unsubscribe_source);
        $this->assertTrue(Newsletter::isSubscribed('newsletter', 'happy@example.net'));

        $this->assertContains('\\Seen', $this->server->messages[$hard]['flags']);
        $this->assertNotContains('\\Seen', $this->server->messages[$human]['flags'], 'human mail is left untouched');
    }

    public function test_messages_are_processed_only_once(): void
    {
        $campaign = $this->sendTo(['gone@gmail.com']);
        $this->server->add(MailboxMessages::postfixHardBounce('gone@gmail.com', $this->delivery($campaign, 'gone@gmail.com')->uuid));
        $this->server->add(MailboxMessages::human());

        $processor = app(MailboxProcessor::class);
        $this->assertSame(2, $processor->run()['checked']);
        $this->assertSame(0, $processor->run()['checked'], 'neither the handled nor the unknown message is read again');

        $this->server->add(MailboxMessages::autoReply());
        $this->assertSame(1, $processor->run()['checked']);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $campaign = $this->sendTo(['gone@gmail.com']);
        $uid = $this->server->add(MailboxMessages::postfixHardBounce('gone@gmail.com', $this->delivery($campaign, 'gone@gmail.com')->uuid));

        $this->artisan('newsletter:process-mailbox', ['--dry-run' => true])->expectsOutputToContain('bounces gone@gmail.com')->assertSuccessful();

        $this->assertSame(DeliveryStatus::Accepted, $this->delivery($campaign, 'gone@gmail.com')->status);
        $this->assertSame([], $this->server->messages[$uid]['flags']);
    }

    public function test_handled_messages_can_be_moved_to_a_folder(): void
    {
        config(['newsletter.mailbox.processed_folder' => 'Newsletter-Processed']);
        $uid = $this->server->add(MailboxMessages::autoReply());

        app(MailboxProcessor::class)->run();

        $this->assertSame('Newsletter-Processed', $this->server->messages[$uid]['folder']);
    }

    public function test_bounces_of_confirmation_mails_suppress_by_address(): void
    {
        $this->server->add(MailboxMessages::postfixHardBounce('typo@gmial.com', '00000000-0000-4000-8000-000000000000'));

        app(MailboxProcessor::class)->run();

        $this->assertTrue(Suppression::isSuppressed('typo@gmial.com'));
    }

    public function test_maintenance_checks_the_mailbox_on_its_interval(): void
    {
        $this->server->add(MailboxMessages::autoReply());

        $this->artisan('newsletter:maintain')->assertSuccessful();
        $this->server->add(MailboxMessages::autoReply());
        $this->artisan('newsletter:maintain')->assertSuccessful();

        $this->assertSame(1, collect($this->server->commands)->filter(fn ($c) => str_starts_with($c, 'SELECT'))->count());

        $this->travel(11)->minutes();
        $this->artisan('newsletter:maintain')->assertSuccessful();
        $this->assertSame(2, collect($this->server->commands)->filter(fn ($c) => str_starts_with($c, 'SELECT'))->count());
    }
}
