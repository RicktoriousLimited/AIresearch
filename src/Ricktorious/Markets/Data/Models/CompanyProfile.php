<?php

declare(strict_types=1);

namespace Ricktorious\Markets\Data\Models;

use DateTimeImmutable;

final class CompanyProfile
{
    /**
     * @param array<int, NewsItem> $news
     */
    public function __construct(
        private string $symbol,
        private string $name,
        private string $sector,
        private float $price,
        private float $change,
        private float $changePercent,
        private float $marketCap,
        private string $summary,
        private array $news,
        private ?DateTimeImmutable $lastUpdated = null
    ) {
    }

    public function symbol(): string
    {
        return $this->symbol;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function sector(): string
    {
        return $this->sector;
    }

    public function price(): float
    {
        return $this->price;
    }

    public function change(): float
    {
        return $this->change;
    }

    public function changePercent(): float
    {
        return $this->changePercent;
    }

    public function marketCap(): float
    {
        return $this->marketCap;
    }

    public function summary(): string
    {
        return $this->summary;
    }

    /**
     * @return array<int, NewsItem>
     */
    public function news(): array
    {
        return $this->news;
    }

    public function lastUpdated(): ?DateTimeImmutable
    {
        return $this->lastUpdated;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'symbol' => $this->symbol(),
            'name' => $this->name(),
            'sector' => $this->sector(),
            'price' => $this->price(),
            'change' => $this->change(),
            'change_percent' => $this->changePercent(),
            'market_cap' => $this->marketCap(),
            'summary' => $this->summary(),
        ];
    }
}
