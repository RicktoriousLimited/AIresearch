<?php

declare(strict_types=1);

namespace Ricktorious\Markets\Insights;

use Ricktorious\Markets\News\NewsArticle;

/**
 * Snapshot of how a single article contributes to investor sentiment.
 */
final class SentimentSnapshot
{
    /**
     * @param array<string, float> $segments
     */
    public function __construct(
        private NewsArticle $article,
        private float $score,
        private array $segments
    ) {
    }

    public function article(): NewsArticle
    {
        return $this->article;
    }

    public function score(): float
    {
        return $this->score;
    }

    /**
     * @return array<string, float>
     */
    public function segments(): array
    {
        return $this->segments;
    }
}
