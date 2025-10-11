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
    <title>Autopilot research briefs &ndash; AIresearch</title>
    <link rel="stylesheet" href="<?= $escape($assets['theme'] . '?v=' . $versions['theme']) ?>">
    <link rel="stylesheet" href="<?= $escape($assets['styles'] . '?v=' . $versions['styles']) ?>">
    <link rel="stylesheet" href="<?= $escape($assets['research'] . '?v=' . $versions['research']) ?>">
</head>
<body class="site site--graph">
<?php SiteLayout::renderHeader($navigationPaths, 'graph', [
    ['label' => 'Graph hub', 'href' => $hubPath],
    ['label' => 'Graph overview', 'href' => $overviewPath],
    ['label' => 'Research console', 'href' => $researchPath],
    ['label' => 'Launch search', 'href' => $state['searchPath']],
]); ?>
<main class="site-main graph-main graph-main--autopilot">
    <section class="autopilot-hero">
        <div class="site-container autopilot-hero__shell">
            <div class="autopilot-hero__intro">
                <span class="autopilot-hero__eyebrow">Report automation</span>
                <h1>Autopilot research briefs</h1>
                <p class="autopilot-hero__lead">Blend every stored analysis into an instant brief with citations, highlights, and unique scoring pulled directly from the knowledge graph.</p>
                <nav class="graph-subnav" aria-label="Knowledge graph sections">
                    <ul class="graph-subnav__list">
                        <li><a class="graph-subnav__link" href="<?= $escape($hubPath) ?>">Graph hub</a></li>
                        <li><a class="graph-subnav__link" href="<?= $escape($overviewPath) ?>">Overview</a></li>
                        <li><a class="graph-subnav__link is-active" aria-current="page" href="<?= $escape($autopilotPath) ?>">Autopilot brief</a></li>
                        <li><a class="graph-subnav__link" href="<?= $escape($researchPath) ?>">Research console</a></li>
                    </ul>
                </nav>
            </div>
            <div>
                <?php if ($trendingTopics !== []): ?>
                    <div class="autopilot-hero__suggestions">
                        <span class="autopilot-hero__label">Suggested prompts</span>
                        <div class="autopilot-hero__chips">
                            <?php foreach ($trendingTopics as $topic): ?>
                                <button type="button" class="graph-suggestions__chip" data-graph-suggestion data-query="<?= $escape($topic) ?>"><?= $escape($topic) ?></button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>
    <div class="graph-shell site-container">
        <section class="panel autopilot-panel">
            <header class="panel-header">
                <div>
                    <h2>Autopilot research brief</h2>
                    <p class="panel-subtitle">Generate a structured briefing that merges cross-source evidence, key themes, and multimedia assets.</p>
                </div>
                <div class="panel-actions">
                    <button type="button" class="button ghost" data-report-refresh>Refresh insights</button>
                </div>
            </header>
            <div class="grid autopilot-grid">
                <article class="card span-2">
                    <h3>Compose a prompt</h3>
                    <form class="report-form autopilot-form" data-report-form>
                        <div class="form-group">
                            <label for="report-query">Focus area</label>
                            <textarea id="report-query" data-report-query placeholder="e.g. Cross-industry AI investments in 2024" spellcheck="false"></textarea>
                            <p class="help-text">The brief builder compares all crawled sources, scores uniqueness, and fuses overlapping narratives into a single report.</p>
                        </div>
                        <div class="autopilot-form__actions">
                            <div class="autopilot-form__status">
                                <p class="status" data-report-status hidden></p>
                                <small>Briefs use graph-backed facts to ensure every insight is cited.</small>
                            </div>
                            <button type="submit" class="button primary">Generate brief</button>
                        </div>
                    </form>
                </article>
                <article class="card span-3">
                    <h3>Instant brief</h3>
                    <div class="report-output" data-report-output>
                        <p class="card-subtle" data-report-empty>Run a brief to cross-reference the latest sources, citations, and imagery.</p>
                        <div class="report-results" data-report-results hidden>
                            <div class="report-summary" data-report-summary></div>
                            <div class="report-topics" data-report-topics-wrapper>
                                <h4>Key themes</h4>
                                <ul data-report-topics></ul>
                            </div>
                            <div class="report-highlights" data-report-highlights></div>
                            <div class="report-combined" data-report-combined-wrapper>
                                <h4>Cross-referenced insights</h4>
                                <ol data-report-combined></ol>
                            </div>
                            <div class="report-citations" data-report-citations-wrapper>
                                <h4>Citations &amp; assets</h4>
                                <ol data-report-citations></ol>
                                <div class="report-images" data-report-images></div>
                            </div>
                        </div>
                    </div>
                </article>
                <article class="card span-3">
                    <h3>Document comparison</h3>
                    <p class="card-subtle" data-comparison-empty>Once sources are ingested their uniqueness scores and overlaps will appear here.</p>
                    <div class="document-comparison" data-report-comparison hidden></div>
                </article>
            </div>
        </section>
        <section class="panel graph-analytics">
            <header class="panel-header">
                <div>
                    <h2>Graph context</h2>
                    <p class="panel-subtitle">Monitor coverage signals that influence the quality of generated briefs.</p>
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
                    <p class="card-subtle">Use the freshest fact and supporting source to anchor your next brief.</p>
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
