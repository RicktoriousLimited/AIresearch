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

$stylesPath = $assetBase . '/assets/styles.css';
$researchStylesPath = $assetBase . '/assets/research.css';
$scriptPath = $assetBase . '/assets/search.js';
$newsScriptPath = $assetBase . '/assets/news-search.js';
$knowledgeScriptPath = $assetBase . '/assets/knowledge-graph.js';
$apiPath = $assetBase . '/api/research.php';
$newsEndpoint = $assetBase . '/api/news-search.php';
$homePath = $assetBase . '/index.php';
$graphPath = $assetBase . '/knowledge-graph.php';

$stylesVersion = file_exists(__DIR__ . '/assets/styles.css') ? (string) filemtime(__DIR__ . '/assets/styles.css') : (string) time();
$researchStylesVersion = file_exists(__DIR__ . '/assets/research.css') ? (string) filemtime(__DIR__ . '/assets/research.css') : (string) time();
$scriptVersion = file_exists(__DIR__ . '/assets/search.js') ? (string) filemtime(__DIR__ . '/assets/search.js') : (string) time();
$newsScriptVersion = file_exists(__DIR__ . '/assets/news-search.js') ? (string) filemtime(__DIR__ . '/assets/news-search.js') : (string) time();
$knowledgeScriptVersion = file_exists(__DIR__ . '/assets/knowledge-graph.js') ? (string) filemtime(__DIR__ . '/assets/knowledge-graph.js') : (string) time();

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

$autopilotConfig = [
    'endpoints' => [
        'search' => $apiPath,
    ],
];

$initialJson = json_encode($initialState, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if (!is_string($initialJson)) {
    $initialJson = '{}';
}

$autopilotJson = json_encode($autopilotConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if (!is_string($autopilotJson)) {
    $autopilotJson = '{}';
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
    <title>Autopilot Search &ndash; AIresearch</title>
    <link rel="stylesheet" href="<?= $escape($stylesPath . '?v=' . $stylesVersion) ?>">
    <link rel="stylesheet" href="<?= $escape($researchStylesPath . '?v=' . $researchStylesVersion) ?>">
</head>
<body class="search-page">
<header class="site-header">
    <div class="shell header-shell">
        <a class="brand" href="<?= $escape($assetBase . '/index.php') ?>">AIresearch</a>
        <nav class="primary-nav" aria-label="Primary">
            <a href="<?= $escape($assetBase . '/index.php') ?>" class="primary-nav__link">Home</a>
            <a href="<?= $escape($assetBase . '/search.php') ?>" class="primary-nav__link primary-nav__link--active">Autopilot search</a>
            <a href="<?= $escape($assetBase . '/knowledge-graph.php') ?>" class="primary-nav__link">Knowledge graph</a>
            <a href="<?= $escape($assetBase . '/docs') ?>" class="primary-nav__link">Documentation</a>
        </nav>
        <div class="header-actions">
            <a class="button primary" href="<?= $escape($assetBase . '/knowledge-graph.php') ?>">Explore graph</a>
        </div>
    </div>
</header>
<main class="search-main search-main--engine">
    <section class="search-toolbar">
        <div class="shell search-toolbar__shell">
            <a class="search-toolbar__brand" href="<?= $escape($homePath) ?>" aria-label="Back to homepage">AIresearch</a>
            <form class="search-form search-form--bar" data-search-form role="search">
                <label class="visually-hidden" for="search-query">Search the knowledge graph</label>
                <div class="search-form__field">
                    <input id="search-query" name="q" type="search" placeholder="Search the Autopilot graph" autocomplete="off" spellcheck="false" data-search-input>
                    <button type="submit" class="button primary">Autopilot brief</button>
                </div>
            </form>
        </div>
        <div class="shell search-toolbar__meta">
            <div class="search-toolbar__chips" data-search-trending<?= $topEntities === [] ? ' hidden' : '' ?>>
                <span class="search-toolbar__label">Popular searches</span>
                <div class="search-toolbar__list" data-search-trending-list>
                    <?php foreach ($topEntities as $entityRow): ?>
                        <?php $entityName = (string) ($entityRow['entity'] ?? ''); ?>
                        <?php if ($entityName === '') { continue; } ?>
                        <button type="button" class="chip" data-entity="<?= $escape($entityName) ?>"><?= $escape($entityName) ?></button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </section>

    <section class="search-body" id="results">
        <div class="shell search-body__shell">
            <div class="search-grid">
                <aside class="search-sidebar">
                    <div class="search-sidebar__card search-sidebar__card--metrics">
                        <h2>Graph coverage</h2>
                        <p class="search-sidebar__hint">Signals currently indexed from crawls, analyst uploads, and partner feeds.</p>
                        <dl class="search-metrics" data-search-metrics<?= $hasGraph ? '' : ' hidden' ?>>
                            <div class="search-metrics__item">
                                <dt>Documents processed</dt>
                                <dd data-metric-documents><?= $documentsProcessed ?></dd>
                            </div>
                            <div class="search-metrics__item">
                                <dt>Triples extracted</dt>
                                <dd data-metric-triples><?= $triplesExtracted ?></dd>
                            </div>
                            <div class="search-metrics__item">
                                <dt>Unique entities</dt>
                                <dd data-metric-entities><?= $uniqueEntities ?></dd>
                            </div>
                            <div class="search-metrics__item">
                                <dt>Synonym groups</dt>
                                <dd data-metric-synonyms><?= $synonymGroups ?></dd>
                            </div>
                            <div class="search-metrics__item">
                                <dt>Sources tracked</dt>
                                <dd data-metric-sources><?= $sourcesTracked ?></dd>
                            </div>
                            <div class="search-metrics__item">
                                <dt>Last refreshed</dt>
                                <dd data-metric-updated><?= $updatedLabel !== null ? $escape($updatedLabel) : 'Awaiting first crawl' ?></dd>
                            </div>
                        </dl>
                        <?php if (!$hasGraph): ?>
                            <p class="search-sidebar__empty">No indexed graph yet. Run a crawl or upload a dossier to populate Autopilot insights.</p>
                        <?php else: ?>
                            <p class="search-sidebar__empty">Need deeper exploration? <a href="<?= $escape($graphPath) ?>">Open the knowledge graph</a>.</p>
                        <?php endif; ?>
                    </div>
                    <div class="search-sidebar__card">
                        <h2>Helpful references</h2>
                        <p class="search-sidebar__hint" data-search-sources-empty<?= $hasGraph ? ' hidden' : '' ?>>Run a search to surface the most cited sources for your topic.</p>
                        <ul class="reference-list" data-search-sources>
                            <?php foreach (array_slice($sources, 0, 8) as $source): ?>
                                <?php $title = isset($source['title']) && is_string($source['title']) && trim($source['title']) !== '' ? $source['title'] : ($source['url'] ?? ''); ?>
                                <?php $url = isset($source['url']) && is_string($source['url']) ? $source['url'] : ''; ?>
                                <?php $lastSeen = isset($source['last_seen']) && is_string($source['last_seen']) ? $formatDate($source['last_seen']) : null; ?>
                                <?php if ($title === '' && $url === '') { continue; } ?>
                                <li class="reference-list__item">
                                    <?php if ($url !== ''): ?>
                                        <a href="<?= $escape($url) ?>" target="_blank" rel="noopener" class="reference-list__title"><?= $escape((string) $title) ?></a>
                                    <?php else: ?>
                                        <span class="reference-list__title"><?= $escape((string) $title) ?></span>
                                    <?php endif; ?>
                                    <?php if ($lastSeen !== null): ?>
                                        <span class="reference-list__meta">Seen <?= $escape($lastSeen) ?></span>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <div class="search-sidebar__card">
                        <h2>Entity explorer</h2>
                        <p class="search-sidebar__hint" data-search-entities-empty<?= $hasGraph ? ' hidden' : '' ?>>Search to surface entity profiles with context, relations, and supporting sources.</p>
                        <div class="entity-results" data-search-entities>
                            <?php foreach (array_slice($initialSearch['entities'] ?? [], 0, 8) as $entity): ?>
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
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <aside class="search-sidebar__card entity-detail" data-entity-detail hidden>
                        <div class="entity-detail__header">
                            <h2 data-entity-name>Entity detail</h2>
                            <p class="entity-detail__score" data-entity-score></p>
                            <div class="pill-list" data-entity-synonyms></div>
                        </div>
                        <div class="entity-detail__body">
                            <div class="entity-detail__section" data-entity-facts-wrap>
                                <h3>Key facts</h3>
                                <ul class="entity-detail__facts" data-entity-facts></ul>
                            </div>
                            <div class="entity-detail__section" data-entity-relations-wrap>
                                <h3>Relation highlights</h3>
                                <ul class="entity-detail__relations" data-entity-relations></ul>
                            </div>
                        </div>
                    </aside>
                </aside>
                <div class="search-main-column">
                    <div class="status" data-search-status aria-live="polite">
                        <?= $hasGraph ? 'Ready to explore the global knowledge graph.' : 'No graph data yet. Start by analysing text or scraping a URL from the Data Studio.' ?>
                    </div>
                    <article class="autopilot-brief">
                        <header class="autopilot-brief__header">
                            <div>
                                <span class="autopilot-brief__eyebrow">Autopilot brief</span>
                                <h1>Evidence-backed summary for your query</h1>
                                <p class="autopilot-brief__lead">Submit a focus area to generate an analyst-ready narrative. Autopilot cross-references the graph and cites the most relevant sources.</p>
                            </div>
                            <button type="button" class="button ghost" data-report-refresh>Refresh with latest graph</button>
                        </header>
                        <div class="autopilot-brief__layout">
                            <div class="autopilot-brief__form">
                                <form class="report-form" data-report-form>
                                    <label class="visually-hidden" for="report-query">Brief focus</label>
                                    <textarea id="report-query" data-report-query placeholder="e.g. Autonomous vehicle safety breakthroughs" spellcheck="false"></textarea>
                                    <p class="help-text">Autopilot blends every stored analysis, scores uniqueness, and fuses overlapping narratives into one report.</p>
                                    <div class="report-actions">
                                        <button type="submit" class="button primary">Generate brief</button>
                                        <p class="status" data-report-status hidden></p>
                                    </div>
                                </form>
                            </div>
                            <div class="autopilot-brief__results" data-report-output>
                                <p class="autopilot-brief__empty" data-report-empty>Run a brief to cross-reference the latest sources, citations, and imagery.</p>
                                <div class="report-results" data-report-results hidden>
                                    <div class="report-summary" data-report-summary></div>
                                    <div class="report-topics" data-report-topics-wrapper>
                                        <h2>Key themes</h2>
                                        <ul data-report-topics></ul>
                                    </div>
                                    <div class="report-highlights" data-report-highlights></div>
                                    <div class="report-combined" data-report-combined-wrapper>
                                        <h2>Cross-referenced insights</h2>
                                        <ol data-report-combined></ol>
                                    </div>
                                    <div class="report-citations" data-report-citations-wrapper>
                                        <h2>Citations &amp; assets</h2>
                                        <ol data-report-citations></ol>
                                        <div class="report-images" data-report-images></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </article>
                    <section class="search-sections" data-search-results<?= $hasGraph ? '' : ' hidden' ?>>
                        <article class="result-block">
                            <header>
                                <h2>Relation signals</h2>
                                <p class="result-block__hint" data-search-relations-empty<?= $hasGraph ? ' hidden' : '' ?>>Relation matches will appear here once you run a search.</p>
                            </header>
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
                        <article class="result-block">
                            <header>
                                <h2>Synonym clusters</h2>
                                <p class="result-block__hint" data-search-synonyms-empty<?= $hasGraph ? ' hidden' : '' ?>>Advanced name matching highlights aliases and related spellings.</p>
                            </header>
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
                        <article class="result-block result-block--table">
                            <header>
                                <h2>Triple matches</h2>
                                <p class="result-block__hint" data-search-triples-empty<?= $hasGraph ? ' hidden' : '' ?>>Semantic triples that mention your query will appear below.</p>
                            </header>
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
                    </section>
                </div>
            </div>
        </div>
    </section>

    <section class="news-section" data-news-app data-news-endpoint="<?= $escape($newsEndpoint) ?>">
        <div class="shell">
            <header class="news-section__header">
                <div>
                    <h2>Live news intelligence</h2>
                    <p class="news-section__subtitle">Quality-ranked headlines from the crawler, searchable by entity, topic, and source credibility.</p>
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
            </header>
            <div class="news-section__meta">
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
        </div>
    </section>
</main>
<footer class="site-footer">
    <div class="shell">
        <p>Knowledge graph snapshots are stored at <code><?= $escape($repository->path()) ?></code>. Scrape additional URLs from the <a href="<?= $escape($homePath) ?>">Data Preparation Studio</a>.</p>
    </div>
</footer>
<script>window.AISearch = <?= $initialJson ?>;</script>
<script>window.AIKnowledgeGraph = <?= $autopilotJson ?>;</script>
<script src="<?= $escape($knowledgeScriptPath . '?v=' . $knowledgeScriptVersion) ?>" defer></script>
<script src="<?= $escape($scriptPath . '?v=' . $scriptVersion) ?>" defer></script>
<script src="<?= $escape($newsScriptPath . '?v=' . $newsScriptVersion) ?>" defer></script>
</body>
</html>
