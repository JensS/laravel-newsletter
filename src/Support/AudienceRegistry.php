<?php

namespace JensS\Newsletter\Support;

use Illuminate\Contracts\Container\Container;
use JensS\Newsletter\Contracts\RecipientProvider;
use JensS\Newsletter\Exceptions\UnknownAudienceException;

final class AudienceRegistry
{
    /** @var array<string, array<string, mixed>> */
    private array $runtime = [];

    /** @var array<string, Audience> */
    private array $resolved = [];

    public function __construct(private readonly Container $container) {}

    /**
     * Register an audience at runtime (e.g. from a service provider), which
     * allows passing a provider instance instead of a class name.
     *
     * @param  array<string, mixed>  $config
     */
    public function register(string $key, array|RecipientProvider $config): void
    {
        if ($config instanceof RecipientProvider) {
            $config = ['source' => $config];
        }

        $this->runtime[$key] = $config;
        unset($this->resolved[$key]);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->definitions());
    }

    public function get(string $key): Audience
    {
        if (isset($this->resolved[$key])) {
            return $this->resolved[$key];
        }

        $definitions = $this->definitions();

        if (! array_key_exists($key, $definitions)) {
            throw UnknownAudienceException::make($key);
        }

        return $this->resolved[$key] = new Audience($key, (array) $definitions[$key], $this->container);
    }

    /** @return array<string, Audience> */
    public function all(): array
    {
        $out = [];
        foreach (array_keys($this->definitions()) as $key) {
            $out[$key] = $this->get($key);
        }

        return $out;
    }

    /** @return array<string, string> key => label */
    public function options(): array
    {
        return array_map(fn (Audience $a) => $a->label(), $this->all());
    }

    /** Forget resolved audiences (config changes in tests). */
    public function flush(): void
    {
        $this->resolved = [];
    }

    /** @return array<string, mixed> */
    private function definitions(): array
    {
        return array_merge((array) config('newsletter.audiences', []), $this->runtime);
    }
}
