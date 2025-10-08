<?php

declare(strict_types=1);

namespace Ricktorious\Ecommerce\Core;

/**
 * Represents a block that can be rendered on the storefront.
 */
final class BlockType
{
    /** @var callable */
    private $renderer;

    /** @param callable $renderer */
    public function __construct(
        private string $name,
        private string $label,
        callable $renderer,
        private array $schema = [],
        private array $defaultSettings = []
    ) {
        $this->renderer = $renderer;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    /**
     * Return the schema describing configurable options.
     *
     * @return array<string, mixed>
     */
    public function getSchema(): array
    {
        return $this->schema;
    }

    /**
     * Render the block with the provided settings and context.
     *
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $context
     */
    public function render(array $settings = [], array $context = []): string
    {
        $payload = array_replace_recursive($this->defaultSettings, $settings);

        return (string) ($this->renderer)($payload, $context);
    }
}
