<?php

declare(strict_types=1);

require __DIR__ . '/src/App/bootstrap.php';

use App\KnowledgeGraph\GraphRepository;
use App\KnowledgeGraph\GraphResearcher;
use App\KnowledgeGraph\ResearchService;
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
$stylesVersion = file_exists(__DIR__ . '/assets/styles.css') ? (string) filemtime(__DIR__ . '/assets/styles.css') : (string) time();
$scriptVersion = file_exists(__DIR__ . '/assets/home.js') ? (string) filemtime(__DIR__ . '/assets/home.js') : (string) time();

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

$coverageStats = [
    [
        'label' => 'Documents analysed',
        'value' => $documentsProcessed,
    ],
    [
        'label' => 'Knowledge graph triples',
        'value' => $triplesExtracted,
    ],
    [
        'label' => 'Unique entities indexed',
        'value' => $uniqueEntities,
    ],
    [
        'label' => 'Synonym groups linked',
        'value' => $synonymGroups,
    ],
    [
        'label' => 'Curated sources',
        'value' => $sourcesTracked,
    ],
];

if ($updatedLabel !== null) {
    $coverageStats[] = [
        'label' => 'Graph last updated',
        'value' => $updatedLabel,
    ];
}

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
    'intelligent search for AI market shifts',
    'foundation model evaluation frameworks',
    'emerging biotech partnerships',
    'climate risk scenario planning',
    'synthetic data governance policies',
    'quantum compute hardware vendors',
    'customer experience AI benchmarks',
];

$trendingQueries = [];
foreach ($entityNames as $entityName) {
    $trendingQueries[] = $entityName;
}
foreach ($curatedQueries as $query) {
    $trendingQueries[] = $query;
}
$trendingQueries = array_values(array_unique(array_filter($trendingQueries, static fn(string $value): bool => trim($value) !== '')));
$trendingQueries = array_slice($trendingQueries, 0, 10);

$placeholderPhrases = $trendingQueries !== [] ? $trendingQueries : $curatedQueries;
$placeholderJson = json_encode($placeholderPhrases, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if (!is_string($placeholderJson)) {
    $placeholderJson = '[]';
}

$featureHighlights = [
    [
        'title' => 'Intelligent ranking',
        'description' => 'Entity-aware scoring blends graph context, synonym clusters, and fact density so the most relevant answers surface first.',
    ],
    [
        'title' => 'Cited summaries',
        'description' => 'Generate briefing-ready narratives grounded in traceable graph facts and linked source material.',
    ],
    [
        'title' => 'Search your corpus',
        'description' => 'Connect proprietary documents, filings, and research feeds to build a unified intelligence surface for the whole team.',
    ],
];

$workflowSteps = [
    [
        'title' => 'Ask a question',
        'description' => 'Start with a natural language question or a specific entity. Intelligent search instantly expands it with related terms.',
    ],
    [
        'title' => 'Review the facts',
        'description' => 'See graph-backed facts, linked counterparties, and trending relationships before diving into raw sources.',
    ],
    [
        'title' => 'Share insight',
        'description' => 'Export findings to slides, share a live link, or pivot into the knowledge graph when you need to go deeper.',
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AIresearch Intelligent Search</title>
    <link rel="stylesheet" href="<?= esc($stylesPath . '?v=' . $stylesVersion) ?>">
</head>
<body class="site site--home">
<header class="site-header">
    <div class="site-header__inner">
        <a class="site-brand" href="<?= esc($homePath) ?>">AIresearch</a>
        <nav class="site-nav" aria-label="Primary navigation">
            <a class="site-nav__link site-nav__link--active" href="<?= esc($homePath) ?>">Home</a>
            <a class="site-nav__link" href="<?= esc($searchPath) ?>">Intelligent search</a>
            <a class="site-nav__link" href="<?= esc($graphPath) ?>">Knowledge graph</a>
            <a class="site-nav__link" href="<?= esc($docsPath) ?>">Docs</a>
        </nav>
    </div>
</header>
<main class="landing" id="main">
    <section class="landing__hero">
        <div class="landing__hero-content">
            <p class="landing__eyebrow">Unified research workspace</p>
            <h1 class="landing__title">Intelligent search built on your knowledge graph</h1>
            <p class="landing__subtitle">Ask a question, explore the connected facts, and move from curiosity to confident decisions with a single search bar.</p>
            <form class="landing__search" action="<?= esc($searchPath) ?>" method="get" role="search" data-home-search>
                <label class="visually-hidden" for="home-query">Ask a research question</label>
                <input
                    id="home-query"
                    name="q"
                    type="search"
                    placeholder="Search emerging topics, companies, or risks"
                    autocomplete="off"
                    spellcheck="false"
                    data-home-search-input
                    data-home-phrases='<?= esc($placeholderJson) ?>'
                >
                <button type="submit" class="landing__search-submit">Search</button>
            </form>
            <?php if ($trendingQueries !== []): ?>
                <div class="landing__suggestions" data-home-suggestions>
                    <span class="landing__suggestions-label">Suggested searches:</span>
                    <div class="landing__chips">
                        <?php foreach ($trendingQueries as $query): ?>
                            <button type="button" class="chip" data-home-chip="<?= esc($query) ?>"><?= esc($query) ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="feature-grid" aria-labelledby="feature-heading">
        <div class="section-heading">
            <h2 id="feature-heading">Why teams adopt intelligent search</h2>
            <p>Every result blends semantic retrieval with graph-native reasoning so you can navigate complex research questions like a conventional search experience.</p>
        </div>
        <div class="feature-grid__items">
            <?php foreach ($featureHighlights as $feature): ?>
                <article class="feature-card">
                    <h3 class="feature-card__title"><?= esc($feature['title']) ?></h3>
                    <p class="feature-card__description"><?= esc($feature['description']) ?></p>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="stats-grid" aria-labelledby="stats-heading">
        <div class="section-heading">
            <h2 id="stats-heading">Knowledge coverage at a glance</h2>
            <p>Connect the crawler to your corpus and watch these totals update automatically.</p>
        </div>
        <dl class="stats-grid__items">
            <?php foreach ($coverageStats as $stat): ?>
                <div class="stats-grid__item">
                    <dt><?= esc($stat['label']) ?></dt>
                    <dd><?= esc($stat['value']) ?></dd>
                </div>
            <?php endforeach; ?>
        </dl>
    </section>

    <section class="workflow" aria-labelledby="workflow-heading">
        <div class="section-heading">
            <h2 id="workflow-heading">From query to briefing in minutes</h2>
            <p>Launch the search experience, inspect the facts, and hand stakeholders a narrative backed by citations.</p>
        </div>
        <ol class="workflow__steps">
            <?php foreach ($workflowSteps as $index => $step): ?>
                <li class="workflow__step">
                    <span class="workflow__index">0<?= esc((string) ($index + 1)) ?></span>
                    <div class="workflow__body">
                        <h3><?= esc($step['title']) ?></h3>
                        <p><?= esc($step['description']) ?></p>
                    </div>
                </li>
            <?php endforeach; ?>
        </ol>
        <div class="workflow__actions">
            <a class="button" href="<?= esc($searchPath) ?>">Open intelligent search</a>
            <a class="button button--subtle" href="<?= esc($graphPath) ?>">Explore the knowledge graph</a>
        </div>
    </section>

    <section class="cta" aria-labelledby="cta-heading">
        <div class="cta__content">
            <h2 id="cta-heading">Ready to connect your own sources?</h2>
            <p>Use the API to schedule crawls, stream updates, or push proprietary research. Intelligent search keeps everything discoverable.</p>
            <div class="cta__actions">
                <a class="button" href="<?= esc($docsPath) ?>">View API docs</a>
                <a class="button button--subtle" href="<?= esc($apiPath) ?>">Browse endpoints</a>
            </div>
        </div>
    </section>
</main>
<script src="<?= esc($scriptPath . '?v=' . $scriptVersion) ?>" defer></script>
</body>
</html>
