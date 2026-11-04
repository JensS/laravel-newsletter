<?php

namespace JensS\Newsletter\Console;

use Illuminate\Console\Command;
use JensS\Newsletter\Exceptions\CampaignException;
use JensS\Newsletter\Models\Campaign;
use JensS\Newsletter\Sending\CampaignDispatcher;

class SendCommand extends Command
{
    protected $signature = 'newsletter:send {campaign : Campaign id or uuid} {--force : Do not ask for confirmation}';

    protected $description = 'Freeze a campaign and start sending it now';

    public function handle(CampaignDispatcher $dispatcher): int
    {
        $id = (string) $this->argument('campaign');
        $campaign = Campaign::query()->where(is_numeric($id) ? 'id' : 'uuid', $id)->first();

        if ($campaign === null) {
            $this->components->error('Campaign not found.');

            return self::FAILURE;
        }

        $count = $dispatcher->estimateRecipients($campaign);

        if (! $this->option('force') && ! $this->confirm("Send \"{$campaign->subject}\" to about {$count} recipients of [{$campaign->audience}]?")) {
            return self::SUCCESS;
        }

        try {
            $dispatcher->start($campaign);
        } catch (CampaignException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Sending started. Deliveries are processed by the queue worker.');

        return self::SUCCESS;
    }
}
