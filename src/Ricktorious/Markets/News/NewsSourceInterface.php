<?php

declare(strict_types=1);

namespace Ricktorious\Markets\News;

/**
 * @internal contract for news sources feeding the discovery pipeline.
 */
interface NewsSourceInterface
{
    /**
     * @return array<int, NewsArticle>
     */
    public function fetch(string $company, int $limit = 25): array;
}
