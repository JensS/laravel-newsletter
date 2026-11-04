<?php

namespace JensS\Newsletter\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use JensS\Newsletter\Enums\CampaignStatus;
use JensS\Newsletter\Enums\DeliveryStatus;
use JensS\Newsletter\Events\CampaignCompleted;
use JensS\Newsletter\Events\DeliverySkipped;
use JensS\Newsletter\Exceptions\CampaignException;
use JensS\Newsletter\Facades\Newsletter;
use JensS\Newsletter\Mail\CampaignMessage;
use JensS\Newsletter\Models\Campaign;
use JensS\Newsletter\Models\Subscription;
use JensS\Newsletter\Tests\TestCase;

class CampaignSendingTest extends TestCase
{
    private function subscribers(int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            Newsletter::subscribe('newsletter', "sub{$i}@example.test", ['confirmed' => true, 'name' => "Sub {$i}"]);
        }
    }

    public function test_full_send_creates_one_delivery_per_recipient_and_completes(): void
    {
        Event::fake([CampaignCompleted::class]);
        config(['newsletter.queue.chunk_size' => 2]);
        $this->subscribers(5);
        Newsletter::subscribe('newsletter', 'pending@example.test'); // unconfirmed: excluded

        $campaign = $this->campaign();
        Newsletter::send($campaign);

        $this->assertSame(CampaignStatus::Sending, $campaign->fresh()->status);
        $this->work();

        $campaign->refresh();
        $this->assertSame(CampaignStatus::Sent, $campaign->status);
        $this->assertNotNull($campaign->finished_at);
        $this->assertSame(5, $campaign->recipient_count);
        $this->assertSame(5, $campaign->deliveries()->count());
        $this->assertCount(5, $this->driver->sent);
        $this->assertNotContains('pending@example.test', $this->driver->recipients());

        $progress = $campaign->progress();
        $this->assertSame(5, $progress->count(DeliveryStatus::Accepted));
        $this->assertSame(0, $progress->count(DeliveryStatus::Delivered), 'acceptance is not reported as delivery');
        $this->assertSame(100.0, $progress->percentage());

        $delivery = $campaign->deliveries()->first();
        $this->assertSame('fake', $delivery->provider);
        $this->assertSame('provider-'.$delivery->uuid, $delivery->provider_message_id);
        $this->assertSame(1, $delivery->attempts);

        Event::assertDispatched(CampaignCompleted::class);
    }

    public function test_content_is_frozen_when_sending_starts(): void
    {
        $this->subscribers(1);
        $campaign = $this->campaign();
        Newsletter::send($campaign);

        $campaign->refresh();
        $this->assertTrue($campaign->isFrozen());
        $this->assertSame('Hello {{ first_name|friend }}', $campaign->snapshot['subject']);

        try {
            $campaign->update(['subject' => 'Changed']);
            $this->fail('Frozen campaign was editable');
        } catch (CampaignException) {
        }

        // Even a raw DB change of the live field does not alter what is sent.
        Campaign::query()->whereKey($campaign->id)->update(['content_html' => '<p>tampered</p>']);
        $this->work();

        $this->assertStringContainsString('Spring', $this->driver->sent[0]->rendered->html);
        $this->assertStringNotContainsString('tampered', $this->driver->sent[0]->rendered->html);

        // Non-content fields stay editable.
        $campaign->fresh()->update(['name' => 'Renamed']);
        $this->assertSame('Renamed', $campaign->fresh()->name);
    }

    public function test_messages_are_personalised_and_carry_unsubscribe_headers(): void
    {
        Newsletter::subscribe('newsletter', 'ann@example.test', ['confirmed' => true, 'name' => 'Ann Smith']);
        Newsletter::subscribe('newsletter', 'anon@example.test', ['confirmed' => true]);

        Newsletter::send($this->campaign());
        $this->work();

        [$ann, $anon] = $this->driver->sent;

        $this->assertSame('Hello Ann', $ann->rendered->subject);
        $this->assertSame('Hello friend', $anon->rendered->subject);
        $this->assertStringContainsString('Hi Ann Smith', $ann->rendered->html);
        $this->assertStringContainsString('What is new', $ann->rendered->html, 'preview text');
        $this->assertStringContainsString('Example Street 1', $ann->rendered->html, 'postal address');
        $this->assertStringContainsString('Hi Ann Smith, see the news (https://example.test/news).', $ann->rendered->text);
        $this->assertStringContainsString($ann->rendered->unsubscribeUrl, $ann->rendered->text);

        $headers = $ann->headerLines();
        $this->assertSame('<'.$ann->rendered->oneClickUrl.'>', $headers['List-Unsubscribe']);
        $this->assertSame('List-Unsubscribe=One-Click', $headers['List-Unsubscribe-Post']);
        $this->assertStringContainsString('/one-click', $headers['List-Unsubscribe']);
        $this->assertStringContainsString('newsletter.example.test', $headers['List-Id']);
        $this->assertArrayHasKey('X-Newsletter-Delivery', $headers);
    }

    public function test_mailer_driver_sends_real_mime_through_laravel_mail(): void
    {
        $this->app->forgetInstance(\JensS\Newsletter\Contracts\DeliveryDriver::class);
        $this->app->offsetUnset(\JensS\Newsletter\Contracts\DeliveryDriver::class);
        $this->app->bind(\JensS\Newsletter\Contracts\DeliveryDriver::class, \JensS\Newsletter\Drivers\MailerDriver::class);

        Newsletter::subscribe('newsletter', 'mime@example.test', ['confirmed' => true, 'name' => 'Mia']);
        $campaign = $this->campaign();
        Newsletter::send($campaign);
        $this->work();

        $messages = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);

        $email = $messages[0]->getOriginalMessage();
        $this->assertSame('Hello Mia', $email->getSubject());
        $this->assertStringContainsString('One-Click', $email->getHeaders()->get('List-Unsubscribe-Post')->getBodyAsString());
        $this->assertNotEmpty($email->getTextBody());
        $this->assertNotEmpty($email->getHtmlBody());

        $delivery = $campaign->deliveries()->sole();
        $this->assertSame(DeliveryStatus::Accepted, $delivery->status);
        $this->assertSame('array', $delivery->provider);
        $this->assertStringStartsWith($delivery->uuid.'@', $delivery->message_id);
    }

    public function test_eligibility_is_rechecked_immediately_before_sending(): void
    {
        Event::fake([DeliverySkipped::class]);
        $this->subscribers(3);
        $member = $this->user(['email' => 'm@example.test']);
        $member->subscribeToNewsletter('members');

        $campaign = $this->campaign();
        $memberCampaign = $this->campaign(['audience' => 'members']);
        Newsletter::send($campaign);
        Newsletter::send($memberCampaign);

        // Prepare deliveries only (process the preparation jobs, not the sends).
        app(\JensS\Newsletter\Sending\CampaignDispatcher::class)->prepareNextPage($campaign->fresh());
        app(\JensS\Newsletter\Sending\CampaignDispatcher::class)->prepareNextPage($memberCampaign->fresh());
        $this->assertSame(3, $campaign->deliveries()->where('status', 'queued')->count());

        // Things change while the deliveries wait in the queue.
        Newsletter::unsubscribe('newsletter', 'sub1@example.test');
        Newsletter::suppressions()->add('sub2@example.test', 'manual');
        $member->update(['approved' => false]);

        $this->work();

        $this->assertSame(DeliveryStatus::Skipped, $this->delivery($campaign, 'sub1@example.test')->status);
        $this->assertSame(DeliveryStatus::Suppressed, $this->delivery($campaign, 'sub2@example.test')->status);
        $this->assertSame(DeliveryStatus::Accepted, $this->delivery($campaign, 'sub3@example.test')->status);
        $this->assertSame(DeliveryStatus::Skipped, $this->delivery($memberCampaign, 'm@example.test')->status);
        $this->assertSame(['sub3@example.test'], $this->driver->recipients());
        $this->assertSame(CampaignStatus::Sent, $campaign->fresh()->status);
        $this->assertSame(CampaignStatus::Sent, $memberCampaign->fresh()->status);
    }

    public function test_suppressed_addresses_are_recorded_but_not_sent(): void
    {
        $this->subscribers(2);
        Newsletter::suppressions()->add('sub1@example.test', 'bounce');

        $campaign = $this->campaign();
        Newsletter::send($campaign);
        $this->work();

        $this->assertSame(DeliveryStatus::Suppressed, $this->delivery($campaign, 'sub1@example.test')->status);
        $this->assertSame(['sub2@example.test'], $this->driver->recipients());
    }

    public function test_member_email_changes_reach_queued_deliveries(): void
    {
        $member = $this->user(['email' => 'before@example.test']);
        $member->subscribeToNewsletter('members');
        $campaign = $this->campaign(['audience' => 'members']);
        Newsletter::send($campaign);
        app(\JensS\Newsletter\Sending\CampaignDispatcher::class)->prepareNextPage($campaign->fresh());

        $member->update(['email' => 'after@example.test']);
        $this->work();

        $this->assertSame(['after@example.test'], $this->driver->recipients());
        $this->assertSame('after@example.test', Subscription::query()->sole()->email);
    }

    public function test_application_provided_audience_uses_host_preference_and_honours_opt_outs(): void
    {
        $this->user(['email' => 'yes@example.test', 'weekly_recap' => true]);
        $this->user(['email' => 'no@example.test', 'weekly_recap' => false]);
        $this->user(['email' => 'unapproved@example.test', 'weekly_recap' => true, 'approved' => false]);
        $optedOut = $this->user(['email' => 'optout@example.test', 'weekly_recap' => true]);

        Newsletter::unsubscribe('weekly-recap', 'optout@example.test', 'link');
        $this->assertFalse($optedOut->fresh()->weekly_recap);
        $optedOut->fresh()->update(['weekly_recap' => true]); // host flips it back without clearing the opt-out

        $campaign = $this->campaign(['audience' => 'weekly-recap']);
        Newsletter::send($campaign);
        $this->work();

        $this->assertSame(['yes@example.test'], $this->driver->recipients());

        Newsletter::subscriptions()->clearOptOut('weekly-recap', 'optout@example.test');
        $this->assertTrue(Newsletter::isSubscribed('weekly-recap', 'optout@example.test'));
    }

    public function test_cancel_stops_remaining_deliveries(): void
    {
        $this->subscribers(4);
        $campaign = $this->campaign();
        Newsletter::send($campaign);
        app(\JensS\Newsletter\Sending\CampaignDispatcher::class)->prepareNextPage($campaign->fresh());

        // One delivery goes out before the cancel.
        app(\JensS\Newsletter\Sending\DeliverySender::class)->send($campaign->deliveries()->orderBy('id')->first()->id);

        $cancelled = Newsletter::cancel($campaign->fresh());
        $this->work();

        $this->assertSame(3, $cancelled);
        $this->assertCount(1, $this->driver->sent);
        $this->assertSame(CampaignStatus::Cancelled, $campaign->fresh()->status);
        $this->assertSame(3, $campaign->progress()->count(DeliveryStatus::Cancelled));
        $this->assertSame(1, $campaign->progress()->count(DeliveryStatus::Accepted));
    }

    public function test_scheduled_campaigns_start_when_due(): void
    {
        $this->subscribers(1);
        $campaign = Newsletter::schedule($this->campaign(), now()->addHour());

        $this->artisan('newsletter:maintain')->assertSuccessful();
        $this->assertSame(CampaignStatus::Scheduled, $campaign->fresh()->status);

        // Scheduled campaigns can still be edited.
        $campaign->update(['subject' => 'Updated subject']);

        $this->travel(61)->minutes();
        $this->artisan('newsletter:maintain')->assertSuccessful();
        $this->work();

        $this->assertSame(CampaignStatus::Sent, $campaign->fresh()->status);
        $this->assertSame('Updated subject', $this->driver->sent[0]->rendered->subject);
    }

    public function test_test_email_uses_current_content_and_disables_one_click(): void
    {
        Mail::fake();
        $campaign = $this->campaign();

        $sent = Newsletter::campaigns()->sendTest($campaign, ['editor@example.test', 'invalid'], 'Editor');

        $this->assertSame(1, $sent);
        Mail::assertSent(CampaignMessage::class, function (CampaignMessage $m) {
            return $m->recipientEmail === 'editor@example.test'
                && $m->rendered->subject === '[Test] Hello Editor'
                && ! isset($m->headerLines()['List-Unsubscribe-Post']);
        });
        $this->assertSame(CampaignStatus::Draft, $campaign->fresh()->status);
        $this->assertSame(0, $campaign->deliveries()->count());
    }

    public function test_campaigns_without_content_or_with_a_broken_layout_are_refused(): void
    {
        $this->expectException(CampaignException::class);
        Newsletter::send($this->campaign(['content_html' => '  ']));
    }

    public function test_layout_without_unsubscribe_link_is_refused(): void
    {
        $this->app['view']->addNamespace('broken', __DIR__.'/../Fixtures/views');
        $campaign = $this->campaign(['layout' => 'broken::no-footer']);

        $this->expectException(CampaignException::class);
        $this->expectExceptionMessage('unsubscribe link');
        Newsletter::send($campaign);
    }

    public function test_content_blocks_are_rendered_once_at_freeze_time(): void
    {
        $block = new class implements \JensS\Newsletter\Contracts\ContentBlock
        {
            public int $renders = 0;

            public function label(): string
            {
                return 'Latest posts';
            }

            public function schema(): array
            {
                return [];
            }

            public function render(Campaign $campaign, array $options): \JensS\Newsletter\Support\RenderedBlock
            {
                $this->renders++;

                return new \JensS\Newsletter\Support\RenderedBlock('<ul><li>'.e($options['title']).' #'.$this->renders.'</li></ul>');
            }
        };

        Newsletter::registerBlock('latest-posts', $block);
        $this->subscribers(3);

        $campaign = $this->campaign(['blocks' => [['type' => 'latest-posts', 'data' => ['title' => 'Post']]]]);
        Newsletter::send($campaign);
        $this->work();

        foreach ($this->driver->sent as $message) {
            $this->assertStringContainsString('Post #1', $message->rendered->html);
            $this->assertStringContainsString('- Post #1', $message->rendered->text);
        }

        $this->assertSame(1, $block->renders, 'one render for all recipients');
    }
}
