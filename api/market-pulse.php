<?php

declare(strict_types=1);

require __DIR__ . '/../src/Ricktorious/Markets/bootstrap.php';

header('Content-Type: application/json');

/**
 * @param DateTimeImmutable $time
 */
function relative_time(DateTimeImmutable $time): string
{
    $now = new DateTimeImmutable('now', $time->getTimezone());
    $diff = max(0, $now->getTimestamp() - $time->getTimestamp());

    if ($diff < 60) {
        return 'just now';
    }

    if ($diff < 3600) {
        $minutes = (int) floor($diff / 60);

        return $minutes === 1 ? '1 minute ago' : sprintf('%d minutes ago', $minutes);
    }

    if ($diff < 86400) {
        $hours = (int) floor($diff / 3600);

        return $hours === 1 ? '1 hour ago' : sprintf('%d hours ago', $hours);
    }

    $days = (int) floor($diff / 86400);

    return $days === 1 ? '1 day ago' : sprintf('%d days ago', $days);
}

try {
    $kernel = ricktorious_markets_kernel();
    $overviewService = $kernel->overviewService();
    $newsService = $kernel->companyNewsService();
    $searchService = $kernel->searchService();

    $overview = $overviewService->snapshot();
    $companies = $searchService->companies();
    $latestNews = $newsService->latestAcrossMarket(12);

    $companyCount = count($companies);
    $cacheAgeMinutes = null;
    $lastUpdatedIso = null;
    $lastUpdatedRelative = 'recently';
    $rawLastUpdated = $overview['last_updated'] ?? null;
    if (is_string($rawLastUpdated) && $rawLastUpdated !== '') {
        try {
            $lastUpdatedTime = new DateTimeImmutable($rawLastUpdated);
            $lastUpdatedIso = $lastUpdatedTime->format(DATE_ATOM);
            $cacheAgeMinutes = (int) floor(max(0, (new DateTimeImmutable())->getTimestamp() - $lastUpdatedTime->getTimestamp()) / 60);
            $lastUpdatedRelative = relative_time($lastUpdatedTime);
        } catch (Exception $exception) {
            $lastUpdatedRelative = $rawLastUpdated;
        }
    }

    $newsPayload = array_map(
        static function (array $item): array {
            $news = $item['news'] ?? [];
            if (isset($news['published_at']) && $news['published_at'] instanceof DateTimeInterface) {
                $news['published_at'] = $news['published_at']->format(DATE_ATOM);
            }

            return [
                'company' => $item['company'] ?? [],
                'news' => $news,
                'sentiment_label' => $item['sentiment_label'] ?? 'neutral',
                'sentiment_score' => (float) ($item['sentiment_score'] ?? 0.0),
            ];
        },
        $latestNews
    );

    $payload = [
        'generated_at' => date(DATE_ATOM),
        'overview' => array_merge(
            $overview,
            [
                'company_count' => $companyCount,
                'news_count' => count($newsPayload),
                'cache_age_minutes' => $cacheAgeMinutes,
                'last_updated_iso' => $lastUpdatedIso,
                'last_updated_relative' => $lastUpdatedRelative,
            ]
        ),
        'watchlist' => array_slice($overview['top_movers'] ?? [], 0, 5),
        'sectors' => array_slice($overview['sectors'] ?? [], 0, 6),
        'latest_news' => $newsPayload,
    ];

    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Unable to build market snapshot',
        'details' => $error->getMessage(),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
