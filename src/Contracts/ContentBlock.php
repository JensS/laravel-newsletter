<?php

namespace JensS\Newsletter\Contracts;

use JensS\Newsletter\Models\Campaign;
use JensS\Newsletter\Support\RenderedBlock;

/**
 * Application-supplied content (latest posts, upcoming events ...).
 * Blocks are rendered once, when a campaign is frozen, so every recipient
 * receives identical content.
 */
interface ContentBlock
{
    public function label(): string;

    /**
     * Filament form fields for the block's options (optional, return [] if none).
     *
     * @return array<int, mixed>
     */
    public function schema(): array;

    /** @param  array<string, mixed>  $options */
    public function render(Campaign $campaign, array $options): RenderedBlock;
}
