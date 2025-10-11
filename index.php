<?php

declare(strict_types=1);

require __DIR__ . '/src/App/bootstrap.php';

use App\Crawler\HiddenCrawler;
use App\KnowledgeGraph\GraphRepository;
use App\KnowledgeGraph\GraphResearcher;
use App\KnowledgeGraph\ResearchService;
use App\News\NewsSearchService;
use App\Web\PathResolver;

function esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$paths = PathResolver::resolve();
$basePath = $paths['basePath'];
$assetBase = $paths['assetBase'];

$stylesPath = PathResolver::url($assetBase, 'assets/styles.css');
$scriptPath = PathResolver::url($assetBase, 'assets/home.js');
$themePath = PathResolver::url($assetBase, 'assets/theme.css');
$autocompleteScriptPath = PathResolver::url($assetBase, 'assets/autocomplete.js');
$stylesVersion = file_exists(__DIR__ . '/assets/styles.css') ? (string) filemtime(__DIR__ . '/assets/styles.css') : (string) time();
$scriptVersion = file_exists(__DIR__ . '/assets/home.js') ? (string) filemtime(__DIR__ . '/assets/home.js') : (string) time();
$themeVersion = file_exists(__DIR__ . '/assets/theme.css') ? (string) filemtime(__DIR__ . '/assets/theme.css') : (string) time();
$autocompleteScriptVersion = file_exists(__DIR__ . '/assets/autocomplete.js') ? (string) filemtime(__DIR__ . '/assets/autocomplete.js') : (string) time();

$homePath = PathResolver::url($assetBase, 'index.php');
$searchPath = PathResolver::url($assetBase, 'search.php');
$graphPath = PathResolver::url($assetBase, 'knowledge-graph.php');
$docsPath = PathResolver::url($assetBase, 'docs');
$apiPath = PathResolver::url($assetBase, 'api');

$repository = new GraphRepository();
$researcher = new GraphResearcher($repository);
$service = new ResearchService($repository);

$initialSearch = $researcher->searchGraph('', 18);
$topEntities = $service->listTopEntities(12);
$summary = isset($initialSearch['summary']) && is_array($initialSearch['summary']) ? $initialSearch['summary'] : [];
$sources = isset($initialSearch['sources']) && is_array($initialSearch['sources']) ? $initialSearch['sources'] : [];
$updatedAt = isset($initialSearch['updated_at']) && is_string($initialSearch['updated_at']) ? $initialSearch['updated_at'] : null;

$formatNumber = static function ($value): string {
    if (!is_numeric($value)) {
        $value = 0;
    }

    return number_format((int) round((float) $value));
};

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

$documentsProcessed = $formatNumber($summary['documents_processed'] ?? 0);
$triplesExtracted = $formatNumber($summary['triples'] ?? count($initialSearch['triples'] ?? []));
$uniqueEntities = $formatNumber($summary['unique_entities'] ?? count($initialSearch['entities'] ?? []));
$synonymGroups = $formatNumber($summary['synonym_groups'] ?? count($initialSearch['synonyms'] ?? []));
$sourcesTracked = $formatNumber(count($sources));
$updatedLabel = $formatDate($updatedAt) ?? $updatedAt;

$entityNames = [];
foreach ($topEntities as $entityRow) {
    if (!is_array($entityRow)) {
        continue;
    }

    $name = isset($entityRow['entity']) && is_string($entityRow['entity']) ? trim($entityRow['entity']) : '';
    if ($name === '') {
        continue;
    }

    $entityNames[] = $name;
}

$curatedQueries = [
    'latest ai regulation briefing',
    'chip supply chain headlines',
    'startup funding announcements',
    'enterprise security breaches',
    'energy transition investments',
    'climate risk disclosures',
    'biotech clinical trial updates',
];

$newsResults = [];
$newsMeta = [];
$discoverySnapshot = [
    'seeds' => [],
    'total_nodes' => 0,
    'pending' => 0,
    'recommended' => [],
];
$newsTopics = [];
$newsSuggestedQueries = [];
$trendingQueries = [];

try {
    $crawlerStorage = __DIR__ . '/storage/backend/crawler-history.json';
    $hiddenCrawler = new HiddenCrawler($crawlerStorage, null, null, $service);
    $newsService = new NewsSearchService($hiddenCrawler, $repository);
    $newsPayload = $newsService->search('', ['limit' => 16]);
    if (is_array($newsPayload)) {
        $newsResults = isset($newsPayload['results']) && is_array($newsPayload['results']) ? $newsPayload['results'] : [];
        $newsMeta = isset($newsPayload['meta']) && is_array($newsPayload['meta']) ? $newsPayload['meta'] : [];
        if (isset($newsMeta['discovery']) && is_array($newsMeta['discovery'])) {
            $discoverySnapshot = $newsMeta['discovery'];
        } else {
            $discoverySnapshot = $hiddenCrawler->discoveryTree();
        }
    }
} catch (Throwable $exception) {
    $newsResults = [];
    $newsMeta = [];
    $discoverySnapshot = [
        'seeds' => [],
        'total_nodes' => 0,
        'pending' => 0,
        'recommended' => [],
    ];
}

if (isset($newsMeta['topics']) && is_array($newsMeta['topics'])) {
    foreach ($newsMeta['topics'] as $topicRow) {
        if (!is_array($topicRow)) {
            continue;
        }

        $topicName = isset($topicRow['topic']) ? (string) $topicRow['topic'] : '';
        if ($topicName !== '') {
            $newsTopics[] = $topicName;
        }
    }
}

if (isset($newsMeta['suggested_queries']) && is_array($newsMeta['suggested_queries'])) {
    foreach ($newsMeta['suggested_queries'] as $suggested) {
        if (!is_string($suggested)) {
            continue;
        }

        $value = trim($suggested);
        if ($value !== '') {
            $newsSuggestedQueries[] = $value;
        }
    }
}

foreach ($newsSuggestedQueries as $query) {
    $trendingQueries[] = $query;
}
foreach ($newsTopics as $topic) {
    $trendingQueries[] = $topic;
}
foreach ($entityNames as $entityName) {
    $trendingQueries[] = $entityName;
}
foreach ($curatedQueries as $query) {
    $trendingQueries[] = $query;
}
$trendingQueries = array_values(array_unique(array_filter($trendingQueries, static fn(string $value): bool => trim($value) !== '')));
$trendingQueries = array_slice($trendingQueries, 0, 12);

$placeholderPhrases = $trendingQueries !== [] ? $trendingQueries : $curatedQueries;
$placeholderJson = json_encode($placeholderPhrases, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if (!is_string($placeholderJson)) {
    $placeholderJson = '[]';
}

$allSuggestions = array_merge(
    $curatedQueries,
    $newsSuggestedQueries,
    $newsTopics,
    $entityNames,
    $trendingQueries
);
$allSuggestions = array_values(array_filter(array_unique(array_map(static function ($value) {
    if (!is_string($value)) {
        return '';
    }

    return trim($value);
}))));
$autocompleteJson = json_encode($allSuggestions, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($autocompleteJson)) {
    $autocompleteJson = '[]';
}

$topStories = array_slice($newsResults, 0, 4);
$topSources = isset($newsMeta['sources']) && is_array($newsMeta['sources']) ? array_slice($newsMeta['sources'], 0, 3) : [];
$newsSummaryStats = [
    'total' => isset($newsMeta['total_matches']) ? (int) $newsMeta['total_matches'] : count($newsResults),
    'highQuality' => isset($newsMeta['high_quality']) ? (int) $newsMeta['high_quality'] : 0,
    'averageQuality' => isset($newsMeta['average_quality']) ? (float) $newsMeta['average_quality'] : 0.0,
    'ingested' => isset($newsMeta['ingested']) ? (int) $newsMeta['ingested'] : 0,
];

$heroMetrics = [
    [
        'label' => 'Stories indexed',
        'value' => $formatNumber($newsSummaryStats['total']),
    ],
    [
        'label' => 'High-quality stories',
        'value' => $formatNumber($newsSummaryStats['highQuality']),
    ],
    [
        'label' => 'Average quality score',
        'value' => number_format((float) $newsSummaryStats['averageQuality'], 1),
    ],
    [
        'label' => 'Fresh ingests this run',
        'value' => $formatNumber($newsSummaryStats['ingested']),
    ],
];

$trustedSourcesSummary = [];
foreach ($topSources as $sourceRow) {
    if (!is_array($sourceRow)) {
        continue;
    }

    $domain = isset($sourceRow['domain']) ? (string) $sourceRow['domain'] : '';
    if ($domain === '') {
        continue;
    }

    $average = isset($sourceRow['average_quality']) ? (float) $sourceRow['average_quality'] : 0.0;
    $trustedSourcesSummary[] = $average > 0.0
        ? sprintf('%s (%.1f)', $domain, $average)
        : $domain;
}
$trustedSourcesSummary = array_slice($trustedSourcesSummary, 0, 3);

$discoverySeeds = isset($discoverySnapshot['seeds']) && is_array($discoverySnapshot['seeds']) ? $discoverySnapshot['seeds'] : [];
$discoveryTotal = isset($discoverySnapshot['total_nodes']) ? (int) $discoverySnapshot['total_nodes'] : count($discoverySeeds);
$discoveryPending = isset($discoverySnapshot['pending']) ? (int) $discoverySnapshot['pending'] : 0;
$discoveryRecommended = isset($discoverySnapshot['recommended']) && is_array($discoverySnapshot['recommended'])
    ? $discoverySnapshot['recommended']
    : [];
$discoveryMetrics = [
    [
        'label' => 'Seeds tracked',
        'value' => $formatNumber(count($discoverySeeds)),
    ],
    [
        'label' => 'Pages mapped',
        'value' => $formatNumber($discoveryTotal),
    ],
    [
        'label' => 'Pending crawl',
        'value' => $formatNumber($discoveryPending),
    ],
    [
        'label' => 'Next crawl targets',
        'value' => $formatNumber(count($discoveryRecommended)),
    ],
];
$discoveryPreviewSeeds = array_slice($discoverySeeds, 0, 3);
$discoveryPreviewRecommended = array_slice($discoveryRecommended, 0, 4);

$featureHighlights = [
    [
        'title' => 'Live news crawl',
        'description' => 'The autonomous crawler follows trusted sources and freshly discovered links to keep headlines current by the minute.',
    ],
    [
        'title' => 'Quality scoring',
        'description' => 'Each article is ranked on source authority, recency, and entity coverage so analysts can jump straight to decisive updates.',
    ],
    [
        'title' => 'Discovery map',
        'description' => 'Visualise which seed URLs unlocked every follow-on story and spot coverage gaps that are ready for the next crawl.',
    ],
];

$workflowSteps = [
    [
        'title' => 'Track the live feed',
        'description' => 'Run a search or browse trending topics to surface the freshest reporting across your portfolio.',
    ],
    [
        'title' => 'Audit discovery paths',
        'description' => 'See the crawl tree to understand which links delivered each article and decide where to expand coverage next.',
    ],
    [
        'title' => 'Alert stakeholders',
        'description' => 'Share curated digests or export citations directly from the news graph for compliance-ready updates.',
    ],
];

$formatRelative = static function (?string $value) use ($formatDate): ?string {
    if ($value === null || trim($value) === '') {
        return null;
    }

    try {
        $timestamp = new DateTimeImmutable($value);
    } catch (Exception $exception) {
        return $value;
    }

    $diff = time() - $timestamp->getTimestamp();
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AIresearch Newsroom</title>
    <link rel="stylesheet" href="<?= esc($themePath . '?v=' . $themeVersion) ?>">
    <link rel="stylesheet" href="<?= esc($stylesPath . '?v=' . $stylesVersion) ?>">
</head>
<body class="site site--home site--news">
<header class="site-header">
    <div class="site-header__inner">
        <a class="site-brand" href="<?= esc($homePath) ?>">AIresearch</a>
        <nav class="site-nav" aria-label="Primary navigation">
            <a class="site-nav__link site-nav__link--active" href="<?= esc($homePath) ?>">Home</a>
            <a class="site-nav__link" href="<?= esc($searchPath) ?>">News search</a>
            <a class="site-nav__link" href="<?= esc($graphPath) ?>">Knowledge graph</a>
            <a class="site-nav__link" href="<?= esc($docsPath) ?>">Docs</a>
        </nav>
    </div>
</header>
<main class="news-home" id="main">
    <section class="news-home__hero">
        <div class="news-home__hero-grid">
            <div class="news-home__lead">
                <p class="news-home__eyebrow">Live briefing feed</p>
                <h1 class="news-home__title">Search the AIresearch newsroom</h1>
                <p class="news-home__subtitle">Monitor trusted headlines, rapid crawls, and graph-ranked summaries in one search bar.</p>
                <form class="news-home__form" action="<?= esc($searchPath) ?>" method="get" role="search" data-home-search data-autocomplete-container>
                    <label class="visually-hidden" for="home-query">Search the latest headlines</label>
                    <input
                        id="home-query"
                        name="q"
                        type="search"
                        placeholder="Search breaking news, companies, or themes"
                        autocomplete="off"
                        spellcheck="false"
                        data-home-search-input
                        data-home-phrases='<?= esc($placeholderJson) ?>'
                        data-autocomplete
                        data-autocomplete-source='<?= esc($autocompleteJson) ?>'
                    >
                    <button type="submit" class="news-home__submit">Search headlines</button>
                </form>
                <?php if ($trendingQueries !== []): ?>
                    <div class="news-home__suggestions" data-home-suggestions>
                        <span class="news-home__suggestions-label">Trending:</span>
                        <div class="news-home__chips">
                            <?php foreach ($trendingQueries as $query): ?>
                                <button type="button" class="news-chip" data-home-chip="<?= esc($query) ?>"><?= esc($query) ?></button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if ($trustedSourcesSummary !== []): ?>
                    <p class="news-home__sources">Top sources: <?= esc(implode(' · ', $trustedSourcesSummary)) ?></p>
                <?php endif; ?>
            </div>
            <aside class="news-home__metrics" aria-label="Newsroom metrics">
                <?php foreach ($heroMetrics as $metric): ?>
                    <div class="news-home__metric">
                        <span class="news-home__metric-value"><?= esc($metric['value']) ?></span>
                        <span class="news-home__metric-label"><?= esc($metric['label']) ?></span>
                    </div>
                <?php endforeach; ?>
            </aside>
        </div>
    </section>

    <?php if ($topStories !== []): ?>
        <section class="news-home__section news-home__section--stories" aria-labelledby="top-stories-heading">
            <div class="section-heading">
                <h2 id="top-stories-heading">Top stories this cycle</h2>
                <p>Curated from <?= esc((string) max(1, count($trustedSourcesSummary))) ?> trusted sources and refreshed automatically.</p>
            </div>
            <div class="news-story-grid">
                <?php foreach ($topStories as $story): ?>
                    <?php
                    if (!is_array($story)) {
                        continue;
                    }

                    $storyUrl = isset($story['url']) ? (string) $story['url'] : '';
                    if ($storyUrl === '') {
                        continue;
                    }

                    $storyTitle = trim((string) ($story['title'] ?? $storyUrl));
                    if ($storyTitle === '') {
                        $storyTitle = $storyUrl;
                    }

                    $storySummary = trim((string) ($story['summary'] ?? $story['preview'] ?? ''));
                    if ($storySummary !== '' && mb_strlen($storySummary) > 220) {
                        $storySummary = rtrim(mb_substr($storySummary, 0, 220)) . '…';
                    }

                    $storyDomain = isset($story['source_domain']) ? (string) $story['source_domain'] : '';
                    $storyRelative = $formatRelative($story['last_checked_at'] ?? $story['fetched_at'] ?? null);
                    $storyTopics = isset($story['topics']) && is_array($story['topics'])
                        ? array_slice(array_filter(array_map(static fn($topic) => is_string($topic) ? trim($topic) : '', $story['topics'])), 0, 3)
                        : [];
                    $storyQuality = isset($story['quality_score']) ? number_format((float) $story['quality_score'], 1) : '0.0';
                    $storyIngest = !empty($story['ingest']);
                    ?>
                    <article class="news-story-card">
                        <div class="news-story-card__header">
                            <span class="news-story-card__quality<?= $storyIngest ? ' news-story-card__quality--ingested' : '' ?>">Quality <?= esc($storyQuality) ?></span>
                            <?php if ($storyDomain !== ''): ?>
                                <span class="news-story-card__domain"><?= esc($storyDomain) ?></span>
                            <?php endif; ?>
                        </div>
                        <h3 class="news-story-card__title">
                            <a href="<?= esc($storyUrl) ?>" target="_blank" rel="noopener">
                                <?= esc($storyTitle) ?>
                            </a>
                        </h3>
                        <?php if ($storySummary !== ''): ?>
                            <p class="news-story-card__summary"><?= esc($storySummary) ?></p>
                        <?php endif; ?>
                        <div class="news-story-card__footer">
                            <?php if ($storyRelative !== null): ?>
                                <span class="news-story-card__time"><?= esc($storyRelative) ?></span>
                            <?php endif; ?>
                            <?php if ($storyTopics !== []): ?>
                                <div class="news-story-card__topics" aria-label="Topics">
                                    <?php foreach ($storyTopics as $topic): ?>
                                        <span><?= esc($topic) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <section class="news-home__section news-home__section--discovery" aria-labelledby="discovery-heading">
        <div class="section-heading">
            <h2 id="discovery-heading">Discovery map snapshot</h2>
            <p>Review how the crawler expanded coverage and queue the next wave of pages to fetch.</p>
        </div>
        <div class="news-discovery">
            <div class="news-discovery__metrics">
                <?php foreach ($discoveryMetrics as $metric): ?>
                    <div class="news-discovery__metric">
                        <span class="news-discovery__metric-value"><?= esc($metric['value']) ?></span>
                        <span class="news-discovery__metric-label"><?= esc($metric['label']) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="news-discovery__content">
                <?php if ($discoveryPreviewSeeds !== []): ?>
                    <ul class="news-discovery__list">
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

                            $seedChildren = isset($seed['children']) && is_array($seed['children'])
                                ? array_slice($seed['children'], 0, 3)
                                : [];
                            $seedChildCount = isset($seed['child_count']) ? (int) $seed['child_count'] : count($seedChildren);
                            $seedRelative = $formatRelative($seed['last_seen_at'] ?? $seed['first_seen_at'] ?? null);
                            ?>
                            <li class="news-discovery__item">
                                <h3 class="news-discovery__item-title">
                                    <a href="<?= esc($seedUrl) ?>" target="_blank" rel="noopener"><?= esc($seedTitle) ?></a>
                                </h3>
                                <p class="news-discovery__item-meta">
                                    <?= esc((string) $seedChildCount) ?> linked page<?= $seedChildCount === 1 ? '' : 's' ?>
                                    <?php if ($seedRelative !== null): ?>
                                        · <?= esc($seedRelative) ?>
                                    <?php endif; ?>
                                </p>
                                <?php if ($seedChildren !== []): ?>
                                    <ul class="news-discovery__children">
                                        <?php foreach ($seedChildren as $child): ?>
                                            <?php
                                            if (!is_array($child)) {
                                                continue;
                                            }

                                            $childUrl = isset($child['url']) ? (string) $child['url'] : '';
                                            if ($childUrl === '') {
                                                continue;
                                            }

                                            $childTitle = trim((string) ($child['title'] ?? $childUrl));
                                            if ($childTitle === '') {
                                                $childTitle = $childUrl;
                                            }

                                            $childStatus = isset($child['status']) ? (string) $child['status'] : '';
                                            $childRelative = $formatRelative($child['last_seen_at'] ?? $child['first_seen_at'] ?? null);
                                            ?>
                                            <li>
                                                <a href="<?= esc($childUrl) ?>" target="_blank" rel="noopener"><?= esc($childTitle) ?></a>
                                                <?php if ($childStatus === 'pending'): ?>
                                                    <span class="news-discovery__badge">Pending</span>
                                                <?php endif; ?>
                                                <?php if ($childRelative !== null): ?>
                                                    <span class="news-discovery__time"><?= esc($childRelative) ?></span>
                                                <?php endif; ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="news-discovery__empty">Seed URLs have not been crawled yet. Start a discovery run from the newsroom search console.</p>
                <?php endif; ?>

                <?php if ($discoveryPreviewRecommended !== []): ?>
                    <div class="news-discovery__queue">
                        <h3>Ready for the next crawl</h3>
                        <ul>
                            <?php foreach ($discoveryPreviewRecommended as $recommendation): ?>
                                <?php
                                if (!is_array($recommendation)) {
                                    continue;
                                }
                                $recDomain = isset($recommendation['domain']) ? (string) $recommendation['domain'] : '';
                                $recUrl = isset($recommendation['url']) ? (string) $recommendation['url'] : '';
                                $recLabel = $recDomain !== '' ? $recDomain : $recUrl;
                                if ($recLabel === '') {
                                    continue;
                                }
                                $recRelative = $formatRelative($recommendation['last_seen_at'] ?? null);
                                ?>
                                <li>
                                    <span class="news-discovery__queue-domain"><?= esc($recLabel) ?></span>
                                    <?php if ($recRelative !== null): ?>
                                        <span class="news-discovery__queue-time"><?= esc($recRelative) ?></span>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <a class="button button--ghost" href="<?= esc($searchPath) ?>">Open discovery console</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="news-home__section news-home__section--features" aria-labelledby="feature-heading">
        <div class="section-heading">
            <h2 id="feature-heading">Why analysts trust the news graph</h2>
            <p>Blend automated crawling with knowledge graph scoring to stay ahead of market-moving headlines.</p>
        </div>
        <div class="news-feature-grid">
            <?php foreach ($featureHighlights as $feature): ?>
                <article class="news-feature-card">
                    <h3><?= esc($feature['title']) ?></h3>
                    <p><?= esc($feature['description']) ?></p>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="news-home__section news-home__section--workflow" aria-labelledby="workflow-heading">
        <div class="section-heading">
            <h2 id="workflow-heading">From alert to action in three steps</h2>
            <p>Use the newsroom workflow to triage breaking updates and move insight into stakeholder hands faster.</p>
        </div>
        <ol class="news-workflow">
            <?php foreach ($workflowSteps as $index => $step): ?>
                <li class="news-workflow__step">
                    <span class="news-workflow__index">0<?= esc((string) ($index + 1)) ?></span>
                    <div class="news-workflow__body">
                        <h3><?= esc($step['title']) ?></h3>
                        <p><?= esc($step['description']) ?></p>
                    </div>
                </li>
            <?php endforeach; ?>
        </ol>
        <div class="news-workflow__actions">
            <a class="button" href="<?= esc($searchPath) ?>">Launch news search</a>
            <a class="button button--subtle" href="<?= esc($graphPath) ?>">Explore the knowledge graph</a>
        </div>
    </section>

    <section class="news-home__section news-home__section--cta" aria-labelledby="cta-heading">
        <div class="news-cta">
            <div>
                <h2 id="cta-heading">Bring your feeds into the newsroom</h2>
                <p>Schedule crawls, push proprietary updates, or trigger discovery directly from the API. Every article is scored, cited, and searchable.</p>
            </div>
            <div class="news-cta__actions">
                <a class="button" href="<?= esc($docsPath) ?>">View API docs</a>
                <a class="button button--subtle" href="<?= esc($apiPath) ?>">Browse endpoints</a>
            </div>
        </div>
    </section>
</main>
<script src="<?= esc($autocompleteScriptPath . '?v=' . $autocompleteScriptVersion) ?>" defer></script>
<script src="<?= esc($scriptPath . '?v=' . $scriptVersion) ?>" defer></script>
</body>
</html>
