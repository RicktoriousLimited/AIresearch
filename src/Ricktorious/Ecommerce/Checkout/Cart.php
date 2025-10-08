<?php

declare(strict_types=1);

namespace Ricktorious\Ecommerce\Checkout;

use Ricktorious\Ecommerce\Catalog\Product;
use Ricktorious\Ecommerce\Catalog\ProductRepository;

final class Cart
{
    /** @var array<string, int> */
    private array $items = [];

    /**
     * @param array<string, int> $items
     */
    private function __construct(array $items = [])
    {
        foreach ($items as $id => $quantity) {
            $quantity = (int) $quantity;
            if ($quantity > 0) {
                $this->items[(string) $id] = $quantity;
            }
        }
    }

    /**
     * @param array<string, int> $items
     */
    public static function fromArray(array $items = []): self
    {
        return new self($items);
    }

    public function addProduct(Product $product, int $quantity = 1): void
    {
        $quantity = max(1, $quantity);
        $current = $this->items[$product->id()] ?? 0;
        $this->items[$product->id()] = $current + $quantity;
    }

    public function updateQuantity(string $productId, int $quantity): void
    {
        $quantity = max(0, $quantity);
        if ($quantity === 0) {
            unset($this->items[$productId]);

            return;
        }

        $this->items[$productId] = $quantity;
    }

    public function removeProduct(string $productId): void
    {
        unset($this->items[$productId]);
    }

    public function clear(): void
    {
        $this->items = [];
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function itemCount(): int
    {
        return array_sum($this->items);
    }

    /**
     * @return array<string, int>
     */
    public function items(): array
    {
        return $this->items;
    }

    /**
     * @return array<int, array{product: Product, quantity: int, line_total: float}>
     */
    public function detailedItems(ProductRepository $repository): array
    {
        $details = [];
        foreach ($this->items as $productId => $quantity) {
            $product = $repository->find($productId);
            if (!$product instanceof Product) {
                continue;
            }

            $lineTotal = $product->price() * $quantity;
            $details[] = [
                'product' => $product,
                'quantity' => $quantity,
                'line_total' => $lineTotal,
            ];
        }

        return $details;
    }

    public function total(ProductRepository $repository): float
    {
        $total = 0.0;
        foreach ($this->detailedItems($repository) as $item) {
            $total += $item['line_total'];
        }

        return $total;
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return $this->items;
    }
}
