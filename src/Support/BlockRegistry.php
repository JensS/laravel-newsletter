<?php

namespace JensS\Newsletter\Support;

use Illuminate\Contracts\Container\Container;
use JensS\Newsletter\Contracts\ContentBlock;

final class BlockRegistry
{
    /** @var array<string, ContentBlock|class-string<ContentBlock>> */
    private array $runtime = [];

    public function __construct(private readonly Container $container) {}

    /** @param  ContentBlock|class-string<ContentBlock>  $block */
    public function register(string $key, ContentBlock|string $block): void
    {
        $this->runtime[$key] = $block;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->definitions());
    }

    public function get(string $key): ContentBlock
    {
        $block = $this->definitions()[$key] ?? throw new \InvalidArgumentException("Unknown newsletter block [{$key}].");

        return $block instanceof ContentBlock ? $block : $this->container->make($block);
    }

    /** @return array<string, ContentBlock> */
    public function all(): array
    {
        $out = [];
        foreach (array_keys($this->definitions()) as $key) {
            $out[$key] = $this->get($key);
        }

        return $out;
    }

    /** @return array<string, ContentBlock|class-string<ContentBlock>> */
    private function definitions(): array
    {
        return array_merge((array) config('newsletter.content.blocks', []), $this->runtime);
    }
}
