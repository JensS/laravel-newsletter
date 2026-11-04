<?php

namespace JensS\Newsletter\Recipients;

use Illuminate\Database\Eloquent\Model;
use JensS\Newsletter\Contracts\MemberResolver;
use JensS\Newsletter\Contracts\NewsletterMember;
use JensS\Newsletter\Support\Email;
use JensS\Newsletter\Support\Recipient;

/**
 * Default resolver: uses the NewsletterMember interface when implemented,
 * otherwise the attribute map in config('newsletter.members').
 */
class ConfiguredMemberResolver implements MemberResolver
{
    public function resolve(Model $member, string $key): ?Recipient
    {
        if (! $this->eligible($member)) {
            return null;
        }

        [$email, $name, $locale] = $this->extract($member);

        if ($email === null || ! Email::isValid($email)) {
            return null;
        }

        return new Recipient(key: $key, email: $email, name: $name, locale: $locale, subscribable: $member);
    }

    /** @return array{0: ?string, 1: ?string, 2: ?string} */
    public function extract(Model $member): array
    {
        if ($member instanceof NewsletterMember) {
            return [$member->getNewsletterEmail(), $member->getNewsletterName(), $member->getNewsletterLocale()];
        }

        $map = (array) config('newsletter.members.map', []);
        $get = fn (?string $attr) => $attr ? data_get($member, $attr) : null;

        return [
            $get($map['email'] ?? 'email'),
            $get($map['name'] ?? 'name'),
            $get($map['locale'] ?? null),
        ];
    }

    protected function eligible(Model $member): bool
    {
        if (method_exists($member, 'trashed') && $member->trashed()) {
            return false;
        }

        if ($member instanceof NewsletterMember) {
            return $member->isNewsletterEligible();
        }

        $rule = config('newsletter.members.eligibility');

        if ($rule === null || $rule === '') {
            return true;
        }

        if (method_exists($member, $rule)) {
            return (bool) $member->{$rule}();
        }

        return (bool) data_get($member, $rule);
    }
}
