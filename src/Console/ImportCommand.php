<?php

namespace JensS\Newsletter\Console;

use Illuminate\Console\Command;
use JensS\Newsletter\Subscriptions\SubscriptionManager;

class ImportCommand extends Command
{
    protected $signature = 'newsletter:import
        {audience : Target audience}
        {file : CSV file with a header row (email, status, name, locale, consent_at, confirmed_at, requested_at, unsubscribed_at, source, ip)}
        {--source=import : Source recorded for rows without one}
        {--status=subscribed : Status for rows without a status column: subscribed, pending or unsubscribed}';

    protected $description = 'Import subscribers, pending sign-ups and opt-outs, keeping their original consent data';

    public function handle(SubscriptionManager $subscriptions): int
    {
        $path = (string) $this->argument('file');

        if (! is_readable($path)) {
            $this->components->error("Cannot read {$path}");

            return self::FAILURE;
        }

        $handle = fopen($path, 'r');
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), fgetcsv($handle, escape: '\\') ?: []);

        if (! in_array('email', $header, true)) {
            $this->components->error('The CSV needs an "email" column.');

            return self::FAILURE;
        }

        $rows = (function () use ($handle, $header) {
            while (($line = fgetcsv($handle, escape: '\\')) !== false) {
                if (count($line) === count($header)) {
                    yield array_filter(array_combine($header, $line), fn ($v) => $v !== '' && $v !== null);
                }
            }
        })();

        if (SubscriptionManager::importStatus($this->option('status')) === null) {
            $this->components->error('Unknown --status; use subscribed, pending or unsubscribed.');

            return self::FAILURE;
        }

        try {
            $result = $subscriptions->import((string) $this->argument('audience'), $rows, (string) $this->option('source'), (string) $this->option('status'));
        } catch (\InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        } finally {
            fclose($handle);
        }

        $this->components->info("Imported {$result['imported']}, updated {$result['updated']} (opt-outs applied), skipped {$result['skipped']} (invalid, unsupported or already present).");

        return self::SUCCESS;
    }
}
