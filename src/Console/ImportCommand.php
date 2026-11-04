<?php

namespace JensS\Newsletter\Console;

use Illuminate\Console\Command;
use JensS\Newsletter\Subscriptions\SubscriptionManager;

class ImportCommand extends Command
{
    protected $signature = 'newsletter:import
        {audience : Target subscription audience}
        {file : CSV file with a header row (email, name, locale, consent_at, confirmed_at, source, ip)}
        {--source=import : Source recorded for rows without one}';

    protected $description = 'Import existing, already-consented subscribers (keeps original consent timestamps)';

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

        $result = $subscriptions->import((string) $this->argument('audience'), $rows, (string) $this->option('source'));
        fclose($handle);

        $this->components->info("Imported {$result['imported']}, skipped {$result['skipped']} (invalid or already present).");

        return self::SUCCESS;
    }
}
