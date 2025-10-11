<?php

declare(strict_types=1);

require __DIR__ . '/src/App/bootstrap.php';

use App\Crawler\HiddenCrawler;
use App\KnowledgeGraph\GraphRepository;
use App\KnowledgeGraph\GraphResearcher;
use App\KnowledgeGraph\ResearchService;
use App\News\NewsSearchService;
use App\Web\PathResolver;
use App\Web\SiteLayout;

$paths = PathResolver::resolve();
$basePath = $paths['basePath'];
$assetBase = $paths['assetBase'];

$stylesPath = PathResolver::url($assetBase, 'assets/styles.css');
$themePath = PathResolver::url($assetBase, 'assets/theme.css');
$researchStylesPath = PathResolver::url($assetBase, 'assets/research.css');
$autocompleteScriptPath = PathResolver::url($assetBase, 'assets/autocomplete.js');
$homePath = PathResolver::url($assetBase, 'index.php');
$searchPath = PathResolver::url($assetBase, 'search.php');
$graphPath = PathResolver::url($assetBase, 'knowledge-graph.php');
$docsPath = PathResolver::url($assetBase, 'docs');
$apiPath = PathResolver::url($assetBase, 'api/research.php');
$scriptPath = PathResolver::url($assetBase, 'assets/knowledge-graph.js');

$navigationPaths = [
    'home' => $homePath,
    'search' => $searchPath,
    'graph' => $graphPath,
    'docs' => $docsPath,
];

$stylesVersion = file_exists(__DIR__ . '/assets/styles.css') ? (string) filemtime(__DIR__ . '/assets/styles.css') : (string) time();
$themeVersion = file_exists(__DIR__ . '/assets/theme.css') ? (string) filemtime(__DIR__ . '/assets/theme.css') : (string) time();
$researchStylesVersion = file_exists(__DIR__ . '/assets/research.css') ? (string) filemtime(__DIR__ . '/assets/research.css') : (string) time();
$autocompleteScriptVersion = file_exists(__DIR__ . '/assets/autocomplete.js') ? (string) filemtime(__DIR__ . '/assets/autocomplete.js') : (string) time();
$scriptVersion = file_exists(__DIR__ . '/assets/knowledge-graph.js') ? (string) filemtime(__DIR__ . '/assets/knowledge-graph.js') : (string) time();

$repository = new GraphRepository();
$researcher = new GraphResearcher($repository);
$service = new ResearchService($repository);

$initialSearch = $researcher->searchGraph('', 12);
$topEntities = $service->listTopEntities(12);
$summary = isset($initialSearch['summary']) && is_array($initialSearch['summary']) ? $initialSearch['summary'] : [];
$sources = isset($initialSearch['sources']) && is_array($initialSearch['sources']) ? $initialSearch['sources'] : [];
$updatedAt = isset($initialSearch['updated_at']) && is_string($initialSearch['updated_at']) ? $initialSearch['updated_at'] : null;
$entities = isset($initialSearch['entities']) && is_array($initialSearch['entities']) ? $initialSearch['entities'] : [];
$relations = isset($initialSearch['relations']) && is_array($initialSearch['relations']) ? $initialSearch['relations'] : [];
$synonymGroups = isset($initialSearch['synonyms']) && is_array($initialSearch['synonyms']) ? $initialSearch['synonyms'] : [];
$triples = isset($initialSearch['triples']) && is_array($initialSearch['triples']) ? $initialSearch['triples'] : [];

$hasGraph = $entities !== [] || $relations !== [] || $triples !== [];

$trendingTopics = [];

try {
    $storage = __DIR__ . '/storage/backend/crawler-history.json';
    $crawler = new HiddenCrawler($storage);
    $newsService = new NewsSearchService($crawler, $repository);
    $newsPayload = $newsService->search('', ['limit' => 24]);
    if (is_array($newsPayload)) {
        $meta = isset($newsPayload['meta']) && is_array($newsPayload['meta']) ? $newsPayload['meta'] : [];

        $topics = [];
        if (isset($meta['topics']) && is_array($meta['topics'])) {
            foreach ($meta['topics'] as $topicRow) {
                if (!is_array($topicRow)) {
                    continue;
                }

                $topicName = isset($topicRow['topic']) ? (string) $topicRow['topic'] : '';
                if ($topicName !== '') {
                    $topics[] = $topicName;
                }
            }
        }

        $suggestedQueries = [];
        if (isset($meta['suggested_queries']) && is_array($meta['suggested_queries'])) {
            foreach ($meta['suggested_queries'] as $query) {
                if (!is_string($query)) {
                    continue;
                }

                $value = trim($query);
                if ($value !== '') {
                    $suggestedQueries[] = $value;
                }
            }
        }

        $trendingTopics = array_values(array_unique(array_filter(array_merge(
            $suggestedQueries,
            $topics
        ), static fn(string $value): bool => trim($value) !== '')));
        $trendingTopics = array_slice($trendingTopics, 0, 12);
    }
} catch (Throwable) {
    $trendingTopics = [];
}

$autocompleteJson = json_encode($trendingTopics, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($autocompleteJson)) {
    $autocompleteJson = '[]';
}

$escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES);
$formatNumber = static function ($value): string {
    if (!is_numeric($value)) {
        return '0';
    }

    $intValue = (int) round((float) $value);
    return number_format($intValue);
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

$initialState = [
    'endpoints' => [
        'search' => $apiPath,
        'list' => $apiPath,
        'refresh' => $apiPath,
        'crawl' => $apiPath,
    ],
    'paths' => [
        'home' => $homePath,
        'graph' => $repository->path(),
    ],
    'initial' => [
        'search' => $initialSearch,
        'hasGraph' => $hasGraph,
        'top' => $topEntities,
        'suggestions' => $trendingTopics,
    ],
];

$initialJson = json_encode($initialState, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if (!is_string($initialJson)) {
    $initialJson = '{}';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Knowledge graph workspace &ndash; AIresearch</title>
    <link rel="stylesheet" href="<?= $escape($themePath . '?v=' . $themeVersion) ?>">
    <link rel="stylesheet" href="<?= $escape($stylesPath . '?v=' . $stylesVersion) ?>">
    <link rel="stylesheet" href="<?= $escape($researchStylesPath . '?v=' . $researchStylesVersion) ?>">
</head>
<body class="site site--graph">
<?php SiteLayout::renderHeader($navigationPaths, 'graph', [
    ['label' => 'Launch search', 'href' => $searchPath],
]); ?>
<main class="site-main graph-main">
    <section class="graph-hero">
        <div class="site-container">
            <div class="graph-hero__content">
                <div>
                    <p class="eyebrow">Live intelligence workspace</p>
                    <h1>Explore the unified knowledge graph</h1>
                    <p class="lead">Monitor the latest entities, relationship triples, and curated sources powering Autopilot briefs. Use the controls below to search, refresh crawls, and orchestrate new ingestion runs.</p>
                </div>
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
            </div>
        </div>
    </section>
    <div class="graph-shell site-container">
        <section class="panel">
            <header class="panel-header">
                <div>
                    <h2>Shared intelligence</h2>
                    <p class="panel-subtitle">The crawler continuously ingests public URLs, extracts semantic triples, and merges them into this shared knowledge base. Explore the latest entities, relations, and supporting sources below.</p>
                </div>
            </header>
            <div class="graph-feedback<?= $hasGraph ? ' is-hidden' : '' ?>" data-graph-feedback role="status">
                <?php if (!$hasGraph): ?>
                    <p>No scraped documents yet. Use the <a href="<?= $escape($homePath) ?>">Data Preparation Studio</a> to fetch an article and enrich the shared graph.</p>
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

        <section class="panel autopilot-panel">
            <header class="panel-header">
                <div>
                    <h2>Autopilot research brief</h2>
                    <p class="panel-subtitle">Blend every stored analysis into a single knowledge graph brief with citations, supporting media, and uniqueness scoring.</p>
                </div>
                <div class="panel-actions">
                    <button type="button" class="button ghost" data-report-refresh>Refresh insights</button>
                </div>
            </header>
            <div class="grid autopilot-grid">
                <article class="card span-2">
                    <h3>Compose a prompt</h3>
                    <form class="report-form" data-report-form>
                        <div class="form-group">
                            <label for="report-query">Focus area</label>
                            <textarea id="report-query" data-report-query placeholder="e.g. Cross-industry AI investments in 2024" spellcheck="false"></textarea>
                            <p class="help-text">The brief builder compares all crawled sources, scores uniqueness, and fuses overlapping narratives into a single report.</p>
                        </div>
                        <div class="report-actions">
                            <button type="submit" class="button primary">Generate brief</button>
                        </div>
                        <p class="status" data-report-status hidden></p>
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
        <p>Knowledge graph snapshots are stored at <code><?= $escape($repository->path()) ?></code>. Scrape additional URLs from the <a href="<?= $escape($homePath) ?>">Data Preparation Studio</a>.</p>
    </section>
</main>
<?php SiteLayout::renderFooter($navigationPaths, 'Unified knowledge graph powering AIresearch intelligence.'); ?>
<script>
    window.AIKnowledgeGraph = <?= $initialJson ?>;
</script>
<script src="<?= $escape($autocompleteScriptPath . '?v=' . $autocompleteScriptVersion) ?>" defer></script>
<script src="<?= $escape($scriptPath . '?v=' . $scriptVersion) ?>" defer></script>
</body>
</html>
