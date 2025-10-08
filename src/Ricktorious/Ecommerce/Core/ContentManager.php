<?php

declare(strict_types=1);

namespace Ricktorious\Ecommerce\Core;

/**
 * Lightweight in-memory content manager for page layouts.
 */
final class ContentManager
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $pages = [];

    /**
     * @param array<string, mixed> $definition
     */
    public function definePage(string $identifier, array $definition): void
    {
        $this->pages[$identifier] = $definition;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getPage(string $identifier): ?array
    {
        return $this->pages[$identifier] ?? null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function allPages(): array
    {
        return $this->pages;
    }
}
