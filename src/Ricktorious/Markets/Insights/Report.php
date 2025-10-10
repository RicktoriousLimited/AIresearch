<?php

declare(strict_types=1);

namespace Ricktorious\Markets\Insights;

final class Report
{
    /**
     * @param array<int, string> $highlights
     * @param array<string, array<string, mixed>> $sentimentBySegment
     * @param array<int, array<string, mixed>> $timeline
     * @param array<int, array<string, mixed>> $articles
     */
    public function __construct(
        private string $company,
        private string $generatedAt,
        private string $overview,
        private array $highlights,
        private array $sentimentBySegment,
        private array $timeline,
        private array $articles
    ) {
    }

    public function company(): string
    {
        return $this->company;
    }

    public function generatedAt(): string
    {
        return $this->generatedAt;
    }

    public function overview(): string
    {
        return $this->overview;
    }

    /**
     * @return array<int, string>
     */
    public function highlights(): array
    {
        return $this->highlights;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function sentimentBySegment(): array
    {
        return $this->sentimentBySegment;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function timeline(): array
    {
        return $this->timeline;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function articles(): array
    {
        return $this->articles;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'company' => $this->company,
            'generated_at' => $this->generatedAt,
            'overview' => $this->overview,
            'highlights' => $this->highlights,
            'sentiment_by_segment' => $this->sentimentBySegment,
            'timeline' => $this->timeline,
            'articles' => $this->articles,
        ];
    }
}
