<?php

namespace JensS\Newsletter\Support;

/** Thin wrapper around PHP's DNS functions so checks can be tested. */
class DnsResolver
{
    public function available(): bool
    {
        return function_exists('dns_get_record');
    }

    /** @return list<string> */
    public function txt(string $host): array
    {
        return array_values(array_map(fn ($r) => (string) ($r['txt'] ?? implode('', (array) ($r['entries'] ?? []))), @dns_get_record($host, DNS_TXT) ?: []));
    }

    /** @return list<string> */
    public function cname(string $host): array
    {
        return array_values(array_map(fn ($r) => (string) ($r['target'] ?? ''), @dns_get_record($host, DNS_CNAME) ?: []));
    }

    /** @return list<string> */
    public function a(string $host): array
    {
        $ips = @gethostbynamel($host);

        return $ips === false ? [] : array_values($ips);
    }

    public function ptr(string $ip): ?string
    {
        $name = @gethostbyaddr($ip);

        return ($name === false || $name === $ip) ? null : $name;
    }
}
