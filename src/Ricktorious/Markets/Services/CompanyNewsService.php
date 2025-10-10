<?php

declare(strict_types=1);

namespace Ricktorious\Markets\Services;

use Ricktorious\Markets\Data\LocalMarketDataRepository;
use Ricktorious\Markets\Data\Models\CompanyProfile;
use Ricktorious\Markets\Data\Models\NewsItem;

final class CompanyNewsService
{
    public function __construct(private LocalMarketDataRepository $repository)
    {
    }

    public function company(string $query): ?CompanyProfile
    {
        return $this->repository->company($query);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function latestAcrossMarket(int $limit = 12): array
    {
        $items = [];
        foreach ($this->repository->latestNewsAcrossMarket($limit) as $row) {
            /** @var CompanyProfile $company */
            $company = $row['company'];
            /** @var NewsItem $news */
            $news = $row['news'];

            $items[] = [
                'company' => $company->toArray(),
                'news' => $news->toArray(),
                'published_at' => $news->publishedAt(),
                'sentiment_label' => $news->sentiment()['label'] ?? 'neutral',
                'sentiment_score' => (float) ($news->sentiment()['score'] ?? 0.0),
            ];
        }

        return $items;
    }

    /**
     * @param CompanyProfile $company
     * @return array<int, array<string, mixed>>
     */
    public function newsForCompany(CompanyProfile $company): array
    {
        $items = [];
        foreach ($company->news() as $news) {
            $items[] = [
                'company' => $company->toArray(),
                'news' => $news->toArray(),
                'sentiment_label' => $news->sentiment()['label'] ?? 'neutral',
                'sentiment_score' => (float) ($news->sentiment()['score'] ?? 0.0),
            ];
        }

        usort(
            $items,
            static fn (array $a, array $b): int => strtotime((string) $b['news']['published_at']) <=> strtotime((string) $a['news']['published_at'])
        );

        return $items;
    }
}
