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
        'description' => 'Validate coverage stats and inspect recent entity and relation matches.',
        'href' => $overviewPath,
        'action' => 'Open overview',
    ],
    [
        'title' => 'Autopilot briefs',
        'description' => 'Convert freshly ingested data into shareable graph-backed narratives.',
        'href' => $autopilotPath,
        'action' => 'Generate brief',
    ],
    [
        'title' => 'Admin hub',
        'description' => 'Monitor ingestion health, spotlight triples, and manage workspaces.',
        'href' => $hubPath,
        'action' => 'Go to hub',
    ],
];

$integrationLinks = [
    [
        'title' => 'Search workspace',
        'description' => 'Run instant searches to confirm entities and relation coverage.',
        'href' => $state['searchPath'],
    ],
    [
        'title' => 'Data preparation studio',
        'description' => 'Scrape and clean new URLs before pushing them into the shared graph.',
        'href' => $state['homePath'],
    ],
    [
        'title' => 'Graph documentation',
        'description' => 'Reference ingestion workflows, API endpoints, and schema notes.',
        'href' => $state['docsPath'],
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Knowledge graph research console &ndash; AIresearch</title>
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
    ['label' => 'Autopilot brief', 'href' => $autopilotPath],
    ['label' => 'Launch search', 'href' => $state['searchPath']],
]); ?>
<main class="site-main graph-main">
    <section class="graph-hero">
        <div class="site-container">
            <div class="graph-hero__content">
                <div class="graph-hero__intro">
                    <p class="eyebrow">Operations console</p>
                    <h1>Grow the knowledge graph</h1>
                    <p class="lead">Keep ingestion running smoothly with a focused set of tools.</p>
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
                    <p class="graph-hero__hint">Check coverage before scheduling your next crawl.</p>
                </aside>
            </div>
        </div>
    </section>
    <?php if ($trendingTopics !== []): ?>
        <section class="graph-suggestions site-container" data-graph-suggestions>
            <div class="graph-suggestions__header">
                <h2>Run a quick coverage check</h2>
                <p>Use trending prompts to validate how the graph handles current narratives.</p>
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
        <section class="panel research-console">
            <header class="panel-header">
                <div>
                    <h2>Research console</h2>
                    <p class="panel-subtitle">Review high-priority entities and run crawls without distraction.</p>
                </div>
                <div class="panel-actions">
                    <button type="button" class="button ghost" data-refresh-sources>Refresh stored sources</button>
                </div>
            </header>
            <div class="grid research-grid">
                <article class="card">
                    <h3>Recommended leads</h3>
                    <p class="card-subtle" data-top-empty>No enriched entities yet. Run a crawl or scrape a page to surface suggestions.</p>
                    <div class="entity-results entity-results--top" data-top-entities></div>
                </article>
                <article class="card">
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
                <article class="card">
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
        <section class="panel">
            <header class="panel-header">
                <div>
                    <h2>Graph health &amp; ingestion</h2>
                    <p class="panel-subtitle">Stay ahead of ingestion trends and surface the freshest supporting context.</p>
                </div>
            </header>
            <div class="grid graph-analytics__grid">
                <article class="card">
                    <h3>Graph health signals</h3>
                    <p class="card-subtle" data-graph-coverage-empty<?= $graphCoverageSignals !== [] ? ' hidden' : '' ?>>Keep crawls running to populate coverage, synonym, and ingestion metrics.</p>
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
                    <p class="card-subtle" data-graph-timeline-empty<?= $graphTimeline !== [] ? ' hidden' : '' ?>>Timeline updates appear after crawls merge sources into the graph.</p>
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
                    <p class="card-subtle" data-graph-spotlight-empty<?= is_array($spotlight) && $spotlight !== [] ? ' hidden' : '' ?>>We surface a fresh triple with the source that introduced it.</p>
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
                    <h3>Latest sources</h3>
                    <p class="card-subtle" data-graph-sources-empty<?= $sources !== [] ? ' hidden' : '' ?>>Scraped URLs and dossiers populate after each crawl.</p>
                    <ul class="sources-list" data-graph-sources<?= $sources === [] ? ' hidden' : '' ?>>
                        <?php foreach (array_slice($sources, 0, 5) as $source): ?>
                            <?php
                            $label = is_string($source['title'] ?? null) && trim((string) $source['title']) !== ''
                                ? (string) $source['title']
                                : (string) ($source['url'] ?? '');
                            $sourceUrl = (string) ($source['url'] ?? '');
                            $characters = $formatNumber($source['characters'] ?? 0);
                            $fetchedAt = isset($source['fetched_at']) && is_string($source['fetched_at'])
                                ? ($formatDate($source['fetched_at']) ?? $source['fetched_at'])
                                : null;
                            $preview = (string) ($source['preview'] ?? '');
                            ?>
                            <li>
                                <p class="source-title"><?php if ($sourceUrl !== ''): ?><a href="<?= $escape($sourceUrl) ?>" target="_blank" rel="noopener noreferrer"><?= $escape($label) ?></a><?php else: ?><?= $escape($label) ?><?php endif; ?></p>
                                <p class="source-meta"><?= $escape($characters) ?> characters<?php if ($fetchedAt): ?> • <?= $escape($fetchedAt) ?><?php endif; ?></p>
                                <?php if ($preview !== ''): ?>
                                    <p class="source-preview"><?= $escape($preview) ?></p>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
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
                    <h2>Extend your research</h2>
                    <p class="panel-subtitle">Switch between ingestion, analysis, and reporting tools without leaving the graph.</p>
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
