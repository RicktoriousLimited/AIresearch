<?php

declare(strict_types=1);

require __DIR__ . '/src/App/bootstrap.php';

use App\KnowledgeGraph\GraphRepository;
use App\KnowledgeGraph\GraphResearcher;
use App\KnowledgeGraph\ResearchService;

function esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php');
$scriptDir = str_replace('\\', '/', dirname($scriptName));
if ($scriptDir === '.' || $scriptDir === '/' || $scriptDir === '\\\\') {
    $scriptDir = '';
}

$basePath = rtrim($scriptDir, '/');
if ($basePath !== '') {
    $basePath = '/' . ltrim($basePath, '/');
}

$assetBase = $basePath === '' ? '' : $basePath;

$stylesPath = $assetBase . '/assets/styles.css';
$scriptPath = $assetBase . '/assets/home.js';
$stylesVersion = file_exists(__DIR__ . '/assets/styles.css') ? (string) filemtime(__DIR__ . '/assets/styles.css') : (string) time();
$scriptVersion = file_exists(__DIR__ . '/assets/home.js') ? (string) filemtime(__DIR__ . '/assets/home.js') : (string) time();

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
$sourcesTracked = $formatNumber(count($sources));
$triplesExtracted = $formatNumber($summary['triples'] ?? count($initialSearch['triples'] ?? []));
$uniqueEntities = $formatNumber($summary['unique_entities'] ?? count($initialSearch['entities'] ?? []));
$synonymGroups = $formatNumber($summary['synonym_groups'] ?? count($initialSearch['synonyms'] ?? []));
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
    'foundation model evaluation frameworks',
    'emerging biotech partnerships',
    'autonomous vehicle safety breakthroughs',
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
        'title' => 'Unified research knowledge graph',
        'description' => 'Search across structured triples, relations, and citations sourced from crawls, uploads, and live research feeds.',
    ],
    [
        'title' => 'Research-grade relevance ranking',
        'description' => 'Hybrid semantic retrieval blends keyword precision with vector recall so expert queries surface the right evidence first.',
    ],
    [
        'title' => 'Explainable citations for every fact',
        'description' => 'Every entity, relation, and metric links back to the original paragraph so analysts can verify context instantly.',
    ],
];

$workflowSteps = [
    [
        'step' => '01',
        'title' => 'Ask an ambitious question',
        'description' => 'Start with a broad topic, emerging company, or strategic theme. The search engine expands the query and fetches corroborating evidence.',
    ],
    [
        'step' => '02',
        'title' => 'Trace the knowledge graph',
        'description' => 'Drill into entities to inspect related organisations, technologies, key people, and supporting documents.',
    ],
    [
        'step' => '03',
        'title' => 'Export the insight',
        'description' => 'Capture clean triples, entity summaries, and citation trails that plug into memos, RFP responses, or downstream models.',
    ],
];

$spotlightCollections = [
    [
        'title' => 'AI policy & regulation tracker',
        'description' => 'Live monitoring of AI safety legislation, national frameworks, and regulator commentary across jurisdictions.',
        'tags' => ['Governance', 'Risk'],
    ],
    [
        'title' => 'Climate innovation landscape',
        'description' => 'Rapidly surface clean tech founders, funding rounds, partnerships, and supply-chain moves in one query.',
        'tags' => ['Climate', 'Innovation'],
    ],
    [
        'title' => 'Enterprise automation signals',
        'description' => 'Track which platforms enterprises deploy, adjacent vendors they shortlist, and the problems each solution actually solves.',
        'tags' => ['Automation', 'Market intelligence'],
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AIresearch · Search the collective research graph</title>
    <link rel="stylesheet" href="<?= esc($stylesPath . '?v=' . $stylesVersion); ?>">
</head>
<body class="home-page">
<header class="site-header">
    <div class="shell header-shell">
        <a class="brand" href="<?= esc($assetBase . '/index.php'); ?>">AIresearch</a>
        <nav class="primary-nav" aria-label="Primary">
            <a href="#search" class="primary-nav__link">Home</a>
            <a href="<?= esc($assetBase . '/search.php'); ?>" class="primary-nav__link">Graph search</a>
            <a href="<?= esc($assetBase . '/knowledge-graph.php'); ?>" class="primary-nav__link">Knowledge graph</a>
            <a href="<?= esc($assetBase . '/docs'); ?>" class="primary-nav__link">Documentation</a>
        </nav>
        <div class="header-actions">
            <a class="button primary" href="<?= esc($assetBase . '/search.php'); ?>">Launch search</a>
        </div>
    </div>
</header>

<main class="home-main">
    <section class="search-hero" id="search">
        <div class="shell">
            <div class="search-hero__intro">
                <p class="eyebrow">Research search</p>
                <h1>Search and understand any topic in seconds</h1>
                <p class="lead">Instantly surface entities, relationships, and citations across the shared knowledge graph built from analyst notes, crawled articles, and curated datasets.</p>
            </div>
            <form class="search-form" method="get" action="<?= esc($assetBase . '/search.php'); ?>" role="search" data-home-search>
                <label class="visually-hidden" for="home-search-input">Search the AIresearch graph</label>
                <div class="search-form__field">
                    <input id="home-search-input" name="q" type="search" placeholder="Try &ldquo;<?= esc($placeholderPhrases[0] ?? 'emerging AI research hubs'); ?>&rdquo;" autocomplete="off" spellcheck="false" data-home-search-input data-home-phrases='<?= esc($placeholderJson); ?>'>
                    <button type="submit" class="button primary">Search graph</button>
                </div>
            </form>
            <?php if ($trendingQueries !== []): ?>
            <div class="search-trending" data-home-trending>
                <span class="search-trending__label">Trending research</span>
                <div class="search-trending__chips">
                    <?php foreach ($trendingQueries as $query): ?>
                        <button type="button" class="chip" data-home-chip="<?= esc($query); ?>"><?= esc($query); ?></button>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            <div class="search-stats" aria-live="polite">
                <div class="stat">
                    <span class="stat__value"><?= esc($documentsProcessed); ?></span>
                    <span class="stat__label">Documents analysed</span>
                </div>
                <div class="stat">
                    <span class="stat__value"><?= esc($triplesExtracted); ?></span>
                    <span class="stat__label">Knowledge triples</span>
                </div>
                <div class="stat">
                    <span class="stat__value"><?= esc($uniqueEntities); ?></span>
                    <span class="stat__label">Entities indexed</span>
                </div>
                <div class="stat">
                    <span class="stat__value"><?= esc($synonymGroups); ?></span>
                    <span class="stat__label">Synonym groups</span>
                </div>
                <div class="stat">
                    <span class="stat__value"><?= esc($sourcesTracked); ?></span>
                    <span class="stat__label">Curated sources</span>
                </div>
                <?php if ($updatedLabel !== null): ?>
                <div class="stat">
                    <span class="stat__value"><?= esc($updatedLabel); ?></span>
                    <span class="stat__label">Last refreshed</span>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="section section--features" id="features">
        <div class="shell">
            <div class="section-header">
                <h2>Why researchers choose AIresearch</h2>
                <p class="muted">Purpose-built for analysts, strategists, and operators who need trusted insight backed by verifiable sources.</p>
            </div>
            <div class="feature-grid">
                <?php foreach ($featureHighlights as $feature): ?>
                    <article class="feature-card">
                        <h3><?= esc($feature['title']); ?></h3>
                        <p><?= esc($feature['description']); ?></p>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="section section--collections" id="collections">
        <div class="shell">
            <div class="section-header">
                <h2>Spotlight collections</h2>
                <p class="muted">Jump-start your next briefing with curated discovery sets updated continuously.</p>
            </div>
            <div class="collection-grid">
                <?php foreach ($spotlightCollections as $collection): ?>
                    <article class="collection-card">
                        <h3><?= esc($collection['title']); ?></h3>
                        <p><?= esc($collection['description']); ?></p>
                        <?php if (!empty($collection['tags'])): ?>
                            <div class="collection-card__tags">
                                <?php foreach ($collection['tags'] as $tag): ?>
                                    <span class="tag" data-home-suggestion="<?= esc($tag); ?>"><?= esc($tag); ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="section section--workflow" id="workflow">
        <div class="shell">
            <div class="section-header">
                <h2>Research workflow in one interface</h2>
                <p class="muted">From first query to export-ready facts, every step stays connected to the same knowledge graph.</p>
            </div>
            <div class="workflow-grid">
                <?php foreach ($workflowSteps as $step): ?>
                    <article class="workflow-step">
                        <span class="workflow-step__number"><?= esc($step['step']); ?></span>
                        <h3><?= esc($step['title']); ?></h3>
                        <p><?= esc($step['description']); ?></p>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
</main>

<footer class="site-footer">
    <div class="shell footer-shell">
        <div class="footer-brand">
            <a href="<?= esc($assetBase . '/index.php'); ?>">AIresearch</a>
            <p class="muted">Search, trace, and export the insights that matter. Built for research teams that demand transparent evidence.</p>
        </div>
        <div class="footer-links">
            <div>
                <h4>Platform</h4>
                <ul>
                    <li><a href="<?= esc($assetBase . '/search.php'); ?>">Graph search</a></li>
                    <li><a href="<?= esc($assetBase . '/knowledge-graph.php'); ?>">Knowledge graph</a></li>
                    <li><a href="<?= esc($assetBase . '/research.php'); ?>">Research CLI</a></li>
                </ul>
            </div>
            <div>
                <h4>Resources</h4>
                <ul>
                    <li><a href="<?= esc($assetBase . '/docs'); ?>">Documentation</a></li>
                    <li><a href="<?= esc($assetBase . '/docs/guides/getting-started.md'); ?>">Getting started</a></li>
                    <li><a href="<?= esc($assetBase . '/api'); ?>">API</a></li>
                </ul>
            </div>
            <div>
                <h4>Support</h4>
                <ul>
                    <li><a href="<?= esc($assetBase . '/health.php'); ?>">System health</a></li>
                    <li><a href="mailto:support@airesearch.local">Contact</a></li>
                </ul>
            </div>
        </div>
    </div>
</footer>

<script src="<?= esc($scriptPath . '?v=' . $scriptVersion); ?>" defer></script>
</body>
</html>
