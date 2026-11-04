<?php

namespace JensS\Newsletter\Subscriptions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use JensS\Newsletter\Enums\DeliveryStatus;
use JensS\Newsletter\Events\MemberForgotten;
use JensS\Newsletter\Models\Delivery;
use JensS\Newsletter\Models\Subscription;
use JensS\Newsletter\Models\Suppression;
use JensS\Newsletter\Recipients\ConfiguredMemberResolver;
use JensS\Newsletter\Support\Email;

/**
 * Hooks for account changes: call these from your account controller or let
 * the HasNewsletterSubscriptions trait call them automatically.
 */
final class MemberLifecycle
{
    public function __construct(private readonly ConfiguredMemberResolver $members) {}

    /**
     * Move a member's subscriptions to a new address. Queued deliveries pick up
     * the new address when they are sent (recipients are re-resolved).
     */
    public function changeEmail(Model|string $memberOrOldEmail, string $newEmail, ?string $oldEmail = null): int
    {
        $newEmail = Email::normalize($newEmail);

        return DB::transaction(function () use ($memberOrOldEmail, $newEmail, $oldEmail) {
            $rows = $this->subscriptionsFor($memberOrOldEmail, $oldEmail)->get();
            $moved = 0;

            foreach ($rows as $subscription) {
                $clash = Subscription::query()
                    ->forAudience($subscription->audience)
                    ->where('email', $newEmail)
                    ->whereKeyNot($subscription->id)
                    ->first();

                // An unlinked public subscription with the new address already exists: keep the member-linked one.
                $clash?->delete();

                $subscription->forceFill(['email' => $newEmail])->save();
                $moved++;
            }

            return $moved;
        });
    }

    /**
     * Erase newsletter data for a member (account deletion). Pending deliveries
     * are skipped, subscriptions deleted, delivery history anonymised.
     * Suppression entries are kept so a bounced/complaining address is never
     * mailed again; pass $keepSuppression = false to remove them too.
     */
    public function forget(Model|string $member, bool $keepSuppression = true): void
    {
        [$email, $model] = $this->identify($member);
        $emails = array_values(array_filter(array_unique(array_merge(
            [$email],
            $this->subscriptionsFor($member)->pluck('email')->all(),
        ))));

        DB::transaction(function () use ($emails, $model, $member, $keepSuppression) {
            $deliveries = Delivery::query()->where(function (Builder $q) use ($emails, $model) {
                $q->whereIn('email', $emails);

                if ($model) {
                    $q->orWhere(fn ($q) => $q->where('subscribable_type', $model->getMorphClass())->where('subscribable_id', $model->getKey()));
                }
            });

            (clone $deliveries)->where('status', DeliveryStatus::Queued->value)->update([
                'status' => DeliveryStatus::Skipped->value,
                'last_error' => 'Recipient account deleted',
                'updated_at' => now(),
            ]);

            foreach ((clone $deliveries)->whereNull('anonymized_at')->get() as $delivery) {
                $delivery->forceFill([
                    'email' => Email::anonymize($delivery->email),
                    'name' => null,
                    'personalization' => null,
                    'subscribable_type' => null,
                    'subscribable_id' => null,
                    'anonymized_at' => now(),
                ])->save();
            }

            $this->subscriptionsFor($member)->delete();

            if (! $keepSuppression) {
                Suppression::query()->whereIn('email', $emails)->delete();
            }
        });

        event(new MemberForgotten($model, (string) $email));
    }

    /**
     * Everything stored about a member, for data-export requests.
     *
     * @return array<string, mixed>
     */
    public function export(Model|string $member): array
    {
        [$email, $model] = $this->identify($member);
        $subscriptions = $this->subscriptionsFor($member)->get();
        $emails = array_values(array_unique(array_filter(array_merge([$email], $subscriptions->pluck('email')->all()))));

        return [
            'subscriptions' => $subscriptions->map(fn (Subscription $s) => [
                'audience' => $s->audience,
                'email' => $s->email,
                'name' => $s->name,
                'status' => $s->status->value,
                'source' => $s->source,
                'consent_at' => $s->consent_at?->toIso8601String(),
                'consent_ip' => $s->consent_ip,
                'consent_text' => $s->consent_text,
                'confirmed_at' => $s->confirmed_at?->toIso8601String(),
                'unsubscribed_at' => $s->unsubscribed_at?->toIso8601String(),
                'unsubscribe_source' => $s->unsubscribe_source,
            ])->all(),
            'deliveries' => Delivery::query()->with('campaign:id,name,subject')
                ->where(function (Builder $q) use ($emails, $model) {
                    $q->whereIn('email', $emails);

                    if ($model) {
                        $q->orWhere(fn ($q) => $q->where('subscribable_type', $model->getMorphClass())->where('subscribable_id', $model->getKey()));
                    }
                })
                ->orderBy('id')
                ->get()
                ->map(fn (Delivery $d) => [
                    'campaign' => $d->campaign?->name,
                    'subject' => $d->campaign?->subject,
                    'email' => $d->email,
                    'status' => $d->status->value,
                    'accepted_at' => $d->accepted_at?->toIso8601String(),
                    'delivered_at' => $d->delivered_at?->toIso8601String(),
                ])->all(),
            'suppressions' => Suppression::query()->whereIn('email', $emails)->get(['email', 'reason', 'created_at'])
                ->map(fn ($s) => ['email' => $s->email, 'reason' => $s->reason, 'since' => $s->created_at?->toIso8601String()])
                ->all(),
        ];
    }

    private function subscriptionsFor(Model|string $member, ?string $email = null): Builder
    {
        [$resolvedEmail, $model] = $this->identify($member);
        $email = $email ? Email::normalize($email) : $resolvedEmail;

        return Subscription::query()->where(function (Builder $q) use ($email, $model) {
            if ($email) {
                $q->where('email', $email);
            }

            if ($model) {
                $q->orWhere(fn ($q) => $q->where('subscribable_type', $model->getMorphClass())->where('subscribable_id', $model->getKey()));
            }
        });
    }

    /** @return array{0: ?string, 1: ?Model} */
    private function identify(Model|string $member): array
    {
        if (is_string($member)) {
            return [Email::normalize($member), null];
        }

        $email = $this->members->extract($member)[0];

        return [$email ? Email::normalize($email) : null, $member];
    }
}
