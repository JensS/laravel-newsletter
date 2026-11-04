<?php

namespace JensS\Newsletter\Support;

use Illuminate\Contracts\Container\Container;
use JensS\Newsletter\Contracts\MemberResolver;
use JensS\Newsletter\Contracts\RecipientProvider;
use JensS\Newsletter\Recipients\ConfiguredMemberResolver;
use JensS\Newsletter\Recipients\OptOutFilteringProvider;
use JensS\Newsletter\Recipients\SubscriptionRecipientProvider;

final class Audience
{
    private ?RecipientProvider $provider = null;

    /** @param  array<string, mixed>  $config */
    public function __construct(
        public readonly string $key,
        private readonly array $config,
        private readonly Container $container,
    ) {}

    public function label(): string
    {
        return (string) ($this->config['label'] ?? $this->key);
    }

    public function description(): ?string
    {
        return $this->config['description'] ?? null;
    }

    /** True when recipients are the package's own subscription records. */
    public function usesSubscriptions(): bool
    {
        return ($this->config['source'] ?? 'subscriptions') === 'subscriptions';
    }

    public function linksMembers(): bool
    {
        return $this->usesSubscriptions() && (bool) ($this->config['members'] ?? false);
    }

    public function requiresDoubleOptIn(): bool
    {
        return $this->usesSubscriptions() && (bool) ($this->config['double_opt_in'] ?? true);
    }

    public function allowsPublicSignup(): bool
    {
        return $this->usesSubscriptions()
            && ! $this->linksMembers()
            && (bool) ($this->config['public_signup'] ?? false);
    }

    /** @return array<string, mixed> */
    public function config(): array
    {
        return $this->config;
    }

    /**
     * The recipient provider, already filtered so that opt-outs recorded by the
     * package are honoured for application-provided audiences.
     */
    public function recipients(): RecipientProvider
    {
        if ($this->provider !== null) {
            return $this->provider;
        }

        if ($this->usesSubscriptions()) {
            $resolver = $this->linksMembers() ? $this->memberResolver() : null;

            return $this->provider = new SubscriptionRecipientProvider($this->key, $resolver, $this->linksMembers());
        }

        $source = $this->config['source'];
        $provider = $source instanceof RecipientProvider ? $source : $this->container->make($source);

        if (! $provider instanceof RecipientProvider) {
            throw new \InvalidArgumentException("Audience [{$this->key}] source must implement ".RecipientProvider::class);
        }

        return $this->provider = new OptOutFilteringProvider($this->key, $provider);
    }

    /** The raw application provider for provider-backed audiences. */
    public function applicationProvider(): ?RecipientProvider
    {
        $recipients = $this->recipients();

        return $recipients instanceof OptOutFilteringProvider ? $recipients->inner() : null;
    }

    private function memberResolver(): MemberResolver
    {
        $eligibility = config('newsletter.members.eligibility');

        if (is_string($eligibility) && class_exists($eligibility) && is_subclass_of($eligibility, MemberResolver::class)) {
            return $this->container->make($eligibility);
        }

        return $this->container->make(ConfiguredMemberResolver::class);
    }
}
