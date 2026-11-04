<?php

namespace JensS\Newsletter\Tests\Feature;

use JensS\Newsletter\Support\DnsResolver;
use JensS\Newsletter\Tests\Fixtures\FakeDns;
use JensS\Newsletter\Tests\TestCase;

class DoctorNetcupTest extends TestCase
{
    private function netcupSetup(array $overrides = []): void
    {
        config(array_merge([
            'mail.default' => 'smtp',
            'mail.mailers.smtp' => [
                'transport' => 'smtp', 'scheme' => 'smtps', 'host' => 'mx2f80.netcup.net', 'port' => 465,
                'username' => 'newsletter@example.org', 'password' => 'x',
            ],
            'newsletter.mail.from.address' => 'newsletter@example.org',
            'newsletter.worker.tick_token' => 'set',
            'newsletter.rate_limit' => ['per_second' => 1, 'per_minute' => 20, 'per_hour' => 300, 'per_day' => 2000, 'per_domain_per_minute' => 10],
            'newsletter.mailbox.enabled' => true,
            'newsletter.mailbox.host' => 'mx2f80.netcup.net',
            'newsletter.mailbox.username' => 'newsletter@example.org',
            'newsletter.mail.unsubscribe_mailto' => 'newsletter@example.org',
            'app.url' => 'https://example.org',
        ], $overrides));
    }

    private function dns(array $without = []): FakeDns
    {
        $records = [
            'txt' => [
                'example.org' => ['v=spf1 mx a include:_spf.webhosting.systems ~all'],
                '_dmarc.example.org' => ['v=DMARC1; p=none; rua=mailto:dmarc@example.org'],
            ],
            'cname' => [
                'key1._domainkey.example.org' => ['key1._domainkey.webhosting.systems'],
                'key2._domainkey.example.org' => ['key2._domainkey.webhosting.systems'],
            ],
            'a' => ['mx2f80.netcup.net' => ['192.0.2.25'], 'mx2f80.netcup.net.' => ['192.0.2.25']],
            'ptr' => ['192.0.2.25' => ['mx2f80.netcup.net']],
        ];

        foreach ($without as [$type, $host]) {
            unset($records[$type][$host]);
        }

        $fake = new FakeDns($records);
        $this->app->instance(DnsResolver::class, $fake);

        return $fake;
    }

    public function test_a_correct_netcup_setup_passes(): void
    {
        $this->netcupSetup();
        $this->dns();

        $this->artisan('newsletter:doctor')
            ->expectsOutputToContain('netcup detected')
            ->expectsOutputToContain('DKIM record for selector [key2]')
            ->assertSuccessful();
    }

    public function test_missing_dkim_cname_fails_with_the_exact_record_to_add(): void
    {
        $this->netcupSetup();
        $this->dns([['cname', 'key2._domainkey.example.org']]);

        $this->artisan('newsletter:doctor')
            ->expectsOutputToContain('CNAME key2._domainkey → key2._domainkey.webhosting.systems')
            ->assertFailed();
    }

    public function test_spf_without_netcup_include_and_duplicate_spf_fail(): void
    {
        $this->netcupSetup();
        $dns = $this->dns();
        $dns->records['txt']['example.org'] = ['v=spf1 mx ~all', 'v=spf1 include:other.example ~all'];

        $this->artisan('newsletter:doctor')
            ->expectsOutputToContain('Merge them into one')
            ->assertFailed();

        $dns->records['txt']['example.org'] = ['v=spf1 mx a ~all'];
        $this->artisan('newsletter:doctor')
            ->expectsOutputToContain('include:_spf.webhosting.systems')
            ->assertFailed();
    }

    public function test_sender_domain_must_match_the_smtp_mailbox(): void
    {
        $this->netcupSetup(['newsletter.mail.from.address' => 'news@other-domain.example']);
        $this->dns();

        $this->artisan('newsletter:doctor', ['--skip-dns' => true])
            ->expectsOutputToContain('fails DMARC alignment')
            ->assertFailed();
    }

    public function test_php_mail_and_port_25_are_rejected(): void
    {
        $this->netcupSetup(['mail.mailers.smtp' => ['transport' => 'sendmail']]);
        $this->artisan('newsletter:doctor', ['--skip-dns' => true])
            ->expectsOutputToContain('only DKIM-signs mail sent through its mail server')
            ->assertFailed();

        $this->netcupSetup(['mail.mailers.smtp' => ['transport' => 'smtp', 'host' => 'mx2f80.netcup.net', 'port' => 25, 'username' => 'newsletter@example.org']]);
        $this->artisan('newsletter:doctor', ['--skip-dns' => true])
            ->expectsOutputToContain('Use 465 (implicit TLS) or 587 (STARTTLS)')
            ->assertFailed();
    }

    public function test_missing_bounce_mailbox_and_limits_are_warned(): void
    {
        $this->netcupSetup(['newsletter.mailbox.enabled' => false, 'newsletter.rate_limit' => ['per_minute' => 100]]);
        $this->dns();

        $this->artisan('newsletter:doctor')
            ->expectsOutputToContain('Bounce mailbox is configured (IMAP)')
            ->expectsOutputToContain('ask netcup support')
            ->assertSuccessful();
    }
}
