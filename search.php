<?php

declare(strict_types=1);

require __DIR__ . '/src/App/bootstrap.php';

use App\Crawler\HiddenCrawler;
use App\News\NewsSearchService;
use App\News\SearchCache;
use App\Web\PathResolver;
use App\Web\SearchViewHelpers;

function esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function formatRelative(?string $value): ?string
{
    if ($value === null) {
        return null;
    }

    $trimmed = trim($value);
    if ($trimmed === '') {
        return null;
    }

    try {
        $date = new DateTimeImmutable($trimmed);
    } catch (Throwable $exception) {
        return null;
    }

    $diff = time() - $date->getTimestamp();
    if ($diff < 0) {
        $diff = 0;
    }

    if ($diff < 60) {
        return 'just now';
    }

    $minutes = (int) floor($diff / 60);
    if ($minutes < 60) {
        return $minutes === 1 ? '1 minute ago' : $minutes . ' minutes ago';
    }

    $hours = (int) floor($minutes / 60);
    if ($hours < 24) {
        return $hours === 1 ? '1 hour ago' : $hours . ' hours ago';
    }

    $days = (int) floor($hours / 24);
    if ($days < 7) {
        return $days === 1 ? '1 day ago' : $days . ' days ago';
    }

    $weeks = (int) floor($days / 7);
    if ($weeks < 5) {
        return $weeks === 1 ? '1 week ago' : $weeks . ' weeks ago';
    }

    try {
        return $date->format('F j, Y');
    } catch (Throwable $exception) {
        return null;
    }
}

function metaLine(array $entry): string
{
    $parts = [];

    $source = trim((string) ($entry['source_site_name'] ?? $entry['source_domain'] ?? ''));
    if ($source !== '') {
        $parts[] = $source;
    }

    $published = formatRelative($entry['source_published_at'] ?? $entry['last_checked_at'] ?? null);
    if ($published !== null) {
        $parts[] = $published;
    }

    $score = isset($entry['quality_label']) ? trim((string) $entry['quality_label']) : '';
    if ($score !== '') {
        $parts[] = $score . ' quality';
    }

    return implode(' · ', $parts);
}

function queryParam(string $key): string
{
    return isset($_GET[$key]) ? trim((string) $_GET[$key]) : '';
}

function formatNumber(?int $value): string
{
    if ($value === null) {
        return '—';
    }

    return number_format($value);
}

function formatScore(?float $value): string
{
    if ($value === null) {
        return '—';
    }

    $formatted = number_format($value, 1, '.', '');
    $formatted = rtrim(rtrim($formatted, '0'), '.');

    return $formatted === '' ? '0' : $formatted;
}

function formatPercent(?float $value, int $precision = 0): string
{
    if ($value === null) {
        return '—';
    }

    $percentage = $value * 100;
    $formatted = number_format($percentage, $precision, '.', '');
    $formatted = rtrim(rtrim($formatted, '0'), '.');

    return $formatted === '' ? '0%' : $formatted . '%';
}

/**
 * @param array<string, string> $params
 *
 * @return array<string, string>
 */
function normaliseQueryParams(array $params): array
{
    $normalised = [];

    foreach ($params as $key => $value) {
        if (!is_string($value)) {
            continue;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            continue;
        }

        $normalised[$key] = $trimmed;
    }

    return $normalised;
}

function buildSearchUrl(string $basePath, array $params): string
{
    $filtered = normaliseQueryParams($params);
    $query = http_build_query($filtered, '', '&', PHP_QUERY_RFC3986);

    return $query === '' ? $basePath : $basePath . '?' . $query;
}

function toggleFilterUrl(string $basePath, array $currentParams, string $key, string $value): string
{
    $params = $currentParams;
    if (isset($params[$key]) && $params[$key] === $value) {
        unset($params[$key]);
    } else {
        $params[$key] = $value;
    }

    return buildSearchUrl($basePath, $params);
}

function removeFilterUrl(string $basePath, array $currentParams, string $key): string
{
    $params = $currentParams;
    unset($params[$key]);

    return buildSearchUrl($basePath, $params);
}

/**
 * @param array<string, string> $filters
 */
function isFilterActive(array $filters, string $key, string $value): bool
{
    return isset($filters[$key]) && $filters[$key] === $value;
}

/**
 * @param array<string, array<int, array<string, mixed>>> $facets
 */
function facetLabel(array $facets, string $group, string $value): ?string
{
    if (!isset($facets[$group]) || !is_array($facets[$group])) {
        return null;
    }

    foreach ($facets[$group] as $facet) {
        if (!is_array($facet)) {
            continue;
        }

        if ((string) ($facet['key'] ?? '') === $value) {
            return isset($facet['label']) ? (string) $facet['label'] : $value;
        }
    }

    return null;
}

$paths = PathResolver::resolve();
$assetBase = $paths['assetBase'];

$searchStylesPath = PathResolver::url($assetBase, 'assets/search.css');
$searchStylesVersion = file_exists(__DIR__ . '/assets/search.css') ? (string) filemtime(__DIR__ . '/assets/search.css') : (string) time();

$homePath = PathResolver::url($assetBase, 'index.php');
$searchPath = PathResolver::url($assetBase, 'search.php');

$query = queryParam('q');

$recencyFilter = queryParam('recency');
$qualityFilter = queryParam('quality');
$typeFilter = queryParam('type');
$ingestionFilter = queryParam('ingestion');
$sourceFilter = queryParam('source');

$filters = normaliseQueryParams([
    'recency' => $recencyFilter,
    'quality' => $qualityFilter,
    'type' => $typeFilter,
    'ingestion' => $ingestionFilter,
    'source' => $sourceFilter,
]);

$results = [];
$meta = [];
$status = $query === '' ? 'Streaming the latest crawler intelligence.' : sprintf('Searching for “%s”…', $query);
$crawlerUnavailable = false;
$usingCachedResults = false;
$cachedFallbackSource = '';

$cache = new SearchCache(__DIR__ . '/storage/backend/search-cache.json');
$searchLimit = 30;
$cacheKey = $cache->fingerprint($query, $filters, $searchLimit);
$baselineCacheKey = $cache->fingerprint('', [], $searchLimit);
$latestCacheKey = $cache->fingerprint('__LATEST__', [], $searchLimit);

try {
    $storage = __DIR__ . '/storage/backend/crawler-history.json';
    $crawler = new HiddenCrawler($storage);
    $newsService = new NewsSearchService($crawler);
    $payload = $newsService->search($query, [
        'limit' => $searchLimit,
        'filters' => $filters,
    ]);
    if (is_array($payload)) {
        if (isset($payload['results']) && is_array($payload['results'])) {
            $results = $payload['results'];
        }
        if (isset($payload['meta']) && is_array($payload['meta'])) {
            $meta = $payload['meta'];
        }
        $cache->store($cacheKey, $payload);
        if ($cacheKey === $baselineCacheKey) {
            $cache->store($latestCacheKey, $payload);
        }
    }
} catch (Throwable $exception) {
    $cachedPayload = $cache->fetch($cacheKey);
    if ($cachedPayload === null && $query !== '') {
        $cachedFallbackSource = 'latest';
        $cachedPayload = $cache->fetch($latestCacheKey) ?? $cache->fetch($baselineCacheKey);
    } elseif ($cachedPayload !== null) {
        $cachedFallbackSource = 'query';
    }

    if (is_array($cachedPayload)) {
        $results = isset($cachedPayload['results']) && is_array($cachedPayload['results'])
            ? $cachedPayload['results']
            : [];
        $meta = isset($cachedPayload['meta']) && is_array($cachedPayload['meta'])
            ? $cachedPayload['meta']
            : [];
        $status = $query === ''
            ? 'Crawler is restarting – showing cached intelligence.'
            : sprintf('Crawler is restarting – showing cached insights for “%s”.', $query);
        if ($cachedFallbackSource === 'latest' && $query !== '') {
            $status = 'Crawler is restarting – showing cached trending intelligence.';
        }
        $usingCachedResults = true;
    } else {
        $results = [];
        $meta = [];
        $status = 'Crawler results are temporarily unavailable.';
        $crawlerUnavailable = true;
    }
}

$resultCount = count($results);
$totalMatches = isset($meta['total_matches']) ? (int) $meta['total_matches'] : $resultCount;
$totalAvailable = isset($meta['total_available']) ? (int) $meta['total_available'] : $totalMatches;
$returnedMatches = isset($meta['returned']) ? (int) $meta['returned'] : $resultCount;
$averageQuality = isset($meta['average_quality']) ? (float) $meta['average_quality'] : null;
$highQuality = isset($meta['high_quality']) ? (int) $meta['high_quality'] : null;
$ingestedCount = isset($meta['ingested']) ? (int) $meta['ingested'] : null;

$filterCount = count($filters);

if (!$crawlerUnavailable) {
    if ($query === '') {
        $status = $filterCount === 0
            ? sprintf('Streaming %s newly enriched highlights.', formatNumber($totalMatches))
            : sprintf(
                'Streaming %s highlights with %d active filter%s.',
                formatNumber($totalMatches),
                $filterCount,
                $filterCount === 1 ? '' : 's'
            );
    } else {
        if ($filterCount === 0) {
            $status = sprintf(
                'Showing %s match%s for “%s”.',
                formatNumber($totalMatches),
                $totalMatches === 1 ? '' : 'es',
                $query
            );
        } else {
            $status = sprintf(
                'Showing %s of %s match%s for “%s”.',
                formatNumber($totalMatches),
                formatNumber($totalAvailable),
                $totalAvailable === 1 ? '' : 'es',
                $query
            );
        }
    }
}

$facetsAll = isset($meta['facets_all']) && is_array($meta['facets_all']) ? $meta['facets_all'] : [];
$facetsFiltered = isset($meta['facets_filtered']) && is_array($meta['facets_filtered'])
    ? $meta['facets_filtered']
    : (isset($meta['facets']) && is_array($meta['facets']) ? $meta['facets'] : []);

$suggestedQueries = [];
if (isset($meta['suggested_queries']) && is_array($meta['suggested_queries'])) {
    foreach ($meta['suggested_queries'] as $suggestion) {
        if (!is_string($suggestion)) {
            continue;
        }
        $trimmed = trim($suggestion);
        if ($trimmed === '') {
            continue;
        }
        $suggestedQueries[] = $trimmed;
    }
}

$trendingTopics = [];
if (isset($meta['topics']) && is_array($meta['topics'])) {
    foreach ($meta['topics'] as $topicRow) {
        if (!is_array($topicRow)) {
            continue;
        }
        $topicLabel = trim((string) ($topicRow['topic'] ?? ''));
        if ($topicLabel === '') {
            continue;
        }
        $trendingTopics[] = [
            'topic' => $topicLabel,
            'count' => (int) ($topicRow['count'] ?? 0),
        ];
    }
}

$metaSources = [];
$metaSourceLabels = [];
if (isset($meta['sources']) && is_array($meta['sources'])) {
    foreach ($meta['sources'] as $sourceRow) {
        if (!is_array($sourceRow)) {
            continue;
        }
        $domain = trim((string) ($sourceRow['domain'] ?? ''));
        if ($domain === '') {
            continue;
        }
        $count = (int) ($sourceRow['count'] ?? 0);
        $average = isset($sourceRow['average_quality']) ? (float) $sourceRow['average_quality'] : null;
        $metaSources[] = [
            'domain' => $domain,
            'count' => $count,
            'average_quality' => $average,
        ];
        $metaSourceLabels[$domain] = $domain;
    }
}

$activeFilters = $filters;
if (isset($meta['active_filters']) && is_array($meta['active_filters'])) {
    foreach ($meta['active_filters'] as $key => $value) {
        if (!is_string($value)) {
            continue;
        }
        $trimmed = trim($value);
        if ($trimmed === '') {
            continue;
        }
        $activeFilters[$key] = $trimmed;
    }
}
$activeFilters = normaliseQueryParams($activeFilters);

$queryParams = $query !== '' ? ['q' => $query] : [];
$queryParams = array_merge($queryParams, $activeFilters);
$queryParams = normaliseQueryParams($queryParams);

$filterLabels = [
    'recency' => 'Recency',
    'quality' => 'Quality',
    'type' => 'Content type',
    'ingestion' => 'Workflow',
    'source' => 'Publisher',
];
$facetLookup = [
    'recency' => 'recency',
    'quality' => 'quality',
    'type' => 'content_types',
    'ingestion' => 'ingestion',
];

$activeFiltersDisplay = [];
foreach ($activeFilters as $key => $value) {
    $label = $filterLabels[$key] ?? ucfirst(str_replace('_', ' ', (string) $key));
    $valueLabel = null;
    if (isset($facetLookup[$key])) {
        $valueLabel = facetLabel($facetsAll, $facetLookup[$key], $value);
    } elseif ($key === 'source') {
        $valueLabel = $metaSourceLabels[$value] ?? $value;
    }
    if ($valueLabel === null) {
        $valueLabel = $value;
    }

    $activeFiltersDisplay[] = [
        'key' => $key,
        'label' => $label,
        'value' => $value,
        'valueLabel' => $valueLabel,
        'removeUrl' => removeFilterUrl($searchPath, $queryParams, $key),
    ];
}

$facetGroups = [];
$facetDefinitions = [
    'recency' => ['title' => 'Recency', 'param' => 'recency'],
    'quality' => ['title' => 'Quality bands', 'param' => 'quality'],
    'content_types' => ['title' => 'Document type', 'param' => 'type'],
    'ingestion' => ['title' => 'Workflow', 'param' => 'ingestion'],
];

foreach ($facetDefinitions as $key => $config) {
    $items = isset($facetsAll[$key]) && is_array($facetsAll[$key]) ? $facetsAll[$key] : [];
    if ($items === []) {
        continue;
    }

    $facetGroups[] = [
        'key' => $key,
        'title' => $config['title'],
        'param' => $config['param'],
        'items' => $items,
    ];
}

$sourceStats = [];

foreach ($results as $entry) {
    $sourceLabel = trim((string) ($entry['source_site_name'] ?? $entry['source_domain'] ?? ''));
    if ($sourceLabel === '') {
        continue;
    }

    if (!isset($sourceStats[$sourceLabel])) {
        $sourceStats[$sourceLabel] = [
            'label' => $sourceLabel,
            'count' => 0,
            'latest' => null,
            'latestTimestamp' => null,
        ];
    }

    $sourceStats[$sourceLabel]['count']++;

    $latestCandidate = '';

    $publishedAt = isset($entry['source_published_at']) ? (string) $entry['source_published_at'] : '';
    $checkedAt = isset($entry['last_checked_at']) ? (string) $entry['last_checked_at'] : '';

    if ($publishedAt !== '') {
        $latestCandidate = $publishedAt;
    } elseif ($checkedAt !== '') {
        $latestCandidate = $checkedAt;
    }

    if ($latestCandidate !== '') {
        try {
            $candidateDate = new DateTimeImmutable($latestCandidate);
            $candidateTimestamp = $candidateDate->getTimestamp();
        } catch (Throwable $exception) {
            $candidateTimestamp = null;
        }

        if ($candidateTimestamp !== null) {
            $currentTimestamp = $sourceStats[$sourceLabel]['latestTimestamp'];
            if ($currentTimestamp === null || $candidateTimestamp > $currentTimestamp) {
                $sourceStats[$sourceLabel]['latestTimestamp'] = $candidateTimestamp;
                $sourceStats[$sourceLabel]['latest'] = $candidateDate->format(DateTimeInterface::ATOM);
            }
        }
    }
}

$topSources = array_values($sourceStats);

usort($topSources, static function (array $first, array $second): int {
    $countComparison = $second['count'] <=> $first['count'];
    if ($countComparison !== 0) {
        return $countComparison;
    }

    $firstTimestamp = $first['latestTimestamp'] ?? 0;
    $secondTimestamp = $second['latestTimestamp'] ?? 0;

    return $secondTimestamp <=> $firstTimestamp;
});

$topSources = array_map(
    static function (array $source): array {
        $source['latestRelative'] = $source['latest'] !== null ? formatRelative($source['latest']) : null;
        unset($source['latestTimestamp']);

        return $source;
    },
    array_slice($topSources, 0, 5)
);

$heroSuggestions = $suggestedQueries;

if ($heroSuggestions === []) {
    foreach ($trendingTopics as $topic) {
        $heroSuggestions[] = $topic['topic'];
    }
}

if ($heroSuggestions === []) {
    foreach ($topSources as $source) {
        $heroSuggestions[] = $source['label'];
    }
}

$uniqueSuggestions = [];
foreach ($heroSuggestions as $suggestion) {
    $trimmed = trim($suggestion);
    if ($trimmed === '' || isset($uniqueSuggestions[$trimmed])) {
        continue;
    }

    $uniqueSuggestions[$trimmed] = true;
}

$heroSuggestions = array_slice(array_keys($uniqueSuggestions), 0, 5);

if ($heroSuggestions === [] && $query === '') {
    $heroSuggestions = [
        'artificial intelligence',
        'emerging startups',
        'global regulations',
    ];
}

$cachedNotice = '';
if ($usingCachedResults) {
    if ($cachedFallbackSource === 'latest' && $query !== '') {
        $cachedNotice = 'Serving cached trending intelligence while the crawler restarts.';
    } else {
        $cachedNotice = 'Serving cached intelligence from the last successful crawl while the crawler restarts.';
    }
}

$resultSubtitle = '';
if ($resultCount > 0) {
    if ($filterCount === 0) {
        $resultSubtitle = sprintf(
            'Top signals ranked by freshness from %s match%s.',
            formatNumber($totalMatches),
            $totalMatches === 1 ? '' : 'es'
        );
    } else {
        $resultSubtitle = sprintf(
            'Top %s stor%s from %s filtered match%s (of %s total).',
            formatNumber($resultCount),
            $resultCount === 1 ? 'y' : 'ies',
            formatNumber($totalMatches),
            $totalMatches === 1 ? '' : 'es',
            formatNumber($totalAvailable)
        );
    }
}

if ($crawlerUnavailable) {
    $metricCards = [];
} else {
    $metricCards = [
        [
            'label' => 'Live matches',
            'value' => formatNumber($totalMatches),
            'hint' => $filterCount === 0 ? 'Ready to review now' : 'After applied filters',
        ],
        [
            'label' => 'Catalogue size',
            'value' => formatNumber($totalAvailable),
            'hint' => $filterCount === 0 ? 'Matching crawler docs' : 'Before filters',
        ],
        [
            'label' => 'Avg. quality',
            'value' => formatScore($averageQuality),
            'hint' => 'Mean editorial score',
        ],
    ];

    if ($highQuality !== null) {
        $metricCards[] = [
            'label' => 'High-quality hits',
            'value' => formatNumber($highQuality),
            'hint' => 'Score ≥ 70',
        ];
    }

    if ($ingestedCount !== null) {
        $metricCards[] = [
            'label' => 'Enriched sources',
            'value' => formatNumber($ingestedCount),
            'hint' => 'Captured & enriched',
        ];
    }
}

if ($crawlerUnavailable) {
    $emptyStateHeading = 'Crawler temporarily offline';
    $emptyStateMessage = 'Please check back shortly as we restore the latest intelligence.';
} else {
    $emptyStateHeading = 'No crawler matches yet';
    $emptyStateMessage = 'Try broadening your keywords or clearing filters to relaunch the crawl.';
}

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Search &ndash; AIresearch</title>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="<?= esc($searchStylesPath . '?v=' . $searchStylesVersion) ?>">
</head>
<body class="search-page">
    <header class="search-header">
        <div class="search-header__inner">
            <a class="brand" href="<?= esc($homePath) ?>">AI<span>research</span></a>
            <form class="searchbox" action="<?= esc($searchPath) ?>" method="get" role="search" aria-label="Search AIresearch">
                <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M15.5 14h-.79l-.28-.27A6.471 6.471 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79L20 21.5 21.5 20l-6-6zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5s4.5 2.01 4.5 4.5S11.99 14 9.5 14z"/></svg>
                <label class="sr-only" for="search-query">Search AIresearch</label>
                <input id="search-query" type="search" name="q" placeholder="Search AIresearch" value="<?= esc($query) ?>" autofocus>
                <?php foreach ($activeFilters as $key => $value): ?>
                    <?php if (in_array($key, ['recency', 'quality', 'type', 'ingestion', 'source'], true)): ?>
                        <input type="hidden" name="<?= esc($key) ?>" value="<?= esc($value) ?>">
                    <?php endif; ?>
                <?php endforeach; ?>
            </form>
        </div>
    </header>
    <main class="search-main">
        <div class="search-main__primary">
            <div class="search-summary">
                <p class="search-summary__status"><?= esc($status) ?></p>
                <?php if ($cachedNotice !== ''): ?>
                    <div class="search-summary__notice" role="status">
                        <strong>Cached results.</strong> <?= esc($cachedNotice) ?>
                    </div>
                <?php endif; ?>
                <?php if ($metricCards !== []): ?>
                    <div class="search-summary__metrics">
                        <?php foreach ($metricCards as $card): ?>
                            <div class="metric-pill">
                                <span class="metric-pill__value"><?= esc($card['value']) ?></span>
                                <span class="metric-pill__label"><?= esc($card['label']) ?></span>
                                <?php if ($card['hint'] !== ''): ?>
                                    <span class="metric-pill__hint"><?= esc($card['hint']) ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <?php if ($activeFiltersDisplay !== []): ?>
                <div class="active-filters">
                    <span class="active-filters__label">Active filters</span>
                    <div class="active-filters__chips">
                        <?php foreach ($activeFiltersDisplay as $chip): ?>
                            <a class="filter-chip" href="<?= esc($chip['removeUrl']) ?>">
                                <span class="filter-chip__label"><?= esc($chip['label']) ?></span>
                                <span class="filter-chip__value"><?= esc($chip['valueLabel']) ?></span>
                                <span class="filter-chip__remove" aria-hidden="true">&times;</span>
                                <span class="sr-only">Remove <?= esc($chip['label']) ?> filter</span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
            <?php if ($heroSuggestions !== []): ?>
                <div class="search-suggestions">
                    <span class="search-suggestions__label">Trending searches</span>
                    <div class="search-suggestions__chips">
                        <?php foreach ($heroSuggestions as $suggestion): ?>
                            <a class="chip" href="<?= esc(buildSearchUrl($searchPath, ['q' => $suggestion])) ?>"><?= esc($suggestion) ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
            <?php if ($resultCount === 0): ?>
                <div class="empty-state">
                    <h2><?= esc($emptyStateHeading) ?></h2>
                    <p><?= esc($emptyStateMessage) ?></p>
                    <?php if ($trendingTopics !== []): ?>
                        <div class="empty-state__suggestions">
                            <span>Jump to a trending theme:</span>
                            <div class="empty-state__chips">
                                <?php foreach (array_slice($trendingTopics, 0, 4) as $topic): ?>
                                    <a class="chip" href="<?= esc(buildSearchUrl($searchPath, ['q' => $topic['topic']])) ?>"><?= esc($topic['topic']) ?></a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="results-header">
                    <h1><?= esc(formatNumber($resultCount)) ?> surfaced stor<?= $resultCount === 1 ? 'y' : 'ies' ?></h1>
                    <?php if ($resultSubtitle !== ''): ?>
                        <p><?= esc($resultSubtitle) ?></p>
                    <?php endif; ?>
                </div>
                <ol class="results-list">
                    <?php foreach ($results as $entry): ?>
                        <?php
                            $title = trim((string) ($entry['title'] ?? $entry['url'] ?? 'Untitled source'));
                            $url = trim((string) ($entry['url'] ?? ''));
                            $summarySource = (string) ($entry['summary'] ?? $entry['preview'] ?? '');
                            $snippet = SearchViewHelpers::relevantSnippet($summarySource, $query);
                            if ($snippet === '' && $summarySource !== '') {
                                $snippet = SearchViewHelpers::normaliseWhitespace($summarySource);
                            }
                            if ($snippet !== '' && mb_strlen($snippet) > 320) {
                                $snippet = rtrim(mb_substr($snippet, 0, 317)) . '…';
                            }
                            $meta = metaLine($entry);
                            $qualityScore = isset($entry['quality_score']) ? (float) $entry['quality_score'] : null;
                            $contentType = isset($entry['content_type']) ? (string) $entry['content_type'] : '';
                            $contentTypeLabel = match ($contentType) {
                                'article' => 'Article',
                                'non_article' => 'Brief',
                                'error' => 'Unavailable',
                                default => 'Landing page',
                            };
                            $relativeTimestamp = null;
                            $timeCandidates = [
                                isset($entry['source_published_at']) ? (string) $entry['source_published_at'] : null,
                                isset($entry['last_checked_at']) ? (string) $entry['last_checked_at'] : null,
                                isset($entry['fetched_at']) ? (string) $entry['fetched_at'] : null,
                            ];
                            foreach ($timeCandidates as $candidateTime) {
                                if (!is_string($candidateTime) || trim($candidateTime) === '') {
                                    continue;
                                }
                                $relativeTimestamp = formatRelative($candidateTime);
                                if ($relativeTimestamp !== null) {
                                    break;
                                }
                            }
                            $badges = [];
                            if ($qualityScore !== null && $qualityScore > 0) {
                                $badges[] = [
                                    'label' => 'Q' . (string) round($qualityScore),
                                    'title' => sprintf('Quality score %s', formatScore($qualityScore)),
                                    'class' => 'quality',
                                ];
                            }
                            if ($contentTypeLabel !== '') {
                                $badges[] = [
                                    'label' => $contentTypeLabel,
                                    'title' => 'Content type',
                                    'class' => 'type',
                                ];
                            }
                            if ($relativeTimestamp !== null) {
                                $badges[] = [
                                    'label' => $relativeTimestamp,
                                    'title' => 'Last updated',
                                    'class' => 'time',
                                ];
                            }
                            if (!empty($entry['ingest'])) {
                                $badges[] = [
                                    'label' => 'Enriched',
                                    'title' => 'Captured & enriched',
                                    'class' => 'ingested',
                                ];
                            }
                            $displayDomain = '';
                            if (isset($entry['source_domain']) && is_string($entry['source_domain'])) {
                                $displayDomain = trim($entry['source_domain']);
                            }
                            if ($displayDomain === '' && isset($entry['source_site_name']) && is_string($entry['source_site_name'])) {
                                $displayDomain = trim($entry['source_site_name']);
                            }
                        ?>
                        <li class="result">
                            <?php if ($displayDomain !== ''): ?>
                                <span class="result__url"><?= esc($displayDomain) ?></span>
                            <?php endif; ?>
                            <h2 class="result__title">
                                <?php if ($url !== ''): ?>
                                    <a href="<?= esc($url) ?>" target="_blank" rel="noopener noreferrer"><?= esc($title) ?></a>
                                <?php else: ?>
                                    <?= esc($title) ?>
                                <?php endif; ?>
                            </h2>
                            <?php if ($snippet !== ''): ?>
                                <p class="result__snippet"><?= SearchViewHelpers::highlightTerms($snippet, $query) ?></p>
                            <?php endif; ?>
                            <?php if ($meta !== ''): ?>
                                <p class="result__meta"><?= esc($meta) ?></p>
                            <?php endif; ?>
                            <?php if ($url !== ''): ?>
                                <div class="result__actions">
                                    <a class="result__action" href="<?= esc($url) ?>" target="_blank" rel="noopener">Open source</a>
                                    <button
                                        type="button"
                                        class="result__action result__action--secondary result__copy-button"
                                        data-url="<?= esc($url) ?>"
                                        data-label-default="Copy link"
                                        data-label-success="Copied!"
                                    >
                                        Copy link
                                    </button>
                                </div>
                            <?php endif; ?>
                            <?php if ($badges !== []): ?>
                                <ul class="result__tags">
                                    <?php foreach ($badges as $badge): ?>
                                        <li class="result__tag result__tag--<?= esc($badge['class']) ?>" title="<?= esc($badge['title']) ?>"><?= esc($badge['label']) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </div>
        <aside class="search-main__aside">
            <?php if ($facetGroups !== []): ?>
                <section class="insight-card">
                    <header class="insight-card__header">
                        <h3>Filter results</h3>
                        <p>Focus on the signals that matter.</p>
                    </header>
                    <?php foreach ($facetGroups as $group): ?>
                        <div class="insight-card__section">
                            <h4><?= esc($group['title']) ?></h4>
                            <ul class="facet-list">
                                <?php foreach ($group['items'] as $facet): ?>
                                    <?php
                                        if (!is_array($facet)) {
                                            continue;
                                        }
                                        $facetKey = (string) ($facet['key'] ?? '');
                                        if ($facetKey === '') {
                                            continue;
                                        }
                                        $facetLabel = isset($facet['label']) ? (string) $facet['label'] : $facetKey;
                                        $facetCount = (int) ($facet['count'] ?? 0);
                                        $facetShare = isset($facet['share']) ? (float) $facet['share'] : null;
                                        $facetActive = isFilterActive($activeFilters, $group['param'], $facetKey);
                                        $facetUrl = toggleFilterUrl($searchPath, $queryParams, $group['param'], $facetKey);
                                    ?>
                                    <li>
                                        <a class="facet-pill<?= $facetActive ? ' facet-pill--active' : '' ?>" href="<?= esc($facetUrl) ?>">
                                            <span class="facet-pill__label"><?= esc($facetLabel) ?></span>
                                            <span class="facet-pill__count"><?= esc(formatNumber($facetCount)) ?></span>
                                            <span class="facet-pill__share"><?= esc(formatPercent($facetShare, 0)) ?></span>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endforeach; ?>
                </section>
            <?php endif; ?>
            <?php if ($trendingTopics !== []): ?>
                <section class="insight-card">
                    <header class="insight-card__header">
                        <h3>Trending topics</h3>
                        <p>Pivot your coverage in a single click.</p>
                    </header>
                    <div class="insight-card__chips">
                        <?php foreach ($trendingTopics as $topic): ?>
                            <a class="chip chip--topic" href="<?= esc(buildSearchUrl($searchPath, ['q' => $topic['topic']])) ?>">
                                <span><?= esc($topic['topic']) ?></span>
                                <span class="chip__meta"><?= esc(formatNumber($topic['count'])) ?> hits</span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>
            <?php if ($topSources !== [] || $metaSources !== []): ?>
                <section class="insight-card">
                    <header class="insight-card__header">
                        <h3>Publisher intelligence</h3>
                        <p>See who is leading the conversation.</p>
                    </header>
                    <?php if ($topSources !== []): ?>
                        <div class="insight-card__section">
                            <h4>Leading outlets</h4>
                            <ul class="insight-card__facts">
                                <?php foreach ($topSources as $source): ?>
                                    <li>
                                        <strong><?= esc($source['label']) ?></strong>
                                        <span><?= esc($source['count'] === 1 ? '1 mention' : $source['count'] . ' mentions') ?></span>
                                        <?php if ($source['latestRelative'] !== null): ?>
                                            <span>Updated <?= esc($source['latestRelative']) ?></span>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    <?php if ($metaSources !== []): ?>
                        <div class="insight-card__section">
                            <h4>Quality leaders</h4>
                            <ul class="insight-card__facts insight-card__facts--quality">
                                <?php foreach ($metaSources as $source): ?>
                                    <?php $sourceActive = isFilterActive($activeFilters, 'source', $source['domain']); ?>
                                    <li>
                                        <div class="insight-card__fact-row">
                                            <strong><?= esc($source['domain']) ?></strong>
                                            <span><?= esc(formatNumber($source['count'])) ?> hits</span>
                                        </div>
                                        <div class="insight-card__fact-meta">
                                            <span class="insight-card__badge">Avg <?= esc(formatScore($source['average_quality'])) ?></span>
                                            <a class="insight-card__link" href="<?= esc(toggleFilterUrl($searchPath, $queryParams, 'source', $source['domain'])) ?>">
                                                <?= esc($sourceActive ? 'Remove filter' : 'Focus publisher') ?>
                                            </a>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
            <section class="insight-card">
                <header class="insight-card__header">
                    <h3>Search playbook</h3>
                    <p>Sharpen your coverage in a few keystrokes.</p>
                </header>
                <ul class="insight-card__tips">
                    <li>
                        <strong>Use quotes</strong>
                        <span>Wrap exact phrases like “generative AI” to keep results focused.</span>
                    </li>
                    <li>
                        <strong>Combine filters</strong>
                        <span>Add domains or entities (e.g. “open source github”) to compare coverage.</span>
                    </li>
                    <li>
                        <strong>Refresh often</strong>
                        <span>New crawls land every few minutes &mdash; rerun searches for the latest takes.</span>
                    </li>
                </ul>
            </section>
        </aside>
    </main>
    <footer class="search-footer">© <?= date('Y') ?> AIresearch · Search insights engine</footer>
    <script>
        (function () {
            const buttons = Array.from(document.querySelectorAll('.result__copy-button'));
            if (buttons.length === 0) {
                return;
            }

            const clipboardAvailable = navigator.clipboard && typeof navigator.clipboard.writeText === 'function';

            buttons.forEach((button) => {
                const defaultLabel = button.dataset.labelDefault || button.textContent || 'Copy link';
                const successLabel = button.dataset.labelSuccess || 'Copied!';

                if (!clipboardAvailable) {
                    button.disabled = true;
                    button.title = 'Clipboard access unavailable';
                    button.textContent = defaultLabel;
                    return;
                }

                button.addEventListener('click', () => {
                    const url = button.dataset.url;
                    if (!url) {
                        return;
                    }

                    navigator.clipboard.writeText(url).then(() => {
                        button.classList.add('result__action--success');
                        button.textContent = successLabel;
                        setTimeout(() => {
                            button.classList.remove('result__action--success');
                            button.textContent = defaultLabel;
                        }, 2000);
                    }).catch(() => {
                        button.classList.add('result__action--error');
                        button.textContent = 'Copy failed';
                        setTimeout(() => {
                            button.classList.remove('result__action--error');
                            button.textContent = defaultLabel;
                        }, 2000);
                    });
                });
            });
        }());
    </script>
</body>
</html>
