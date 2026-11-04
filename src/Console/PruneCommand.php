<?php

namespace JensS\Newsletter\Console;

use Illuminate\Console\Command;
use JensS\Newsletter\Enums\DeliveryStatus;
use JensS\Newsletter\Enums\SubscriptionStatus;
use JensS\Newsletter\Models\Delivery;
use JensS\Newsletter\Models\Subscription;
use JensS\Newsletter\Models\WebhookEvent;
use JensS\Newsletter\Support\AudienceRegistry;
use JensS\Newsletter\Support\Email;

class PruneCommand extends Command
{
    protected $signature = 'newsletter:prune';

    protected $description = 'Apply the retention rules from config/newsletter.php';

    public function handle(AudienceRegistry $audiences): int
    {
        $rules = (array) config('newsletter.retention', []);

        if ($days = $rules['unconfirmed_subscriptions_days'] ?? null) {
            $n = Subscription::query()->where('status', SubscriptionStatus::Pending->value)
                ->where('created_at', '<', now()->subDays((int) $days))->delete();
            $this->components->twoColumnDetail('Unconfirmed subscriptions deleted', (string) $n);
        }

        if ($days = $rules['unsubscribed_subscriptions_days'] ?? null) {
            // Opt-outs from application-provided audiences are what keeps those people
            // excluded, so they are never pruned.
            $subscriptionAudiences = array_keys(array_filter($audiences->all(), fn ($a) => $a->usesSubscriptions()));
            $n = Subscription::query()->where('status', SubscriptionStatus::Unsubscribed->value)
                ->whereIn('audience', $subscriptionAudiences)
                ->where('unsubscribed_at', '<', now()->subDays((int) $days))->delete();
            $this->components->twoColumnDetail('Old unsubscribes deleted', (string) $n);
        }

        if ($days = $rules['delivery_records_days'] ?? null) {
            $n = 0;
            Delivery::query()
                ->whereNull('anonymized_at')
                ->whereNotIn('status', DeliveryStatus::pendingValues())
                ->where('created_at', '<', now()->subDays((int) $days))
                ->chunkById(500, function ($deliveries) use (&$n) {
                    foreach ($deliveries as $delivery) {
                        $delivery->forceFill([
                            'email' => Email::anonymize($delivery->email),
                            'name' => null,
                            'personalization' => null,
                            'subscribable_type' => null,
                            'subscribable_id' => null,
                            'anonymized_at' => now(),
                        ])->save();
                        $n++;
                    }
                });
            $this->components->twoColumnDetail('Delivery records anonymised', (string) $n);
        }

        if ($days = $rules['webhook_events_days'] ?? null) {
            $n = WebhookEvent::query()->where('created_at', '<', now()->subDays((int) $days))->delete();
            $this->components->twoColumnDetail('Webhook events deleted', (string) $n);
        }

        return self::SUCCESS;
    }
}
