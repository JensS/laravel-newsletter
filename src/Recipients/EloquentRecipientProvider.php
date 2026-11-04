<?php

namespace JensS\Newsletter\Recipients;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use JensS\Newsletter\Contracts\LooksUpByEmail;
use JensS\Newsletter\Contracts\RecipientProvider;
use JensS\Newsletter\Support\Email;
use JensS\Newsletter\Support\Recipient;
use JensS\Newsletter\Support\RecipientPage;

/**
 * Base class for application-owned audiences backed by an Eloquent query.
 *
 *     class WeeklyRecapRecipients extends EloquentRecipientProvider
 *     {
 *         protected function query(): Builder
 *         {
 *             return User::query()->where('approved', true)->where('weekly_recap', true);
 *         }
 *     }
 *
 * The query must only contain currently eligible records; it is re-run for a
 * single record immediately before each message is sent.
 */
abstract class EloquentRecipientProvider implements LooksUpByEmail, RecipientProvider
{
    abstract protected function query(): Builder;

    /** Map a model to a recipient. Override for custom columns or attributes. */
    protected function toRecipient(Model $model): ?Recipient
    {
        [$email, $name, $locale] = app(ConfiguredMemberResolver::class)->extract($model);

        if ($email === null || ! Email::isValid($email)) {
            return null;
        }

        return new Recipient(
            key: $this->keyFor($model),
            email: $email,
            name: $name,
            locale: $locale,
            subscribable: $model,
        );
    }

    public function page(?string $cursor, int $limit): RecipientPage
    {
        $query = $this->query();
        $keyName = $query->getModel()->getQualifiedKeyName();

        $rows = $query
            ->when($cursor !== null, fn ($q) => $q->where($keyName, '>', $cursor))
            ->reorder($keyName)
            ->limit($limit)
            ->get();

        $recipients = [];
        foreach ($rows as $model) {
            if ($recipient = $this->toRecipient($model)) {
                $recipients[] = $recipient;
            }
        }

        return new RecipientPage(
            $recipients,
            $rows->count() === $limit ? (string) $rows->last()->getKey() : null,
        );
    }

    public function count(): int
    {
        return $this->query()->count();
    }

    public function find(string $key): ?Recipient
    {
        $model = $this->query()->getModel();
        $prefix = $model->getMorphClass().':';

        if (! str_starts_with($key, $prefix)) {
            return null;
        }

        $found = $this->query()->whereKey(substr($key, strlen($prefix)))->first();

        return $found ? $this->toRecipient($found) : null;
    }

    public function findByEmail(string $email): ?Recipient
    {
        $found = $this->query()->where($this->emailColumn(), Email::normalize($email))->first();

        return $found ? $this->toRecipient($found) : null;
    }

    /** Column holding the address; used by the preference page lookup. */
    protected function emailColumn(): string
    {
        return (string) config('newsletter.members.map.email', 'email');
    }

    protected function keyFor(Model $model): string
    {
        return $model->getMorphClass().':'.$model->getKey();
    }
}
