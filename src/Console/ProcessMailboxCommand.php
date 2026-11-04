<?php

namespace JensS\Newsletter\Console;

use Illuminate\Console\Command;
use JensS\Newsletter\Mailbox\ImapException;
use JensS\Newsletter\Mailbox\MailboxProcessor;

class ProcessMailboxCommand extends Command
{
    protected $signature = 'newsletter:process-mailbox
        {--dry-run : Classify messages without changing anything}
        {--test : Only check that the mailbox can be opened}';

    protected $description = 'Read bounces, spam complaints and mailto unsubscribes from the bounce mailbox (IMAP)';

    public function handle(MailboxProcessor $processor): int
    {
        if (! MailboxProcessor::configured()) {
            $this->components->error('No bounce mailbox configured. Set NEWSLETTER_MAILBOX_HOST, _USERNAME and _PASSWORD.');

            return self::FAILURE;
        }

        try {
            if ($this->option('test')) {
                $info = $processor->test();
                $this->components->info("Mailbox OK: {$info['exists']} message(s) in ".config('newsletter.mailbox.folder'));

                return self::SUCCESS;
            }

            $stats = $processor->run((bool) $this->option('dry-run'), fn (string $line) => $this->output->isVerbose() || $this->option('dry-run') ? $this->line($line) : null);
        } catch (ImapException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($stats as $name => $count) {
            $this->components->twoColumnDetail(str_replace('_', ' ', ucfirst($name)), (string) $count);
        }

        return self::SUCCESS;
    }
}
