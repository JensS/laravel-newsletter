<?php

namespace JensS\Newsletter\Tests\Feature;

use Illuminate\Support\Facades\DB;
use JensS\Newsletter\Exceptions\CampaignException;
use JensS\Newsletter\Facades\Newsletter;
use JensS\Newsletter\Sending\CampaignDispatcher;
use JensS\Newsletter\Support\CampaignRenderer;
use JensS\Newsletter\Support\Recipient;
use JensS\Newsletter\Tests\TestCase;

class FrozenLayoutTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/nl-layouts-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->app['view']->addNamespace('site', $this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        @rmdir($this->dir);

        parent::tearDown();
    }

    private function layout(string $body): void
    {
        file_put_contents($this->dir.'/mail.blade.php', $body);
        touch($this->dir.'/mail.blade.php', time() + random_int(1, 1000)); // force Blade to recompile
    }

    private const GOOD = '<html><head><title>{{ $subject }}</title></head><body>VERSION-%s <p>Dear {{ $recipient->firstName() }} ({{ $recipient->email }})</p>{{ $content }}<a href="{{ $unsubscribeUrl }}">{{ __(\'newsletter::messages.footer.unsubscribe\') }}</a></body></html>';

    public function test_template_deployments_do_not_change_queued_messages(): void
    {
        $this->layout(sprintf(self::GOOD, 'A'));
        Newsletter::subscribe('newsletter', 'q@example.test', ['confirmed' => true, 'name' => 'Quinn Doe']);
        $campaign = $this->campaign(['layout' => 'site::mail']);

        Newsletter::send($campaign);
        DB::table('jobs')->delete();
        app(CampaignDispatcher::class)->prepareNextPage($campaign->fresh());

        // A deployment ships a new template, or deletes it, while deliveries wait in the queue.
        $this->layout(sprintf(self::GOOD, 'B'));
        unlink($this->dir.'/mail.blade.php');
        $this->work();

        $html = $this->driver->sent[0]->rendered->html;
        $this->assertStringContainsString('VERSION-A', $html);
        $this->assertStringContainsString('<title>Hello Quinn</title>', $html);
        $this->assertStringContainsString('Dear Quinn (q@example.test)', $html);
        $this->assertStringContainsString('Hi Quinn Doe', $html);
        $this->assertStringContainsString('href="'.e($this->driver->sent[0]->rendered->unsubscribeUrl).'"', $html);
        $this->assertStringNotContainsString($campaign->fresh()->snapshot['marker'], $html);
        $this->assertStringNotContainsString($campaign->fresh()->snapshot['marker'], $this->driver->sent[0]->rendered->text);
    }

    public function test_one_shell_per_locale_with_language_fallback(): void
    {
        config(['newsletter.content.locales' => ['de']]);
        Newsletter::subscribe('newsletter', 'en@example.test', ['confirmed' => true, 'locale' => 'en']);
        Newsletter::subscribe('newsletter', 'de@example.test', ['confirmed' => true, 'locale' => 'de']);
        Newsletter::subscribe('newsletter', 'at@example.test', ['confirmed' => true, 'locale' => 'de_AT']);
        Newsletter::subscribe('newsletter', 'fr@example.test', ['confirmed' => true, 'locale' => 'fr']);

        $campaign = $this->campaign();
        Newsletter::send($campaign);
        $this->work();

        $this->assertEqualsCanonicalizing(['en', 'de', 'fr'], array_keys($campaign->fresh()->snapshot['shells']), 'de_AT has no own translations and shares the de shell');

        $html = fn (string $email) => collect($this->driver->sent)->first(fn ($m) => $m->recipientEmail === $email)->rendered->html;
        $this->assertStringContainsString('>Unsubscribe<', $html('en@example.test'));
        $this->assertStringContainsString('>Abmelden<', $html('de@example.test'));
        $this->assertStringContainsString('>Abmelden<', $html('at@example.test'));

        // A locale that appears only later (e.g. via an application provider) uses its language, then the default.
        $renderer = app(CampaignRenderer::class);
        $snapshot = $campaign->fresh()->snapshot;
        $this->assertStringContainsString('Abmelden', $renderer->render($snapshot, new Recipient('x', 'ch@example.test', locale: 'de_CH'))->html);
        $this->assertStringContainsString('Unsubscribe', $renderer->render($snapshot, new Recipient('x', 'nl@example.test', locale: 'nl'))->html);
    }

    public function test_layouts_that_transform_per_recipient_values_are_refused(): void
    {
        $this->layout('<body>{{ \Illuminate\Support\Str::upper($subject) }} {{ $content }} <a href="{{ $unsubscribeUrl }}">x</a></body>');

        $this->expectException(CampaignException::class);
        $this->expectExceptionMessage('changes per-recipient values');

        Newsletter::send($this->campaign(['layout' => 'site::mail']));
    }

    public function test_layouts_must_output_the_content(): void
    {
        $this->layout('<body><a href="{{ $unsubscribeUrl }}">x</a></body>');

        $this->expectException(CampaignException::class);
        $this->expectExceptionMessage('$content');

        Newsletter::send($this->campaign(['layout' => 'site::mail']));
    }

    public function test_recipient_values_are_escaped_and_cannot_inject_markers(): void
    {
        $this->layout(sprintf(self::GOOD, 'A'));
        $campaign = $this->campaign(['layout' => 'site::mail']);
        $snapshot = app(CampaignRenderer::class)->snapshot($campaign);

        $evil = new Recipient('x', 'evil@example.test', '<script>alert(1)</script> '.$snapshot['marker'].'unsubscribeurl');
        $html = app(CampaignRenderer::class)->render($snapshot, $evil)->html;

        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertSame(1, substr_count($html, '/newsletter/unsubscribe/'), 'marker text in a name is not replaced');
    }

    public function test_snapshots_from_before_layout_freezing_still_render(): void
    {
        $campaign = $this->campaign();
        $snapshot = app(CampaignRenderer::class)->snapshot($campaign);
        unset($snapshot['shells'], $snapshot['marker'], $snapshot['default_locale']);
        $snapshot['version'] = 1;

        $rendered = app(CampaignRenderer::class)->render($snapshot, new Recipient('x', 'v1@example.test', 'Vee'));

        $this->assertStringContainsString('Hi Vee', $rendered->html);
        $this->assertStringContainsString($rendered->unsubscribeUrl, $rendered->text);
    }
}
