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

$statGroups = [
    [
        'label' => 'Documents analysed',
        'value' => $documentsProcessed,
    ],
    [
        'label' => 'Knowledge triples',
        'value' => $triplesExtracted,
    ],
    [
        'label' => 'Entities indexed',
        'value' => $uniqueEntities,
    ],
    [
        'label' => 'Synonym groups',
        'value' => $synonymGroups,
    ],
    [
        'label' => 'Curated sources',
        'value' => $sourcesTracked,
    ],
];

if ($updatedLabel !== null) {
    $statGroups[] = [
        'label' => 'Last refreshed',
        'value' => $updatedLabel,
    ];
}

$featureHighlights = [
    [
        'title' => 'Briefs grounded in citations',
        'description' => 'Autopilot produces executive-ready narratives with inline citations and linked evidence for every claim.',
    ],
    [
        'title' => 'Graph-native search',
        'description' => 'Blend entity awareness, relation scoring, and semantic retrieval to answer complex research prompts quickly.',
    ],
    [
        'title' => 'Continuous signal tracking',
        'description' => 'Monitor filings, posts, and analyst notes so your coverage area stays fresh without manual stitching.',
    ],
];

$quickLinks = [
    [
        'label' => 'Run an Autopilot brief',
        'href' => $assetBase . '/search.php',
    ],
    [
        'label' => 'Explore the knowledge graph',
        'href' => $assetBase . '/knowledge-graph.php',
    ],
    [
        'label' => 'Read the documentation',
        'href' => $assetBase . '/docs',
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

<main class="home-main home-main--search">
    <section class="home-search" id="search">
        <div class="shell home-search__shell">
            <div class="home-search__brand">
                <span class="home-search__logo">AIresearch</span>
                <p class="home-search__tagline">Autopilot search that generates research briefs in seconds.</p>
            </div>
            <form class="search-form home-search__form" method="get" action="<?= esc($assetBase . '/search.php'); ?>" role="search" data-home-search>
                <label class="visually-hidden" for="home-search-input">Search the AIresearch graph</label>
                <div class="search-form__field">
                    <input id="home-search-input" name="q" type="search" placeholder="Try &ldquo;<?= esc($placeholderPhrases[0] ?? 'emerging AI research hubs'); ?>&rdquo;" autocomplete="off" spellcheck="false" data-home-search-input data-home-phrases='<?= esc($placeholderJson); ?>'>
                    <button type="submit" class="button primary">Autopilot brief</button>
                </div>
            </form>
            <?php if ($trendingQueries !== []): ?>
            <div class="home-search__chips" data-home-trending>
                <span class="home-search__label">Popular queries</span>
                <div class="home-search__list">
                    <?php foreach ($trendingQueries as $query): ?>
                        <button type="button" class="chip" data-home-chip="<?= esc($query); ?>"><?= esc($query); ?></button>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            <div class="home-search__links">
                <?php foreach ($quickLinks as $link): ?>
                    <a class="home-search__link" href="<?= esc($link['href']); ?>"><?= esc($link['label']); ?></a>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="home-metrics">
        <div class="shell home-metrics__shell">
            <div class="home-metrics__grid" aria-live="polite">
                <?php foreach ($statGroups as $stat): ?>
                    <article class="home-metric">
                        <span class="home-metric__label"><?= esc($stat['label']); ?></span>
                        <span class="home-metric__value"><?= esc((string) $stat['value']); ?></span>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="home-highlights">
        <div class="shell home-highlights__shell">
            <h2>Why teams choose Autopilot</h2>
            <div class="home-highlights__grid">
                <?php foreach ($featureHighlights as $feature): ?>
                    <article class="home-highlight">
                        <h3><?= esc($feature['title']); ?></h3>
                        <p><?= esc($feature['description']); ?></p>
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
