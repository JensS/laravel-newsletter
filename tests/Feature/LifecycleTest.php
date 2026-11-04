<?php

namespace JensS\Newsletter\Tests\Feature;

use JensS\Newsletter\Enums\DeliveryStatus;
use JensS\Newsletter\Facades\Newsletter;
use JensS\Newsletter\Models\Delivery;
use JensS\Newsletter\Models\Subscription;
use JensS\Newsletter\Sending\CampaignDispatcher;
use JensS\Newsletter\Tests\TestCase;

class LifecycleTest extends TestCase
{
    public function test_email_change_moves_subscriptions(): void
    {
        $user = $this->user(['email' => 'old@example.test']);
        $user->subscribeToNewsletter('members');
        Newsletter::subscribe('newsletter', 'old@example.test', ['confirmed' => true]);

        $user->update(['email' => 'New@Example.test']);

        $this->assertSame(['new@example.test', 'new@example.test'], Subscription::query()->orderBy('id')->pluck('email')->all());
        $this->assertTrue(Newsletter::isSubscribed('newsletter', 'new@example.test'));
    }

    public function test_deleted_members_are_excluded_from_pending_sends_and_erased(): void
    {
        $user = $this->user(['email' => 'gone@example.test']);
        $user->subscribeToNewsletter('members');
        $other = $this->user(['email' => 'stays@example.test']);
        $other->subscribeToNewsletter('members');

        $campaign = $this->campaign(['audience' => 'members']);
        Newsletter::send($campaign);
        app(CampaignDispatcher::class)->prepareNextPage($campaign->fresh());

        $user->forceDelete();
        $this->work();

        $this->assertSame(['stays@example.test'], $this->driver->recipients());
        $this->assertSame(0, Subscription::query()->where('email', 'gone@example.test')->count());

        $erased = Delivery::query()->where('status', DeliveryStatus::Skipped->value)->sole();
        $this->assertStringStartsWith('anon-', $erased->email);
        $this->assertNull($erased->subscribable_id);
        $this->assertNotNull($erased->anonymized_at);
    }

    public function test_soft_deleted_members_are_skipped_at_send_time(): void
    {
        $user = $this->user(['email' => 'soft@example.test']);
        $user->subscribeToNewsletter('members');
        $campaign = $this->campaign(['audience' => 'members']);
        Newsletter::send($campaign);
        app(CampaignDispatcher::class)->prepareNextPage($campaign->fresh());

        $user->delete();
        $this->work();

        $this->assertSame([], $this->driver->recipients());
        $this->assertSame(DeliveryStatus::Skipped, $campaign->deliveries()->sole()->status);
    }

    public function test_forget_can_be_called_explicitly_with_an_email(): void
    {
        Newsletter::subscribe('newsletter', 'manual@example.test', ['confirmed' => true]);
        Newsletter::forgetMember('manual@example.test');

        $this->assertSame(0, Subscription::query()->count());
    }

    public function test_export_contains_consent_history_and_deliveries(): void
    {
        $user = $this->user(['email' => 'export@example.test']);
        $user->subscribeToNewsletter('members', source: 'account-settings');
        $campaign = $this->campaign(['audience' => 'members']);
        Newsletter::send($campaign);
        $this->work();
        Newsletter::suppressions()->add('export@example.test', 'complaint');

        $export = Newsletter::exportMember($user);

        $this->assertSame('members', $export['subscriptions'][0]['audience']);
        $this->assertSame('account-settings', $export['subscriptions'][0]['source']);
        $this->assertNotNull($export['subscriptions'][0]['consent_at']);
        $this->assertSame('accepted', $export['deliveries'][0]['status']);
        $this->assertSame('Spring update', $export['deliveries'][0]['campaign']);
        $this->assertSame('complaint', $export['suppressions'][0]['reason']);
    }

    public function test_prune_applies_retention_rules(): void
    {
        Newsletter::subscribe('newsletter', 'stale@example.test'); // pending, never confirmed
        Newsletter::subscribe('newsletter', 'left@example.test', ['confirmed' => true]);
        Newsletter::unsubscribe('newsletter', 'left@example.test');
        Newsletter::unsubscribe('weekly-recap', 'optout@example.test'); // provider opt-out: must survive

        $this->travel(800)->days();
        $this->artisan('newsletter:prune')->assertSuccessful();

        $this->assertSame(['optout@example.test'], Subscription::query()->pluck('email')->all());
    }
}
