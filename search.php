<?php

declare(strict_types=1);

require __DIR__ . '/src/App/bootstrap.php';

use App\Crawler\HiddenCrawler;
use App\KnowledgeGraph\GraphRepository;
use App\News\NewsSearchService;
use App\Web\PathResolver;
use App\Web\SiteLayout;

function esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$paths = PathResolver::resolve();
$assetBase = $paths['assetBase'];
$basePath = $paths['basePath'];

$stylesPath = PathResolver::url($assetBase, 'assets/styles.css');
$newsStylesPath = PathResolver::url($assetBase, 'assets/news-search.css');
$scriptPath = PathResolver::url($assetBase, 'assets/news-search.js');
$themePath = PathResolver::url($assetBase, 'assets/theme.css');
$autocompleteScriptPath = PathResolver::url($assetBase, 'assets/autocomplete.js');
$stylesVersion = file_exists(__DIR__ . '/assets/styles.css') ? (string) filemtime(__DIR__ . '/assets/styles.css') : (string) time();
$newsStylesVersion = file_exists(__DIR__ . '/assets/news-search.css') ? (string) filemtime(__DIR__ . '/assets/news-search.css') : (string) time();
$scriptVersion = file_exists(__DIR__ . '/assets/news-search.js') ? (string) filemtime(__DIR__ . '/assets/news-search.js') : (string) time();
$themeVersion = file_exists(__DIR__ . '/assets/theme.css') ? (string) filemtime(__DIR__ . '/assets/theme.css') : (string) time();
$autocompleteScriptVersion = file_exists(__DIR__ . '/assets/autocomplete.js') ? (string) filemtime(__DIR__ . '/assets/autocomplete.js') : (string) time();

$homePath = PathResolver::url($assetBase, 'index.php');
$searchPath = PathResolver::url($assetBase, 'search.php');
$graphPath = PathResolver::url($assetBase, 'knowledge-graph.php');
$docsPath = PathResolver::url($assetBase, 'docs');
$newsEndpoint = PathResolver::url($assetBase, 'api/news-search.php');

$navigationPaths = [
    'home' => $homePath,
    'search' => $searchPath,
    'graph' => $graphPath,
    'docs' => $docsPath,
];

$query = isset($_GET['q']) ? trim((string) $_GET['q']) : '';

$initialResults = [];
$initialMeta = [];
$discoverySnapshot = [
    'seeds' => [],
    'total_nodes' => 0,
    'pending' => 0,
    'recommended' => [],
];

try {
    $storage = __DIR__ . '/storage/backend/crawler-history.json';
    $crawler = new HiddenCrawler($storage);
    $newsService = new NewsSearchService($crawler, new GraphRepository());
    $newsPayload = $newsService->search($query, ['limit' => 24]);
    if (is_array($newsPayload)) {
        $initialResults = isset($newsPayload['results']) && is_array($newsPayload['results']) ? $newsPayload['results'] : [];
        $initialMeta = isset($newsPayload['meta']) && is_array($newsPayload['meta']) ? $newsPayload['meta'] : [];
        if (isset($initialMeta['discovery']) && is_array($initialMeta['discovery'])) {
            $discoverySnapshot = $initialMeta['discovery'];
        } else {
            $discoverySnapshot = $crawler->discoveryTree();
        }
    }
} catch (Throwable $exception) {
    $initialResults = [];
    $initialMeta = [];
    $discoverySnapshot = [
        'seeds' => [],
        'total_nodes' => 0,
        'pending' => 0,
        'recommended' => [],
    ];
}

$topics = [];
if (isset($initialMeta['topics']) && is_array($initialMeta['topics'])) {
    foreach ($initialMeta['topics'] as $topicRow) {
        if (!is_array($topicRow)) {
            continue;
        }
        $topicName = isset($topicRow['topic']) ? (string) $topicRow['topic'] : '';
        if ($topicName !== '') {
            $topics[] = $topicName;
        }
    }
}

$suggestedQueries = [];
if (isset($initialMeta['suggested_queries']) && is_array($initialMeta['suggested_queries'])) {
    foreach ($initialMeta['suggested_queries'] as $query) {
        if (!is_string($query)) {
            continue;
        }
        $value = trim($query);
        if ($value !== '') {
            $suggestedQueries[] = $value;
        }
    }
}

$trendingTopics = array_values(array_unique(array_filter(array_merge($suggestedQueries, $topics), static fn(string $value): bool => trim($value) !== '')));
$trendingTopics = array_slice($trendingTopics, 0, 12);

$autocompleteJson = json_encode($trendingTopics, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($autocompleteJson)) {
    $autocompleteJson = '[]';
}

$formatDate = static function (?string $value): ?string {
    if ($value === null || trim($value) === '') {
        return null;
    }

    try {
        $date = new DateTimeImmutable($value);
    } catch (Exception $exception) {
        return $value;
    }

    return $date->format('F j, Y H:i');
};

$formatRelative = static function (?string $value) use ($formatDate): ?string {
    if ($value === null || trim($value) === '') {
        return null;
    }

    try {
        $date = new DateTimeImmutable($value);
    } catch (Exception $exception) {
        return $value;
    }

    $diff = time() - $date->getTimestamp();
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

    return $formatDate($value);
};

$formatPercent = static function (?float $value): string {
    if ($value === null || !is_finite($value)) {
        return '0%';
    }

    $normalised = max(0.0, min(1.0, $value));
    $precision = $normalised >= 0.1 ? 0 : 1;

    return number_format($normalised * 100, $precision) . '%';
};

$initialStatus = $query !== ''
    ? sprintf('Searching for “%s”…', $query)
    : 'Loading live coverage…';
if ($initialMeta !== []) {
    $totalMatches = isset($initialMeta['total_matches']) ? (int) $initialMeta['total_matches'] : count($initialResults);
    $highQuality = isset($initialMeta['high_quality']) ? (int) $initialMeta['high_quality'] : 0;
    $averageQuality = isset($initialMeta['average_quality']) ? (float) $initialMeta['average_quality'] : 0.0;
    $initialStatus = sprintf('Scored %d curated stories · %d high-quality · Avg score %.1f', $totalMatches, $highQuality, $averageQuality);
}

$sources = isset($initialMeta['sources']) && is_array($initialMeta['sources']) ? $initialMeta['sources'] : [];
$statsSummary = 'No trusted sources ranked yet.';
if ($sources !== []) {
    $topSources = array_slice($sources, 0, 3);
    $parts = [];
    foreach ($topSources as $row) {
        if (!is_array($row)) {
            continue;
        }
        $domain = isset($row['domain']) ? (string) $row['domain'] : '';
        if ($domain === '') {
            continue;
        }
        $average = isset($row['average_quality']) ? (float) $row['average_quality'] : 0.0;
        $parts[] = $average > 0.0 ? sprintf('%s (%.1f)', $domain, $average) : $domain;
    }
    if ($parts !== []) {
        $statsSummary = 'Top sources: ' . implode(' · ', $parts);
    }
}

$discoverySeeds = isset($discoverySnapshot['seeds']) && is_array($discoverySnapshot['seeds']) ? $discoverySnapshot['seeds'] : [];
$discoveryTotal = isset($discoverySnapshot['total_nodes']) ? (int) $discoverySnapshot['total_nodes'] : count($discoverySeeds);
$discoveryPending = isset($discoverySnapshot['pending']) ? (int) $discoverySnapshot['pending'] : 0;
$discoveryRecommended = isset($discoverySnapshot['recommended']) && is_array($discoverySnapshot['recommended']) ? $discoverySnapshot['recommended'] : [];
$discoveryRecommendedCount = count($discoveryRecommended);
$discoveryPreviewSeeds = array_slice($discoverySeeds, 0, 3);
$discoveryStatusText = sprintf('Tracking %s page%s · %s pending', number_format($discoveryTotal), $discoveryTotal === 1 ? '' : 's', number_format($discoveryPending));

$facets = isset($initialMeta['facets']) && is_array($initialMeta['facets']) ? $initialMeta['facets'] : [];

$normaliseFacet = static function ($facet): array {
    if (!is_array($facet)) {
        return [];
    }

    $normalised = [];
    foreach ($facet as $row) {
        if (!is_array($row)) {
            continue;
        }

        $label = isset($row['label']) ? (string) $row['label'] : '';
        $count = isset($row['count']) ? (int) $row['count'] : 0;
        $share = isset($row['share']) ? (float) $row['share'] : null;
        if ($label === '' || $count <= 0) {
            continue;
        }

        $normalised[] = [
            'label' => $label,
            'count' => $count,
            'share' => $share,
        ];
    }

    return $normalised;
};

$recencyFacet = $normaliseFacet($facets['recency'] ?? []);
$qualityFacet = $normaliseFacet($facets['quality'] ?? []);
$contentFacet = $normaliseFacet($facets['content_types'] ?? []);
$ingestionFacet = $normaliseFacet($facets['ingestion'] ?? []);

$recencySummary = $recencyFacet === []
    ? 'Fresh coverage metrics will appear once crawls complete.'
    : sprintf('%s of coverage from %s', $formatPercent($recencyFacet[0]['share'] ?? null), strtolower($recencyFacet[0]['label']));

$qualitySummary = $qualityFacet === []
    ? 'Awaiting quality signals from the latest crawl.'
    : sprintf('%s of stories score %s', $formatPercent($qualityFacet[0]['share'] ?? null), strtolower($qualityFacet[0]['label']));

$ingestionSummary = 'No ingestion stats yet.';
if ($ingestionFacet !== []) {
    $totalIngested = 0;
    $totalStories = 0;
    foreach ($ingestionFacet as $row) {
        $totalStories += $row['count'];
        if (stripos($row['label'], 'captured') !== false || stripos($row['label'], 'ingested') !== false) {
            $totalIngested += $row['count'];
        }
    }
    if ($totalStories > 0) {
        $ingestionSummary = sprintf('%s of results already enriched', $formatPercent($totalIngested / $totalStories));
    }
}

$contentSummary = $contentFacet === []
    ? 'Content mix pending enrichment.'
    : sprintf('%s of stories are %s', $formatPercent($contentFacet[0]['share'] ?? null), strtolower($contentFacet[0]['label']));

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>News search &ndash; AIresearch</title>
    <link rel="stylesheet" href="<?= esc($themePath . '?v=' . $themeVersion) ?>">
    <link rel="stylesheet" href="<?= esc($stylesPath . '?v=' . $stylesVersion) ?>">
    <link rel="stylesheet" href="<?= esc($newsStylesPath . '?v=' . $newsStylesVersion) ?>">
</head>
<body class="site site--search search-page--news">
<?php SiteLayout::renderHeader($navigationPaths, 'search'); ?>
<main class="site-main news-search" id="main">
    <div
        class="news-search__shell site-container"
        data-news-app
        data-news-endpoint="<?= esc($newsEndpoint) ?>"
        data-initial-query="<?= esc($query) ?>"
    >
        <section class="news-search__masthead">
            <div class="news-search__masthead-intro">
                <p class="news-search__eyebrow">Live headline monitor</p>
                <h1 class="news-search__title">Discover trusted coverage in seconds</h1>
                <p class="news-search__lead">Query the continuously updating news graph, score sources by authority, and trigger fresh crawls when gaps appear.</p>
                <p class="news-search__context" data-news-context><?= $query !== ''
                    ? 'Tracking coverage for “' . esc($query) . '”.'
                    : 'Start with a topic, organisation, or event to see live intelligence.'
                ?></p>
            </div>
            <form class="news-search__form" data-news-search-form role="search" data-autocomplete-container>
                <label class="visually-hidden" for="news-query">Search the latest headlines</label>
                <input
                    id="news-query"
                    name="q"
                    type="search"
                    placeholder="Search companies, themes, or breaking events"
                    autocomplete="off"
                    spellcheck="false"
                    value="<?= esc($query) ?>"
                    data-news-query
                    data-autocomplete
                    data-autocomplete-source='<?= esc($autocompleteJson) ?>'
                >
                <button type="submit" class="news-search__submit">Search</button>
            </form>
            <div class="news-search__masthead-meta">
                <p class="news-search__status" role="status" data-news-status><?= esc($initialStatus) ?></p>
                <p class="news-search__stats" data-news-stats><?= esc($statsSummary) ?></p>
            </div>
        </section>

        <section class="news-search__insights" aria-label="Coverage insights" data-news-insights>
            <header class="news-search__insights-header">
                <h2>Coverage insights</h2>
                <p>Track freshness, quality mix, content types, and enrichment progress for the current query.</p>
            </header>
            <div class="news-insights__grid">
                <article class="news-insight-card" data-news-recency-card>
                    <h3>Recency</h3>
                    <p class="news-insight-card__summary" data-news-recency-summary><?= esc($recencySummary) ?></p>
                    <ul class="news-insight-card__list" data-news-recency>
                        <?php if ($recencyFacet === []): ?>
                            <li class="news-insight-card__empty">Recency distribution will populate after the next crawl.</li>
                        <?php else: ?>
                            <?php foreach ($recencyFacet as $facetRow): ?>
                                <li>
                                    <span class="label"><?= esc($facetRow['label']) ?></span>
                                    <span class="value"><?= esc((string) $facetRow['count']) ?> · <?= esc($formatPercent($facetRow['share'] ?? null)) ?></span>
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </article>
                <article class="news-insight-card" data-news-quality-card>
                    <h3>Quality mix</h3>
                    <p class="news-insight-card__summary" data-news-quality-summary><?= esc($qualitySummary) ?></p>
                    <ul class="news-insight-card__list" data-news-quality>
                        <?php if ($qualityFacet === []): ?>
                            <li class="news-insight-card__empty">Quality buckets appear once headlines are scored.</li>
                        <?php else: ?>
                            <?php foreach ($qualityFacet as $facetRow): ?>
                                <li>
                                    <span class="label"><?= esc($facetRow['label']) ?></span>
                                    <span class="value"><?= esc((string) $facetRow['count']) ?> · <?= esc($formatPercent($facetRow['share'] ?? null)) ?></span>
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </article>
                <article class="news-insight-card" data-news-content-card>
                    <h3>Content types</h3>
                    <p class="news-insight-card__summary" data-news-content-summary><?= esc($contentSummary) ?></p>
                    <ul class="news-insight-card__list" data-news-content>
                        <?php if ($contentFacet === []): ?>
                            <li class="news-insight-card__empty">We will classify formats as new sources arrive.</li>
                        <?php else: ?>
                            <?php foreach ($contentFacet as $facetRow): ?>
                                <li>
                                    <span class="label"><?= esc($facetRow['label']) ?></span>
                                    <span class="value"><?= esc((string) $facetRow['count']) ?> · <?= esc($formatPercent($facetRow['share'] ?? null)) ?></span>
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </article>
                <article class="news-insight-card" data-news-ingest-card>
                    <h3>Enrichment</h3>
                    <p class="news-insight-card__summary" data-news-ingest-summary><?= esc($ingestionSummary) ?></p>
                    <ul class="news-insight-card__list" data-news-ingest>
                        <?php if ($ingestionFacet === []): ?>
                            <li class="news-insight-card__empty">Ingestion progress will update as documents are processed.</li>
                        <?php else: ?>
                            <?php foreach ($ingestionFacet as $facetRow): ?>
                                <li>
                                    <span class="label"><?= esc($facetRow['label']) ?></span>
                                    <span class="value"><?= esc((string) $facetRow['count']) ?> · <?= esc($formatPercent($facetRow['share'] ?? null)) ?></span>
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </article>
            </div>
        </section>

        <div class="news-search__layout">
            <section class="news-search__results" aria-label="News results">
                <div class="news-search__list" data-news-results>
                <?php if ($initialResults === []): ?>
                    <div class="news-empty">No stories indexed yet. Run a crawl from the discovery console to populate the feed.</div>
                <?php else: ?>
                    <?php foreach ($initialResults as $result): ?>
                        <?php
                        if (!is_array($result)) {
                            continue;
                        }
                        $resultUrl = isset($result['url']) ? (string) $result['url'] : '';
                        if ($resultUrl === '') {
                            continue;
                        }
                        $resultTitle = trim((string) ($result['title'] ?? $resultUrl));
                        if ($resultTitle === '') {
                            $resultTitle = $resultUrl;
                        }
                        $resultSummary = trim((string) ($result['summary'] ?? $result['preview'] ?? ''));
                        if ($resultSummary !== '' && mb_strlen($resultSummary) > 260) {
                            $resultSummary = rtrim(mb_substr($resultSummary, 0, 260)) . '…';
                        }
                        $resultQuality = isset($result['quality_score']) ? number_format((float) $result['quality_score'], 1) : '0.0';
                        $resultLabel = isset($result['quality_label']) ? (string) $result['quality_label'] : 'Quality';
                        $resultDomain = isset($result['source_domain']) ? (string) $result['source_domain'] : '';
                        $resultSiteName = isset($result['source_site_name']) ? trim((string) $result['source_site_name']) : '';
                        $resultRelative = $formatRelative($result['last_checked_at'] ?? $result['fetched_at'] ?? null);
                        $resultPublishedRelative = $formatRelative($result['source_published_at'] ?? null);
                        $resultPublishedAbsolute = $formatDate($result['source_published_at'] ?? null);
                        $resultPublished = $resultPublishedRelative ?? $resultPublishedAbsolute;
                        $resultTopics = isset($result['topics']) && is_array($result['topics'])
                            ? array_slice(array_filter(array_map(static fn($topic) => is_string($topic) ? trim($topic) : '', $result['topics'])), 0, 4)
                            : [];
                        $resultIngest = !empty($result['ingest']);
                        $resultImage = '';
                        if (isset($result['image_url']) && is_string($result['image_url'])) {
                            $resultImage = trim($result['image_url']);
                        }
                        if ($resultImage === '' && isset($result['thumbnail']) && is_string($result['thumbnail'])) {
                            $resultImage = trim($result['thumbnail']);
                        }
                        $metaItems = [];
                        if ($resultSiteName !== '' || $resultDomain !== '') {
                            $sourceLabel = $resultSiteName !== '' ? $resultSiteName : $resultDomain;
                            if ($resultSiteName !== '' && $resultDomain !== '' && mb_strtolower($resultSiteName) !== mb_strtolower($resultDomain)) {
                                $sourceLabel .= ' · ' . $resultDomain;
                            }
                            $metaItems[] = $sourceLabel;
                        }
                        if ($resultPublished !== null) {
                            $metaItems[] = 'Published ' . $resultPublished;
                        }
                        if ($resultRelative !== null) {
                            $metaItems[] = 'Updated ' . $resultRelative;
                        }
                        $cardClasses = 'news-card';
                        if ($resultImage === '') {
                            $cardClasses .= ' news-card--no-image';
                        }
                        ?>
                        <article class="<?= esc($cardClasses) ?>">
                            <div class="news-card__content">
                                <?php if ($metaItems !== []): ?>
                                    <div class="news-card__meta">
                                        <?php foreach ($metaItems as $item): ?>
                                            <span><?= esc($item) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                <h3 class="news-card__title">
                                    <a href="<?= esc($resultUrl) ?>" target="_blank" rel="noopener">
                                        <?= esc($resultTitle) ?>
                                    </a>
                                </h3>
                                <?php if ($resultSummary !== ''): ?>
                                    <p class="news-card__summary"><?= esc($resultSummary) ?></p>
                                <?php endif; ?>
                                <div class="news-card__footer">
                                    <span class="news-card__quality"<?= $resultIngest ? ' data-ingest="yes"' : '' ?>><?= esc($resultLabel) ?> · <?= esc($resultQuality) ?></span>
                                    <?php if ($resultTopics !== []): ?>
                                        <div class="news-card__topics">
                                            <?php foreach ($resultTopics as $topic): ?>
                                                <span><?= esc($topic) ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php if ($resultImage !== ''): ?>
                                <a class="news-card__media" href="<?= esc($resultUrl) ?>" target="_blank" rel="noopener">
                                    <img src="<?= esc($resultImage) ?>" alt="" loading="lazy">
                                </a>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
                </div>
            </section>
            <aside class="news-search__sidebar">
                <div class="news-sidebar__stack">
                    <section class="news-sidebar-card news-search__topics" data-news-topics<?= $trendingTopics === [] ? ' hidden' : '' ?>>
                        <h2>Trending topics</h2>
                        <div class="news-search__topics-list" data-news-topics-list>
                            <?php foreach ($trendingTopics as $topic): ?>
                                <button type="button" class="news-search__chip"><?= esc($topic) ?></button>
                            <?php endforeach; ?>
                        </div>
                    </section>
                    <section class="news-sidebar-card news-discovery" data-news-discovery<?= $discoverySeeds === [] ? ' hidden' : '' ?>>
                        <header class="news-discovery__header">
                            <h2>Discovery map</h2>
                            <p data-news-discovery-status><?= esc($discoveryStatusText) ?></p>
                        </header>
                        <div class="news-discovery__tree" data-news-discovery-tree>
                            <?php if ($discoveryPreviewSeeds !== []): ?>
                                <ul class="discovery-tree">
                                    <?php foreach ($discoveryPreviewSeeds as $seed): ?>
                                        <?php
                                        if (!is_array($seed)) {
                                            continue;
                                        }
                                        $seedUrl = isset($seed['url']) ? (string) $seed['url'] : '';
                                        if ($seedUrl === '') {
                                            continue;
                                        }
                                        $seedTitle = trim((string) ($seed['title'] ?? $seedUrl));
                                        if ($seedTitle === '') {
                                            $seedTitle = $seedUrl;
                                        }
                                        $seedChildCount = isset($seed['child_count']) ? (int) $seed['child_count'] : 0;
                                        $seedRelative = $formatRelative($seed['last_seen_at'] ?? $seed['first_seen_at'] ?? null);
                                        ?>
                                        <li class="discovery-tree__item">
                                            <a href="<?= esc($seedUrl) ?>" target="_blank" rel="noopener" class="discovery-tree__link"><?= esc($seedTitle) ?></a>
                                            <span class="discovery-tree__meta"><?= esc((string) $seedChildCount) ?> link<?= $seedChildCount === 1 ? '' : 's' ?><?= $seedRelative !== null ? ' · ' . esc($seedRelative) : '' ?></span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <p class="discovery-tree__empty">Connect seed URLs to populate the discovery tree.</p>
                            <?php endif; ?>
                        </div>
                        <div class="news-discovery__actions">
                            <button type="button" class="button ghost" data-news-continue<?= $discoveryRecommendedCount === 0 ? ' disabled' : '' ?>>Continue discovery<?= $discoveryRecommendedCount > 0 ? ' (' . esc((string) $discoveryRecommendedCount) . ')' : '' ?></button>
                            <p class="news-discovery__hint" data-news-continue-status><?= $discoveryRecommendedCount === 0 ? 'No queued pages right now.' : '' ?></p>
                        </div>
                    </section>
                </div>
            </aside>
        </div>
    </div>
</main>
<?php SiteLayout::renderFooter($navigationPaths); ?>
<script src="<?= esc($autocompleteScriptPath . '?v=' . $autocompleteScriptVersion) ?>" defer></script>
<script src="<?= esc($scriptPath . '?v=' . $scriptVersion) ?>" defer></script>
</body>
</html>
