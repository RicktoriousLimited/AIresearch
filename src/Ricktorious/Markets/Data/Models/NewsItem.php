<?php

declare(strict_types=1);

namespace Ricktorious\Markets\Data\Models;

use DateTimeImmutable;

final class NewsItem
{
    /**
     * @param array<string, mixed> $sentiment
     */
    public function __construct(
        private string $title,
        private string $url,
        private string $source,
        private string $summary,
        private DateTimeImmutable $publishedAt,
        private array $sentiment = []
    ) {
    }

    public function title(): string
    {
        return $this->title;
    }

    public function url(): string
    {
        return $this->url;
    }

    public function source(): string
    {
        return $this->source;
    }

    public function summary(): string
    {
        return $this->summary;
    }

    public function publishedAt(): DateTimeImmutable
    {
        return $this->publishedAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function sentiment(): array
    {
        return $this->sentiment;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title(),
            'url' => $this->url(),
            'source' => $this->source(),
            'summary' => $this->summary(),
            'published_at' => $this->publishedAt()->format(DATE_ATOM),
            'sentiment' => $this->sentiment(),
        ];
    }
}
