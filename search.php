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
$newsStylesPath = $assetBase . '/assets/google-news.css';
$scriptPath = $assetBase . '/assets/search.js';
$apiPath = $assetBase . '/api/research.php';
$homePath = $assetBase . '/index.php';
$graphPath = $assetBase . '/knowledge-graph.php';

$stylesVersion = file_exists(__DIR__ . '/assets/styles.css') ? (string) filemtime(__DIR__ . '/assets/styles.css') : (string) time();
$newsStylesVersion = file_exists(__DIR__ . '/assets/google-news.css') ? (string) filemtime(__DIR__ . '/assets/google-news.css') : (string) time();
$scriptVersion = file_exists(__DIR__ . '/assets/search.js') ? (string) filemtime(__DIR__ . '/assets/search.js') : (string) time();

$repository = new GraphRepository();
$researcher = new GraphResearcher($repository);
$service = new ResearchService($repository);

$initialSearch = $researcher->searchGraph('', 18);
$topEntities = $service->listTopEntities(12);
$initialBrief = $service->buildResearchBrief('', 5);

$sources = isset($initialSearch['sources']) && is_array($initialSearch['sources']) ? $initialSearch['sources'] : [];
$initialHighlights = isset($initialBrief['highlights']) && is_array($initialBrief['highlights']) ? $initialBrief['highlights'] : [];
$initialCombined = isset($initialBrief['combined_summary']) && is_array($initialBrief['combined_summary']) ? $initialBrief['combined_summary'] : [];
$initialEntities = isset($initialSearch['entities']) && is_array($initialSearch['entities']) ? $initialSearch['entities'] : [];

$initialState = [
    'endpoints' => [
        'search' => $apiPath,
        'report' => $apiPath,
    ],
    'initial' => [
        'report' => $initialBrief,
        'entities' => $initialEntities,
        'top' => $topEntities,
        'sources' => $sources,
    ],
];

$initialJson = json_encode($initialState, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if (!is_string($initialJson)) {
    $initialJson = '{}';
}

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
$formatPercent = static function ($value): string {
    if (!is_numeric($value)) {
        $value = 0;
    }

    $numeric = max(0.0, (float) $value);

    return (string) round($numeric * 100) . '%';
};
$getHost = static function (?string $url): string {
    if ($url === null || trim($url) === '') {
        return '';
    }

    $host = parse_url($url, PHP_URL_HOST);
    if (!is_string($host)) {
        return '';
    }

    if (str_starts_with($host, 'www.')) {
        $host = substr($host, 4);
    }

    return $host;
};

$docCount = isset($initialBrief['document_count']) && is_numeric($initialBrief['document_count']) ? (int) $initialBrief['document_count'] : 0;
$generatedLabel = $formatDate($initialBrief['generated_at'] ?? null);

$initialBriefMetaParts = [];
$focusLabel = isset($initialBrief['query']) && is_string($initialBrief['query']) ? trim($initialBrief['query']) : '';
$initialBriefMetaParts[] = $focusLabel !== '' ? 'Focus: “' . $focusLabel . '”' : 'Focus: Latest coverage';
if ($docCount > 0) {
    $initialBriefMetaParts[] = $formatNumber($docCount) . ' sources';
}
if ($generatedLabel !== null) {
    $initialBriefMetaParts[] = 'Generated ' . $generatedLabel;
}
$initialBriefMeta = implode(' · ', array_filter($initialBriefMetaParts));

$initialResultsMetaParts = [];
if ($initialHighlights !== []) {
    $initialResultsMetaParts[] = $formatNumber(count($initialHighlights)) . ' curated highlight' . (count($initialHighlights) === 1 ? '' : 's');
}
if ($docCount > 0) {
    $initialResultsMetaParts[] = $formatNumber($docCount) . ' total sources';
} elseif ($sources !== []) {
    $initialResultsMetaParts[] = $formatNumber(count($sources)) . ' stored source' . (count($sources) === 1 ? '' : 's');
}
$initialResultsMeta = implode(' · ', array_filter($initialResultsMetaParts));

$initialStatus = 'Enter a focus area to generate a briefing.';
if ($initialHighlights !== []) {
    $initialStatus = 'Showing the latest coverage. Enter a focus area to refine the briefing.';
} elseif ($sources !== []) {
    $initialStatus = 'Showing stored sources from the knowledge graph. Enter a focus area to refine the briefing.';
}

$trendingChips = array_slice($topEntities, 0, 8);
$fallbackSources = $initialHighlights === [] ? array_slice($sources, 0, 6) : [];

$watchlistEntities = [];
foreach (array_slice($initialEntities, 0, 8) as $entity) {
    if (is_array($entity)) {
        $name = isset($entity['entity']) && is_string($entity['entity']) ? trim($entity['entity']) : '';
    } else {
        $name = is_string($entity) ? trim($entity) : '';
    }

    if ($name !== '') {
        $watchlistEntities[] = $name;
    }
}

$workspaceTemplates = [
    [
        'label' => 'LLM evaluation scorecard',
        'description' => 'Compare benchmarks, datasets, and release cadence.',
        'query' => 'foundation model evaluation frameworks',
    ],
    [
        'label' => 'Strategic partnership tracker',
        'description' => 'Surface alliances, integrations, and GTM motions.',
        'query' => 'emerging biotech partnerships',
    ],
    [
        'label' => 'Risk and regulation pulse',
        'description' => 'Follow policy hearings, compliance updates, and fines.',
        'query' => 'ai policy enforcement actions',
    ],
];

$toolbarMetrics = [
    [
        'key' => 'highlights',
        'label' => 'Curated highlights',
        'value' => $formatNumber(count($initialHighlights)),
    ],
    [
        'key' => 'sources',
        'label' => 'Sources analysed',
        'value' => $formatNumber($docCount),
    ],
    [
        'key' => 'entities',
        'label' => 'Entities referenced',
        'value' => $formatNumber(count($initialEntities)),
    ],
    [
        'key' => 'updated',
        'label' => 'Last generated',
        'value' => $generatedLabel ?? 'Not yet generated',
    ],
];

$timeFilters = [
    ['value' => '24h', 'label' => '24h'],
    ['value' => '7d', 'label' => '7d'],
    ['value' => '30d', 'label' => '30d'],
];

$signalFilters = [
    ['value' => 'all', 'label' => 'All signals'],
    ['value' => 'news', 'label' => 'News'],
    ['value' => 'filings', 'label' => 'Filings'],
    ['value' => 'social', 'label' => 'Community'],
];

$sentimentFilters = [
    ['value' => 'any', 'label' => 'Any tone'],
    ['value' => 'positive', 'label' => 'Positive'],
    ['value' => 'neutral', 'label' => 'Neutral'],
    ['value' => 'negative', 'label' => 'Caution'],
];

$workspaceActions = [
    [
        'label' => 'Export briefing',
        'description' => 'Download slides with citations and sources.',
        'action' => 'export',
    ],
    [
        'label' => 'Share with stakeholders',
        'description' => 'Send a live link or schedule recurring updates.',
        'action' => 'share',
    ],
    [
        'label' => 'Launch graph explorer',
        'description' => 'Pivot across relationships inside the knowledge graph.',
        'href' => $graphPath,
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>News Search &ndash; AIresearch</title>
    <link rel="stylesheet" href="<?= $escape($stylesPath . '?v=' . $stylesVersion) ?>">
    <link rel="stylesheet" href="<?= $escape($newsStylesPath . '?v=' . $newsStylesVersion) ?>">
</head>
<body class="search-page search-page--news">
<header class="site-header site-header--compact">
    <div class="shell header-shell header-shell--compact">
        <a class="brand" href="<?= $escape($homePath) ?>">AIresearch</a>
        <a class="primary-nav__link" href="<?= $escape($graphPath) ?>">Knowledge graph</a>
    </div>
</header>
<main class="news-search">
    <div class="news-search__shell">
        <section class="news-search__hero">
            <div class="news-search__hero-grid">
                <div class="news-search__hero-main">
                    <h1 class="news-search__title">Explore the knowledge graph like a news desk</h1>
                    <p class="news-search__lead">Type a topic to surface a living briefing and the most relevant sources without extra clicks.</p>
                    <form class="news-search__form" data-search-form role="search">
                        <label class="visually-hidden" for="news-query">Search focus</label>
                        <input id="news-query" name="q" type="search" placeholder="Search companies, topics, or emerging themes" autocomplete="off" spellcheck="false" data-search-input>
                    </form>
                    <div class="news-search__filters" data-filter-panel>
                        <div class="news-filter" data-filter-group="timeframe">
                            <span class="news-filter__label">Time window</span>
                            <div class="news-filter__options">
                                <?php foreach ($timeFilters as $filter): ?>
                                    <?php $isDefault = $filter['value'] === '7d'; ?>
                                    <button type="button" class="news-filter__chip<?= $isDefault ? ' is-active' : '' ?>" data-filter-value="<?= $escape($filter['value']) ?>"<?= $isDefault ? ' data-filter-default' : '' ?>><?= $escape($filter['label']) ?></button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="news-filter" data-filter-group="signal">
                            <span class="news-filter__label">Signal type</span>
                            <div class="news-filter__options">
                                <?php foreach ($signalFilters as $filter): ?>
                                    <?php $isDefault = $filter['value'] === 'all'; ?>
                                    <button type="button" class="news-filter__chip<?= $isDefault ? ' is-active' : '' ?>" data-filter-value="<?= $escape($filter['value']) ?>"<?= $isDefault ? ' data-filter-default' : '' ?>><?= $escape($filter['label']) ?></button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="news-filter" data-filter-group="sentiment">
                            <span class="news-filter__label">Sentiment</span>
                            <div class="news-filter__options">
                                <?php foreach ($sentimentFilters as $filter): ?>
                                    <?php $isDefault = $filter['value'] === 'any'; ?>
                                    <button type="button" class="news-filter__chip<?= $isDefault ? ' is-active' : '' ?>" data-filter-value="<?= $escape($filter['value']) ?>"<?= $isDefault ? ' data-filter-default' : '' ?>><?= $escape($filter['label']) ?></button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <p class="news-search__filters-note" data-filter-summary>Filtering last 7d · All signals · Any tone</p>
                    <p class="news-search__status news-search__status--info" data-search-status aria-live="polite"><?= $escape($initialStatus) ?></p>
                    <div class="news-search__trending" data-trending<?= $trendingChips === [] ? ' hidden' : '' ?>>
                        <span class="news-search__trending-label">Popular topics</span>
                        <div class="news-search__trending-list" data-trending-list>
                            <?php foreach ($trendingChips as $chip): ?>
                                <?php $chipName = isset($chip['entity']) ? (string) $chip['entity'] : ''; ?>
                                <?php if ($chipName === '') { continue; } ?>
                                <button type="button" class="news-search__chip" data-entity="<?= $escape($chipName) ?>"><?= $escape($chipName) ?></button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="news-search__templates">
                        <span class="news-search__templates-label">Research starters</span>
                        <div class="news-search__templates-grid">
                            <?php foreach ($workspaceTemplates as $template): ?>
                                <button type="button" class="news-template" data-search-template="<?= $escape($template['query']) ?>">
                                    <span class="news-template__title"><?= $escape($template['label']) ?></span>
                                    <span class="news-template__text"><?= $escape($template['description']) ?></span>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <aside class="news-search__hero-aside">
                    <div class="news-toolbar" data-toolbar>
                        <?php foreach ($toolbarMetrics as $metric): ?>
                            <div class="news-toolbar__metric">
                                <span class="news-toolbar__value" data-metric="<?= $escape($metric['key']) ?>"><?= $escape($metric['value']) ?></span>
                                <span class="news-toolbar__label"><?= $escape($metric['label']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="news-search__watchlist">
                        <h3>Active watchlist</h3>
                        <ul class="news-search__watchlist-list" data-watchlist-list>
                            <?php foreach ($watchlistEntities as $entity): ?>
                                <li><button type="button" data-search-template="<?= $escape($entity) ?>"><?= $escape($entity) ?></button></li>
                            <?php endforeach; ?>
                        </ul>
                        <?php if ($watchlistEntities === []): ?>
                            <p class="news-search__watchlist-empty">Run a search to seed your watchlist.</p>
                        <?php else: ?>
                            <p class="news-search__watchlist-hint">Tap an entity to refocus the briefing instantly.</p>
                        <?php endif; ?>
                    </div>
                </aside>
            </div>
        </section>

        <div class="news-search__layout">
        <div class="news-search__main">
        <section class="news-brief<?= $initialCombined === [] ? ' is-empty' : '' ?>" data-brief>
            <header class="news-brief__header">
                <h2 class="news-brief__title">Focus brief</h2>
                <p class="news-brief__meta" data-brief-meta><?= $escape($initialBriefMeta) ?></p>
            </header>
            <ul class="news-brief__list" data-brief-list>
                <?php foreach (array_slice($initialCombined, 0, 4) as $entry): ?>
                    <?php
                        if (!is_array($entry)) {
                            continue;
                        }
                        $answer = isset($entry['answer']) && is_string($entry['answer']) ? trim($entry['answer']) : '';
                        if ($answer === '') {
                            continue;
                        }
                        $question = isset($entry['question']) && is_string($entry['question']) ? trim($entry['question']) : '';
                        $source = isset($entry['source']) && is_array($entry['source']) ? $entry['source'] : [];
                        $sourceTitle = isset($source['title']) && is_string($source['title']) ? trim($source['title']) : '';
                        $sourceUrl = isset($source['url']) && is_string($source['url']) ? trim($source['url']) : '';
                        $sourceLabel = $sourceTitle !== '' ? $sourceTitle : $getHost($sourceUrl);
                    ?>
                    <li class="news-brief__item">
                        <?php if ($question !== ''): ?>
                            <p class="news-brief__item-title"><?= $escape($question) ?></p>
                        <?php endif; ?>
                        <p class="news-brief__item-text"><?= $escape($answer) ?></p>
                        <?php if ($sourceLabel !== ''): ?>
                            <p class="news-brief__item-source">Source:
                                <?php if ($sourceUrl !== ''): ?>
                                    <a href="<?= $escape($sourceUrl) ?>" target="_blank" rel="noopener"><?= $escape($sourceLabel) ?></a>
                                <?php else: ?>
                                    <?= $escape($sourceLabel) ?>
                                <?php endif; ?>
                            </p>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p class="news-brief__empty" data-brief-empty>Start typing to generate a tailored briefing with citations.</p>
        </section>

        <?php $resultsClass = 'news-results' . (($initialHighlights === [] && $fallbackSources === []) ? ' is-empty' : ''); ?>
        <section class="<?= $escape($resultsClass) ?>" data-results-section>
            <header class="news-results__header">
                <h2 class="news-results__title">Latest coverage</h2>
                <p class="news-results__meta" data-results-meta><?= $escape($initialResultsMeta) ?></p>
            </header>
            <p class="news-results__empty" data-results-empty>No stored coverage yet. Run a crawl or upload sources to populate this briefing.</p>
            <div class="news-results__list" data-results>
                <?php if ($initialHighlights !== []): ?>
                    <?php foreach (array_slice($initialHighlights, 0, 6) as $highlight): ?>
                        <?php
                            if (!is_array($highlight)) {
                                continue;
                            }
                            $title = isset($highlight['title']) && is_string($highlight['title']) ? trim($highlight['title']) : '';
                            $summaryText = isset($highlight['summary']) && is_string($highlight['summary']) ? trim($highlight['summary']) : '';
                            $relevance = $formatPercent($highlight['relevance'] ?? 0);
                            $uniqueness = $formatPercent($highlight['uniqueness'] ?? 0);
                            $keywords = isset($highlight['keywords']) && is_array($highlight['keywords']) ? array_slice(array_filter(array_map('strval', $highlight['keywords'])), 0, 6) : [];
                            $source = isset($highlight['source']) && is_array($highlight['source']) ? $highlight['source'] : [];
                            $sourceTitle = isset($source['title']) && is_string($source['title']) ? trim($source['title']) : '';
                            $sourceUrl = isset($source['url']) && is_string($source['url']) ? trim($source['url']) : '';
                            $sourceHost = $getHost($sourceUrl);
                            $sourceLabel = $sourceTitle !== '' ? $sourceTitle : ($sourceHost !== '' ? $sourceHost : 'Source');
                            $fetchedLabel = $formatDate($source['fetched_at'] ?? null);
                        ?>
                        <article class="news-card">
                            <div class="news-card__header">
                                <span class="news-card__source"><?= $escape($sourceLabel) ?><?= $fetchedLabel !== null ? ' · ' . $escape($fetchedLabel) : '' ?></span>
                                <h3 class="news-card__title">
                                    <?php if ($sourceUrl !== ''): ?>
                                        <a href="<?= $escape($sourceUrl) ?>" target="_blank" rel="noopener"><?= $escape($title !== '' ? $title : $sourceLabel) ?></a>
                                    <?php else: ?>
                                        <?= $escape($title !== '' ? $title : $sourceLabel) ?>
                                    <?php endif; ?>
                                </h3>
                                <span class="news-card__metrics">Relevance <?= $escape($relevance) ?> · Uniqueness <?= $escape($uniqueness) ?></span>
                            </div>
                            <?php if ($summaryText !== ''): ?>
                                <p class="news-card__summary"><?= $escape($summaryText) ?></p>
                            <?php endif; ?>
                            <?php if ($keywords !== []): ?>
                                <div class="news-card__keywords">
                                    <?php foreach ($keywords as $keyword): ?>
                                        <span><?= $escape($keyword) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                <?php elseif ($fallbackSources !== []): ?>
                    <?php foreach ($fallbackSources as $source): ?>
                        <?php
                            if (!is_array($source)) {
                                continue;
                            }
                            $title = isset($source['title']) && is_string($source['title']) ? trim($source['title']) : '';
                            $url = isset($source['url']) && is_string($source['url']) ? trim($source['url']) : '';
                            $preview = isset($source['preview']) && is_string($source['preview']) ? trim($source['preview']) : '';
                            $summaryText = isset($source['summary']) && is_string($source['summary']) ? trim($source['summary']) : '';
                            $snippet = $summaryText !== '' ? $summaryText : $preview;
                            $keywords = isset($source['keywords']) && is_array($source['keywords']) ? array_slice(array_filter(array_map('strval', $source['keywords'])), 0, 6) : [];
                            $lastSeen = $formatDate($source['last_seen'] ?? ($source['fetched_at'] ?? null));
                            $host = $getHost($url);
                        ?>
                        <article class="news-card news-card--source">
                            <div class="news-card__header">
                                <span class="news-card__source"><?= $escape($host !== '' ? $host : 'Stored source') ?><?= $lastSeen !== null ? ' · ' . $escape($lastSeen) : '' ?></span>
                                <h3 class="news-card__title">
                                    <?php if ($url !== ''): ?>
                                        <a href="<?= $escape($url) ?>" target="_blank" rel="noopener"><?= $escape($title !== '' ? $title : ($host !== '' ? $host : 'Source')) ?></a>
                                    <?php else: ?>
                                        <?= $escape($title !== '' ? $title : 'Source') ?>
                                    <?php endif; ?>
                                </h3>
                                <?php if ($snippet !== ''): ?>
                                    <span class="news-card__metrics">Snapshot from the knowledge graph</span>
                                <?php endif; ?>
                            </div>
                            <?php if ($snippet !== ''): ?>
                                <p class="news-card__summary"><?= $escape($snippet) ?></p>
                            <?php endif; ?>
                            <?php if ($keywords !== []): ?>
                                <div class="news-card__keywords">
                                    <?php foreach ($keywords as $keyword): ?>
                                        <span><?= $escape($keyword) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>
        </div>
        <aside class="news-search__aside">
            <section class="news-sidecard">
                <h3>Workspace actions</h3>
                <ul class="news-sidecard__list">
                    <?php foreach ($workspaceActions as $action): ?>
                        <li>
                            <?php if (isset($action['href'])): ?>
                                <a class="news-sidecard__button" href="<?= $escape($action['href']) ?>">
                                    <span class="news-sidecard__title"><?= $escape($action['label']) ?></span>
                                    <span class="news-sidecard__text"><?= $escape($action['description']) ?></span>
                                </a>
                            <?php else: ?>
                                <button type="button" class="news-sidecard__button" data-workspace-action="<?= $escape($action['action'] ?? '') ?>">
                                    <span class="news-sidecard__title"><?= $escape($action['label']) ?></span>
                                    <span class="news-sidecard__text"><?= $escape($action['description']) ?></span>
                                </button>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
            <section class="news-sidecard">
                <h3>Research starters</h3>
                <ul class="news-sidecard__template-list">
                    <?php foreach ($workspaceTemplates as $template): ?>
                        <li>
                            <button type="button" data-search-template="<?= $escape($template['query']) ?>">
                                <span class="news-sidecard__title"><?= $escape($template['label']) ?></span>
                                <span class="news-sidecard__text"><?= $escape($template['description']) ?></span>
                            </button>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
            <section class="news-sidecard">
                <h3>Latest stored sources</h3>
                <ul class="news-sidecard__sources" data-source-list>
                    <?php foreach (array_slice($fallbackSources, 0, 5) as $source): ?>
                        <?php
                            if (!is_array($source)) {
                                continue;
                            }
                            $title = isset($source['title']) && is_string($source['title']) ? trim($source['title']) : '';
                            $url = isset($source['url']) && is_string($source['url']) ? trim($source['url']) : '';
                            $host = $getHost($url);
                            $label = $title !== '' ? $title : ($host !== '' ? $host : 'Source');
                            $lastSeen = $formatDate($source['last_seen'] ?? ($source['fetched_at'] ?? null));
                        ?>
                        <li>
                            <span class="news-sidecard__source-title" title="<?= $escape($label) ?>"><?= $escape($label) ?></span>
                            <?php if ($lastSeen !== null): ?>
                                <span class="news-sidecard__source-meta"><?= $escape($lastSeen) ?></span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        </aside>
        </div>
    </div>
</main>
<footer class="site-footer">
    <div class="shell">
        <p>Knowledge graph snapshots are stored at <code><?= $escape($repository->path()) ?></code>. Add new URLs or uploads from the <a href="<?= $escape($homePath) ?>">Data Preparation Studio</a>.</p>
    </div>
</footer>
<script>window.AISearch = <?= $initialJson ?>;</script>
<script src="<?= $escape($scriptPath . '?v=' . $scriptVersion) ?>" defer></script>
</body>
</html>
