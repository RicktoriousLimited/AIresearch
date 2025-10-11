<?php

declare(strict_types=1);

require __DIR__ . '/src/App/bootstrap.php';

use App\Crawler\HiddenCrawler;
use App\News\NewsSearchService;
use App\Web\PathResolver;
use App\Web\SiteLayout;

function esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function relevantSnippet(string $text, string $query = ''): string
{
    $clean = trim($text);
    if ($clean === '') {
        return '';
    }

    $sentences = preg_split('/(?<=[.!?])\s+/u', $clean) ?: [];
    if ($sentences === []) {
        $sentences = [$clean];
    }

    $normalisedQuery = trim(mb_strtolower($query));
    if ($normalisedQuery !== '') {
        foreach ($sentences as $sentence) {
            if (mb_strpos(mb_strtolower($sentence), $normalisedQuery) !== false) {
                return trim($sentence);
            }
        }
    }

    return trim($sentences[0]);
}

function highlightTerms(string $text, string $query = ''): string
{
    $escaped = esc($text);
    $tokens = preg_split('/\s+/u', trim($query)) ?: [];
    $patterns = [];
    foreach ($tokens as $token) {
        $token = trim($token);
        if ($token === '' || mb_strlen($token) < 2) {
            continue;
        }
        $patterns[] = preg_quote($token, '/');
    }

    if ($patterns === []) {
        return $escaped;
    }

    $pattern = '/(' . implode('|', $patterns) . ')/iu';
    $highlighted = preg_replace($pattern, '<mark>$1</mark>', $escaped);

    return is_string($highlighted) ? $highlighted : $escaped;
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

$paths = PathResolver::resolve();
$assetBase = $paths['assetBase'];

$stylesPath = PathResolver::url($assetBase, 'assets/styles.css');
$themePath = PathResolver::url($assetBase, 'assets/theme.css');
$searchStylesPath = PathResolver::url($assetBase, 'assets/search.css');
$stylesVersion = file_exists(__DIR__ . '/assets/styles.css') ? (string) filemtime(__DIR__ . '/assets/styles.css') : (string) time();
$themeVersion = file_exists(__DIR__ . '/assets/theme.css') ? (string) filemtime(__DIR__ . '/assets/theme.css') : (string) time();
$searchStylesVersion = file_exists(__DIR__ . '/assets/search.css') ? (string) filemtime(__DIR__ . '/assets/search.css') : (string) time();

$homePath = PathResolver::url($assetBase, 'index.php');
$searchPath = PathResolver::url($assetBase, 'search.php');

$navigationPaths = [
    'home' => $homePath,
    'search' => $searchPath,
];

$query = isset($_GET['q']) ? trim((string) $_GET['q']) : '';

$results = [];
$status = $query === '' ? 'Showing the newest crawler highlights.' : sprintf('Results for “%s”.', $query);

try {
    $storage = __DIR__ . '/storage/backend/crawler-history.json';
    $crawler = new HiddenCrawler($storage);
    $newsService = new NewsSearchService($crawler);
    $payload = $newsService->search($query, ['limit' => 30]);
    if (is_array($payload) && isset($payload['results']) && is_array($payload['results'])) {
        $results = $payload['results'];
    }
    if (isset($payload['meta']['total_matches'])) {
        $matches = (int) $payload['meta']['total_matches'];
        if ($query !== '') {
            $status = sprintf('Found %d match%s for “%s”.', $matches, $matches === 1 ? '' : 'es', $query);
        }
    }
} catch (Throwable $exception) {
    $results = [];
    $status = 'Crawler results are temporarily unavailable.';
}

$resultCount = count($results);

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
    array_slice($topSources, 0, 3)
);

$heroSuggestions = [];

foreach ($topSources as $source) {
    $heroSuggestions[] = $source['label'];
}

if ($heroSuggestions === [] && $query === '') {
    $heroSuggestions = [
        'artificial intelligence',
        'emerging startups',
        'global regulations',
    ];
}

$heroDescription = $query === ''
    ? 'Browse the freshest crawler highlights and jump into the strongest sources instantly.'
    : sprintf('Investigate “%s” with citations ranked by recency and quality.', $query);

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Search &ndash; AIresearch</title>
    <link rel="stylesheet" href="<?= esc($themePath . '?v=' . $themeVersion) ?>">
    <link rel="stylesheet" href="<?= esc($stylesPath . '?v=' . $stylesVersion) ?>">
    <link rel="stylesheet" href="<?= esc($searchStylesPath . '?v=' . $searchStylesVersion) ?>">
</head>
<body class="site site--search search-app">
<?php SiteLayout::renderHeader($navigationPaths, 'search'); ?>
<main class="site-main search-layout">
    <section class="search-hero">
        <div class="site-container search-hero__inner">
            <div class="search-hero__header">
                <h1>Live crawler search</h1>
                <p><?= esc($heroDescription) ?></p>
            </div>
            <form action="<?= esc($searchPath) ?>" method="get" class="search-form search-hero__form">
                <label class="visually-hidden" for="search-query">Search the crawler</label>
                <input id="search-query" type="search" name="q" placeholder="Search the live crawler" value="<?= esc($query) ?>" autofocus>
                <button type="submit" class="button primary">Search</button>
            </form>
            <p class="search-status"><?= esc($status) ?></p>
            <?php if ($heroSuggestions !== []): ?>
                <div class="search-suggestions">
                    <span class="search-suggestions__label">Quick jumps</span>
                    <div class="search-suggestions__chips">
                        <?php foreach ($heroSuggestions as $suggestion): ?>
                            <a class="chip" href="<?= esc($searchPath . '?q=' . rawurlencode($suggestion)) ?>"><?= esc($suggestion) ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="search-results">
        <div class="site-container">
            <div class="search-columns">
                <div class="search-columns__primary">
                    <?php if ($resultCount === 0): ?>
                        <div class="section-heading">
                            <h2>No crawler matches yet</h2>
                            <p>Try a broader phrase or trigger a new crawl with a different focus.</p>
                        </div>
                    <?php else: ?>
                        <div class="section-heading">
                            <h2><?= esc((string) $resultCount) ?> matching stor<?= $resultCount === 1 ? 'y' : 'ies' ?></h2>
                            <p>Highlights are sorted by freshness so you can vet sources in seconds.</p>
                        </div>
                        <ul class="search-grid">
                            <?php foreach ($results as $entry): ?>
                                <?php
                                    $title = trim((string) ($entry['title'] ?? $entry['url'] ?? 'Untitled source'));
                                    $url = trim((string) ($entry['url'] ?? ''));
                                    $summarySource = (string) ($entry['summary'] ?? $entry['preview'] ?? '');
                                    $snippet = relevantSnippet($summarySource, $query);
                                    if ($snippet === '' && $summarySource !== '') {
                                        $snippet = trim($summarySource);
                                    }
                                    if ($snippet !== '' && mb_strlen($snippet) > 320) {
                                        $snippet = rtrim(mb_substr($snippet, 0, 317)) . '…';
                                    }
                                    $meta = metaLine($entry);
                                ?>
                                <li class="story-card story-card--search">
                                    <h3 class="story-card__title">
                                        <?php if ($url !== ''): ?>
                                            <a href="<?= esc($url) ?>" target="_blank" rel="noopener noreferrer"><?= esc($title) ?></a>
                                        <?php else: ?>
                                            <?= esc($title) ?>
                                        <?php endif; ?>
                                    </h3>
                                    <?php if ($snippet !== ''): ?>
                                        <p class="story-card__snippet"><?= highlightTerms($snippet, $query) ?></p>
                                    <?php endif; ?>
                                    <?php if ($meta !== ''): ?>
                                        <p class="story-card__meta"><?= esc($meta) ?></p>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
                <aside class="entity-detail">
                    <div class="entity-detail__header">
                        <h3>Source breakdown</h3>
                        <p class="entity-detail__score">See which publishers dominate this query.</p>
                    </div>
                    <div class="entity-detail__body">
                        <?php if ($topSources === []): ?>
                            <p class="entity-detail__empty">Run a search to surface leading outlets.</p>
                        <?php else: ?>
                            <div class="entity-detail__section">
                                <h4>Leading outlets</h4>
                                <ul class="entity-detail__facts top-sources">
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
                        <div class="entity-detail__section">
                            <h4>Search tips</h4>
                            <ul class="entity-detail__relations top-sources__tips">
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
                        </div>
                    </div>
                </aside>
            </div>
        </div>
    </section>
</main>
<?php SiteLayout::renderFooter($navigationPaths); ?>
</body>
</html>
