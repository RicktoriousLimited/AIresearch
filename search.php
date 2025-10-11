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
$basePath = $paths['basePath'];

$stylesPath = PathResolver::url($assetBase, 'assets/styles.css');
$themePath = PathResolver::url($assetBase, 'assets/theme.css');
$stylesVersion = file_exists(__DIR__ . '/assets/styles.css') ? (string) filemtime(__DIR__ . '/assets/styles.css') : (string) time();
$themeVersion = file_exists(__DIR__ . '/assets/theme.css') ? (string) filemtime(__DIR__ . '/assets/theme.css') : (string) time();

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

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Search &ndash; AIresearch</title>
    <link rel="stylesheet" href="<?= esc($themePath . '?v=' . $themeVersion) ?>">
    <link rel="stylesheet" href="<?= esc($stylesPath . '?v=' . $stylesVersion) ?>">
</head>
<body class="site site--search">
<?php SiteLayout::renderHeader($navigationPaths, 'search'); ?>
<main class="site-main simple-layout">
    <section class="search-hero">
        <div class="site-container search-hero__inner">
            <form action="<?= esc($searchPath) ?>" method="get" class="search-form">
                <label class="visually-hidden" for="search-query">Search the crawler</label>
                <input id="search-query" type="search" name="q" placeholder="Search the live crawler" value="<?= esc($query) ?>" autofocus>
                <button type="submit" class="button">Search</button>
            </form>
            <p class="search-status"><?= esc($status) ?></p>
        </div>
    </section>

    <section class="story-section">
        <div class="site-container">
            <?php if ($resultCount === 0): ?>
                <div class="section-heading">
                    <h2>No stories yet</h2>
                    <p>Try broadening your search or triggering a new crawl with a different topic.</p>
                </div>
            <?php else: ?>
                <div class="section-heading">
                    <h2><?= esc((string) $resultCount) ?> relevant brief<?= $resultCount === 1 ? '' : 's' ?></h2>
                    <p>Each card shows the most relevant excerpt our crawler extracted from the source.</p>
                </div>
                <ul class="story-list">
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
                        <li class="story-card">
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
    </section>
</main>
<?php SiteLayout::renderFooter($navigationPaths); ?>
</body>
</html>
