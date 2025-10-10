<?php

declare(strict_types=1);

namespace Ricktorious\Markets\News;

use DateTimeImmutable;
use DateTimeInterface;

final class NewsArticle
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private string $title,
        private string $url,
        private string $source,
        private DateTimeImmutable $publishedAt,
        private string $summary,
        private array $metadata = []
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

    public function publishedAt(): DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function summary(): string
    {
        return $this->summary;
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return $this->metadata;
    }

    public function isoTimestamp(): string
    {
        return $this->publishedAt->format(DateTimeInterface::ATOM);
    }
}
