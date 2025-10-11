<?php

declare(strict_types=1);

use App\Web\PathResolver;
use App\Web\SiteLayout;

$state = require __DIR__ . '/knowledge-graph-state.php';

$assetBase = $state['assetBase'];
$assets = $state['assets'];
$versions = $state['versions'];
$navigationPaths = $state['navigationPaths'];
$escape = $state['escape'];
$formatNumber = $state['formatNumber'];
$formatDate = $state['formatDate'];
$heroDigest = $state['heroDigest'];
$trendingTopics = array_slice($state['trendingTopics'], 0, 8);
$graphCoverageSignals = $state['graphCoverageSignals'];
$graphTimeline = $state['graphTimeline'];
$spotlight = $state['spotlight'];
$summary = $state['summary'];
$sources = $state['sources'];
$entities = $state['entities'];
$relations = $state['relations'];
$synonymGroups = $state['synonymGroups'];
$triples = $state['triples'];
$hasGraph = (bool) $state['hasGraph'];
$initialJson = $state['initialJson'];
$autocompleteJson = $state['autocompleteJson'];
$graphRepositoryPath = (string) ($state['initialState']['paths']['graph'] ?? '');

$hubPath = PathResolver::url($assetBase, 'knowledge-graph.php');
$overviewPath = PathResolver::url($assetBase, 'knowledge-graph-overview.php');
$autopilotPath = PathResolver::url($assetBase, 'knowledge-graph-autopilot.php');
$researchPath = PathResolver::url($assetBase, 'knowledge-graph-research.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Knowledge graph research console &ndash; AIresearch</title>
    <link rel="stylesheet" href="<?= $escape($assets['theme'] . '?v=' . $versions['theme']) ?>">
    <link rel="stylesheet" href="<?= $escape($assets['styles'] . '?v=' . $versions['styles']) ?>">
    <link rel="stylesheet" href="<?= $escape($assets['research'] . '?v=' . $versions['research']) ?>">
</head>
<body class="site site--graph">
<?php SiteLayout::renderHeader($navigationPaths, 'graph', [
    ['label' => 'Graph hub', 'href' => $hubPath],
    ['label' => 'Graph overview', 'href' => $overviewPath],
    ['label' => 'Autopilot brief', 'href' => $autopilotPath],
    ['label' => 'Launch search', 'href' => $state['searchPath']],
]); ?>
<main class="site-main graph-main">
    <section class="graph-hero">
        <div class="site-container">
            <div class="graph-hero__content">
                <div>
                    <p class="eyebrow">Operations console</p>
                    <h1>Grow the knowledge graph</h1>
                    <p class="lead">Schedule crawls, monitor ingestion runs, and surface recommended leads that keep the graph fresh.</p>
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
                            <li><a class="graph-subnav__link" href="<?= $escape($hubPath) ?>">Graph hub</a></li>
                            <li><a class="graph-subnav__link" href="<?= $escape($overviewPath) ?>">Overview</a></li>
                            <li><a class="graph-subnav__link" href="<?= $escape($autopilotPath) ?>">Autopilot brief</a></li>
                            <li><a class="graph-subnav__link is-active" aria-current="page" href="<?= $escape($researchPath) ?>">Research console</a></li>
                        </ul>
                    </nav>
                </div>
            </div>
        </div>
    </section>
    <div class="graph-shell site-container">
        <?php if ($trendingTopics !== []): ?>
            <section class="graph-suggestions">
                <div class="graph-suggestions__header">
                    <h2>Seed the crawler</h2>
                    <p>Use analyst-curated topics as starting points for new ingestion jobs.</p>
                </div>
                <div class="graph-suggestions__chips">
                    <?php foreach ($trendingTopics as $topic): ?>
                        <button type="button" class="graph-suggestions__chip" data-graph-suggestion data-query="<?= $escape($topic) ?>"><?= $escape($topic) ?></button>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>
        <section class="panel research-console">
            <header class="panel-header">
                <div>
                    <h2>Research console</h2>
                    <p class="panel-subtitle">Monitor the top-ranked entities and orchestrate automated crawls that expand the shared knowledge graph.</p>
                </div>
                <div class="panel-actions">
                    <button type="button" class="button ghost" data-refresh-sources>Refresh stored sources</button>
                </div>
            </header>
            <div class="grid research-grid">
                <article class="card span-2">
                    <h3>Recommended leads</h3>
                    <p class="card-subtle" data-top-empty>No enriched entities yet. Run a crawl or scrape a page to surface suggestions.</p>
                    <div class="entity-results entity-results--top" data-top-entities></div>
                </article>
                <article class="card span-3">
                    <h3>Auto crawler</h3>
                    <form class="crawler-form" data-crawl-form>
                        <div class="form-group">
                            <label for="crawl-seeds">Seed URLs</label>
                            <textarea id="crawl-seeds" data-crawl-seeds placeholder="https://example.com/news&#10;https://labs.example.org/blog" spellcheck="false" required></textarea>
                            <p class="help-text">Provide one URL per line. The crawler fetches each page, follows in-domain links, and merges new triples into the shared graph.</p>
                        </div>
                        <div class="crawler-inline">
                            <label>
                                <span>Pages to crawl</span>
                                <input type="number" min="1" max="50" value="5" data-crawl-limit>
                            </label>
                            <label>
                                <span>Depth</span>
                                <input type="number" min="0" max="5" value="2" data-crawl-depth>
                            </label>
                        </div>
                        <label class="toggle crawler-toggle">
                            <input type="checkbox" data-crawl-cross-domain>
                            <span>Allow cross-domain crawling</span>
                        </label>
                        <div class="crawler-actions">
                            <button type="submit" class="button primary">Start crawl</button>
                        </div>
                    </form>
                    <div class="status crawler-status" data-crawl-status></div>
                    <div class="crawler-results" data-crawl-results hidden>
                        <h4>Latest crawl</h4>
                        <ul class="list-block" data-crawl-ingested></ul>
                        <div class="crawler-errors" data-crawl-errors hidden>
                            <h5>Errors</h5>
                            <ul></ul>
                        </div>
                    </div>
                </article>
                <article class="card span-2">
                    <h3>Crawl summary</h3>
                    <p class="card-subtle" data-crawl-summary-empty>No automated crawl has been run yet.</p>
                    <dl class="summary-list" data-crawl-summary hidden></dl>
                    <div class="crawler-queue" data-crawl-queue hidden>
                        <h4>Remaining queue</h4>
                        <p class="card-subtle" data-crawl-queue-empty>No queued URLs.</p>
                        <ul></ul>
                    </div>
                </article>
            </div>
        </section>
        <section class="panel graph-analytics">
            <header class="panel-header">
                <div>
                    <h2>Operational telemetry</h2>
                    <p class="panel-subtitle">Check ingestion velocity and coverage trends before launching new jobs.</p>
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
                    <p class="card-subtle">Confirm that the freshest fact aligns with your research focus before kicking off another crawl.</p>
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
