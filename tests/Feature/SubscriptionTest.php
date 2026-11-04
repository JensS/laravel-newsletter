<?php

namespace JensS\Newsletter\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use JensS\Newsletter\Enums\SubscriptionStatus;
use JensS\Newsletter\Events\Subscribed;
use JensS\Newsletter\Events\Unsubscribed;
use JensS\Newsletter\Facades\Newsletter;
use JensS\Newsletter\Mail\ConfirmSubscription;
use JensS\Newsletter\Models\Subscription;
use JensS\Newsletter\Support\LinkToken;
use JensS\Newsletter\Tests\TestCase;

class SubscriptionTest extends TestCase
{
    public function test_public_signup_requires_double_opt_in_and_records_consent(): void
    {
        Mail::fake();
        Event::fake([Subscribed::class]);

        $this->post('/newsletter/subscribe', ['email' => 'Ann@Example.test', 'name' => 'Ann', 'source' => 'footer'])
            ->assertOk()
            ->assertSee('Please click the link');

        $subscription = Subscription::query()->sole();
        $this->assertSame('ann@example.test', $subscription->email);
        $this->assertSame(SubscriptionStatus::Pending, $subscription->status);
        $this->assertSame('form:footer', $subscription->source);
        $this->assertSame('127.0.0.1', $subscription->consent_ip);
        $this->assertNotEmpty($subscription->consent_text);
        $this->assertNull($subscription->consent_at);
        Event::assertNotDispatched(Subscribed::class);

        $confirmUrl = null;
        Mail::assertSent(ConfirmSubscription::class, function (ConfirmSubscription $mail) use (&$confirmUrl) {
            $confirmUrl = $mail->confirmUrl;

            return $mail->hasTo('ann@example.test');
        });

        // GET (e.g. a link scanner) must not confirm.
        $this->get($confirmUrl)->assertOk()->assertSee('Confirm subscription');
        $this->assertSame(SubscriptionStatus::Pending, $subscription->fresh()->status);

        $this->post($confirmUrl)->assertOk()->assertSee('Subscription confirmed');

        $subscription->refresh();
        $this->assertSame(SubscriptionStatus::Subscribed, $subscription->status);
        $this->assertNotNull($subscription->confirmed_at);
        $this->assertNotNull($subscription->consent_at);
        $this->assertNull($subscription->confirmation_token);
        Event::assertDispatched(Subscribed::class);

        // Token is single-use.
        $this->post($confirmUrl)->assertNotFound();
    }

    public function test_confirmation_links_expire(): void
    {
        Mail::fake();
        Newsletter::subscribe('newsletter', 'late@example.test');
        $url = null;
        Mail::assertSent(ConfirmSubscription::class, function ($m) use (&$url) {
            $url = $m->confirmUrl;

            return true;
        });

        $this->travel(73)->hours();

        $this->post($url)->assertNotFound();
        $this->assertSame(SubscriptionStatus::Pending, Subscription::query()->sole()->status);
    }

    public function test_repeated_signups_do_not_spam_or_reveal_status(): void
    {
        Mail::fake();

        $first = $this->post('/newsletter/subscribe', ['email' => 'bob@example.test'])->getContent();
        $second = $this->post('/newsletter/subscribe', ['email' => 'bob@example.test'])->getContent();

        Mail::assertSentCount(1);
        $this->assertSame($first, $second);

        Subscription::query()->update(['status' => 'subscribed']);
        $third = $this->post('/newsletter/subscribe', ['email' => 'bob@example.test'])->getContent();
        $this->assertSame($first, $third);
        Mail::assertSentCount(1);
    }

    public function test_honeypot_and_non_public_audiences_are_ignored(): void
    {
        Mail::fake();

        $this->post('/newsletter/subscribe', ['email' => 'bot@example.test', 'website' => 'http://spam'])->assertOk();
        $this->post('/newsletter/subscribe', ['email' => 'x@example.test', 'audience' => 'members'])->assertSessionHasErrors('audience');

        $this->assertSame(0, Subscription::query()->count());
        Mail::assertNothingSent();
    }

    public function test_members_are_never_subscribed_automatically(): void
    {
        $user = $this->user();

        $this->assertFalse($user->isSubscribedToNewsletter('members'));
        $this->assertFalse($user->isSubscribedToNewsletter('newsletter'));
        $this->assertSame(0, Subscription::query()->count());

        $user->subscribeToNewsletter('members', source: 'account-settings');

        $this->assertTrue($user->isSubscribedToNewsletter('members'));
        $this->assertFalse($user->isSubscribedToNewsletter('newsletter'));

        $subscription = $user->newsletterSubscriptions()->sole();
        $this->assertSame('account-settings', $subscription->source);
        $this->assertNotNull($subscription->consent_at);

        $user->unsubscribeFromNewsletter('members');
        $this->assertFalse($user->fresh()->isSubscribedToNewsletter('members'));
    }

    public function test_member_audiences_reject_unlinked_addresses(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Newsletter::subscribe('members', 'stranger@example.test');
    }

    public function test_unsubscribe_link_shows_page_then_unsubscribes_on_post(): void
    {
        Event::fake([Unsubscribed::class]);
        $sub = Newsletter::subscribe('newsletter', 'cara@example.test', ['confirmed' => true]);
        $token = LinkToken::unsubscribe('newsletter', 'cara@example.test');

        $this->get("/newsletter/unsubscribe/{$token}")->assertOk()->assertSee('cara@example.test');
        $this->assertSame(SubscriptionStatus::Subscribed, $sub->fresh()->status);

        $this->post("/newsletter/unsubscribe/{$token}")->assertOk()->assertSee('You have been unsubscribed');

        $sub->refresh();
        $this->assertSame(SubscriptionStatus::Unsubscribed, $sub->status);
        $this->assertSame('link', $sub->unsubscribe_source);
        $this->assertNotNull($sub->unsubscribed_at);
        Event::assertDispatched(Unsubscribed::class);

        $this->get('/newsletter/unsubscribe/forged.token')->assertNotFound();
    }

    public function test_one_click_unsubscribe_works_without_csrf_or_session(): void
    {
        $sub = Newsletter::subscribe('newsletter', 'dan@example.test', ['confirmed' => true]);
        $token = LinkToken::unsubscribe('newsletter', 'dan@example.test');

        $this->call('POST', "/newsletter/unsubscribe/{$token}/one-click", ['List-Unsubscribe' => 'One-Click'])
            ->assertOk();

        $this->assertSame(SubscriptionStatus::Unsubscribed, $sub->fresh()->status);
        $this->assertSame('one-click', $sub->fresh()->unsubscribe_source);
    }

    public function test_preference_page_manages_audiences_without_login(): void
    {
        $user = $this->user(['email' => 'eve@example.test', 'weekly_recap' => true]);
        Newsletter::subscribe('newsletter', 'eve@example.test', ['confirmed' => true]);
        $token = LinkToken::preferences('eve@example.test');

        $this->get("/newsletter/preferences/{$token}")
            ->assertOk()
            ->assertSee('Newsletter')
            ->assertSee('Member news')
            ->assertSee('Weekly recap');

        // Keep only the member newsletter.
        $this->post("/newsletter/preferences/{$token}", ['audiences' => ['members']])->assertRedirect();

        $this->assertFalse(Newsletter::isSubscribed('newsletter', 'eve@example.test'));
        $this->assertTrue($user->isSubscribedToNewsletter('members'));
        $this->assertFalse(Newsletter::isSubscribed('weekly-recap', 'eve@example.test'));
        $this->assertFalse($user->fresh()->weekly_recap, 'HandlesUnsubscribes updates the application preference');

        $this->post("/newsletter/preferences/{$token}", ['unsubscribe_all' => '1'])->assertRedirect();
        $this->assertFalse($user->isSubscribedToNewsletter('members'));
    }

    public function test_import_keeps_original_consent(): void
    {
        $result = Newsletter::subscriptions()->import('newsletter', [
            ['email' => 'old@example.test', 'name' => 'Old', 'consent_at' => '2021-03-04 05:06:07', 'source' => 'legacy-form'],
            ['email' => 'not-an-email'],
            ['email' => 'OLD@example.test'],
        ]);

        $this->assertSame(['imported' => 1, 'skipped' => 2], $result);
        $sub = Subscription::query()->sole();
        $this->assertSame('2021-03-04 05:06:07', $sub->consent_at->format('Y-m-d H:i:s'));
        $this->assertSame('legacy-form', $sub->source);
    }
}
