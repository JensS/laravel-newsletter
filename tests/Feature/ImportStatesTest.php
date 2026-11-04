<?php

namespace JensS\Newsletter\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use JensS\Newsletter\Enums\SubscriptionStatus;
use JensS\Newsletter\Events\Subscribed;
use JensS\Newsletter\Events\Unsubscribed;
use JensS\Newsletter\Facades\Newsletter;
use JensS\Newsletter\Models\Subscription;
use JensS\Newsletter\Tests\TestCase;

class ImportStatesTest extends TestCase
{
    private function sub(string $email, string $audience = 'newsletter'): Subscription
    {
        return Subscription::query()->forAudience($audience)->where('email', $email)->sole();
    }

    public function test_all_three_states_are_imported_with_their_history(): void
    {
        Mail::fake();
        Event::fake([Subscribed::class, Unsubscribed::class]);

        $result = Newsletter::subscriptions()->import('newsletter', [
            ['email' => 'active@example.test', 'status' => 'active', 'consent_at' => '2022-01-02 03:04:05'],
            ['email' => 'waiting@example.test', 'status' => 'unconfirmed', 'requested_at' => '2026-10-30 12:00:00', 'ip' => '192.0.2.7'],
            ['email' => 'left@example.test', 'status' => 'opted_out', 'consent_at' => '2020-05-01', 'unsubscribed_at' => '2024-02-03 10:00:00', 'source' => 'legacy-footer'],
            ['email' => 'weird@example.test', 'status' => 'bananas'],
            ['email' => 'baddate@example.test', 'consent_at' => 'not a date'],
        ]);

        $this->assertSame(['imported' => 3, 'updated' => 0, 'skipped' => 2], $result);

        $active = $this->sub('active@example.test');
        $this->assertSame(SubscriptionStatus::Subscribed, $active->status);
        $this->assertSame('2022-01-02 03:04:05', $active->consent_at->format('Y-m-d H:i:s'));

        $pending = $this->sub('waiting@example.test');
        $this->assertSame(SubscriptionStatus::Pending, $pending->status);
        $this->assertNull($pending->confirmation_token);
        $this->assertSame('2026-10-30 12:00:00', $pending->created_at->format('Y-m-d H:i:s'));
        $this->assertSame('192.0.2.7', $pending->consent_ip);

        $left = $this->sub('left@example.test');
        $this->assertSame(SubscriptionStatus::Unsubscribed, $left->status);
        $this->assertSame('2024-02-03 10:00:00', $left->unsubscribed_at->format('Y-m-d H:i:s'));
        $this->assertSame('legacy-footer', $left->unsubscribe_source);
        $this->assertSame('2020-05-01', $left->consent_at->format('Y-m-d'));

        // Imports are silent: no confirmation mails, no events.
        Mail::assertNothingSent();
        Event::assertNothingDispatched();
    }

    public function test_only_subscribed_rows_receive_campaigns(): void
    {
        Newsletter::subscriptions()->import('newsletter', [
            ['email' => 'a@example.test', 'status' => 'subscribed'],
            ['email' => 'p@example.test', 'status' => 'pending'],
            ['email' => 'u@example.test', 'status' => 'unsubscribed'],
        ]);

        Newsletter::send($this->campaign());
        $this->work();

        $this->assertSame(['a@example.test'], $this->driver->recipients());
    }

    public function test_imported_pending_and_unsubscribed_people_can_sign_up_again_with_double_opt_in(): void
    {
        Mail::fake();
        Newsletter::subscriptions()->import('newsletter', [
            ['email' => 'p@example.test', 'status' => 'pending'],
            ['email' => 'u@example.test', 'status' => 'unsubscribed'],
        ]);

        Newsletter::subscribe('newsletter', 'p@example.test');
        Newsletter::subscribe('newsletter', 'u@example.test');

        Mail::assertSent(\JensS\Newsletter\Mail\ConfirmSubscription::class, 2);
        $this->assertSame(SubscriptionStatus::Pending, $this->sub('u@example.test')->status);
    }

    public function test_imported_opt_outs_override_older_subscriptions_only(): void
    {
        Newsletter::subscribe('newsletter', 'old@example.test', ['confirmed' => true, 'consent_at' => now()->subYear()]);
        Newsletter::subscribe('newsletter', 'renewed@example.test', ['confirmed' => true]);

        $result = Newsletter::subscriptions()->import('newsletter', [
            ['email' => 'old@example.test', 'status' => 'unsubscribed', 'unsubscribed_at' => now()->subMonth()->toDateTimeString()],
            ['email' => 'renewed@example.test', 'status' => 'unsubscribed', 'unsubscribed_at' => now()->subMonth()->toDateTimeString()],
            ['email' => 'old@example.test', 'status' => 'subscribed'], // never re-activates
        ]);

        $this->assertSame(['imported' => 0, 'updated' => 1, 'skipped' => 2], $result);
        $this->assertSame(SubscriptionStatus::Unsubscribed, $this->sub('old@example.test')->status);
        $this->assertSame(SubscriptionStatus::Subscribed, $this->sub('renewed@example.test')->status, 'consent after the old withdrawal wins');
    }

    public function test_opt_outs_can_be_imported_into_application_audiences(): void
    {
        $this->user(['email' => 'recap-yes@example.test', 'weekly_recap' => true]);
        $this->user(['email' => 'recap-no@example.test', 'weekly_recap' => true]);

        $result = Newsletter::subscriptions()->import('weekly-recap', [
            ['email' => 'recap-no@example.test', 'status' => 'unsubscribed'],
            ['email' => 'recap-yes@example.test', 'status' => 'subscribed'], // the application owns this preference
        ]);

        $this->assertSame(['imported' => 1, 'updated' => 0, 'skipped' => 1], $result);

        Newsletter::send($this->campaign(['audience' => 'weekly-recap']));
        $this->work();

        $this->assertSame(['recap-yes@example.test'], $this->driver->recipients());
    }

    public function test_member_audiences_are_not_importable(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Newsletter::subscriptions()->import('members', [['email' => 'm@example.test']]);
    }

    public function test_csv_with_status_column_and_default_status(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'nl').'.csv';
        file_put_contents($file, "email,status,unsubscribed_at\ns1@example.test,,\ns2@example.test,unsubscribed,2025-01-01 00:00:00\n");

        $this->artisan('newsletter:import', ['audience' => 'newsletter', 'file' => $file, '--status' => 'pending'])
            ->expectsOutputToContain('Imported 2')
            ->assertSuccessful();

        $this->artisan('newsletter:import', ['audience' => 'newsletter', 'file' => $file, '--status' => 'nope'])->assertFailed();
        $this->artisan('newsletter:import', ['audience' => 'members', 'file' => $file])->assertFailed();
        unlink($file);

        $this->assertSame(SubscriptionStatus::Pending, $this->sub('s1@example.test')->status);
        $this->assertSame(SubscriptionStatus::Unsubscribed, $this->sub('s2@example.test')->status);
    }
}
