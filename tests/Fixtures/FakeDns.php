<?php

namespace JensS\Newsletter\Tests\Fixtures;

use JensS\Newsletter\Support\DnsResolver;

final class FakeDns extends DnsResolver
{
    /** @param  array<string, array<string, list<string>>>  $records  type => host => values */
    public function __construct(public array $records = []) {}

    public function available(): bool
    {
        return true;
    }

    public function txt(string $host): array
    {
        return $this->records['txt'][$host] ?? [];
    }

    public function cname(string $host): array
    {
        return $this->records['cname'][$host] ?? [];
    }

    public function a(string $host): array
    {
        return $this->records['a'][$host] ?? [];
    }

    public function ptr(string $ip): ?string
    {
        return $this->records['ptr'][$ip][0] ?? null;
    }
}
