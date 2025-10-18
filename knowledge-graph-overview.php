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
$trendingTopics = $state['trendingTopics'];
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
$updatedAt = $state['updatedAt'];
$initialJson = $state['initialJson'];
$autocompleteJson = $state['autocompleteJson'];
$graphRepositoryPath = (string) ($state['initialState']['paths']['graph'] ?? '');

$hubPath = PathResolver::url($assetBase, 'knowledge-graph.php');
$overviewPath = PathResolver::url($assetBase, 'knowledge-graph-overview.php');
$autopilotPath = PathResolver::url($assetBase, 'knowledge-graph-autopilot.php');
$researchPath = PathResolver::url($assetBase, 'knowledge-graph-research.php');

$entityFilter = isset($_GET['entity']) ? trim((string) $_GET['entity']) : '';
$entityLimit = isset($_GET['limit']) ? (int) $_GET['limit'] : 5;
$limitOptions = [5, 10, 15, 20];
if (!in_array($entityLimit, $limitOptions, true)) {
    $entityLimit = 5;
}

$entitiesFiltered = $entities;
if ($entityFilter !== '') {
    $entitiesFiltered = array_values(array_filter($entities, static function ($entity) use ($entityFilter): bool {
        if (!is_array($entity)) {
            return false;
        }

        $name = isset($entity['entity']) ? (string) $entity['entity'] : '';
        if ($name !== '' && stripos($name, $entityFilter) !== false) {
            return true;
        }

        $synonyms = $entity['summary']['synonyms'] ?? [];
        if (is_array($synonyms)) {
            foreach ($synonyms as $synonym) {
                if (!is_string($synonym)) {
                    continue;
                }

                if (stripos($synonym, $entityFilter) !== false) {
                    return true;
                }
            }
        }

        return false;
    }));
}

$entitiesSubset = array_slice($entitiesFiltered, 0, $entityLimit);
$filterActive = $entityFilter !== '' || $entityLimit !== 5;
$noEntityResults = $hasGraph && $filterActive && $entitiesSubset === [];

$workspaceShortcuts = [
    [
        'title' => 'Autopilot briefs',
        'description' => 'Generate graph-backed reports with cached prompts and citations.',
        'href' => $autopilotPath,
        'action' => 'Open Autopilot',
    ],
    [
        'title' => 'Research console',
        'description' => 'Run crawls, refresh sources, and triage the latest entity leads.',
        'href' => $researchPath,
        'action' => 'Visit console',
    ],
    [
        'title' => 'Admin hub',
        'description' => 'Monitor ingestion health, spotlight triples, and manage workspaces.',
        'href' => $hubPath,
        'action' => 'Go to hub',
    ],
];

$siteIntegrations = [
    [
        'title' => 'Search workspace',
        'description' => 'Launch semantic search with autocomplete powered by trending topics.',
        'href' => $state['searchPath'],
    ],
    [
        'title' => 'Data preparation studio',
        'description' => 'Scrape, clean, and enrich fresh sources before merging them into the graph.',
        'href' => $state['homePath'],
    ],
    [
        'title' => 'Graph documentation',
        'description' => 'Review ingestion workflows, schema guidance, and automation hooks.',
        'href' => $state['docsPath'],
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Knowledge graph overview &ndash; AIresearch</title>
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
    ['label' => 'Autopilot brief', 'href' => $autopilotPath],
    ['label' => 'Research console', 'href' => $researchPath],
    ['label' => 'Launch search', 'href' => $state['searchPath']],
]); ?>
<main class="site-main graph-main">
    <section class="graph-hero">
        <div class="site-container">
            <div class="graph-hero__content">
                <div class="graph-hero__intro">
                    <p class="eyebrow">Graph intelligence</p>
                    <h1>Knowledge graph overview</h1>
                    <p class="lead">Track core stats, emerging entities, and sources with an AI-simplified overview.</p>
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
                    <p class="graph-hero__hint">Surface entities, relations, and triples for your query instantly.</p>
                </aside>
            </div>
        </div>
    </section>
    <nav class="graph-subnav" aria-label="Graph sections">
        <div class="site-container">
            <ul class="graph-subnav__list">
                <li><a class="graph-subnav__link" href="#graph-snapshot">Snapshot</a></li>
                <li><a class="graph-subnav__link" href="#graph-entities">Entity explorer</a></li>
                <li><a class="graph-subnav__link" href="#graph-timeline">Ingestion timeline</a></li>
                <li><a class="graph-subnav__link" href="#graph-spotlight">Spotlight</a></li>
            </ul>
        </div>
    </nav>
    <?php if ($trendingTopics !== []): ?>
        <section class="graph-suggestions site-container" data-graph-suggestions id="graph-suggestions">
            <div class="graph-suggestions__header">
                <h2>Suggested graph prompts</h2>
                <p>AI-curated prompts refreshed from the latest ingestion runs.</p>
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
        <section class="panel" id="graph-snapshot">
            <header class="panel-header">
                <div>
                    <h2>Graph snapshot</h2>
                    <p class="panel-subtitle">A condensed view of coverage, activity, and supporting evidence.</p>
                </div>
            </header>
            <form class="graph-filter insight-toolbar" method="get" action="<?= $escape($overviewPath) ?>#graph-entities">
                <div class="graph-filter__grid">
                    <div class="graph-filter__field">
                        <label for="entity-filter">Filter entities</label>
                        <input
                            id="entity-filter"
                            name="entity"
                            type="search"
                            value="<?= $escape($entityFilter) ?>"
                            placeholder="Search by entity or synonym"
                        >
                    </div>
                    <div class="graph-filter__field">
                        <label for="entity-limit">Show results</label>
                        <select id="entity-limit" name="limit">
                            <?php foreach ($limitOptions as $option): ?>
                                <option value="<?= $option ?>"<?= $entityLimit === $option ? ' selected' : '' ?>><?= $option ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="graph-filter__actions">
                    <button type="submit" class="button primary">Update view</button>
                    <?php if ($filterActive): ?>
                        <a class="button button--ghost" href="<?= $escape($overviewPath) ?>#graph-entities">Reset</a>
                    <?php endif; ?>
                </div>
            </form>
            <?php
                $feedbackClasses = 'graph-feedback';
                if ($hasGraph && !$noEntityResults) {
                    $feedbackClasses .= ' is-hidden';
                }
            ?>
            <div class="<?= $escape($feedbackClasses) ?>" data-graph-feedback role="status">
                <?php if (!$hasGraph): ?>
                    <p>No scraped documents yet. Use the <a href="<?= $escape($state['homePath']) ?>">Data Preparation Studio</a> to fetch an article and enrich the shared graph.</p>
                <?php elseif ($noEntityResults): ?>
                    <p>No entities matched <?= $entityFilter !== '' ? '<strong>' . $escape($entityFilter) . '</strong>' : 'the selected filters' ?>. Try broadening the query or <a href="<?= $escape($overviewPath) ?>#graph-entities">reset the filters</a>.</p>
                <?php endif; ?>
            </div>
            <?php if ($hasGraph): ?>
                <div class="results-overview" data-graph-metrics>
                    <article class="metric-card">
                        <span class="metric-label">Documents processed</span>
                        <span class="metric-value"><?= $escape($formatNumber($summary['documents_processed'] ?? 0)) ?></span>
                        <span class="metric-sub">Sources tracked: <?= $escape($formatNumber(count($sources))) ?></span>
                    </article>
                    <article class="metric-card">
                        <span class="metric-label">Triples extracted</span>
                        <span class="metric-value"><?= $escape($formatNumber($summary['triples'] ?? count($triples))) ?></span>
                        <span class="metric-sub">Synonym groups: <?= $escape($formatNumber($summary['synonym_groups'] ?? count($synonymGroups))) ?></span>
                    </article>
                    <article class="metric-card">
                        <span class="metric-label">Unique entities</span>
                        <span class="metric-value"><?= $escape($formatNumber($summary['unique_entities'] ?? count($entities))) ?></span>
                        <?php if ($updatedAt !== null): ?>
                            <span class="metric-sub">Updated <?= $escape($formatDate($updatedAt) ?? $updatedAt) ?></span>
                        <?php endif; ?>
                    </article>
                </div>
                <div class="insight-stack insight-stack--two" data-graph-grid id="graph-entities">
                    <article class="card">
                        <h3>Key entities</h3>
                        <p class="card-subtle" data-graph-entities-empty<?= $entitiesSubset !== [] ? ' hidden' : '' ?>>Run a search or adjust the filters to surface the most relevant entities.</p>
                        <div class="entity-results" data-graph-entities>
                            <?php foreach ($entitiesSubset as $entity): ?>
                                <?php $entityName = (string) ($entity['entity'] ?? ''); ?>
                                <?php if ($entityName === '') { continue; } ?>
                                <button type="button" class="entity-chip" data-entity="<?= $escape($entityName) ?>">
                                    <span class="entity-chip__name"><?= $escape($entityName) ?></span>
                                    <?php if (isset($entity['summary']['synonyms']) && is_array($entity['summary']['synonyms']) && $entity['summary']['synonyms'] !== []): ?>
                                        <span class="entity-chip__meta">Synonyms: <?= $escape(implode(', ', $entity['summary']['synonyms'])) ?></span>
                                    <?php endif; ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </article>
                    <article class="card">
                        <h3>Relation signals</h3>
                        <p class="card-subtle" data-graph-relations-empty<?= $relations !== [] ? ' hidden' : '' ?>>Relation matches appear as you search.</p>
                        <ul class="list-block" data-graph-relations>
                            <?php foreach (array_slice($relations, 0, 6) as $relation): ?>
                                <?php $label = (string) ($relation['relation'] ?? $relation['label'] ?? ''); ?>
                                <?php if ($label === '') { continue; } ?>
                                <li>
                                    <span class="label"><?= $escape($label) ?></span>
                                    <?php if (isset($relation['count'])): ?>
                                        <span class="value"><?= $escape($formatNumber($relation['count'])) ?></span>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </article>
                    <article class="card">
                        <h3>Highlighted triples</h3>
                        <p class="card-subtle" data-graph-triples-empty<?= $triples !== [] ? ' hidden' : '' ?>>Entity relationships appear here after a search.</p>
                        <div class="table-wrapper">
                            <table class="search-table" data-graph-triples>
                                <thead>
                                    <tr>
                                        <th scope="col">Subject</th>
                                        <th scope="col">Relation</th>
                                        <th scope="col">Object</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (array_slice($triples, 0, 6) as $triple): ?>
                                        <tr>
                                            <td><?= $escape((string) ($triple['subject'] ?? $triple[0] ?? '')) ?></td>
                                            <td><?= $escape((string) ($triple['relation'] ?? $triple[1] ?? '')) ?></td>
                                            <td><?= $escape((string) ($triple['object'] ?? $triple[2] ?? '')) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </article>
                    <article class="card">
                        <h3>Latest sources</h3>
                        <p class="card-subtle" data-graph-sources-empty<?= $sources !== [] ? ' hidden' : '' ?>>Scraped URLs and research dossiers populate this feed.</p>
                        <ul class="sources-list" data-graph-sources>
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
                </div>
                <div class="graph-analytics__grid insight-stack insight-stack--two">
                    <article class="card">
                        <h3>Graph health signals</h3>
                        <p class="card-subtle" data-graph-coverage-empty<?= $graphCoverageSignals !== [] ? ' hidden' : '' ?>>Keep ingestion running to surface coverage and enrichment metrics.</p>
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
                    <article class="card" id="graph-timeline">
                        <h3>Recent ingestion</h3>
                        <p class="card-subtle" data-graph-timeline-empty<?= $graphTimeline !== [] ? ' hidden' : '' ?>>Timeline updates appear once sources are merged into the knowledge graph.</p>
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
                    <article class="card" id="graph-spotlight">
                        <h3>Graph spotlight</h3>
                        <p class="card-subtle" data-graph-spotlight-empty<?= is_array($spotlight) && $spotlight !== [] ? ' hidden' : '' ?>>Once ingestion runs, we highlight a fresh triple with its supporting source.</p>
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
            <?php endif; ?>
        </section>
    </div>
    <section class="graph-note site-container" aria-label="Use the graph across the site">
        <div class="panel">
            <header class="panel-header">
                <div>
                    <h2>Use graph intelligence everywhere</h2>
                    <p class="panel-subtitle">Jump into related tools that lean on the same knowledge base.</p>
                </div>
            </header>
            <div class="grid graph-grid">
                <?php foreach ($siteIntegrations as $integration): ?>
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
