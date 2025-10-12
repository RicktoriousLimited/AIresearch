<?php

declare(strict_types=1);

use App\Web\PathResolver;
use App\Web\SiteLayout;

$state = require __DIR__ . '/knowledge-graph-state.php';

$assetBase = $state['assetBase'];
$assets = $state['assets'];
$versions = $state['versions'];
$navigationLinks = $state['navigationLinks'];
$escape = $state['escape'];
$formatNumber = $state['formatNumber'];
$formatDate = $state['formatDate'];
$heroDigest = $state['heroDigest'];
$trendingTopics = array_slice($state['trendingTopics'], 0, 6);
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

$workspaceShortcuts = [
    [
        'title' => 'Graph overview',
        'description' => 'Inspect metrics, entities, and triples surfaced from your latest searches.',
        'href' => $overviewPath,
        'action' => 'Open overview',
    ],
    [
        'title' => 'Research console',
        'description' => 'Queue crawls, refresh stored sources, and triage recommended leads.',
        'href' => $researchPath,
        'action' => 'Visit console',
    ],
    [
        'title' => 'Admin hub',
        'description' => 'Monitor ingestion health metrics from the operator workspace.',
        'href' => $hubPath,
        'action' => 'Go to hub',
    ],
];

$integrationLinks = [
    [
        'title' => 'Search workspace',
        'description' => 'Pivot into semantic search to explore related entities and relations.',
        'href' => $state['searchPath'],
    ],
    [
        'title' => 'Data preparation studio',
        'description' => 'Scrape and enrich additional context before refreshing your brief.',
        'href' => $state['homePath'],
    ],
    [
        'title' => 'Graph documentation',
        'description' => 'Review automation hooks and schema notes for autopilot-ready data.',
        'href' => $state['docsPath'],
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Autopilot research briefs &ndash; AIresearch</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="<?= $escape($assets['theme'] . '?v=' . $versions['theme']) ?>">
    <link rel="stylesheet" href="<?= $escape($assets['styles'] . '?v=' . $versions['styles']) ?>">
    <link rel="stylesheet" href="<?= $escape($assets['research'] . '?v=' . $versions['research']) ?>">
    <link rel="stylesheet" href="<?= $escape($assets['knowledge'] . '?v=' . $versions['knowledge']) ?>">
</head>
<body class="graph-page site site--graph">
<?php SiteLayout::renderHeader($navigationLinks, 'graph', [
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
                <p class="autopilot-hero__lead">Create a concise brief from the latest graph-backed evidence.</p>
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
    </section>
    <?php if ($trendingTopics !== []): ?>
        <section class="graph-suggestions site-container" data-graph-suggestions>
            <div class="graph-suggestions__header">
                <h2>Jump-start a new brief</h2>
                <p>Autopilot highlights current topics so you can generate focused summaries instantly.</p>
            </div>
            <div class="graph-suggestions__chips">
                <?php foreach ($trendingTopics as $topic): ?>
                    <?php $topic = (string) $topic; ?>
                    <?php if ($topic === '') { continue; } ?>
                    <button type="button" class="graph-suggestions__chip" data-graph-suggestion data-query="<?= $escape($topic) ?>"><?= $escape($topic) ?></button>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
    <div class="graph-shell site-container">
        <section class="panel autopilot-panel">
            <header class="panel-header">
                <div>
                    <h2>Generate a brief</h2>
                    <p class="panel-subtitle">Provide a focus area and let the graph assemble the essentials.</p>
                </div>
            </header>
            <div class="grid autopilot-grid">
                <article class="card">
                    <h3>Compose a prompt</h3>
                    <form class="report-form autopilot-form" data-report-form>
                        <div class="form-group">
                            <label for="report-query">Focus area</label>
                            <textarea id="report-query" data-report-query placeholder="e.g. Cross-industry AI investments in 2024" spellcheck="false"></textarea>
                            <p class="help-text">Autopilot compares every stored source, keeping only the strongest citations.</p>
                        </div>
                        <div class="autopilot-form__actions">
                            <div class="autopilot-form__status">
                                <p class="status" data-report-status hidden></p>
                                <small>Reports stay cached so you can revisit them instantly.</small>
                            </div>
                            <button type="submit" class="button primary">Generate brief</button>
                        </div>
                    </form>
                </article>
                <article class="card">
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
            </div>
        </section>
        <section class="panel">
            <header class="panel-header">
                <div>
                    <h2>Graph context for your brief</h2>
                    <p class="panel-subtitle">Review health signals, ingestion pace, and the latest supporting source.</p>
                </div>
            </header>
            <div class="grid graph-analytics__grid">
                <article class="card">
                    <h3>Graph health signals</h3>
                    <p class="card-subtle" data-graph-coverage-empty<?= $graphCoverageSignals !== [] ? ' hidden' : '' ?>>Keep ingestion running to surface coverage, enrichment, and alias metrics.</p>
                    <ul class="stat-list" data-graph-coverage<?= $graphCoverageSignals === [] ? ' hidden' : '' ?>>
                        <?php foreach ($graphCoverageSignals as $signal): ?>
                            <?php $label = (string) ($signal['label'] ?? ''); ?>
                            <?php $value = (string) ($signal['value'] ?? ''); ?>
                            <?php $hint = (string) ($signal['hint'] ?? ''); ?>
                            <?php if ($label === '' || $value === '') { continue; } ?>
                            <li>
                                <div class="stat-list__row">
                                    <span class="stat-list__label"><?= $escape($label) ?></span>
                                    <span class="stat-list__value"><?= $escape($value) ?></span>
                                </div>
                                <?php if ($hint !== ''): ?>
                                    <p class="stat-list__hint"><?= $escape($hint) ?></p>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </article>
                <article class="card">
                    <h3>Recent ingestion</h3>
                    <p class="card-subtle" data-graph-timeline-empty<?= $graphTimeline !== [] ? ' hidden' : '' ?>>Once fresh sources are ingested, the timeline helps gauge how current your brief will be.</p>
                    <?php $maxTimelineCount = $graphTimeline !== [] ? max(array_map(static fn($row) => (int) ($row['count'] ?? 0), $graphTimeline)) : 0; ?>
                    <ul class="graph-timeline" data-graph-timeline<?= $graphTimeline === [] ? ' hidden' : '' ?>>
                        <?php foreach ($graphTimeline as $bucket): ?>
                            <?php $label = (string) ($bucket['label'] ?? ''); ?>
                            <?php $count = (int) ($bucket['count'] ?? 0); ?>
                            <?php if ($label === '') { continue; } ?>
                            <?php $meterWidth = $maxTimelineCount > 0 ? (int) round(($count / $maxTimelineCount) * 100) : 0; ?>
                            <li>
                                <span class="graph-timeline__date"><?= $escape($label) ?></span>
                                <span class="graph-timeline__meter"><span style="--meter-width: <?= $escape((string) $meterWidth) ?>%"></span></span>
                                <span class="graph-timeline__value"><?= $escape($formatNumber($count)) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </article>
                <article class="card">
                    <h3>Graph spotlight</h3>
                    <p class="card-subtle" data-graph-spotlight-empty<?= is_array($spotlight) && $spotlight !== [] ? ' hidden' : '' ?>>Autopilot highlights a recent triple so you can cite it in your summary.</p>
                    <div class="graph-spotlight" data-graph-spotlight>
                        <?php if (is_array($spotlight) && $spotlight !== []): ?>
                            <div class="graph-spotlight__triple">
                                <span class="graph-spotlight__subject"><?= $escape((string) ($spotlight['subject'] ?? '')) ?></span>
                                <span class="graph-spotlight__relation"><?= $escape((string) ($spotlight['relation'] ?? '')) ?></span>
                                <span class="graph-spotlight__object"><?= $escape((string) ($spotlight['object'] ?? '')) ?></span>
                            </div>
                            <?php $preview = (string) ($spotlight['source_preview'] ?? ''); ?>
                            <?php if ($preview !== ''): ?>
                                <p class="graph-spotlight__preview"><?= $escape($preview) ?></p>
                            <?php endif; ?>
                            <?php $sourceTitle = (string) ($spotlight['source_title'] ?? ''); ?>
                            <?php $sourceUrl = (string) ($spotlight['source_url'] ?? ''); ?>
                            <?php if ($sourceTitle !== '' || $sourceUrl !== ''): ?>
                                <p class="graph-spotlight__source">
                                    <?php if ($sourceUrl !== ''): ?>
                                        <a href="<?= $escape($sourceUrl) ?>" target="_blank" rel="noopener noreferrer"><?= $escape($sourceTitle !== '' ? $sourceTitle : $sourceUrl) ?></a>
                                    <?php else: ?>
                                        <?= $escape($sourceTitle) ?>
                                    <?php endif; ?>
                                </p>
                            <?php endif; ?>
                            <?php $fetchedAt = (string) ($spotlight['fetched_at'] ?? ''); ?>
                            <?php if ($fetchedAt !== ''): ?>
                                <p class="graph-spotlight__meta">Backed by <?= $escape($formatDate($fetchedAt) ?? $fetchedAt) ?></p>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </article>
                <article class="card">
                    <h3>Workspace shortcuts</h3>
                    <ul class="sources-list">
                        <?php foreach ($workspaceShortcuts as $shortcut): ?>
                            <?php if (!isset($shortcut['title'], $shortcut['description'], $shortcut['href'], $shortcut['action'])) { continue; } ?>
                            <li>
                                <p class="source-title"><a href="<?= $escape((string) $shortcut['href']) ?>"><?= $escape((string) $shortcut['title']) ?></a></p>
                                <p class="source-preview"><?= $escape((string) $shortcut['description']) ?></p>
                                <p class="source-meta"><a class="button ghost" href="<?= $escape((string) $shortcut['href']) ?>"><?= $escape((string) $shortcut['action']) ?></a></p>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </article>
            </div>
        </section>
    </div>
    <section class="graph-note site-container" aria-label="Use the graph across the site">
        <div class="panel">
            <header class="panel-header">
                <div>
                    <h2>Extend your brief</h2>
                    <p class="panel-subtitle">Open adjacent tools that build on the same knowledge graph context.</p>
                </div>
            </header>
            <div class="grid graph-grid">
                <?php foreach ($integrationLinks as $integration): ?>
                    <?php $title = (string) ($integration['title'] ?? ''); ?>
                    <?php $description = (string) ($integration['description'] ?? ''); ?>
                    <?php $href = (string) ($integration['href'] ?? ''); ?>
                    <?php if ($title === '' || $href === '') { continue; } ?>
                    <article class="card">
                        <h3><?= $escape($title) ?></h3>
                        <?php if ($description !== ''): ?>
                            <p class="card-subtle"><?= $escape($description) ?></p>
                        <?php endif; ?>
                        <a class="button ghost" href="<?= $escape($href) ?>">Open</a>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <section class="graph-note site-container" aria-label="Knowledge graph storage">
        <?php if ($graphRepositoryPath !== ''): ?>
            <p>Snapshots live at <code><?= $escape($graphRepositoryPath) ?></code>. Scrape extra URLs from the <a href="<?= $escape($state['homePath']) ?>">Data Preparation Studio</a> whenever you need more context.</p>
        <?php else: ?>
            <p>Scrape additional URLs from the <a href="<?= $escape($state['homePath']) ?>">Data Preparation Studio</a> to enrich the shared graph.</p>
        <?php endif; ?>
    </section>
</main>
<?php SiteLayout::renderFooter($navigationLinks, 'Unified knowledge graph powering AIresearch intelligence.'); ?>
<script>
    window.AIKnowledgeGraph = <?= $initialJson ?>;
</script>
<script src="<?= $escape($assets['autocomplete'] . '?v=' . $versions['autocomplete']) ?>" defer></script>
<script src="<?= $escape($assets['script'] . '?v=' . $versions['script']) ?>" defer></script>
</body>
</html>
