<?php

namespace JensS\Newsletter\Contracts;

/**
 * Optional interface for your member model. If it is not implemented the
 * attribute mapping in config('newsletter.members') is used instead.
 */
interface NewsletterMember
{
    public function getNewsletterEmail(): ?string;

    public function getNewsletterName(): ?string;

    public function getNewsletterLocale(): ?string;

    /** Whether this member may currently receive member newsletters. */
    public function isNewsletterEligible(): bool;
}
