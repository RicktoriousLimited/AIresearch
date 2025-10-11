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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Knowledge graph overview &ndash; AIresearch</title>
    <link rel="stylesheet" href="<?= $escape($assets['theme'] . '?v=' . $versions['theme']) ?>">
    <link rel="stylesheet" href="<?= $escape($assets['styles'] . '?v=' . $versions['styles']) ?>">
    <link rel="stylesheet" href="<?= $escape($assets['research'] . '?v=' . $versions['research']) ?>">
</head>
<body class="site site--graph">
<?php SiteLayout::renderHeader($navigationPaths, 'graph', [
    ['label' => 'Graph hub', 'href' => $hubPath],
    ['label' => 'Autopilot brief', 'href' => $autopilotPath],
    ['label' => 'Research console', 'href' => $researchPath],
    ['label' => 'Launch search', 'href' => $state['searchPath']],
]); ?>
<main class="site-main graph-main">
    <section class="graph-hero">
        <div class="site-container">
            <div class="graph-hero__content">
                <div>
                    <p class="eyebrow">Graph intelligence</p>
                    <h1>Knowledge graph overview</h1>
                    <p class="lead">Inspect entity coverage, relation density, and the source material fueling the shared graph.</p>
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
                            <li><a class="graph-subnav__link is-active" aria-current="page" href="<?= $escape($overviewPath) ?>">Overview</a></li>
                            <li><a class="graph-subnav__link" href="<?= $escape($autopilotPath) ?>">Autopilot brief</a></li>
                            <li><a class="graph-subnav__link" href="<?= $escape($researchPath) ?>">Research console</a></li>
                        </ul>
                    </nav>
                </div>
            </div>
        </div>
    </section>
    <div class="graph-shell site-container">
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
                    <h2>Graph analytics</h2>
                    <p class="panel-subtitle">Track ingestion velocity and the depth of evidence backing knowledge graph explorations.</p>
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
                    <h2>Shared intelligence</h2>
                    <p class="panel-subtitle">Explore the latest entities, relations, and supporting sources below.</p>
                </div>
            </header>
            <div class="graph-feedback<?= $hasGraph ? ' is-hidden' : '' ?>" data-graph-feedback role="status">
                <?php if (!$hasGraph): ?>
                    <p>No scraped documents yet. Use the <a href="<?= $escape($state['homePath']) ?>">Data Preparation Studio</a> to fetch an article and enrich the shared graph.</p>
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
                <div class="grid graph-grid" data-graph-grid>
                    <article class="card span-3">
                        <h3>Entity explorer</h3>
                        <p class="card-subtle" data-graph-entities-empty<?= $entities !== [] ? ' hidden' : '' ?>>Run a search to surface the most relevant entities and supporting evidence.</p>
                        <div class="entity-results" data-graph-entities>
                            <?php foreach (array_slice($entities, 0, 6) as $entity): ?>
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
                    <article class="card span-2">
                        <h3>Relation signals</h3>
                        <p class="card-subtle" data-graph-relations-empty<?= $relations !== [] ? ' hidden' : '' ?>>Relation matches will appear here once you start searching.</p>
                        <ul class="list-block" data-graph-relations>
                            <?php foreach (array_slice($relations, 0, 10) as $relation): ?>
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
                    <article class="card span-2">
                        <h3>Synonym clusters</h3>
                        <p class="card-subtle" data-graph-synonyms-empty<?= $synonymGroups !== [] ? ' hidden' : '' ?>>Advanced name matching highlights aliases and related spellings.</p>
                        <ul class="list-block" data-graph-synonyms>
                            <?php foreach (array_slice($synonymGroups, 0, 8) as $group): ?>
                                <?php $entityName = (string) ($group['entity'] ?? ''); ?>
                                <?php $synonyms = isset($group['synonyms']) && is_array($group['synonyms']) ? $group['synonyms'] : []; ?>
                                <?php if ($entityName === '' || $synonyms === []) { continue; } ?>
                                <li>
                                    <span class="label"><?= $escape($entityName) ?></span>
                                    <span class="value"><?= $escape(implode(', ', $synonyms)) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </article>
                    <article class="card span-3">
                        <h3>Highlighted triples</h3>
                        <p class="card-subtle" data-graph-triples-empty<?= $triples !== [] ? ' hidden' : '' ?>>Entity relationships and evidence snippets will appear here.</p>
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
                                    <?php foreach (array_slice($triples, 0, 12) as $triple): ?>
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
                    <article class="card span-2">
                        <h3>Source library</h3>
                        <p class="card-subtle" data-graph-sources-empty<?= $sources !== [] ? ' hidden' : '' ?>>Scraped URLs and research dossiers populate this feed.</p>
                        <ul class="sources-list" data-graph-sources>
                            <?php foreach (array_slice($sources, 0, 6) as $source): ?>
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
            <?php endif; ?>
        </section>
        <section class="panel">
            <header class="panel-header">
                <h2>Entity insights</h2>
                <p class="panel-subtitle">Select an entity to inspect relation histograms, synonym evidence, and the strongest supporting facts.</p>
            </header>
            <div class="entity-detail entity-detail--full" data-graph-entity-detail>
                <p class="empty-state">Choose an entity from the explorer to see a full research summary.</p>
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
