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
$trendingTopics = array_slice($state['trendingTopics'], 0, 6);
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
        'description' => 'Dive into entity discovery, relation density, and supporting evidence curated from recent crawls.',
        'href' => $overviewPath,
        'action' => 'Open overview',
    ],
    [
        'title' => 'Autopilot briefs',
        'description' => 'Compose instant research briefs that fuse citations, highlights, and unique insights from the graph.',
        'href' => $autopilotPath,
        'action' => 'Launch brief builder',
    ],
    [
        'title' => 'Research console',
        'description' => 'Run guided crawls, manage ingestion jobs, and review recommended leads in the operations console.',
        'href' => $researchPath,
        'action' => 'Visit console',
    ],
];

$siteIntegrations = [
    [
        'title' => 'Search workspace',
        'description' => 'Query the knowledge base, autocomplete trending topics, and compare graph-backed sources side-by-side.',
        'href' => $state['searchPath'],
        'action' => 'Launch search',
    ],
    [
        'title' => 'Data preparation studio',
        'description' => 'Scrape fresh URLs, clean content, and push structured data directly into the shared knowledge graph.',
        'href' => $state['homePath'],
        'action' => 'Open studio',
    ],
    [
        'title' => 'Graph documentation',
        'description' => 'Review API endpoints, schema guidance, and automation workflows that extend the knowledge graph.',
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
                <div>
                    <p class="eyebrow">Unified intelligence workspace</p>
                    <h1>Explore the knowledge graph hub</h1>
                    <p class="lead">Track the freshest entities, relationships, and supporting sources powering AIresearch experiences. Jump into a focused workspace or run a quick search below.</p>
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
                <div class="graph-hero__aside">
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
                    <nav class="graph-subnav" aria-label="Knowledge graph sections">
                        <ul class="graph-subnav__list">
                            <li><a class="graph-subnav__link" href="<?= $escape($overviewPath) ?>">Graph overview</a></li>
                            <li><a class="graph-subnav__link" href="<?= $escape($autopilotPath) ?>">Autopilot brief</a></li>
                            <li><a class="graph-subnav__link" href="<?= $escape($researchPath) ?>">Research console</a></li>
                        </ul>
                    </nav>
                </div>
            </div>
        </div>
    </section>
    <div class="graph-shell site-container">
        <section class="panel">
            <header class="panel-header">
                <div>
                    <h2>Choose a focused workspace</h2>
                    <p class="panel-subtitle">Each workspace pairs the shared graph with dedicated tooling for analysis, briefing, or ingestion.</p>
                </div>
            </header>
            <div class="grid graph-hub-grid">
                <?php foreach ($graphIntegrations as $card): ?>
                    <article class="card span-2">
                        <h3><?= $escape($card['title']) ?></h3>
                        <p class="card-subtle"><?= $escape($card['description']) ?></p>
                        <a class="button ghost" href="<?= $escape($card['href']) ?>"><?= $escape($card['action']) ?></a>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
        <section class="graph-suggestions" data-graph-suggestions<?= $trendingTopics === [] ? ' hidden' : '' ?>>
            <div class="graph-suggestions__header">
                <h2>Jump-start discovery</h2>
                <p>Run a graph search with analyst-tested prompts that surface dense entity clusters.</p>
            </div>
            <div class="graph-suggestions__chips">
                <?php foreach ($trendingTopics as $topic): ?>
                    <button type="button" class="graph-suggestions__chip" data-graph-suggestion data-query="<?= $escape($topic) ?>"><?= $escape($topic) ?></button>
                <?php endforeach; ?>
            </div>
        </section>
        <section class="panel graph-analytics">
            <header class="panel-header">
                <div>
                    <h2>Graph analytics snapshot</h2>
                    <p class="panel-subtitle">A quick readout of ingestion velocity, coverage health, and a spotlight fact.</p>
                </div>
            </header>
            <div class="graph-analytics__grid">
                <article class="card span-3">
                    <h3>Ingestion activity</h3>
                    <p class="card-subtle">Recent daily merges into the shared graph.</p>
                    <?php $timelineMaxCount = 0; foreach ($graphTimeline as $bucket) { $timelineMaxCount = max($timelineMaxCount, (int) ($bucket['count'] ?? 0)); } ?>
                    <ol class="graph-timeline" data-graph-timeline<?= $graphTimeline === [] ? ' hidden' : '' ?>>
                        <?php foreach ($graphTimeline as $bucket): ?>
                            <?php
                            $bucketCount = (int) ($bucket['count'] ?? 0);
                            $bucketLabel = (string) ($bucket['label'] ?? $bucket['date'] ?? '');
                            $timelineWidth = $timelineMaxCount > 0 ? (int) round(($bucketCount / $timelineMaxCount) * 100) : 0;
                            ?>
                            <li>
                                <span class="graph-timeline__date"><?= $escape($bucketLabel) ?></span>
                                <span class="graph-timeline__meter"><span style="--meter-width: <?= $escape((string) $timelineWidth) ?>%;"></span></span>
                                <span class="graph-timeline__value"><?= $escape($formatNumber($bucketCount)) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                    <p class="card-subtle" data-graph-timeline-empty<?= $graphTimeline !== [] ? ' hidden' : '' ?>>No crawl history yet. Kick off a crawl to populate the activity timeline.</p>
                </article>
                <article class="card span-2">
                    <h3>Coverage signals</h3>
                    <p class="card-subtle">Quality indicators that surface as the graph expands.</p>
                    <ul class="stat-list" data-graph-coverage<?= $graphCoverageSignals === [] ? ' hidden' : '' ?>>
                        <?php foreach ($graphCoverageSignals as $signal): ?>
                            <?php
                            $signalLabel = (string) ($signal['label'] ?? '');
                            $signalValue = (string) ($signal['value'] ?? '');
                            $signalHint = (string) ($signal['hint'] ?? '');
                            if ($signalLabel === '' || $signalValue === '') {
                                continue;
                            }
                            ?>
                            <li>
                                <div class="stat-list__row">
                                    <span class="stat-list__label"><?= $escape($signalLabel) ?></span>
                                    <span class="stat-list__value"><?= $escape($signalValue) ?></span>
                                </div>
                                <?php if ($signalHint !== ''): ?>
                                    <p class="stat-list__hint"><?= $escape($signalHint) ?></p>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="card-subtle" data-graph-coverage-empty<?= $graphCoverageSignals !== [] ? ' hidden' : '' ?>>Coverage signals will appear after the first ingestion.</p>
                </article>
                <article class="card span-3">
                    <h3>Graph spotlight</h3>
                    <p class="card-subtle">A headline fact and supporting source selected from the freshest crawl.</p>
                    <div class="graph-spotlight" data-graph-spotlight>
                        <?php if ($spotlight !== null): ?>
                            <?php
                            $spotlightSubject = (string) ($spotlight['subject'] ?? '');
                            $spotlightRelation = (string) ($spotlight['relation'] ?? '');
                            $spotlightObject = (string) ($spotlight['object'] ?? '');
                            $spotlightTitle = (string) ($spotlight['source_title'] ?? '');
                            $spotlightUrl = (string) ($spotlight['source_url'] ?? '');
                            $spotlightPreview = (string) ($spotlight['source_preview'] ?? '');
                            $spotlightFetched = isset($spotlight['fetched_at']) && is_string($spotlight['fetched_at'])
                                ? ($formatDate($spotlight['fetched_at']) ?? $spotlight['fetched_at'])
                                : null;
                            ?>
                            <div class="graph-spotlight__triple">
                                <span class="graph-spotlight__subject"><?= $escape($spotlightSubject) ?></span>
                                <span class="graph-spotlight__relation"><?= $escape($spotlightRelation) ?></span>
                                <span class="graph-spotlight__object"><?= $escape($spotlightObject) ?></span>
                            </div>
                            <?php if ($spotlightPreview !== ''): ?>
                                <p class="graph-spotlight__preview"><?= $escape($spotlightPreview) ?></p>
                            <?php endif; ?>
                            <?php if ($spotlightTitle !== '' || $spotlightUrl !== ''): ?>
                                <p class="graph-spotlight__source">Source:
                                    <?php if ($spotlightUrl !== ''): ?>
                                        <a href="<?= $escape($spotlightUrl) ?>" target="_blank" rel="noopener noreferrer"><?= $escape($spotlightTitle !== '' ? $spotlightTitle : $spotlightUrl) ?></a>
                                    <?php else: ?>
                                        <?= $escape($spotlightTitle) ?>
                                    <?php endif; ?>
                                </p>
                            <?php endif; ?>
                            <?php if ($spotlightFetched !== null): ?>
                                <p class="graph-spotlight__meta">Backed by <?= $escape($spotlightFetched) ?></p>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <p class="card-subtle" data-graph-spotlight-empty<?= $spotlight !== null ? ' hidden' : '' ?>>Run a search to surface a headline fact from the graph.</p>
                </article>
            </div>
        </section>
        <section class="panel">
            <header class="panel-header">
                <div>
                    <h2>Where the graph shows up</h2>
                    <p class="panel-subtitle">Cross-site features that plug into the shared intelligence layer.</p>
                </div>
            </header>
            <div class="grid graph-hub-grid">
                <?php foreach ($siteIntegrations as $card): ?>
                    <article class="card span-2">
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
            <p>Knowledge graph snapshots are stored at <code><?= $escape($graphRepositoryPath) ?></code>. Scrape additional URLs from the <a href="<?= $escape($state['homePath']) ?>">Data Preparation Studio</a>.</p>
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
