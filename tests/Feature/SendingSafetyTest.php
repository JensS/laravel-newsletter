<?php

namespace JensS\Newsletter\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use JensS\Newsletter\Contracts\DeliveryDriver;
use JensS\Newsletter\Drivers\MailerDriver;
use JensS\Newsletter\Enums\CampaignStatus;
use JensS\Newsletter\Enums\DeliveryStatus;
use JensS\Newsletter\Exceptions\SystemicDeliveryException;
use JensS\Newsletter\Facades\Newsletter;
use JensS\Newsletter\Jobs\Middleware\ThrottleDeliveries;
use JensS\Newsletter\Models\Campaign;
use JensS\Newsletter\Sending\CampaignDispatcher;
use JensS\Newsletter\Sending\CircuitBreaker;
use JensS\Newsletter\Sending\DeliverySender;
use JensS\Newsletter\Support\ContentInspector;
use JensS\Newsletter\Tests\TestCase;
use JensS\Newsletter\Webhooks\DeliveryEventRecorder;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

class SendingSafetyTest extends TestCase
{
    private function prepared(array $emails): Campaign
    {
        foreach ($emails as $email) {
            Newsletter::subscribe('newsletter', $email, ['confirmed' => true]);
        }

        $campaign = $this->campaign();
        Newsletter::send($campaign);
        DB::table('jobs')->delete();
        app(CampaignDispatcher::class)->prepareNextPage($campaign->fresh());

        return $campaign->fresh();
    }

    public function test_systemic_errors_pause_all_sending_without_failing_deliveries(): void
    {
        config(['newsletter.circuit_breaker.pause_minutes' => [5, 15]]);
        $campaign = $this->prepared(['a@example.test', 'b@example.test', 'c@example.test']);
        $this->driver->failures = [new SystemicDeliveryException('451 4.3.2 Sending quota exceeded')];

        $this->work();

        $breaker = app(CircuitBreaker::class);
        $this->assertTrue($breaker->isOpen());
        $this->assertStringContainsString('quota', $breaker->reason());
        $this->assertCount(0, $this->driver->sent);
        $this->assertSame(3, $campaign->progress()->count(DeliveryStatus::Queued), 'nothing failed');
        $this->assertSame(3, DB::table('jobs')->count(), 'all jobs released, none dropped');
        $this->assertSame(1, $campaign->deliveries()->max('attempts'), 'only one delivery reached the server');

        $this->travel(6)->minutes();
        $this->work();

        $this->assertCount(3, $this->driver->sent);
        $this->assertSame(CampaignStatus::Sent, $campaign->fresh()->status);
        $this->assertFalse($breaker->isOpen());
    }

    public function test_breaker_pauses_escalate_while_the_problem_persists(): void
    {
        config(['newsletter.circuit_breaker.pause_minutes' => [5, 15, 60]]);
        $breaker = app(CircuitBreaker::class);

        $this->assertSame(300, $breaker->trip('quota'));
        $this->assertSame(300, $breaker->trip('quota again'), 'no escalation while already open');
        $this->travel(6)->minutes();
        $this->assertSame(900, $breaker->trip('still quota'));

        $this->travel(16)->minutes();
        $breaker->recordSuccess();
        $this->assertSame(300, $breaker->trip('new problem'), 'success resets the escalation');
    }

    public function test_mailer_driver_maps_smtp_errors(): void
    {
        Mail::extend('broken', fn () => new class extends AbstractTransport
        {
            public static ?TransportException $next = null;

            protected function doSend(SentMessage $message): void
            {
                throw self::$next;
            }

            public function __toString(): string
            {
                return 'broken://';
            }
        });
        config(['mail.mailers.broken' => ['transport' => 'broken'], 'newsletter.mail.mailer' => 'broken']);
        $this->app->bind(DeliveryDriver::class, MailerDriver::class);

        $campaign = $this->prepared(['x@example.test']);
        $transport = Mail::mailer('broken')->getSymfonyTransport();
        $transport::$next = new TransportException('Expected response code "235" but got code "535", with message "535 5.7.8 Error: authentication failed".', 535);

        $this->work();

        $this->assertTrue(app(CircuitBreaker::class)->isOpen());
        $this->assertSame(DeliveryStatus::Queued, $campaign->deliveries()->sole()->status);
    }

    public function test_reputation_guard_pauses_and_resume_continues(): void
    {
        config(['newsletter.guard.min_sample' => 4, 'newsletter.guard.max_bounce_rate' => 0.3]);
        $campaign = $this->prepared(array_map(fn ($i) => "h{$i}@example.test", range(1, 8)));
        $sender = app(DeliverySender::class);
        foreach ($campaign->deliveries()->orderBy('id')->pluck('id')->take(4) as $id) {
            $sender->send($id);
        }

        $recorder = app(DeliveryEventRecorder::class);
        $recorder->record($this->delivery($campaign, 'h1@example.test'), null, DeliveryEventRecorder::BOUNCED, true);
        $this->assertSame(CampaignStatus::Sending, $campaign->fresh()->status, '25 % is below 30 %');

        $recorder->record($this->delivery($campaign, 'h2@example.test'), null, DeliveryEventRecorder::BOUNCED, true);
        $campaign->refresh();
        $this->assertSame(CampaignStatus::Paused, $campaign->status, '50 % is above 30 %');
        $this->assertStringContainsString('Bounce', $campaign->pause_reason);

        // Paused: queued deliveries are not sent.
        $this->work();
        $this->assertCount(4, $this->driver->sent);
        $this->assertSame(4, $campaign->progress()->count(DeliveryStatus::Queued));

        config(['newsletter.guard.max_bounce_rate' => 0.9]);
        app(CampaignDispatcher::class)->resume($campaign);
        $this->work();

        $this->assertCount(8, $this->driver->sent);
        $this->assertSame(CampaignStatus::Sent, $campaign->fresh()->status);
    }

    public function test_complaints_pause_at_a_much_lower_rate(): void
    {
        config(['newsletter.guard.min_sample' => 2]);
        $campaign = $this->prepared(['k1@example.test', 'k2@example.test', 'k3@example.test']);
        $sender = app(DeliverySender::class);
        foreach ($campaign->deliveries()->orderBy('id')->pluck('id')->take(2) as $id) {
            $sender->send($id);
        }

        app(DeliveryEventRecorder::class)->record($this->delivery($campaign, 'k1@example.test'), null, DeliveryEventRecorder::COMPLAINED);

        $this->assertSame(CampaignStatus::Paused, $campaign->fresh()->status);
        $this->assertStringContainsString('Complaint', $campaign->fresh()->pause_reason);
    }

    public function test_daily_limit(): void
    {
        config(['newsletter.rate_limit' => ['per_day' => 2]]);
        $campaign = $this->prepared(['d1@example.test', 'd2@example.test', 'd3@example.test']);

        $this->work();
        $this->assertCount(2, $this->driver->sent);

        $this->travel(1)->days();
        $this->travel(1)->minutes();
        $this->work();
        $this->assertCount(3, $this->driver->sent);
        $this->assertSame(CampaignStatus::Sent, $campaign->fresh()->status);
    }

    public function test_long_waits_are_not_mistaken_for_lost_jobs(): void
    {
        config(['newsletter.rate_limit' => ['per_day' => 1]]);
        $this->prepared(['w1@example.test', 'w2@example.test']);

        $this->work();
        $this->assertSame(1, DB::table('jobs')->count(), 'second delivery waits for tomorrow');

        $this->travel(3)->hours();
        $this->artisan('newsletter:maintain')->assertSuccessful();

        $this->assertSame(1, DB::table('jobs')->count(), 'no duplicate job queued');
    }

    public function test_per_provider_limit_groups_sibling_domains(): void
    {
        $this->assertSame('google', ThrottleDeliveries::providerKey('a@GoogleMail.com'));
        $this->assertSame('united-internet', ThrottleDeliveries::providerKey('a@web.de'));
        $this->assertSame('united-internet', ThrottleDeliveries::providerKey('a@gmx.net'));
        $this->assertSame('example.org', ThrottleDeliveries::providerKey('a@example.org'));

        config(['newsletter.rate_limit' => ['per_domain_per_minute' => 1]]);
        $this->prepared(['a@gmail.com', 'b@googlemail.com', 'c@web.de']);

        $this->work();
        $this->assertEqualsCanonicalizing(['a@gmail.com', 'c@web.de'], $this->driver->recipients());

        $this->travel(61)->seconds();
        $this->work();
        $this->assertCount(3, $this->driver->sent);
    }

    public function test_smtp_messages_carry_return_path_feedback_id_and_tokenized_mailto(): void
    {
        config([
            'newsletter.mail.return_path' => 'bounces@example.test',
            'newsletter.mail.unsubscribe_mailto' => 'unsubscribe@example.test',
            'newsletter.mail.feedback_id_sender' => 'Example Club',
        ]);
        $this->app->bind(DeliveryDriver::class, MailerDriver::class);

        $campaign = $this->prepared(['r@example.test']);
        $this->work();

        $sent = app('mailer')->getSymfonyTransport()->messages()[0];
        $this->assertSame('bounces@example.test', $sent->getEnvelope()->getSender()->getAddress());

        $headers = $sent->getOriginalMessage()->getHeaders();
        $this->assertSame('c'.$campaign->id.':newsletter:newsletter:Example-Club', $headers->get('Feedback-ID')->getBodyAsString());
        $this->assertMatchesRegularExpression('#<mailto:unsubscribe@example.test\?subject=unsubscribe%20[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+>#', $headers->get('List-Unsubscribe')->getBodyAsString());
        $this->assertMatchesRegularExpression('#^<https?://[^>]+/one-click>, <mailto:#', $headers->get('List-Unsubscribe')->getBodyAsString());
    }

    public function test_confirmation_mails_use_the_return_path_too(): void
    {
        config(['newsletter.mail.return_path' => 'bounces@example.test']);

        Newsletter::subscribe('newsletter', 'new@example.test');

        $sent = app('mailer')->getSymfonyTransport()->messages()[0];
        $this->assertSame('bounces@example.test', $sent->getEnvelope()->getSender()->getAddress());
    }

    public function test_relative_urls_are_made_absolute_when_freezing(): void
    {
        config(['newsletter.branding.url' => 'https://club.example']);
        $campaign = $this->prepared(['u@example.test']);
        Campaign::query()->whereKey($campaign->id)->update(['status' => 'draft', 'snapshot' => null]);
        $campaign = $this->campaign([
            'content_html' => '<p><a href="/events">Events</a> <img src="img/logo.png" alt="Logo"> <a href="https://x.example/a">x</a> <a href="mailto:a@b.c">m</a> <a href="{{ preferences_url }}">p</a></p>',
        ]);

        $snapshot = app(\JensS\Newsletter\Support\CampaignRenderer::class)->snapshot($campaign);

        $this->assertStringContainsString('href="https://club.example/events"', $snapshot['html']);
        $this->assertStringContainsString('src="https://club.example/img/logo.png"', $snapshot['html']);
        $this->assertStringContainsString('href="https://x.example/a"', $snapshot['html']);
        $this->assertStringContainsString('href="mailto:a@b.c"', $snapshot['html']);
        $this->assertStringContainsString('href="{{ preferences_url }}"', $snapshot['html']);
    }

    public function test_content_inspector_warnings(): void
    {
        $warnings = ContentInspector::inspect([
            'subject' => 'FREE STUFF FOR EVERYONE!!!',
            'preview_text' => null,
            'html' => '<a href="http://bit.ly/x">x</a><img src="https://a/b.png">'.str_repeat('<p>Lorem ipsum</p>', 10000),
        ]);
        $text = implode("\n", $warnings);

        foreach (['Gmail clips', 'http://', 'bit.ly', 'alt text', 'capital letters', 'spam filters look for', 'No preview text'] as $expected) {
            $this->assertStringContainsString($expected, $text);
        }

        $this->assertSame([], ContentInspector::inspect([
            'subject' => 'Our spring meetup',
            'preview_text' => 'Join us on Friday',
            'html' => '<p>'.str_repeat('Plenty of real text here. ', 20).'</p><img src="https://a/b.png" alt="Photo">',
        ]));
    }
}
