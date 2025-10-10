<?php

declare(strict_types=1);

namespace Ricktorious\Markets\Data;

use DateTimeImmutable;
use Ricktorious\Markets\Data\Models\CompanyProfile;
use Ricktorious\Markets\Data\Models\NewsItem;
use RuntimeException;

final class LocalMarketDataRepository
{
    /**
     * @var array<string, CompanyProfile>
     */
    private array $companies = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $indices = [];

    private ?DateTimeImmutable $lastUpdated = null;

    public function __construct(private string $datasetPath)
    {
        $this->load();
    }

    /**
     * @return array<int, CompanyProfile>
     */
    public function companies(): array
    {
        return array_values($this->companies);
    }

    public function company(string $query): ?CompanyProfile
    {
        $normalized = strtolower(trim($query));
        if ($normalized === '') {
            return null;
        }

        foreach ($this->companies as $company) {
            if (strtolower($company->symbol()) === $normalized || strtolower($company->name()) === $normalized) {
                return $company;
            }

            if (str_contains(strtolower($company->name()), $normalized)) {
                return $company;
            }
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function indices(): array
    {
        return $this->indices;
    }

    public function lastUpdated(): ?DateTimeImmutable
    {
        return $this->lastUpdated;
    }

    /**
     * @return array<int, CompanyProfile>
     */
    public function search(string $query, int $limit = 5): array
    {
        $normalized = strtolower(trim($query));
        if ($normalized === '') {
            return array_slice($this->companies(), 0, $limit);
        }

        $results = [];
        foreach ($this->companies as $company) {
            if (
                str_contains(strtolower($company->symbol()), $normalized)
                || str_contains(strtolower($company->name()), $normalized)
                || str_contains(strtolower($company->sector()), $normalized)
            ) {
                $results[] = $company;
            }
        }

        return array_slice($results, 0, $limit);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function latestNewsAcrossMarket(int $limit = 12): array
    {
        $allNews = [];
        foreach ($this->companies as $company) {
            foreach ($company->news() as $item) {
                $allNews[] = [
                    'company' => $company,
                    'news' => $item,
                ];
            }
        }

        usort(
            $allNews,
            static fn (array $a, array $b): int => $b['news']->publishedAt() <=> $a['news']->publishedAt()
        );

        return array_slice($allNews, 0, $limit);
    }

    private function load(): void
    {
        if (!is_file($this->datasetPath)) {
            throw new RuntimeException('Market dataset not found at ' . $this->datasetPath);
        }

        $json = file_get_contents($this->datasetPath);
        if ($json === false) {
            throw new RuntimeException('Unable to read dataset at ' . $this->datasetPath);
        }

        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid dataset format.');
        }

        $lastUpdated = isset($data['last_updated']) ? new DateTimeImmutable((string) $data['last_updated']) : null;
        $this->lastUpdated = $lastUpdated;
        $this->indices = isset($data['indices']) && is_array($data['indices']) ? array_values($data['indices']) : [];

        $companies = [];
        foreach ((array) ($data['companies'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $newsItems = [];
            foreach ((array) ($entry['news'] ?? []) as $news) {
                if (!is_array($news)) {
                    continue;
                }

                $publishedAt = isset($news['published_at']) ? new DateTimeImmutable((string) $news['published_at']) : new DateTimeImmutable();
                $newsItems[] = new NewsItem(
                    (string) ($news['title'] ?? ''),
                    (string) ($news['url'] ?? ''),
                    (string) ($news['source'] ?? ''),
                    (string) ($news['summary'] ?? ''),
                    $publishedAt,
                    is_array($news['sentiment'] ?? null) ? $news['sentiment'] : []
                );
            }

            $company = new CompanyProfile(
                (string) ($entry['symbol'] ?? ''),
                (string) ($entry['name'] ?? ''),
                (string) ($entry['sector'] ?? ''),
                (float) ($entry['price'] ?? 0.0),
                (float) ($entry['change'] ?? 0.0),
                (float) ($entry['change_percent'] ?? 0.0),
                (float) ($entry['market_cap'] ?? 0.0),
                (string) ($entry['summary'] ?? ''),
                $newsItems,
                $lastUpdated
            );

            $companies[strtoupper($company->symbol())] = $company;
        }

        $this->companies = $companies;
    }
}
