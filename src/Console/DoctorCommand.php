<?php

namespace JensS\Newsletter\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use JensS\Newsletter\Mailbox\MailboxProcessor;
use JensS\Newsletter\Support\AudienceRegistry;
use JensS\Newsletter\Support\DnsResolver;
use JensS\Newsletter\Support\Email;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Throwable;

class DoctorCommand extends Command
{
    protected $signature = 'newsletter:doctor
        {--dkim-selector=* : DKIM selector(s) to look up (netcup: key1 key2, Resend: resend)}
        {--skip-dns : Skip DNS lookups}
        {--smtp : Connect and log in to the SMTP server (sends nothing)}
        {--mailbox : Open the bounce mailbox over IMAP}';

    protected $description = 'Check configuration, queue, SMTP and sending-domain authentication before going live';

    private int $errors = 0;

    private int $warnings = 0;

    public function handle(AudienceRegistry $audiences, DnsResolver $dns): int
    {
        $this->components->info('Newsletter health check');

        $this->checkApplication($audiences);

        $mailer = config('newsletter.mail.mailer') ?: config('mail.default');
        $mailerConfig = (array) config("mail.mailers.{$mailer}", []);
        $transport = $mailerConfig['transport'] ?? null;
        $from = (string) (config('newsletter.mail.from.address') ?: config('mail.from.address'));
        $domain = (string) Email::domain($from);
        $netcup = $this->isNetcup($mailerConfig, $domain, $dns);

        $this->section('Mail transport'.($netcup ? ' (netcup detected)' : ''));
        $this->check(! in_array($transport, ['log', 'array', null], true), "Mailer [{$mailer}] uses a real transport ({$transport})", 'Configure MAIL_MAILER / NEWSLETTER_MAILER');
        $this->check(! in_array($transport, ['sendmail', 'mail'], true), 'Mail is not sent via sendmail/PHP mail()', 'Use authenticated SMTP. netcup only DKIM-signs mail sent through its mail server, not through the web server.');
        $this->check($from !== '' && Email::isValid($from), "Sender address is set ({$from})", 'Set NEWSLETTER_FROM_ADDRESS');

        if ($transport === 'smtp') {
            $this->checkSmtp($mailerConfig, $from, $netcup);
        }

        if (config('newsletter.mail.driver') === 'resend' || $transport === 'resend') {
            $this->warnUnless(filled(config('newsletter.webhooks.resend.secret')), 'Resend webhook secret is set', 'Add a webhook in Resend pointing to '.url(config('newsletter.routes.prefix').'/webhooks/resend').' and set NEWSLETTER_RESEND_WEBHOOK_SECRET');
        }

        $this->checkContentAndCompliance();

        if ($domain !== '' && ! $this->option('skip-dns')) {
            $this->checkDns($domain, $netcup, $dns, (string) ($mailerConfig['host'] ?? ''));
        }

        if ($this->option('smtp')) {
            $this->testSmtp((string) $mailer);
        }

        if ($this->option('mailbox')) {
            $this->testMailbox();
        }

        $this->newLine();

        if ($this->errors > 0) {
            $this->components->error("{$this->errors} problem(s), {$this->warnings} warning(s).");

            return self::FAILURE;
        }

        $this->components->info($this->warnings ? "OK with {$this->warnings} warning(s)." : 'All checks passed.');

        return self::SUCCESS;
    }

    private function checkApplication(AudienceRegistry $audiences): void
    {
        $this->section('Application');
        $prefix = config('newsletter.table_prefix', 'newsletter_');
        $this->check(Schema::hasTable($prefix.'campaigns'), 'Database tables exist', 'Run php artisan newsletter:install && php artisan migrate');

        try {
            $all = $audiences->all();
            $this->check($all !== [], 'At least one audience is configured', 'Add audiences to config/newsletter.php');
            foreach ($all as $key => $audience) {
                $audience->recipients();
                $this->check(true, "Audience [{$key}] resolves");
            }
        } catch (Throwable $e) {
            $this->check(false, 'Audiences resolve', $e->getMessage());
        }

        $connection = config('newsletter.queue.connection') ?: config('queue.default');
        $this->check($connection !== 'sync', "Queue connection is asynchronous ({$connection})", 'Set NEWSLETTER_QUEUE_CONNECTION=database and create the jobs table');

        if (config("queue.connections.{$connection}.driver") === 'database') {
            $table = config("queue.connections.{$connection}.table", 'jobs');
            $this->check(Schema::hasTable($table), "Queue table [{$table}] exists", 'Run php artisan make:queue-table && php artisan migrate');
        }

        $this->warnUnless(filled(config('newsletter.worker.tick_token')), 'HTTP tick is configured (NEWSLETTER_TICK_TOKEN)', 'Without a frequent trigger, sending only progresses when cron runs newsletter:work');
    }

    /** @param  array<string, mixed>  $config */
    private function checkSmtp(array $config, string $from, bool $netcup): void
    {
        $host = (string) ($config['host'] ?? '');
        $port = (int) ($config['port'] ?? 0);
        $scheme = strtolower((string) ($config['scheme'] ?? $config['encryption'] ?? ''));
        $username = (string) ($config['username'] ?? '');

        $this->check($host !== '' && $host !== 'localhost' && $host !== '127.0.0.1', "SMTP host is set ({$host})", 'Use the mail server from your hosting panel (netcup: shown in the WCP, e.g. mxXXXX.netcup.net)');
        $this->check($port !== 25, "SMTP port is a submission port ({$port})", 'Use 465 (implicit TLS) or 587 (STARTTLS); port 25 is for server-to-server mail and often blocked');

        if ($port === 465) {
            $this->check(in_array($scheme, ['smtps', 'ssl', 'tls'], true), 'Port 465 uses implicit TLS', 'Set MAIL_SCHEME=smtps (Laravel 12+) for port 465');
        }

        $this->check($username !== '', 'SMTP authentication is configured', 'Set MAIL_USERNAME and MAIL_PASSWORD (the full mailbox address)');

        if ($username !== '' && Email::isValid($username) && $from !== '') {
            $sameDomain = strcasecmp((string) Email::domain($username), (string) Email::domain($from)) === 0;
            $this->check($sameDomain, 'Sender domain matches the SMTP mailbox domain', "From is {$from}, but you authenticate as {$username}. The hoster signs DKIM for the mailbox domain; a different From domain fails DMARC alignment.");
            $this->warnUnless(strcasecmp($username, $from) === 0, 'Sender address is the authenticated mailbox', "Shared hosts (netcup included) may reject or rewrite mail whose From differs from {$username}. Use a real mailbox like newsletter@".Email::domain($from).' as both.');
        }

        $returnPath = (string) config('newsletter.mail.return_path');
        if ($returnPath !== '') {
            $this->check(strcasecmp((string) Email::domain($returnPath), (string) Email::domain($from)) === 0, 'Return-Path uses the sender domain', 'SPF is checked against the Return-Path domain; keep it on the From domain for alignment');
        }

        $mailboxUser = (string) config('newsletter.mailbox.username');
        $bounceTarget = $returnPath !== '' ? $returnPath : $username;
        $this->warnUnless(MailboxProcessor::configured(), 'Bounce mailbox is configured (IMAP)', 'With SMTP, bounces arrive as emails. Set NEWSLETTER_MAILBOX_* so newsletter:process-mailbox can suppress dead addresses.');

        if (MailboxProcessor::configured() && $bounceTarget !== '' && Email::isValid($mailboxUser)) {
            $this->warnUnless(strcasecmp($mailboxUser, $bounceTarget) === 0, 'Bounce mailbox receives the bounces', "Bounces go to {$bounceTarget} (envelope sender) but the processed mailbox is {$mailboxUser}.");
        }

        $this->warnUnless(filled(config('newsletter.mail.unsubscribe_mailto')) || ! MailboxProcessor::configured(), 'mailto unsubscribe address is set', 'Set NEWSLETTER_UNSUBSCRIBE_MAILTO (e.g. the bounce mailbox) to offer a mailto List-Unsubscribe that the mailbox processor handles');

        $limits = (array) config('newsletter.rate_limit');
        $this->line(sprintf('    <fg=gray>Limits: %s/s, %s/min, %s/h, %s/day, %s/min per provider</>',
            $limits['per_second'] ?? '–', $limits['per_minute'] ?? '–', $limits['per_hour'] ?? '–', $limits['per_day'] ?? '–', $limits['per_domain_per_minute'] ?? '–'));
        $this->warnUnless(! empty($limits['per_hour']) && ! empty($limits['per_day']), 'Hourly and daily limits are set', 'Shared-hosting SMTP servers enforce (often unpublished) hourly/daily caps. Set NEWSLETTER_RATE_PER_HOUR and _PER_DAY below them'.($netcup ? ' (ask netcup support for your package\'s limit)' : ''));
        $this->warnUnless(! empty($limits['per_domain_per_minute']), 'Per-provider limit is set', 'Set NEWSLETTER_RATE_PER_DOMAIN_PER_MINUTE (e.g. 10) so Gmail, GMX/web.de and T-Online do not see bursts');
    }

    private function checkContentAndCompliance(): void
    {
        $this->section('Compliance');
        $this->warnUnless(filled(config('newsletter.branding.postal_address')), 'Postal address for the footer is set', 'Set NEWSLETTER_POSTAL_ADDRESS (Impressum / CAN-SPAM)');
        $this->check(config('newsletter.routes.enabled', true) && Route::has('newsletter.unsubscribe'), 'Unsubscribe routes are enabled (one-click)', 'Enable newsletter.routes.enabled');
        $this->warnUnless(str_starts_with((string) config('app.url'), 'https://'), 'APP_URL uses https', 'Unsubscribe links are generated from APP_URL');
        $this->warnUnless((bool) config('newsletter.guard.enabled', true), 'Reputation guard is enabled', 'Campaigns will not pause on high bounce/complaint rates');
        $this->warnUnless((bool) config('newsletter.circuit_breaker.enabled', true), 'Circuit breaker is enabled', 'Sending will not pause when the server reports quota or auth errors');
    }

    private function checkDns(string $domain, bool $netcup, DnsResolver $dns, string $smtpHost): void
    {
        $this->section("DNS for {$domain}");

        if (! $dns->available()) {
            $this->warnUnless(false, 'DNS lookups available', 'dns_get_record() is disabled; check SPF, DKIM and DMARC with an external tool');

            return;
        }

        // SPF
        $spf = array_values(array_filter($dns->txt($domain), fn ($t) => str_starts_with(strtolower(trim($t)), 'v=spf1')));
        $this->check($spf !== [], 'SPF record exists', $netcup ? 'Add TXT @ "v=spf1 mx a include:_spf.webhosting.systems ~all"' : 'Publish an SPF record that includes your mail server');

        if (count($spf) > 1) {
            $this->check(false, 'Exactly one SPF record', 'Multiple SPF records are a permanent error (SPF fails everywhere). Merge them into one.');
        } elseif ($spf !== []) {
            $record = strtolower($spf[0]);
            $this->line('    <fg=gray>'.$spf[0].'</>');

            if ($netcup) {
                $this->check(str_contains($record, 'include:_spf.webhosting.systems'), 'SPF authorises netcup\'s mail servers', 'Add include:_spf.webhosting.systems to the SPF record');
            }

            $this->check(! preg_match('/[+?]all\b/', $record), 'SPF does not allow everyone', 'Use ~all (or -all once everything is verified), never +all or ?all');
            $lookups = count(array_filter(preg_split('/\s+/', $record) ?: [], fn ($t) => preg_match('#^[+~?-]?(include:|a$|a[:/]|mx$|mx[:/]|ptr|exists:)|^redirect=#', $t) === 1));
            $this->warnUnless($lookups <= 8, "SPF uses {$lookups} direct DNS lookups (limit 10 incl. nested)", 'Too many include/a/mx mechanisms cause SPF permerror');
        }

        // DKIM
        $selectors = (array) $this->option('dkim-selector');
        if ($selectors === [] && $netcup) {
            $selectors = ['key1', 'key2'];
        }

        if ($selectors === []) {
            $this->warnUnless(false, 'DKIM selector given', 'Re-run with --dkim-selector=<selector> to verify DKIM');
        }

        foreach ($selectors as $selector) {
            $host = "{$selector}._domainkey.{$domain}";
            $records = array_merge($dns->txt($host), $dns->cname($host));
            $hint = $netcup
                ? "Add CNAME {$selector}._domainkey → {$selector}._domainkey.webhosting.systems (both key1 and key2 are required)"
                : "Publish the DKIM key your provider gives you at {$host}";
            $this->check(array_filter($records) !== [], "DKIM record for selector [{$selector}]", $hint);
        }

        // DMARC
        $dmarc = array_values(array_filter($dns->txt('_dmarc.'.$domain), fn ($t) => str_starts_with(strtolower(trim($t)), 'v=dmarc1')));
        $this->check($dmarc !== [], 'DMARC record exists', 'Bulk senders must publish DMARC, e.g. _dmarc TXT "v=DMARC1; p=none; rua=mailto:dmarc@'.$domain.'"');

        if ($dmarc !== []) {
            $this->line('    <fg=gray>'.$dmarc[0].'</>');
            $this->warnUnless(str_contains(strtolower($dmarc[0]), 'rua='), 'DMARC aggregate reports (rua) are requested', 'Add rua=mailto:… to see who sends as your domain and whether SPF/DKIM pass');
        }

        // Reverse DNS of the outgoing server
        if ($smtpHost !== '') {
            $ips = $dns->a($smtpHost);
            $ptr = $ips !== [] ? $dns->ptr($ips[0]) : null;
            $confirmed = $ptr !== null && in_array($ips[0], $dns->a($ptr), true);
            $this->warnUnless($confirmed, "Mail server {$smtpHost} has matching reverse DNS".($ptr ? " ({$ptr})" : ''), 'Missing forward-confirmed reverse DNS gets mail rejected (Gmail 550-5.7.25). On shared hosting only the hoster can fix it.');
        }
    }

    private function testSmtp(string $mailer): void
    {
        $this->section('SMTP login test');

        try {
            $transport = Mail::mailer($mailer)->getSymfonyTransport();

            if (! $transport instanceof SmtpTransport) {
                $this->warnUnless(false, 'SMTP transport', 'The configured mailer is not SMTP; skipped');

                return;
            }

            $transport->start();
            $transport->stop();
            $this->check(true, 'Connected, TLS negotiated and authenticated');
        } catch (Throwable $e) {
            $this->check(false, 'SMTP login', $e->getMessage());
        }
    }

    private function testMailbox(): void
    {
        $this->section('Bounce mailbox test');

        if (! MailboxProcessor::configured()) {
            $this->check(false, 'Bounce mailbox configured', 'Set NEWSLETTER_MAILBOX_HOST, _USERNAME, _PASSWORD');

            return;
        }

        try {
            $info = app(MailboxProcessor::class)->test();
            $this->check(true, "Mailbox opened ({$info['exists']} messages)");
        } catch (Throwable $e) {
            $this->check(false, 'Mailbox login', $e->getMessage());
        }
    }

    /** @param  array<string, mixed>  $mailerConfig */
    private function isNetcup(array $mailerConfig, string $domain, DnsResolver $dns): bool
    {
        if (preg_match('/(netcup\.net|webhosting\.systems)$/i', (string) ($mailerConfig['host'] ?? ''))) {
            return true;
        }

        if ($domain === '' || $this->option('skip-dns') || ! $dns->available()) {
            return false;
        }

        foreach ($dns->txt($domain) as $txt) {
            if (stripos($txt, '_spf.webhosting.systems') !== false) {
                return true;
            }
        }

        return false;
    }

    private function section(string $title): void
    {
        $this->newLine();
        $this->line("  <options=bold>{$title}</>");
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
