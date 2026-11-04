<?php

namespace JensS\Newsletter\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use JensS\Newsletter\Support\AudienceRegistry;
use JensS\Newsletter\Support\Email;
use Throwable;

class DoctorCommand extends Command
{
    protected $signature = 'newsletter:doctor {--dkim-selector=* : DKIM selector(s) to look up, e.g. "resend"} {--skip-dns : Skip DNS lookups}';

    protected $description = 'Check configuration, queue and sending-domain authentication before going live';

    private int $errors = 0;

    private int $warnings = 0;

    public function handle(AudienceRegistry $audiences): int
    {
        $this->components->info('Newsletter health check');

        $prefix = config('newsletter.table_prefix', 'newsletter_');
        $this->check(Schema::hasTable($prefix.'campaigns'), 'Database tables exist', 'Run php artisan newsletter:install && php artisan migrate');

        try {
            $all = $audiences->all();
            $this->check($all !== [], 'At least one audience is configured', 'Add audiences to config/newsletter.php');
            foreach ($all as $key => $audience) {
                $audience->recipients(); // resolves the provider class
                $this->check(true, "Audience [{$key}] resolves");
            }
        } catch (Throwable $e) {
            $this->check(false, 'Audiences resolve', $e->getMessage());
        }

        $connection = config('newsletter.queue.connection') ?: config('queue.default');
        $this->check($connection !== 'sync', "Queue connection is asynchronous ({$connection})", 'Set QUEUE_CONNECTION=database (or NEWSLETTER_QUEUE_CONNECTION) and run the migrations for the jobs table');

        if (config("queue.connections.{$connection}.driver") === 'database') {
            $table = config("queue.connections.{$connection}.table", 'jobs');
            $this->check(Schema::hasTable($table), "Queue table [{$table}] exists", 'Run php artisan make:queue-table && php artisan migrate');
        }

        $this->warnUnless(
            filled(config('newsletter.worker.tick_token')) || $this->output->isQuiet(),
            'HTTP tick is configured (NEWSLETTER_TICK_TOKEN)',
            'Without a frequent trigger, sending only progresses when cron runs newsletter:work'
        );

        $mailer = config('newsletter.mail.mailer') ?: config('mail.default');
        $transport = config("mail.mailers.{$mailer}.transport");
        $this->check(! in_array($transport, ['log', 'array', null], true), "Mailer [{$mailer}] uses a real transport ({$transport})", 'Configure MAIL_MAILER / NEWSLETTER_MAILER');

        $from = config('newsletter.mail.from.address') ?: config('mail.from.address');
        $this->check($from && Email::isValid($from), "Sender address is set ({$from})", 'Set NEWSLETTER_FROM_ADDRESS');
        $this->warnUnless(filled(config('newsletter.branding.postal_address')), 'Postal address for the footer is set', 'Set NEWSLETTER_POSTAL_ADDRESS (required by many jurisdictions)');
        $this->check(config('newsletter.routes.enabled', true) && Route::has('newsletter.unsubscribe'), 'Unsubscribe routes are enabled', 'Enable newsletter.routes.enabled');
        $this->warnUnless(str_starts_with((string) config('app.url'), 'https://'), 'APP_URL uses https', 'Unsubscribe and tracking links are generated from APP_URL');

        if (config('newsletter.mail.driver') === 'resend' || $transport === 'resend') {
            $this->warnUnless(filled(config('newsletter.webhooks.resend.secret')), 'Resend webhook secret is set', 'Add a webhook in Resend pointing to '.url(config('newsletter.routes.prefix').'/webhooks/resend').' and set NEWSLETTER_RESEND_WEBHOOK_SECRET');
        }

        if ($from && ! $this->option('skip-dns')) {
            $this->checkDns((string) Email::domain($from));
        }

        $this->newLine();

        if ($this->errors > 0) {
            $this->components->error("{$this->errors} problem(s), {$this->warnings} warning(s).");

            return self::FAILURE;
        }

        $this->components->info($this->warnings ? "OK with {$this->warnings} warning(s)." : 'All checks passed.');

        return self::SUCCESS;
    }

    private function checkDns(string $domain): void
    {
        if (! function_exists('dns_get_record')) {
            $this->warnUnless(false, 'DNS lookups available', 'dns_get_record() is disabled; check SPF, DKIM and DMARC manually');

            return;
        }

        $txt = fn (string $host) => array_map(fn ($r) => $r['txt'] ?? '', @dns_get_record($host, DNS_TXT) ?: []);

        $spf = array_filter($txt($domain), fn ($t) => str_starts_with(strtolower($t), 'v=spf1'));
        $this->warnUnless($spf !== [], "SPF record found for {$domain}", 'Publish an SPF record that includes your mail provider (your provider may use a subdomain for the envelope sender)');

        $dmarc = array_filter($txt('_dmarc.'.$domain), fn ($t) => str_starts_with(strtolower($t), 'v=dmarc1'));
        $this->check($dmarc !== [], "DMARC record found for {$domain}", 'Bulk senders must publish DMARC, e.g. _dmarc.'.$domain.' TXT "v=DMARC1; p=none; rua=mailto:dmarc@'.$domain.'"');

        $selectors = (array) $this->option('dkim-selector');

        if ($selectors === []) {
            $this->warnUnless(false, 'DKIM selector given', 'Re-run with --dkim-selector=<selector> (Resend: "resend") to verify DKIM');

            return;
        }

        foreach ($selectors as $selector) {
            $records = array_merge($txt("{$selector}._domainkey.{$domain}"), array_map(fn ($r) => $r['target'] ?? '', @dns_get_record("{$selector}._domainkey.{$domain}", DNS_CNAME) ?: []));
            $this->check(array_filter($records) !== [], "DKIM record found for selector [{$selector}]", "Publish the DKIM key your provider gives you at {$selector}._domainkey.{$domain}");
        }
    }

    private function check(bool $ok, string $label, string $hint = ''): void
    {
        if ($ok) {
            $this->components->twoColumnDetail($label, '<fg=green>OK</>');

            return;
        }

        $this->errors++;
        $this->components->twoColumnDetail($label, '<fg=red>FAIL</>');

        if ($hint) {
            $this->line("    <fg=gray>{$hint}</>");
        }
    }

    private function warnUnless(bool $ok, string $label, string $hint = ''): void
    {
        if ($ok) {
            $this->components->twoColumnDetail($label, '<fg=green>OK</>');

            return;
        }

        $this->warnings++;
        $this->components->twoColumnDetail($label, '<fg=yellow>WARN</>');

        if ($hint) {
            $this->line("    <fg=gray>{$hint}</>");
        }
    }
}
