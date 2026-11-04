<?php

namespace JensS\Newsletter\Tests\Feature;

use Illuminate\Support\Facades\DB;
use JensS\Newsletter\Contracts\RecipientProvider;
use JensS\Newsletter\Enums\CampaignStatus;
use JensS\Newsletter\Enums\DeliveryStatus;
use JensS\Newsletter\Exceptions\CampaignException;
use JensS\Newsletter\Exceptions\PermanentDeliveryException;
use JensS\Newsletter\Exceptions\TemporaryDeliveryException;
use JensS\Newsletter\Facades\Newsletter;
use JensS\Newsletter\Jobs\SendCampaignDelivery;
use JensS\Newsletter\Models\Campaign;
use JensS\Newsletter\Models\Delivery;
use JensS\Newsletter\Sending\CampaignDispatcher;
use JensS\Newsletter\Support\Recipient;
use JensS\Newsletter\Support\RecipientPage;
use JensS\Newsletter\Tests\TestCase;

class ReliabilityTest extends TestCase
{
    private function prepared(int $subscribers = 1): Campaign
    {
        for ($i = 1; $i <= $subscribers; $i++) {
            Newsletter::subscribe('newsletter', "r{$i}@example.test", ['confirmed' => true]);
        }

        $campaign = $this->campaign();
        Newsletter::send($campaign);
        DB::table('jobs')->delete(); // drop the preparation job; prepare inline instead
        app(CampaignDispatcher::class)->prepareNextPage($campaign->fresh());

        return $campaign->fresh();
    }

    public function test_temporary_failures_are_retried_with_backoff(): void
    {
        config(['newsletter.queue.backoff' => [60]]);
        $campaign = $this->prepared();
        $this->driver->failures = [new TemporaryDeliveryException('451 try again later')];

        $this->work();

        $delivery = $campaign->deliveries()->sole();
        $this->assertSame(DeliveryStatus::Queued, $delivery->status);
        $this->assertSame('451 try again later', $delivery->last_error);
        $this->assertSame(1, DB::table('jobs')->count(), 'job released for a later retry');
        $this->assertSame(CampaignStatus::Sending, $campaign->fresh()->status);

        $this->work(); // not yet due
        $this->assertCount(0, $this->driver->sent);

        $this->travel(61)->seconds();
        $this->work();

        $delivery->refresh();
        $this->assertSame(DeliveryStatus::Accepted, $delivery->status);
        $this->assertSame(2, $delivery->attempts);
        $this->assertNull($delivery->last_error);
        $this->assertCount(1, $this->driver->sent);
        $this->assertSame(CampaignStatus::Sent, $campaign->fresh()->status);
    }

    public function test_unknown_exceptions_are_treated_as_temporary(): void
    {
        config(['newsletter.queue.backoff' => [1]]);
        $campaign = $this->prepared();
        $this->driver->failures = [new \RuntimeException('connection reset')];

        $this->work();
        $this->assertSame(DeliveryStatus::Queued, $campaign->deliveries()->sole()->status);

        $this->travel(2)->seconds();
        $this->work();
        $this->assertSame(DeliveryStatus::Accepted, $campaign->deliveries()->sole()->status);
    }

    public function test_retries_are_exhausted_after_the_configured_number_of_failures(): void
    {
        config(['newsletter.queue.tries' => 3, 'newsletter.queue.backoff' => [1]]);
        $campaign = $this->prepared();
        $this->driver->failures = array_fill(0, 5, new TemporaryDeliveryException('still down'));

        for ($i = 0; $i < 4; $i++) {
            $this->work();
            $this->travel(2)->seconds();
        }

        $delivery = $campaign->deliveries()->sole();
        $this->assertSame(DeliveryStatus::Failed, $delivery->status);
        $this->assertSame(3, $delivery->attempts);
        $this->assertNotNull($delivery->failed_at);
        $this->assertCount(0, $this->driver->sent);
        $this->assertSame(CampaignStatus::Sent, $campaign->fresh()->status, 'campaign completes even with failures');
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_permanent_failures_are_not_retried(): void
    {
        $campaign = $this->prepared();
        $this->driver->failures = [new PermanentDeliveryException('550 no such user')];

        $this->work();

        $delivery = $campaign->deliveries()->sole();
        $this->assertSame(DeliveryStatus::Failed, $delivery->status);
        $this->assertSame('550 no such user', $delivery->last_error);
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_duplicate_job_dispatch_sends_only_once(): void
    {
        $campaign = $this->prepared();
        $delivery = $campaign->deliveries()->sole();

        SendCampaignDelivery::dispatch($delivery->id);
        SendCampaignDelivery::dispatch($delivery->id);
        $this->assertSame(3, DB::table('jobs')->count());

        $this->work();

        $this->assertCount(1, $this->driver->sent);
        $this->assertSame(1, $delivery->fresh()->attempts);
    }

    public function test_re_running_preparation_creates_no_duplicates(): void
    {
        $campaign = $this->prepared(3);
        $jobs = DB::table('jobs')->count();

        // Simulate a preparation job that died after inserting but before saving its cursor.
        Campaign::query()->whereKey($campaign->id)->update(['prepare_cursor' => null, 'prepared_at' => null]);
        app(CampaignDispatcher::class)->prepareNextPage($campaign->fresh());

        $this->assertSame(3, $campaign->deliveries()->count());
        $this->assertSame($jobs, DB::table('jobs')->count(), 'no second job for already dispatched deliveries');

        $this->work();
        $this->assertCount(3, $this->driver->sent);
    }

    public function test_a_campaign_can_only_be_started_once(): void
    {
        Newsletter::subscribe('newsletter', 'once@example.test', ['confirmed' => true]);
        $campaign = $this->campaign();
        $sameCampaignElsewhere = Campaign::query()->find($campaign->id);

        Newsletter::send($campaign);

        $this->expectException(CampaignException::class);
        Newsletter::send($sameCampaignElsewhere); // stale instance still says "draft"
    }

    public function test_one_copy_per_address_even_if_recipients_share_it(): void
    {
        Newsletter::registerAudience('dupes', new class implements RecipientProvider
        {
            public function page(?string $cursor, int $limit): RecipientPage
            {
                return new RecipientPage([
                    new Recipient('a:1', 'shared@example.test', 'One'),
                    new Recipient('a:2', 'SHARED@example.test', 'Two'),
                    new Recipient('a:3', 'other@example.test', 'Three'),
                ], null);
            }

            public function count(): int
            {
                return 3;
            }

            public function find(string $key): ?Recipient
            {
                return match ($key) {
                    'a:1' => new Recipient('a:1', 'shared@example.test'),
                    'a:2' => new Recipient('a:2', 'shared@example.test'),
                    'a:3' => new Recipient('a:3', 'other@example.test'),
                };
            }
        });

        $campaign = $this->campaign(['audience' => 'dupes']);
        Newsletter::send($campaign);
        $this->work();

        $this->assertSame(2, $campaign->deliveries()->count());
        $this->assertEqualsCanonicalizing(['shared@example.test', 'other@example.test'], $this->driver->recipients());
    }

    public function test_rate_limits_release_jobs_without_counting_as_failures(): void
    {
        config(['newsletter.rate_limit' => ['per_minute' => 2], 'newsletter.queue.tries' => 1]);
        $campaign = $this->prepared(3);

        $this->work();
        $this->assertCount(2, $this->driver->sent);
        $this->assertSame(1, $campaign->progress()->count(DeliveryStatus::Queued));

        $this->travel(61)->seconds();
        $this->work();

        $this->assertCount(3, $this->driver->sent);
        $this->assertSame(CampaignStatus::Sent, $campaign->fresh()->status);
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_interrupted_sends_are_not_retried_without_idempotency(): void
    {
        $campaign = $this->prepared();
        $delivery = $campaign->deliveries()->sole();
        DB::table('jobs')->delete();

        // A worker claimed the delivery and was killed mid-send.
        Delivery::query()->whereKey($delivery->id)->update(['status' => 'sending', 'claimed_at' => now()->subHour()]);

        $this->artisan('newsletter:maintain')->assertSuccessful();

        $delivery->refresh();
        $this->assertSame(DeliveryStatus::Failed, $delivery->status);
        $this->assertStringContainsString('outcome unknown', $delivery->last_error);
        $this->assertSame(CampaignStatus::Sent, $campaign->fresh()->status);
    }

    public function test_interrupted_sends_are_retried_when_the_driver_is_idempotent(): void
    {
        $this->driver->idempotent = true;
        $campaign = $this->prepared();
        $delivery = $campaign->deliveries()->sole();
        DB::table('jobs')->delete();
        Delivery::query()->whereKey($delivery->id)->update(['status' => 'sending', 'claimed_at' => now()->subHour()]);

        $this->artisan('newsletter:maintain')->assertSuccessful();
        $this->work();

        $this->assertSame(DeliveryStatus::Accepted, $delivery->fresh()->status);
        $this->assertCount(1, $this->driver->sent);
    }

    public function test_lost_jobs_are_redispatched(): void
    {
        $campaign = $this->prepared(2);
        DB::table('jobs')->delete(); // e.g. the jobs table was truncated

        $this->travel(3)->hours();
        $this->artisan('newsletter:maintain')->assertSuccessful();
        $this->work();

        $this->assertCount(2, $this->driver->sent);
        $this->assertSame(CampaignStatus::Sent, $campaign->fresh()->status);
    }

    public function test_stalled_preparation_resumes(): void
    {
        config(['newsletter.queue.chunk_size' => 1]);
        Newsletter::subscribe('newsletter', 'p1@example.test', ['confirmed' => true]);
        Newsletter::subscribe('newsletter', 'p2@example.test', ['confirmed' => true]);
        $campaign = $this->campaign();
        Newsletter::send($campaign);
        DB::table('jobs')->delete(); // preparation job lost before running

        $this->travel(15)->minutes();
        $this->artisan('newsletter:maintain')->assertSuccessful();
        $this->work();

        $this->assertCount(2, $this->driver->sent);
        $this->assertSame(CampaignStatus::Sent, $campaign->fresh()->status);
    }
}
