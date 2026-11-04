<?php

namespace JensS\Newsletter\Tests\Feature;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use JensS\Newsletter\Models\Subscription;
use JensS\Newsletter\Support\LinkToken;
use JensS\Newsletter\Tests\Fixtures\RejectSpamSignups;
use JensS\Newsletter\Tests\TestCase;

class SignupMiddlewareTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Routes are registered at boot, so the middleware must be configured before that.
        $app['config']->set('newsletter.routes.signup_middleware', [RejectSpamSignups::class.':captcha']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        RejectSpamSignups::$calls = 0;
        Mail::fake();
    }

    public function test_signups_go_through_the_host_middleware(): void
    {
        $this->post('/newsletter/subscribe', ['email' => 'bot@example.test'])
            ->assertSessionHasErrors('captcha');
        $this->assertSame(0, Subscription::query()->count());

        $this->post('/newsletter/subscribe', ['email' => 'human@example.test', 'captcha' => 'human'])
            ->assertOk();
        $this->assertSame(1, Subscription::query()->count());
        $this->assertSame(2, RejectSpamSignups::$calls);
    }

    public function test_only_the_signup_route_uses_it(): void
    {
        $this->assertContains(RejectSpamSignups::class.':captcha', Route::getRoutes()->getByName('newsletter.subscribe')->gatherMiddleware());

        foreach (['confirm.store', 'unsubscribe.store', 'unsubscribe.one-click', 'preferences.update', 'tick', 'webhooks.resend'] as $name) {
            $this->assertNotContains(RejectSpamSignups::class.':captcha', Route::getRoutes()->getByName("newsletter.{$name}")->gatherMiddleware(), $name);
        }

        // Unsubscribing never asks for a captcha.
        \JensS\Newsletter\Facades\Newsletter::subscribe('newsletter', 'stay@example.test', ['confirmed' => true]);
        $this->call('POST', '/newsletter/unsubscribe/'.LinkToken::unsubscribe('newsletter', 'stay@example.test').'/one-click')->assertOk();
        $this->assertSame(0, RejectSpamSignups::$calls);
    }
}
