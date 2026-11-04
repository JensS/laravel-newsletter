<?php

namespace JensS\Newsletter\Tests\Feature;

use JensS\Newsletter\Enums\CampaignStatus;
use JensS\Newsletter\Facades\Newsletter;
use JensS\Newsletter\Tests\TestCase;

class WorkerTest extends TestCase
{
    public function test_tick_requires_the_configured_token(): void
    {
        $this->postJson('/newsletter/tick')->assertUnauthorized(); // tick disabled by default

        config(['newsletter.worker.tick_token' => 'tick-secret']);

        $this->postJson('/newsletter/tick', [], ['Authorization' => 'Bearer wrong'])->assertUnauthorized();
        $this->postJson('/newsletter/tick', [], ['Authorization' => 'Bearer tick-secret'])->assertStatus(202);
    }

    public function test_tick_starts_due_campaigns_and_drains_the_queue(): void
    {
        config(['newsletter.worker.tick_token' => 'tick-secret']);
        Newsletter::subscribe('newsletter', 'tick@example.test', ['confirmed' => true]);
        $campaign = Newsletter::schedule($this->campaign(), now()->subMinute());

        $this->postJson('/newsletter/tick?sync=1', [], ['X-Newsletter-Token' => 'tick-secret'])
            ->assertOk()
            ->assertJsonPath('result.maintenance.started', 1);

        $this->assertSame(CampaignStatus::Sent, $campaign->fresh()->status);
        $this->assertSame(['tick@example.test'], $this->driver->recipients());
    }

    public function test_work_command_once_mode(): void
    {
        Newsletter::subscribe('newsletter', 'cron@example.test', ['confirmed' => true]);
        Newsletter::send($this->campaign());

        $this->artisan('newsletter:work', ['--once' => true, '--max-time' => 20])->assertSuccessful();

        $this->assertSame(['cron@example.test'], $this->driver->recipients());
    }

    public function test_only_one_worker_runs_at_a_time(): void
    {
        $lock = cache()->lock('newsletter:worker', 60);
        $lock->get();

        $this->assertSame(['status' => 'busy'], Newsletter::tick());

        $lock->release();
        $this->assertSame('ran', Newsletter::tick()['status']);
    }
}
