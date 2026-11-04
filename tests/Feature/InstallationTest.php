<?php

namespace JensS\Newsletter\Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use JensS\Newsletter\Facades\Newsletter;
use JensS\Newsletter\Models\Campaign;
use JensS\Newsletter\Tests\Fixtures\User;
use JensS\Newsletter\Tests\TestCase;

class InstallationTest extends TestCase
{
    public function test_provider_merges_config_and_registers_routes_and_commands(): void
    {
        $this->assertSame('newsletter', config('newsletter.queue.name'));
        $this->assertInstanceOf(\JensS\Newsletter\Newsletter::class, Newsletter::getFacadeRoot());

        foreach (['subscribe', 'confirm', 'unsubscribe', 'unsubscribe.one-click', 'preferences', 'tick', 'webhooks.resend', 'webhooks.generic', 'preview'] as $name) {
            $this->assertTrue(Route::has("newsletter.{$name}"), "route newsletter.{$name}");
        }

        $commands = array_keys(\Illuminate\Support\Facades\Artisan::all());
        foreach (['install', 'work', 'maintain', 'send', 'prune', 'doctor', 'import'] as $command) {
            $this->assertContains("newsletter:{$command}", $commands);
        }
    }

    public function test_machine_endpoints_have_no_session_or_csrf_middleware(): void
    {
        foreach (['unsubscribe.one-click', 'tick', 'webhooks.resend', 'webhooks.generic'] as $name) {
            $middleware = Route::getRoutes()->getByName("newsletter.{$name}")->gatherMiddleware();
            $this->assertNotContains('web', $middleware, $name);
        }

        $this->assertContains('web', Route::getRoutes()->getByName('newsletter.subscribe')->gatherMiddleware());
    }

    public function test_install_command_publishes_config_and_migrations(): void
    {
        $config = config_path('newsletter.php');
        $migrations = fn () => glob(database_path('migrations/*_create_newsletter_tables.php'));

        @unlink($config);
        array_map('unlink', $migrations());

        try {
            $this->artisan('newsletter:install', ['--no-interaction' => true])
                ->expectsOutputToContain('newsletter:work --max-time=3300')
                ->assertSuccessful();

            $this->assertFileExists($config);
            $this->assertCount(1, $migrations());

            // Running it again does not publish a second migration.
            $this->artisan('newsletter:install', ['--no-interaction' => true])->assertSuccessful();
            $this->assertCount(1, $migrations());
        } finally {
            @unlink($config);
            array_map('unlink', $migrations());
        }
    }

    public function test_scheduler_tasks_are_registered(): void
    {
        $events = collect(app(Schedule::class)->events())->map(fn ($e) => $e->command)->implode(' ');

        $this->assertStringContainsString('newsletter:maintain', $events);
        $this->assertStringContainsString('newsletter:prune', $events);
    }

    public function test_admin_access_is_denied_until_the_host_grants_it(): void
    {
        $user = $this->user();
        $campaign = $this->campaign();

        $this->actingAs($user)->get("/newsletter/campaigns/{$campaign->id}/preview")->assertForbidden();

        Gate::define('manage-newsletters', fn (User $u) => $u->email === $user->email);

        $this->actingAs($user)->get("/newsletter/campaigns/{$campaign->id}/preview")
            ->assertOk()
            ->assertSee('Spring')
            ->assertHeader('Content-Security-Policy');
    }

    public function test_doctor_reports_problems(): void
    {
        config(['queue.default' => 'sync', 'mail.default' => 'log']);

        $this->artisan('newsletter:doctor', ['--skip-dns' => true])
            ->expectsOutputToContain('FAIL')
            ->assertFailed();
    }

    public function test_send_command_starts_a_campaign(): void
    {
        Newsletter::subscribe('newsletter', 'cli@example.test', ['confirmed' => true]);
        $campaign = $this->campaign();

        $this->artisan('newsletter:send', ['campaign' => $campaign->uuid, '--force' => true])->assertSuccessful();
        $this->work();

        $this->assertSame(['cli@example.test'], $this->driver->recipients());
        $this->assertInstanceOf(Campaign::class, $campaign);
    }

    public function test_import_command_reads_csv(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'nl').'.csv';
        file_put_contents($file, "email,name,consent_at\nimp@example.test,Imp,2022-01-01 10:00:00\nbroken,,\n");

        $this->artisan('newsletter:import', ['audience' => 'newsletter', 'file' => $file])
            ->expectsOutputToContain('Imported 1, updated 0 (opt-outs applied), skipped 1')
            ->assertSuccessful();

        unlink($file);
        $this->assertTrue(Newsletter::isSubscribed('newsletter', 'imp@example.test'));
    }
}
