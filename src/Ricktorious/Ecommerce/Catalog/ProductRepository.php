<?php

declare(strict_types=1);

namespace Ricktorious\Ecommerce\Catalog;

use RuntimeException;

final class ProductRepository
{
    /** @var array<string, Product> */
    private array $productsById = [];

    /** @var array<string, Product> */
    private array $productsBySlug = [];

    public function __construct(private string $catalogPath)
    {
        $this->load();
    }

    /**
     * @return array<int, Product>
     */
    public function all(): array
    {
        return array_values($this->productsById);
    }

    public function find(string $id): ?Product
    {
        return $this->productsById[$id] ?? null;
    }

    public function findBySlug(string $slug): ?Product
    {
        return $this->productsBySlug[$slug] ?? null;
    }

    /**
     * @return array<int, Product>
     */
    public function featured(int $limit = 4): array
    {
        return array_slice($this->all(), 0, $limit);
    }

    private function load(): void
    {
        if (!is_file($this->catalogPath)) {
            throw new RuntimeException(sprintf('Product catalogue file "%s" not found.', $this->catalogPath));
        }

        $raw = file_get_contents($this->catalogPath);
        if ($raw === false) {
            throw new RuntimeException(sprintf('Unable to read product catalogue from "%s".', $this->catalogPath));
        }

        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException('Catalogue data must decode to an array.');
        }

        foreach ($data as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $product = new Product(
                (string) ($entry['id'] ?? ''),
                (string) ($entry['slug'] ?? ''),
                (string) ($entry['name'] ?? ''),
                (string) ($entry['description'] ?? ''),
                (float) ($entry['price'] ?? 0),
                (string) ($entry['currency'] ?? '$'),
                array_values(array_map('strval', $entry['images'] ?? [])),
                array_values(array_map('strval', $entry['tags'] ?? [])),
                (int) ($entry['inventory'] ?? 0)
            );

            if ($product->id() === '' || $product->slug() === '') {
                continue;
            }

            $this->productsById[$product->id()] = $product;
            $this->productsBySlug[$product->slug()] = $product;
        }
    }
}
