<?php

namespace JensS\Newsletter;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use JensS\Newsletter\Contracts\DeliveryDriver;
use JensS\Newsletter\Drivers\MailerDriver;
use JensS\Newsletter\Drivers\ResendDriver;
use JensS\Newsletter\Models\Campaign;
use JensS\Newsletter\Support\AudienceRegistry;
use JensS\Newsletter\Support\BlockRegistry;
use JensS\Newsletter\Support\SuppressionList;

class NewsletterServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/newsletter.php', 'newsletter');

        $this->app->singleton(AudienceRegistry::class);
        $this->app->singleton(BlockRegistry::class);
        $this->app->singleton(SuppressionList::class);
        $this->app->singleton(Newsletter::class);
        $this->app->singleton(Sending\CircuitBreaker::class);

        $this->app->bind(DeliveryDriver::class, function ($app) {
            $driver = config('newsletter.mail.driver', 'mailer');

            return match ($driver) {
                'mailer' => $app->make(MailerDriver::class),
                'resend' => $app->make(ResendDriver::class),
                default => $app->make($driver),
            };
        });
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'newsletter');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'newsletter');

        if (config('newsletter.routes.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/newsletter.php');
        }

        $this->registerAuthorization();

        if ($this->app->runningInConsole()) {
            $this->registerPublishing();

            $this->commands([
                Console\InstallCommand::class,
                Console\WorkCommand::class,
                Console\MaintainCommand::class,
                Console\SendCommand::class,
                Console\PruneCommand::class,
                Console\DoctorCommand::class,
                Console\ImportCommand::class,
                Console\ProcessMailboxCommand::class,
            ]);
        }

        if (config('newsletter.worker.schedule', true)) {
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
                $schedule->command('newsletter:maintain')->everyMinute()->withoutOverlapping(15);
                $schedule->command('newsletter:prune')->daily()->withoutOverlapping();
            });
        }
    }

    private function registerAuthorization(): void
    {
        $ability = (string) config('newsletter.authorization.ability', 'manage-newsletters');

        // Hosts define this ability (or a Campaign policy); until then nobody is allowed.
        if (! Gate::has($ability)) {
            Gate::define($ability, fn ($user = null) => false);
        }
    }

    private function registerPublishing(): void
    {
        $this->publishes([
            __DIR__.'/../config/newsletter.php' => config_path('newsletter.php'),
        ], 'newsletter-config');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/newsletter'),
        ], 'newsletter-views');

        $this->publishes([
            __DIR__.'/../resources/lang' => $this->app->langPath('vendor/newsletter'),
        ], 'newsletter-translations');

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'newsletter-migrations');
    }
}
