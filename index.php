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

    return implode(' · ', $parts);
}

$paths = PathResolver::resolve();
$basePath = $paths['basePath'];
$assetBase = $paths['assetBase'];

$stylesPath = PathResolver::url($assetBase, 'assets/styles.css');
$themePath = PathResolver::url($assetBase, 'assets/theme.css');
$homeScriptPath = PathResolver::url($assetBase, 'assets/home.js');
$stylesVersion = file_exists(__DIR__ . '/assets/styles.css') ? (string) filemtime(__DIR__ . '/assets/styles.css') : (string) time();
$themeVersion = file_exists(__DIR__ . '/assets/theme.css') ? (string) filemtime(__DIR__ . '/assets/theme.css') : (string) time();
$homeScriptVersion = file_exists(__DIR__ . '/assets/home.js') ? (string) filemtime(__DIR__ . '/assets/home.js') : (string) time();

$homePath = PathResolver::url($assetBase, 'index.php');
$searchPath = PathResolver::url($assetBase, 'search.php');

$navigationPaths = [
    'home' => $homePath,
    'search' => $searchPath,
];

$latestStories = [];

try {
    $crawlerStorage = __DIR__ . '/storage/backend/crawler-history.json';
    $hiddenCrawler = new HiddenCrawler($crawlerStorage);
    $newsService = new NewsSearchService($hiddenCrawler);
    $newsPayload = $newsService->search('', ['limit' => 8]);
    if (is_array($newsPayload) && isset($newsPayload['results']) && is_array($newsPayload['results'])) {
        $latestStories = array_slice($newsPayload['results'], 0, 8);
    }
} catch (Throwable $exception) {
    $latestStories = [];
}

$sampleQueries = [
    'emerging ai regulation',
    'semiconductor supply chain',
    'climate tech investments',
    'cybersecurity breach response',
];

$sampleQueriesJson = json_encode($sampleQueries, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($sampleQueriesJson)) {
    $sampleQueriesJson = '[]';
}

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AIresearch &ndash; Live intelligence made simple</title>
    <link rel="stylesheet" href="<?= esc($themePath . '?v=' . $themeVersion) ?>">
    <link rel="stylesheet" href="<?= esc($stylesPath . '?v=' . $stylesVersion) ?>">
</head>
<body class="site site--home">
<?php SiteLayout::renderHeader($navigationPaths, 'home'); ?>
<main class="site-main simple-layout">
    <section class="hero">
        <div class="site-container hero__inner">
            <div class="hero__content">
                <p class="eyebrow">Real-time crawler summary</p>
                <h1>Find the signal. Skip the noise.</h1>
                <p class="lead">Our crawler condenses fresh coverage into concise briefs so you can scan the most relevant updates instantly.</p>
                <form class="hero-search" data-home-search action="<?= esc($searchPath) ?>" method="get">
                    <label class="visually-hidden" for="hero-query">Search the live index</label>
                    <input
                        id="hero-query"
                        type="search"
                        name="q"
                        placeholder="Search the live crawler"
                        data-home-search-input
                        data-home-phrases='<?= esc($sampleQueriesJson) ?>'
                        required>
                    <button type="submit" class="button">Search</button>
                </form>
                <?php if ($sampleQueries !== []): ?>
                    <ul class="hero-suggestions">
                        <?php foreach ($sampleQueries as $query): ?>
                            <li><button type="button" data-home-suggestion="<?= esc($query) ?>"><?= esc($query) ?></button></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <?php if ($latestStories !== []): ?>
        <section class="story-section">
            <div class="site-container">
                <div class="section-heading">
                    <h2>Latest coverage briefs</h2>
                    <p>Summaries pulled straight from the crawler. Each card highlights the most relevant takeaway from the source.</p>
                </div>
                <ul class="story-list">
                    <?php foreach ($latestStories as $story): ?>
                        <?php
                            $title = trim((string) ($story['title'] ?? $story['url'] ?? 'Untitled source'));
                            $url = trim((string) ($story['url'] ?? ''));
                            $snippetSource = (string) ($story['summary'] ?? $story['preview'] ?? '');
                            $snippet = relevantSnippet($snippetSource);
                            if ($snippet === '' && $snippetSource !== '') {
                                $snippet = trim($snippetSource);
                            }
                            if ($snippet !== '' && mb_strlen($snippet) > 320) {
                                $snippet = rtrim(mb_substr($snippet, 0, 317)) . '…';
                            }
                            $meta = metaLine($story);
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
                                <p class="story-card__snippet"><?= highlightTerms($snippet) ?></p>
                            <?php endif; ?>
                            <?php if ($meta !== ''): ?>
                                <p class="story-card__meta"><?= esc($meta) ?></p>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </section>
    <?php else: ?>
        <section class="story-section">
            <div class="site-container">
                <div class="section-heading">
                    <h2>Latest coverage briefs</h2>
                    <p>The crawler is warming up. Try a search to trigger a fresh crawl.</p>
                </div>
            </div>
        </section>
    <?php endif; ?>
</main>
<?php SiteLayout::renderFooter($navigationPaths); ?>
<script src="<?= esc($homeScriptPath . '?v=' . $homeScriptVersion) ?>" defer></script>
</body>
</html>
