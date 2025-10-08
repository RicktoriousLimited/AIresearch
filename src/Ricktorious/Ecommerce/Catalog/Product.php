<?php

declare(strict_types=1);

namespace Ricktorious\Ecommerce\Catalog;

final class Product
{
    /**
     * @param array<int, string> $images
     * @param array<int, string> $tags
     */
    public function __construct(
        private string $id,
        private string $slug,
        private string $name,
        private string $description,
        private float $price,
        private string $currency,
        private array $images = [],
        private array $tags = [],
        private int $inventory = 0
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function price(): float
    {
        return $this->price;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    /**
     * @return array<int, string>
     */
    public function images(): array
    {
        return $this->images;
    }

    public function primaryImage(): ?string
    {
        return $this->images[0] ?? null;
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return $this->tags;
    }

    public function inventory(): int
    {
        return $this->inventory;
    }

    public function formattedPrice(): string
    {
        return $this->currency . number_format($this->price, 2);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'price' => $this->price,
            'currency' => $this->currency,
            'images' => $this->images,
            'tags' => $this->tags,
            'inventory' => $this->inventory,
            'formatted_price' => $this->formattedPrice(),
        ];
    }
}
