<?php

namespace JensS\Newsletter\Models\Concerns;

trait UsesNewsletterTable
{
    public function getTable(): string
    {
        return config('newsletter.table_prefix', 'newsletter_').$this->baseTable;
    }
}
