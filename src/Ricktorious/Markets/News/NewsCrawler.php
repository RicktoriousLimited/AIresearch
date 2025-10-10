<?php

declare(strict_types=1);

namespace Ricktorious\Markets\News;

use RuntimeException;

final class NewsCrawler
{
    /**
     * @param array<int, NewsSourceInterface> $sources
     */
    public function __construct(private array $sources)
    {
    }

    /**
     * @return array<int, NewsArticle>
     */
    public function discover(string $company, int $limit = 25): array
    {
        $articles = [];
        $seen = [];

        foreach ($this->sources as $source) {
            try {
                $items = $source->fetch($company, $limit);
            } catch (RuntimeException $exception) {
                // Swallow source errors so the pipeline can continue with fallbacks.
                continue;
            }

            foreach ($items as $article) {
                if (!$article instanceof NewsArticle) {
                    continue;
                }

                $key = $article->url();
                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $articles[] = $article;
            }
        }

        usort($articles, static function (NewsArticle $a, NewsArticle $b): int {
            return $b->publishedAt() <=> $a->publishedAt();
        });

        if ($limit > 0) {
            $articles = array_slice($articles, 0, $limit);
        }

        return $articles;
    }
}
