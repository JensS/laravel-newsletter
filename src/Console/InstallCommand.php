<?php

namespace JensS\Newsletter\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class InstallCommand extends Command
{
    protected $signature = 'newsletter:install
        {--views : Also publish the Blade templates}
        {--migrate : Run the migrations without asking}
        {--force : Overwrite previously published files}';

    protected $description = 'Publish the newsletter config and migrations and print the setup checklist';

    public function handle(): int
    {
        $this->components->info('Installing jenss/laravel-newsletter');

        $this->publish('newsletter-config');

        if (! $this->migrationPublished()) {
            $this->publish('newsletter-migrations');
        } else {
            $this->components->twoColumnDetail('Migrations', '<fg=yellow>already published</>');
        }

        if ($this->option('views')) {
            $this->publish('newsletter-views');
        }

        if ($this->option('migrate') || ($this->input->isInteractive() && $this->confirm('Run the migrations now?', true))) {
            $this->call('migrate');
        }

        $this->newLine();
        $this->components->info('Next steps');
        $this->line('  1. Define who may manage newsletters, e.g. in AppServiceProvider::boot():');
        $this->line("       Gate::define('manage-newsletters', fn (\$user) => \$user->is_admin);");
        $this->line('  2. Configure audiences in config/newsletter.php (members are never subscribed automatically).');
        $this->line('  3. Use a real queue connection (not "sync"): QUEUE_CONNECTION=database');
        $this->line('  4. Pick at least one way to run the queue:');
        $this->line('       hourly cron:  0 * * * * php '.base_path('artisan').' newsletter:work --max-time=3300');
        $this->line('       minute cron:  * * * * * php '.base_path('artisan').' schedule:run');
        $this->line('       HTTP tick:    NEWSLETTER_TICK_TOKEN='.Str::random(40));
        $this->line('                     then call POST '.url(config('newsletter.routes.prefix', 'newsletter').'/tick').' every minute');
        $this->line('                     with header "Authorization: Bearer <token>" (e.g. from cron-job.org).');
        $this->line('  5. Set NEWSLETTER_FROM_ADDRESS, NEWSLETTER_POSTAL_ADDRESS and authenticate the sending domain (SPF, DKIM, DMARC).');
        $this->line('  6. Run php artisan newsletter:doctor before your first send.');

        return self::SUCCESS;
    }

    private function publish(string $tag): void
    {
        $this->callSilently('vendor:publish', ['--tag' => $tag, '--force' => (bool) $this->option('force')]);
        $this->components->twoColumnDetail('Published', $tag);
    }

    private function migrationPublished(): bool
    {
        return glob(database_path('migrations/*_create_newsletter_tables.php')) !== [];
    }
}
