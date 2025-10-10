<?php

declare(strict_types=1);

require __DIR__ . '/src/App/bootstrap.php';

use App\KnowledgeGraph\GraphRepository;
use App\KnowledgeGraph\GraphResearcher;
use App\KnowledgeGraph\ResearchService;

$scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '/search.php');
$scriptDir = str_replace('\\', '/', dirname($scriptName));
if ($scriptDir === '.' || $scriptDir === '/' || $scriptDir === '\\') {
    $scriptDir = '';
}
$basePath = rtrim($scriptDir, '/');
if ($basePath !== '') {
    $basePath = '/' . ltrim($basePath, '/');
}

$assetBase = $basePath === '' ? '' : $basePath;

$stylesPath = $assetBase . '/assets/workbench.css';
$searchStylesPath = $assetBase . '/assets/search.css';
$scriptPath = $assetBase . '/assets/search.js';
$newsStylesPath = $assetBase . '/assets/news-search.css';
$newsScriptPath = $assetBase . '/assets/news-search.js';
$apiPath = $assetBase . '/api/research.php';
$newsEndpoint = $assetBase . '/api/news-search.php';
$homePath = $assetBase . '/index.php';
$graphPath = $assetBase . '/knowledge-graph.php';

$stylesVersion = file_exists(__DIR__ . '/assets/workbench.css') ? (string) filemtime(__DIR__ . '/assets/workbench.css') : (string) time();
$searchStylesVersion = file_exists(__DIR__ . '/assets/search.css') ? (string) filemtime(__DIR__ . '/assets/search.css') : (string) time();
$newsStylesVersion = file_exists(__DIR__ . '/assets/news-search.css') ? (string) filemtime(__DIR__ . '/assets/news-search.css') : (string) time();
$scriptVersion = file_exists(__DIR__ . '/assets/search.js') ? (string) filemtime(__DIR__ . '/assets/search.js') : (string) time();
$newsScriptVersion = file_exists(__DIR__ . '/assets/news-search.js') ? (string) filemtime(__DIR__ . '/assets/news-search.js') : (string) time();

$repository = new GraphRepository();
$researcher = new GraphResearcher($repository);
$service = new ResearchService($repository);

$initialSearch = $researcher->searchGraph('', 18);
$topEntities = $service->listTopEntities(12);
$summary = isset($initialSearch['summary']) && is_array($initialSearch['summary']) ? $initialSearch['summary'] : [];
$sources = isset($initialSearch['sources']) && is_array($initialSearch['sources']) ? $initialSearch['sources'] : [];
$updatedAt = isset($initialSearch['updated_at']) && is_string($initialSearch['updated_at']) ? $initialSearch['updated_at'] : null;
$hasGraph = ($initialSearch['entities'] ?? []) !== [] || ($initialSearch['relations'] ?? []) !== [] || ($initialSearch['triples'] ?? []) !== [];

$escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES);
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

$initialState = [
    'endpoints' => [
        'search' => $apiPath,
        'summary' => $apiPath,
    ],
    'initial' => [
        'search' => $initialSearch,
        'top' => $topEntities,
    ],
];

$initialJson = json_encode($initialState, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if (!is_string($initialJson)) {
    $initialJson = '{}';
}

$documentsProcessed = $formatNumber($summary['documents_processed'] ?? 0);
$sourcesTracked = $formatNumber(count($sources));
$triplesExtracted = $formatNumber($summary['triples'] ?? count($initialSearch['triples'] ?? []));
$uniqueEntities = $formatNumber($summary['unique_entities'] ?? count($initialSearch['entities'] ?? []));
$synonymGroups = $formatNumber($summary['synonym_groups'] ?? count($initialSearch['synonyms'] ?? []));
$updatedLabel = $formatDate($updatedAt) ?? $updatedAt;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AIresearch Discovery Search</title>
    <link rel="stylesheet" href="<?= $escape($stylesPath . '?v=' . $stylesVersion) ?>">
    <link rel="stylesheet" href="<?= $escape($searchStylesPath . '?v=' . $searchStylesVersion) ?>">
    <link rel="stylesheet" href="<?= $escape($newsStylesPath . '?v=' . $newsStylesVersion) ?>">
</head>
<body class="search-app">
    <header class="site-header">
        <div class="container">
            <nav class="site-nav" aria-label="Primary">
                <a class="site-nav__link" href="<?= $escape($homePath) ?>">Data Studio</a>
                <a class="site-nav__link" href="<?= $escape($graphPath) ?>">Knowledge Graph</a>
                <a class="site-nav__link site-nav__link--active" href="<?= $escape($assetBase . '/search.php') ?>">Discovery Search</a>
            </nav>
            <div class="search-hero">
                <div>
                    <h1>AIresearch Discovery Search</h1>
                    <p class="tagline">Query the shared knowledge graph, surface relationship triples, and trace every fact back to the original sources.</p>
                </div>
                <form class="search-form" data-search-form role="search">
                    <label class="visually-hidden" for="search-query">Search the knowledge graph</label>
                    <input id="search-query" name="q" type="search" placeholder="Search people, organisations, technologies&hellip;" autocomplete="off" spellcheck="false" data-search-input>
                    <button type="submit" class="button primary">Search</button>
                </form>
                <div class="search-suggestions" data-search-trending<?= $topEntities === [] ? ' hidden' : '' ?>>
                    <span class="search-suggestions__label">Trending</span>
                    <div class="search-suggestions__chips" data-search-trending-list>
                        <?php foreach ($topEntities as $entityRow): ?>
                            <?php $entityName = (string) ($entityRow['entity'] ?? ''); ?>
                            <?php if ($entityName === '') { continue; } ?>
                            <button type="button" class="chip" data-entity="<?= $escape($entityName) ?>"><?= $escape($entityName) ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </header>
    <main class="container">
        <section class="panel">
            <header class="panel-header">
                <div>
                    <h2>Knowledge results</h2>
                    <p class="panel-subtitle">We analyse every crawl, extraction, and upload to make facts instantly searchable.</p>
                </div>
            </header>
            <div class="status" data-search-status aria-live="polite">
                <?= $hasGraph ? 'Ready to explore the global knowledge graph.' : 'No graph data yet. Start by analysing text or scraping a URL from the Data Studio.' ?>
            </div>
            <div class="results-overview" data-search-metrics<?= $hasGraph ? '' : ' hidden' ?>>
                <article class="metric-card">
                    <span class="metric-label">Documents processed</span>
                    <span class="metric-value" data-metric-documents><?= $documentsProcessed ?></span>
                    <span class="metric-sub" data-metric-sources>Sources tracked: <?= $sourcesTracked ?></span>
                </article>
                <article class="metric-card">
                    <span class="metric-label">Triples extracted</span>
                    <span class="metric-value" data-metric-triples><?= $triplesExtracted ?></span>
                    <span class="metric-sub" data-metric-synonyms>Synonym groups: <?= $synonymGroups ?></span>
                </article>
                <article class="metric-card">
                    <span class="metric-label">Unique entities</span>
                    <span class="metric-value" data-metric-entities><?= $uniqueEntities ?></span>
                    <span class="metric-sub" data-metric-updated><?= $updatedLabel !== null ? 'Updated ' . $escape($updatedLabel) : 'Awaiting first crawl' ?></span>
                </article>
            </div>
            <div class="search-results" data-search-results<?= $hasGraph ? '' : ' hidden' ?>>
                <div class="search-columns">
                    <div class="search-columns__primary">
                        <div class="grid search-grid">
                            <article class="card span-3">
                                <h3>Entities</h3>
                                <p class="card-subtle" data-search-entities-empty<?= $hasGraph ? ' hidden' : '' ?>>Search to surface entity profiles with context, relations, and supporting sources.</p>
                                <div class="entity-results" data-search-entities>
                                    <?php foreach (array_slice($initialSearch['entities'] ?? [], 0, 6) as $entity): ?>
                                        <?php $entityName = (string) ($entity['entity'] ?? ''); ?>
                                        <?php if ($entityName === '') { continue; } ?>
                                        <button type="button" class="entity-chip" data-entity="<?= $escape($entityName) ?>">
                                            <span class="entity-chip__name"><?= $escape($entityName) ?></span>
                                            <?php if (isset($entity['score'])): ?>
                                                <span class="entity-chip__score">Match confidence <?= $escape((string) round((float) $entity['score'] * 100)) ?>%</span>
                                            <?php endif; ?>
                                            <?php if (!empty($entity['synonyms'])): ?>
                                                <span class="entity-chip__meta">Synonyms: <?= $escape(implode(', ', (array) $entity['synonyms'])) ?></span>
                                            <?php endif; ?>
                                            <?php if (isset($entity['summary']['facts'])): ?>
                                                <span class="entity-chip__signals">Facts indexed: <?= $escape((string) count((array) $entity['summary']['facts'])) ?></span>
                                            <?php endif; ?>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                            </article>
                            <article class="card span-2">
                                <h3>Relation signals</h3>
                                <p class="card-subtle" data-search-relations-empty<?= $hasGraph ? ' hidden' : '' ?>>Relation matches will appear here once you run a search.</p>
                                <ul class="list-block" data-search-relations>
                                    <?php foreach (array_slice($initialSearch['relations'] ?? [], 0, 8) as $relation): ?>
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
                                <p class="card-subtle" data-search-synonyms-empty<?= $hasGraph ? ' hidden' : '' ?>>Advanced name matching highlights aliases and related spellings.</p>
                                <ul class="list-block" data-search-synonyms>
                                    <?php foreach (array_slice($initialSearch['synonyms'] ?? [], 0, 8) as $group): ?>
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
                                <h3>Triple matches</h3>
                                <p class="card-subtle" data-search-triples-empty<?= $hasGraph ? ' hidden' : '' ?>>Semantic triples that mention your query will appear below.</p>
                                <div class="table-wrapper">
                                    <table class="search-table" data-search-triples>
                                        <thead>
                                            <tr>
                                                <th scope="col">Subject</th>
                                                <th scope="col">Relation</th>
                                                <th scope="col">Object</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach (array_slice($initialSearch['triples'] ?? [], 0, 10) as $triple): ?>
                                                <tr>
                                                    <td><?= $escape((string) ($triple['subject'] ?? '')) ?></td>
                                                    <td><?= $escape((string) ($triple['relation'] ?? '')) ?></td>
                                                    <td><?= $escape((string) ($triple['object'] ?? '')) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </article>
                            <article class="card span-3">
                                <h3>Source registry</h3>
                                <p class="card-subtle" data-search-sources-empty<?= $hasGraph ? ' hidden' : '' ?>>Each fact links back to a crawled or uploaded source.</p>
                                <ul class="list-block" data-search-sources>
                                    <?php foreach (array_slice($sources, 0, 8) as $source): ?>
                                        <?php $title = isset($source['title']) && is_string($source['title']) && trim($source['title']) !== '' ? $source['title'] : ($source['url'] ?? ''); ?>
                                        <?php $url = isset($source['url']) && is_string($source['url']) ? $source['url'] : ''; ?>
                                        <?php $lastSeen = isset($source['last_seen']) && is_string($source['last_seen']) ? $formatDate($source['last_seen']) : null; ?>
                                        <?php if ($title === '' && $url === '') { continue; } ?>
                                        <li>
                                            <?php if ($url !== ''): ?>
                                                <a href="<?= $escape($url) ?>" target="_blank" rel="noopener" class="label"><?= $escape((string) $title) ?></a>
                                            <?php else: ?>
                                                <span class="label"><?= $escape((string) $title) ?></span>
                                            <?php endif; ?>
                                            <?php if ($lastSeen !== null): ?>
                                                <span class="value">Seen <?= $escape($lastSeen) ?></span>
                                            <?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </article>
                        </div>
                    </div>
                    <aside class="card entity-detail" data-entity-detail hidden>
                        <div class="entity-detail__header">
                            <h3 data-entity-name>Entity detail</h3>
                            <p class="entity-detail__score" data-entity-score></p>
                            <div class="pill-list" data-entity-synonyms></div>
                        </div>
                        <div class="entity-detail__body">
                            <div class="entity-detail__section" data-entity-facts-wrap>
                                <h4>Key facts</h4>
                                <ul class="entity-detail__facts" data-entity-facts></ul>
                            </div>
                            <div class="entity-detail__section" data-entity-relations-wrap>
                                <h4>Relation highlights</h4>
                                <ul class="entity-detail__relations" data-entity-relations></ul>
                            </div>
                        </div>
                    </aside>
                </div>
            </div>
        </section>
        <section class="news-panel" data-news-app data-news-endpoint="<?= $escape($newsEndpoint) ?>">
            <div class="news-panel__header">
                <div>
                    <h2>Live news intelligence</h2>
                    <p class="news-panel__subtitle">Quality-ranked headlines from the crawler, searchable by entity, topic and source credibility.</p>
                </div>
                <form class="news-form" data-news-search-form>
                    <label class="visually-hidden" for="news-query">Search the news index</label>
                    <input id="news-query" type="search" name="news-query" placeholder="Search headlines, tickers or topics" autocomplete="off" spellcheck="false" data-news-query>
                    <label class="visually-hidden" for="news-quality">Minimum quality score</label>
                    <select id="news-quality" data-news-quality>
                        <option value="60" selected>High quality (60+)</option>
                        <option value="50">Good quality (50+)</option>
                        <option value="70">Exceptional (70+)</option>
                        <option value="0">All stories</option>
                    </select>
                    <button type="submit" class="button ghost">Search news</button>
                </form>
            </div>
            <div class="news-meta">
                <div class="news-status" data-news-status aria-live="polite">Loading curated sources…</div>
                <div class="news-topics" data-news-topics hidden>
                    <span class="news-topics__label">Trending topics</span>
                    <div class="news-topics__chips" data-news-topics-list></div>
                </div>
                <div class="news-status" data-news-stats></div>
            </div>
            <div class="news-results" data-news-results>
                <div class="news-empty">Launching crawler insights…</div>
            </div>
        </section>
    </main>
    <script>window.AISearch = <?= $initialJson ?>;</script>
    <script src="<?= $escape($scriptPath . '?v=' . $scriptVersion) ?>" defer></script>
    <script src="<?= $escape($newsScriptPath . '?v=' . $newsScriptVersion) ?>" defer></script>
</body>
</html>
