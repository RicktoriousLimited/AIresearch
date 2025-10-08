<?php

declare(strict_types=1);

namespace Ricktorious\Ecommerce\Core;

use InvalidArgumentException;

/**
 * Registry of available blocks.
 */
final class BlockRegistry
{
    /** @var array<string, BlockType> */
    private array $blocks = [];

    public function register(BlockType $block): void
    {
        $name = $block->getName();
        if ($name === '') {
            throw new InvalidArgumentException('Block names must be non-empty strings.');
        }

        $this->blocks[$name] = $block;
    }

    public function has(string $name): bool
    {
        return isset($this->blocks[$name]);
    }

    public function get(string $name): BlockType
    {
        if (!$this->has($name)) {
            throw new InvalidArgumentException(sprintf('Unknown block "%s".', $name));
        }

        return $this->blocks[$name];
    }

    /**
     * @return array<string, BlockType>
     */
    public function all(): array
    {
        return $this->blocks;
    }
}
