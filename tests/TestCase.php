<?php

namespace JensS\Newsletter\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use JensS\Newsletter\Contracts\DeliveryDriver;
use JensS\Newsletter\Models\Campaign;
use JensS\Newsletter\Models\Delivery;
use JensS\Newsletter\NewsletterServiceProvider;
use JensS\Newsletter\Support\AudienceRegistry;
use JensS\Newsletter\Tests\Fixtures\FakeDriver;
use JensS\Newsletter\Tests\Fixtures\User;
use JensS\Newsletter\Tests\Fixtures\WeeklyRecapRecipients;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected FakeDriver $driver;

    protected function getPackageProviders($app): array
    {
        return [NewsletterServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('app.url', 'https://example.test');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]);
        $app['config']->set('queue.default', 'database');
        $app['config']->set('queue.connections.database', [
            'driver' => 'database', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90, 'connection' => 'testing',
        ]);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('mail.default', 'array');
        $app['config']->set('mail.from', ['address' => 'news@example.test', 'name' => 'Example']);

        $app['config']->set('newsletter.rate_limit', ['per_second' => 0, 'per_minute' => 0]);
        $app['config']->set('newsletter.branding.name', 'Example Club');
        $app['config']->set('newsletter.branding.postal_address', 'Example Street 1, 10115 Berlin');
        $app['config']->set('newsletter.members.model', User::class);
        $app['config']->set('newsletter.members.map', ['email' => 'email', 'name' => 'name', 'locale' => 'locale']);
        $app['config']->set('newsletter.members.eligibility', 'approved');
        $app['config']->set('newsletter.audiences', [
            'newsletter' => ['label' => 'Newsletter', 'source' => 'subscriptions', 'double_opt_in' => true, 'public_signup' => true],
            'members' => ['label' => 'Member news', 'source' => 'subscriptions', 'double_opt_in' => false, 'members' => true],
            'weekly-recap' => ['label' => 'Weekly recap', 'source' => WeeklyRecapRecipients::class],
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadLaravelMigrations();
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('approved')->default(true);
            $table->boolean('weekly_recap')->default(false);
            $table->string('locale', 12)->nullable();
            $table->softDeletes();
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = new FakeDriver;
        $this->app->instance(DeliveryDriver::class, $this->driver);
        $this->app->make(AudienceRegistry::class)->flush();
    }

    protected function user(array $attributes = []): User
    {
        static $n = 0;
        $n++;

        return User::query()->create($attributes + [
            'name' => "Member {$n}",
            'email' => "member{$n}@example.test",
            'password' => 'secret',
        ]);
    }

    protected function campaign(array $attributes = []): Campaign
    {
        return Campaign::query()->create($attributes + [
            'name' => 'Spring update',
            'audience' => 'newsletter',
            'subject' => 'Hello {{ first_name|friend }}',
            'preview_text' => 'What is new',
            'content_html' => '<h1>Spring</h1><p>Hi {{ name|there }}, see <a href="https://example.test/news">the news</a>.</p>',
        ]);
    }

    /** Run every queued job until the queue is empty. */
    protected function work(): void
    {
        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'newsletter', '--stop-when-empty' => true, '--sleep' => 0])->run();
    }

    protected function delivery(Campaign $campaign, string $email): ?Delivery
    {
        return $campaign->deliveries()->where('email', $email)->first();
    }
}
