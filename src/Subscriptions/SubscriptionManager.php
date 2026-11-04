<?php

namespace JensS\Newsletter\Subscriptions;

use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JensS\Newsletter\Contracts\HandlesUnsubscribes;
use JensS\Newsletter\Contracts\LooksUpByEmail;
use JensS\Newsletter\Enums\SubscriptionStatus;
use JensS\Newsletter\Events\Subscribed;
use JensS\Newsletter\Events\SubscriptionRequested;
use JensS\Newsletter\Events\Unsubscribed;
use JensS\Newsletter\Mail\ConfirmSubscription;
use JensS\Newsletter\Models\Subscription;
use JensS\Newsletter\Sending\MessageFactory;
use JensS\Newsletter\Recipients\ConfiguredMemberResolver;
use JensS\Newsletter\Support\Audience;
use JensS\Newsletter\Support\AudienceRegistry;
use JensS\Newsletter\Support\Email;

final class SubscriptionManager
{
    /** Do not send another confirmation mail within this many minutes. */
    private const CONFIRMATION_RESEND_MINUTES = 10;

    public function __construct(
        private readonly AudienceRegistry $audiences,
        private readonly MailFactory $mail,
        private readonly ConfiguredMemberResolver $members,
    ) {}

    /**
     * Subscribe an address to a subscription-based audience.
     *
     * Options: name, locale, source, ip, consent_text, metadata,
     * member (Eloquent model to link), confirmed (bool: skip double opt-in,
     * e.g. for a logged-in member with a verified address).
     *
     * @param  array<string, mixed>  $options
     */
    public function subscribe(string $audience, ?string $email, array $options = []): Subscription
    {
        $definition = $this->audiences->get($audience);
        $member = $options['member'] ?? null;

        if (! $definition->usesSubscriptions()) {
            throw new InvalidArgumentException(
                "Audience [{$audience}] is managed by your application. Use clearOptOut() to undo a package opt-out."
            );
        }

        if ($member instanceof Model) {
            $email ??= $this->members->extract($member)[0];
        }

        if ($email === null || ! Email::isValid($email)) {
            throw new InvalidArgumentException('A valid email address is required.');
        }

        if ($definition->linksMembers() && ! $member instanceof Model) {
            throw new InvalidArgumentException("Audience [{$audience}] only accepts linked members (pass the 'member' option).");
        }

        $email = Email::normalize($email);
        $needsConfirmation = $definition->requiresDoubleOptIn() && ! ($options['confirmed'] ?? false);

        /** @var Subscription $subscription */
        [$subscription, $sendConfirmation, $token] = DB::transaction(function () use ($audience, $email, $options, $member, $needsConfirmation) {
            $subscription = $this->findExisting($audience, $email, $member) ?? new Subscription([
                'audience' => $audience,
                'email' => $email,
            ]);

            $subscription->email = $email;
            $subscription->name = $options['name'] ?? $subscription->name;
            $subscription->locale = $options['locale'] ?? $subscription->locale ?? app()->getLocale();

            if ($member instanceof Model) {
                $subscription->subscribable()->associate($member);
            }

            if (isset($options['metadata'])) {
                $subscription->metadata = array_merge((array) $subscription->metadata, (array) $options['metadata']);
            }

            if ($subscription->exists && $subscription->status === SubscriptionStatus::Subscribed) {
                $subscription->save();

                return [$subscription, false, null];
            }

            $subscription->source = $options['source'] ?? $subscription->source ?? 'api';
            $subscription->consent_ip = $options['ip'] ?? null;
            $subscription->consent_text = $options['consent_text'] ?? $subscription->consent_text;
            $subscription->unsubscribed_at = null;
            $subscription->unsubscribe_source = null;

            if (! $needsConfirmation) {
                $subscription->status = SubscriptionStatus::Subscribed;
                $subscription->consent_at = $options['consent_at'] ?? now();
                $subscription->confirmed_at = ($options['confirmed'] ?? false) ? now() : null;
                $subscription->confirmation_token = null;
                $subscription->save();

                return [$subscription, false, null];
            }

            $recentlySent = $subscription->status === SubscriptionStatus::Pending
                && $subscription->confirmation_sent_at?->gt(now()->subMinutes(self::CONFIRMATION_RESEND_MINUTES));

            $subscription->status = SubscriptionStatus::Pending;

            if ($recentlySent) {
                $subscription->save();

                return [$subscription, false, null];
            }

            $token = Str::random(40);
            $subscription->confirmation_token = hash('sha256', $token);
            $subscription->confirmation_sent_at = now();
            $subscription->save();

            return [$subscription, true, $token];
        });

        if ($subscription->wasRecentlyCreated || $subscription->wasChanged('status')) {
            if ($subscription->status === SubscriptionStatus::Subscribed) {
                event(new Subscribed($subscription));
            }
        }

        if ($sendConfirmation) {
            $this->sendConfirmation($subscription, $definition, $token);
            event(new SubscriptionRequested($subscription));
        }

        return $subscription;
    }

    public function confirm(string $token): ?Subscription
    {
        $subscription = $this->findByConfirmationToken($token);

        if ($subscription === null) {
            return null;
        }

        $updated = Subscription::query()
            ->whereKey($subscription->id)
            ->where('status', SubscriptionStatus::Pending->value)
            ->update([
                'status' => SubscriptionStatus::Subscribed->value,
                'confirmed_at' => now(),
                'consent_at' => now(),
                'confirmation_token' => null,
                'updated_at' => now(),
            ]);

        $subscription->refresh();

        if ($updated) {
            event(new Subscribed($subscription));
        }

        return $subscription;
    }

    /** Pending subscription for a still-valid token, without confirming it. */
    public function findByConfirmationToken(string $token): ?Subscription
    {
        $hours = (int) config('newsletter.subscriptions.confirmation_expires_hours', 72);

        return Subscription::query()
            ->where('confirmation_token', hash('sha256', $token))
            ->where('status', SubscriptionStatus::Pending->value)
            ->where('confirmation_sent_at', '>=', now()->subHours($hours))
            ->first();
    }

    /**
     * Withdraw an address from one audience. Works for both subscription and
     * application-provided audiences; for the latter an opt-out record is kept
     * and honoured until clearOptOut() is called.
     */
    public function unsubscribe(string $audience, string $email, string $source = 'link', ?Model $member = null): ?Subscription
    {
        $definition = $this->audiences->get($audience);
        $email = Email::normalize($email);

        $subscription = $this->findExisting($audience, $email, $member);

        if ($subscription === null) {
            if ($definition->usesSubscriptions()) {
                return null;
            }

            $subscription = new Subscription(['audience' => $audience, 'email' => $email, 'source' => 'application']);

            if ($member) {
                $subscription->subscribable()->associate($member);
            }
        }

        if ($subscription->exists && $subscription->status === SubscriptionStatus::Unsubscribed) {
            return $subscription;
        }

        $subscription->status = SubscriptionStatus::Unsubscribed;
        $subscription->unsubscribed_at = now();
        $subscription->unsubscribe_source = $source;
        $subscription->confirmation_token = null;
        $subscription->save();

        if (! $definition->usesSubscriptions()) {
            $provider = $definition->applicationProvider();

            if ($provider instanceof HandlesUnsubscribes) {
                $provider->unsubscribed($email, $member);
            }
        }

        event(new Unsubscribed($subscription, $source));

        return $subscription;
    }

    /** Remove a package opt-out for an application-provided audience. */
    public function clearOptOut(string $audience, string $email): bool
    {
        return Subscription::query()
            ->forAudience($audience)
            ->where('email', Email::normalize($email))
            ->where('status', SubscriptionStatus::Unsubscribed->value)
            ->delete() > 0;
    }

    public function isSubscribed(string $audience, string|Model $who): bool
    {
        $definition = $this->audiences->get($audience);
        [$email, $member] = $this->identify($who);

        if ($definition->usesSubscriptions()) {
            $subscription = $this->findExisting($audience, (string) $email, $member);

            return $subscription?->status === SubscriptionStatus::Subscribed;
        }

        if ($email === null || $this->optedOut($audience, $email)) {
            return false;
        }

        $provider = $definition->applicationProvider();

        return $provider instanceof LooksUpByEmail && $provider->findByEmail($email) !== null;
    }

    /**
     * Audiences shown on the login-free preference page for an address.
     *
     * @return array<string, array{label: string, description: ?string, subscribed: bool}>
     */
    public function preferences(string $email): array
    {
        $email = Email::normalize($email);
        $out = [];

        foreach ($this->audiences->all() as $key => $audience) {
            $state = $this->preferenceState($audience, $email);

            if ($state !== null) {
                $out[$key] = [
                    'label' => $audience->label(),
                    'description' => $audience->description(),
                    'subscribed' => $state,
                ];
            }
        }

        return $out;
    }

    /**
     * Apply the preference page form. Possession of the signed link proves
     * mailbox access, so re-subscribing here counts as confirmed.
     *
     * @param  list<string>  $selected
     */
    public function updatePreferences(string $email, array $selected, ?string $ip = null): void
    {
        $email = Email::normalize($email);

        foreach ($this->preferences($email) as $key => $item) {
            $wanted = in_array($key, $selected, true);

            if ($wanted === $item['subscribed']) {
                continue;
            }

            $audience = $this->audiences->get($key);

            if (! $wanted) {
                $this->unsubscribe($key, $email, 'preferences');

                continue;
            }

            if (! $audience->usesSubscriptions()) {
                $this->clearOptOut($key, $email);

                continue;
            }

            $member = $audience->linksMembers() ? $this->memberByEmail($email) : null;

            if ($audience->linksMembers() && $member === null) {
                continue;
            }

            $this->subscribe($key, $email, [
                'member' => $member,
                'source' => 'preferences',
                'ip' => $ip,
                'confirmed' => true,
            ]);
        }
    }

    /**
     * Import existing records, keeping their original consent data.
     *
     * Row keys: email, status (subscribed | pending | unsubscribed, default
     * $defaultStatus), name, locale, source, ip, consent_at, confirmed_at,
     * requested_at (pending rows), unsubscribed_at.
     *
     * - subscribed: active immediately, with the original consent timestamps.
     * - pending: kept unconfirmed. No confirmation mail is sent; the person gets
     *   one when they sign up again. Expires under the retention rules, counted
     *   from requested_at.
     * - unsubscribed: recorded withdrawal. Also accepted for application-provided
     *   audiences, where it acts as an opt-out. It overrides an existing
     *   subscription unless that subscription's consent is newer.
     *
     * No events are dispatched for imported rows.
     *
     * @param  iterable<array<string, mixed>>  $rows
     * @return array{imported: int, updated: int, skipped: int}
     */
    public function import(string $audience, iterable $rows, string $source = 'import', string $defaultStatus = 'subscribed'): array
    {
        $definition = $this->audiences->get($audience);

        if ($definition->linksMembers()) {
            throw new InvalidArgumentException("Audience [{$audience}] links members; members opt in themselves and cannot be imported.");
        }

        $imported = $updated = $skipped = 0;

        foreach ($rows as $row) {
            $email = isset($row['email']) ? Email::normalize((string) $row['email']) : '';
            $status = self::importStatus($row['status'] ?? $defaultStatus);

            try {
                $dates = [
                    'consent_at' => self::date($row['consent_at'] ?? null),
                    'confirmed_at' => self::date($row['confirmed_at'] ?? null),
                    'requested_at' => self::date($row['requested_at'] ?? null),
                    'unsubscribed_at' => self::date($row['unsubscribed_at'] ?? null),
                ];
            } catch (\Throwable) {
                $skipped++;

                continue;
            }

            // Application-owned audiences only store opt-outs.
            if (! Email::isValid($email) || $status === null || (! $definition->usesSubscriptions() && $status !== SubscriptionStatus::Unsubscribed)) {
                $skipped++;

                continue;
            }

            $existing = $this->findExisting($audience, $email, null);

            if ($existing !== null) {
                $withdrawalIsNewer = $status === SubscriptionStatus::Unsubscribed
                    && $existing->status !== SubscriptionStatus::Unsubscribed
                    && ($dates['unsubscribed_at'] === null || $existing->consent_at === null || $dates['unsubscribed_at']->gte($existing->consent_at));

                if (! $withdrawalIsNewer) {
                    $skipped++;

                    continue;
                }

                $existing->forceFill([
                    'status' => SubscriptionStatus::Unsubscribed,
                    'unsubscribed_at' => $dates['unsubscribed_at'] ?? now(),
                    'unsubscribe_source' => $row['source'] ?? $source,
                    'confirmation_token' => null,
                ])->save();
                $updated++;

                continue;
            }

            $attributes = [
                'audience' => $audience,
                'email' => $email,
                'name' => $row['name'] ?? null,
                'locale' => $row['locale'] ?? null,
                'status' => $status,
                'source' => $row['source'] ?? $source,
                'consent_at' => $dates['consent_at'],
                'confirmed_at' => $dates['confirmed_at'],
                'consent_ip' => $row['ip'] ?? null,
            ];

            if ($status === SubscriptionStatus::Unsubscribed) {
                $attributes['unsubscribed_at'] = $dates['unsubscribed_at'] ?? now();
                $attributes['unsubscribe_source'] = $row['source'] ?? $source;
            }

            $subscription = new Subscription($attributes);

            if ($status === SubscriptionStatus::Pending && $dates['requested_at'] !== null) {
                // Retention for unconfirmed subscriptions counts from the original request.
                $subscription->created_at = $dates['requested_at'];
            }

            $subscription->save();
            $imported++;
        }

        return compact('imported', 'updated', 'skipped');
    }

    /** Map common export values ("active", "unconfirmed", "opted_out", 1/0 …) to a status. */
    public static function importStatus(mixed $value): ?SubscriptionStatus
    {
        return match (strtolower(trim((string) $value))) {
            'subscribed', 'confirmed', 'active', 'opted_in', 'optin', '1', 'yes', 'true' => SubscriptionStatus::Subscribed,
            'pending', 'unconfirmed', 'awaiting_confirmation' => SubscriptionStatus::Pending,
            'unsubscribed', 'opted_out', 'optout', 'inactive', 'withdrawn', '0', 'no', 'false' => SubscriptionStatus::Unsubscribed,
            default => null,
        };
    }

    private static function date(mixed $value): ?Carbon
    {
        return $value === null || $value === '' ? null : Carbon::parse($value);
    }

    public function findExisting(string $audience, string $email, ?Model $member): ?Subscription
    {
        if ($member instanceof Model) {
            $linked = Subscription::query()
                ->forAudience($audience)
                ->where('subscribable_type', $member->getMorphClass())
                ->where('subscribable_id', $member->getKey())
                ->first();

            if ($linked) {
                return $linked;
            }
        }

        return Subscription::query()
            ->forAudience($audience)
            ->where('email', Email::normalize($email))
            ->first();
    }

    public function memberByEmail(string $email): ?Model
    {
        $class = config('newsletter.members.model');
        $column = config('newsletter.members.map.email', 'email');

        if (! is_string($class) || ! class_exists($class)) {
            return null;
        }

        return $class::query()->where($column, Email::normalize($email))->first();
    }

    private function preferenceState(Audience $audience, string $email): ?bool
    {
        $row = Subscription::query()->forAudience($audience->key)->where('email', $email)->first();

        if ($audience->usesSubscriptions()) {
            if ($row !== null) {
                return $row->status === SubscriptionStatus::Subscribed;
            }

            if ($audience->allowsPublicSignup()) {
                return false;
            }

            return $audience->linksMembers() && $this->memberByEmail($email) !== null ? false : null;
        }

        $provider = $audience->applicationProvider();

        if (! $provider instanceof LooksUpByEmail || $provider->findByEmail($email) === null) {
            return $row !== null ? false : null; // opted out earlier: show so they can see the state
        }

        return $row === null || $row->status !== SubscriptionStatus::Unsubscribed;
    }

    private function optedOut(string $audience, string $email): bool
    {
        return Subscription::query()
            ->forAudience($audience)
            ->where('email', Email::normalize($email))
            ->where('status', SubscriptionStatus::Unsubscribed->value)
            ->exists();
    }

    /** @return array{0: ?string, 1: ?Model} */
    private function identify(string|Model $who): array
    {
        if ($who instanceof Model) {
            return [$this->members->extract($who)[0], $who];
        }

        return [Email::normalize($who), null];
    }

    private function sendConfirmation(Subscription $subscription, Audience $audience, string $token): void
    {
        $mailable = new ConfirmSubscription(
            $subscription,
            route('newsletter.confirm', ['token' => $token]),
            $audience->label(),
        );

        MessageFactory::applyReturnPath($mailable);

        $mailer = $this->mail->mailer(config('newsletter.mail.mailer'));
        $pending = $mailer->to($subscription->email, $subscription->name);

        if (config('newsletter.subscriptions.queue_confirmation', false)) {
            $pending->queue($mailable->onConnection(config('newsletter.queue.connection'))->onQueue(config('newsletter.queue.name')));
        } else {
            $pending->send($mailable);
        }
    }
}
