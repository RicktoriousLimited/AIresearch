<?php

declare(strict_types=1);

use App\Web\PathResolver;
use App\Web\SiteLayout;

$state = require __DIR__ . '/../knowledge-graph-state.php';

$assetBase = $state['assetBase'];
$assets = $state['assets'];
$versions = $state['versions'];
$navigationPaths = $state['navigationPaths'];
$escape = $state['escape'];
$formatNumber = $state['formatNumber'];
$formatDate = $state['formatDate'];
$heroDigest = $state['heroDigest'];
$graphCoverageSignals = $state['graphCoverageSignals'];
$graphTimeline = $state['graphTimeline'];
$spotlight = $state['spotlight'];
$trendingTopics = array_slice($state['trendingTopics'], 0, 4);
$hasGraph = (bool) $state['hasGraph'];
$initialJson = $state['initialJson'];
$autocompleteJson = $state['autocompleteJson'];
$initialState = $state['initialState'];

$overviewPath = PathResolver::url($assetBase, 'knowledge-graph-overview.php');
$autopilotPath = PathResolver::url($assetBase, 'knowledge-graph-autopilot.php');
$researchPath = PathResolver::url($assetBase, 'knowledge-graph-research.php');

$graphRepositoryPath = (string) ($initialState['paths']['graph'] ?? '');

$graphIntegrations = [
    [
        'title' => 'Insight overview',
        'description' => 'Review coverage, entity growth, and supporting evidence in one glance.',
        'href' => $overviewPath,
        'action' => 'Open overview',
    ],
    [
        'title' => 'Autopilot briefs',
        'description' => 'Spin up graph-backed briefs with curated citations and highlights.',
        'href' => $autopilotPath,
        'action' => 'Launch brief builder',
    ],
    [
        'title' => 'Research console',
        'description' => 'Monitor crawls, ingest fresh sources, and prioritise new leads.',
        'href' => $researchPath,
        'action' => 'Visit console',
    ],
];

$siteIntegrations = [
    [
        'title' => 'Search workspace',
        'description' => 'Query the graph, autocomplete trending topics, and compare sources side-by-side.',
        'href' => $state['searchPath'],
        'action' => 'Launch search',
    ],
    [
        'title' => 'Data preparation studio',
        'description' => 'Scrape fresh URLs, clean content, and push structured data into the graph.',
        'href' => $state['homePath'],
        'action' => 'Open studio',
    ],
    [
        'title' => 'Graph documentation',
        'description' => 'Review API endpoints, schema guidance, and automation workflows.',
        'href' => $state['docsPath'],
        'action' => 'Read docs',
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Knowledge graph workspace &ndash; AIresearch</title>
    <link rel="stylesheet" href="<?= $escape($assets['theme'] . '?v=' . $versions['theme']) ?>">
    <link rel="stylesheet" href="<?= $escape($assets['styles'] . '?v=' . $versions['styles']) ?>">
    <link rel="stylesheet" href="<?= $escape($assets['research'] . '?v=' . $versions['research']) ?>">
</head>
<body class="site site--graph">
<?php SiteLayout::renderHeader($navigationPaths, 'graph', [
    ['label' => 'Graph overview', 'href' => $overviewPath],
    ['label' => 'Autopilot brief', 'href' => $autopilotPath],
    ['label' => 'Research console', 'href' => $researchPath],
    ['label' => 'Launch search', 'href' => $state['searchPath']],
]); ?>
<main class="site-main graph-main">
    <section class="graph-hero">
        <div class="site-container">
            <div class="graph-hero__content">
                <div class="graph-hero__intro">
                    <p class="eyebrow">Knowledge graph</p>
                    <h1>Focus on what matters</h1>
                    <p class="lead">Search the shared graph or jump straight into the workspace that keeps you moving.</p>
                    <?php if ($heroDigest !== []): ?>
                        <ul class="graph-hero__metrics">
                            <?php foreach ($heroDigest as $metric): ?>
                                <?php $metricLabel = (string) ($metric['label'] ?? ''); ?>
                                <?php $metricValue = (string) ($metric['value'] ?? ''); ?>
                                <?php if ($metricLabel === '' || $metricValue === '') { continue; } ?>
                                <li class="graph-hero__metric">
                                    <span class="graph-hero__metric-value"><?= $escape($metricValue) ?></span>
                                    <span class="graph-hero__metric-label"><?= $escape($metricLabel) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
                <aside class="graph-hero__aside" aria-label="Search the knowledge graph">
                    <form class="graph-search" data-graph-search data-autocomplete-container>
                        <label class="visually-hidden" for="graph-search-input">Search the knowledge graph</label>
                        <input
                            id="graph-search-input"
                            name="q"
                            type="search"
                            placeholder="Search people, organisations, relations&hellip;"
                            autocomplete="off"
                            spellcheck="false"
                            data-autocomplete
                            data-autocomplete-source='<?= $escape($autocompleteJson) ?>'
                        >
                        <button type="submit" class="button primary">Search</button>
                    </form>
                    <p class="graph-hero__hint">Press return to open the overview with your results.</p>
                </aside>
            </div>
        </div>
    </section>
    <div class="graph-shell site-container">
        <section class="panel">
            <header class="panel-header">
                <div>
                    <h2>Pick a workspace</h2>
                    <p class="panel-subtitle">Stay streamlined with the three core views of the graph.</p>
                </div>
            </header>
            <div class="grid graph-hub-grid">
                <?php foreach ($graphIntegrations as $card): ?>
                    <article class="card">
                        <h3><?= $escape($card['title']) ?></h3>
                        <p class="card-subtle"><?= $escape($card['description']) ?></p>
                        <a class="button ghost" href="<?= $escape($card['href']) ?>"><?= $escape($card['action']) ?></a>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
        <?php if ($trendingTopics !== []): ?>
            <section class="panel">
                <div class="graph-suggestions" data-graph-suggestions>
                    <div class="graph-suggestions__header">
                        <h2>Try a suggested prompt</h2>
                        <p>Start with a proven query to surface dense clusters.</p>
                    </div>
                    <div class="graph-suggestions__chips">
                        <?php foreach ($trendingTopics as $topic): ?>
                            <button type="button" class="graph-suggestions__chip" data-graph-suggestion data-query="<?= $escape($topic) ?>"><?= $escape($topic) ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
        <?php endif; ?>
        <section class="panel">
            <header class="panel-header">
                <div>
                    <h2>Use the graph across the site</h2>
                    <p class="panel-subtitle">Jump straight to the supporting tools.</p>
                </div>
            </header>
            <div class="grid graph-hub-grid">
                <?php foreach ($siteIntegrations as $card): ?>
                    <article class="card">
                        <h3><?= $escape($card['title']) ?></h3>
                        <p class="card-subtle"><?= $escape($card['description']) ?></p>
                        <a class="button ghost" href="<?= $escape($card['href']) ?>"><?= $escape($card['action']) ?></a>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    </div>
    <section class="graph-note site-container" aria-label="Knowledge graph storage">
        <?php if ($graphRepositoryPath !== ''): ?>
            <p>Snapshots live at <code><?= $escape($graphRepositoryPath) ?></code>. Scrape extra URLs from the <a href="<?= $escape($state['homePath']) ?>">Data Preparation Studio</a> whenever you need more context.</p>
        <?php else: ?>
            <p>Scrape additional URLs from the <a href="<?= $escape($state['homePath']) ?>">Data Preparation Studio</a> to enrich the shared graph.</p>
        <?php endif; ?>
    </section>
</main>
<?php SiteLayout::renderFooter($navigationPaths, 'Unified knowledge graph powering AIresearch intelligence.'); ?>
<script>
    window.AIKnowledgeGraph = <?= $initialJson ?>;
</script>
<script src="<?= $escape($assets['autocomplete'] . '?v=' . $versions['autocomplete']) ?>" defer></script>
<script src="<?= $escape($assets['script'] . '?v=' . $versions['script']) ?>" defer></script>
</body>
</html>
