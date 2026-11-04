<?php

namespace JensS\Newsletter\Recipients;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use JensS\Newsletter\Support\Recipient;

/**
 * Closure-based provider for registering audiences at runtime:
 *
 *     Newsletter::audiences()->register('weekly-recap', [
 *         'label' => 'Weekly recap',
 *         'source' => QueryRecipientProvider::make(
 *             fn () => User::query()->where('approved', true)->where('weekly_recap', true),
 *             fn (User $u, string $key) => new Recipient($key, $u->email, $u->display_name, $u->locale),
 *         ),
 *     ]);
 */
final class QueryRecipientProvider extends EloquentRecipientProvider
{
    /**
     * @param  Closure(): Builder  $query
     * @param  (Closure(Model, string): ?Recipient)|null  $mapper
     */
    public function __construct(
        private readonly Closure $query,
        private readonly ?Closure $mapper = null,
    ) {}

    public static function make(Closure $query, ?Closure $mapper = null): self
    {
        return new self($query, $mapper);
    }

    protected function query(): Builder
    {
        return ($this->query)();
    }

    protected function toRecipient(Model $model): ?Recipient
    {
        if ($this->mapper === null) {
            return parent::toRecipient($model);
        }

        return ($this->mapper)($model, $this->keyFor($model));
    }
}
