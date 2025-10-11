<?php

declare(strict_types=1);

require __DIR__ . '/src/App/bootstrap.php';

use App\KnowledgeGraph\GraphRepository;
use App\KnowledgeGraph\GraphResearcher;
use App\KnowledgeGraph\ResearchService;
use App\Web\PathResolver;

$paths = PathResolver::resolve();
$basePath = $paths['basePath'];
$assetBase = $paths['assetBase'];

$stylesPath = PathResolver::url($assetBase, 'assets/styles.css');
$newsStylesPath = PathResolver::url($assetBase, 'assets/google-news.css');
$scriptPath = PathResolver::url($assetBase, 'assets/search.js');
$apiPath = PathResolver::url($assetBase, 'api/research.php');
$homePath = PathResolver::url($assetBase, 'index.php');
$graphPath = PathResolver::url($assetBase, 'knowledge-graph.php');

$stylesVersion = file_exists(__DIR__ . '/assets/styles.css') ? (string) filemtime(__DIR__ . '/assets/styles.css') : (string) time();
$newsStylesVersion = file_exists(__DIR__ . '/assets/google-news.css') ? (string) filemtime(__DIR__ . '/assets/google-news.css') : (string) time();
$scriptVersion = file_exists(__DIR__ . '/assets/search.js') ? (string) filemtime(__DIR__ . '/assets/search.js') : (string) time();

$repository = new GraphRepository();
$researcher = new GraphResearcher($repository);
$service = new ResearchService($repository);

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

$initialInsight = $service->buildInsightDocument('', 6);
$initialReport = isset($initialInsight['report']) && is_array($initialInsight['report'])
    ? $initialInsight['report']
    : $service->buildResearchBrief('', 5);
$initialSearch = isset($initialInsight['search']) && is_array($initialInsight['search'])
    ? $initialInsight['search']
    : $researcher->searchGraph('', 18);
$topEntities = $service->listTopEntities(12);
$trendingChips = array_slice($topEntities, 0, 8);

$initialEntities = isset($initialSearch['entities']) && is_array($initialSearch['entities']) ? $initialSearch['entities'] : [];
$initialHighlights = isset($initialReport['highlights']) && is_array($initialReport['highlights']) ? $initialReport['highlights'] : [];
$initialCombined = isset($initialReport['combined_summary']) && is_array($initialReport['combined_summary']) ? $initialReport['combined_summary'] : [];

$initialDocument = isset($initialInsight['document']) && is_array($initialInsight['document']) ? $initialInsight['document'] : [];
$initialDocumentTitle = isset($initialDocument['title']) && is_string($initialDocument['title']) ? trim($initialDocument['title']) : 'Insight briefing';
$initialSections = isset($initialDocument['sections']) && is_array($initialDocument['sections']) ? $initialDocument['sections'] : [];
$initialInsightEntities = isset($initialInsight['entities']) && is_array($initialInsight['entities']) ? $initialInsight['entities'] : [];
$initialReferences = isset($initialInsight['references']) && is_array($initialInsight['references']) ? $initialInsight['references'] : [];

$sources = isset($initialReferences['sources']) && is_array($initialReferences['sources'])
    ? $initialReferences['sources']
    : (isset($initialSearch['sources']) && is_array($initialSearch['sources']) ? $initialSearch['sources'] : []);

$initialInsightEntitiesList = array_slice($initialInsightEntities, 0, 6);
$referenceItems = [];
$referenceSeen = [];

if (isset($initialReferences['citations']) && is_array($initialReferences['citations'])) {
    foreach ($initialReferences['citations'] as $citation) {
        if (!is_array($citation)) {
            continue;
        }

        $url = isset($citation['url']) && is_string($citation['url']) ? trim($citation['url']) : '';
        $id = isset($citation['id']) && is_string($citation['id']) ? trim($citation['id']) : '';
        $key = $url !== '' ? $url : ($id !== '' ? 'citation:' . $id : null);
        if ($key !== null) {
            if (isset($referenceSeen[$key])) {
                continue;
            }
            $referenceSeen[$key] = true;
        }

        $label = isset($citation['title']) && is_string($citation['title']) ? trim($citation['title']) : ($url !== '' ? $getHost($url) : 'Citation');
        $referenceItems[] = [
            'type' => 'citation',
            'label' => $label,
            'url' => $url,
            'id' => $id,
            'preview' => isset($citation['preview']) && is_string($citation['preview']) ? trim($citation['preview']) : '',
            'fetched_at' => isset($citation['fetched_at']) && is_string($citation['fetched_at']) ? trim($citation['fetched_at']) : '',
        ];
    }
}

if (isset($initialReferences['sources']) && is_array($initialReferences['sources'])) {
    foreach ($initialReferences['sources'] as $source) {
        if (!is_array($source)) {
            continue;
        }

        $url = isset($source['url']) && is_string($source['url']) ? trim($source['url']) : '';
        $key = $url !== '' ? $url : (isset($source['title']) && is_string($source['title']) ? 'source:' . trim($source['title']) : null);
        if ($key !== null) {
            if (isset($referenceSeen[$key])) {
                continue;
            }
            $referenceSeen[$key] = true;
        }

        $label = isset($source['title']) && is_string($source['title']) && trim($source['title']) !== '' ? trim($source['title']) : ($url !== '' ? $getHost($url) : 'Source');
        $preview = '';
        if (isset($source['summary']) && is_string($source['summary'])) {
            $preview = trim($source['summary']);
        } elseif (isset($source['preview']) && is_string($source['preview'])) {
            $preview = trim($source['preview']);
        }

        $referenceItems[] = [
            'type' => 'source',
            'label' => $label,
            'url' => $url,
            'preview' => $preview,
            'fetched_at' => isset($source['last_seen']) && is_string($source['last_seen']) ? trim($source['last_seen']) : (isset($source['fetched_at']) && is_string($source['fetched_at']) ? trim($source['fetched_at']) : ''),
        ];
    }
}

$initialInsightReferencesList = array_slice($referenceItems, 0, 10);

$renderSections = [];
foreach ($initialSections as $section) {
    if (!is_array($section)) {
        continue;
    }

    $sectionHeading = isset($section['heading']) && is_string($section['heading']) ? trim($section['heading']) : '';
    $sectionType = isset($section['type']) && is_string($section['type']) ? trim($section['type']) : 'bullets';
    $sectionItems = isset($section['items']) && is_array($section['items']) ? $section['items'] : [];
    $items = [];

    if ($sectionType === 'topics') {
        foreach ($sectionItems as $topic) {
            if (!is_array($topic)) {
                continue;
            }

            $label = isset($topic['label']) && is_string($topic['label']) ? trim($topic['label']) : '';
            if ($label === '') {
                continue;
            }

            $count = isset($topic['count']) ? (int) $topic['count'] : 0;
            $citations = [];
            if (isset($topic['citations']) && is_array($topic['citations'])) {
                $citations = array_slice(
                    array_values(array_filter(
                        array_map('strval', $topic['citations']),
                        static fn(string $value): bool => trim($value) !== ''
                    )),
                    0,
                    6
                );
            }

            $items[] = [
                'label' => $label,
                'count' => $count,
                'citations' => $citations,
            ];
        }
    } else {
        foreach ($sectionItems as $item) {
            if (!is_array($item)) {
                continue;
            }

            $text = isset($item['text']) && is_string($item['text']) ? trim($item['text']) : '';
            if ($text === '') {
                continue;
            }

            $citation = isset($item['citation']) && is_string($item['citation']) ? trim($item['citation']) : '';
            $items[] = ['text' => $text, 'citation' => $citation];
        }
    }

    if ($items === []) {
        continue;
    }

    $renderSections[] = [
        'heading' => $sectionHeading,
        'type' => $sectionType,
        'items' => $items,
    ];
}

$renderEntities = [];
foreach ($initialInsightEntitiesList as $entity) {
    if (!is_array($entity)) {
        continue;
    }

    $name = isset($entity['entity']) && is_string($entity['entity']) ? trim($entity['entity']) : '';
    if ($name === '') {
        continue;
    }

    $summary = isset($entity['summary']) && is_string($entity['summary']) ? trim($entity['summary']) : '';
    $synonyms = isset($entity['synonyms']) && is_array($entity['synonyms'])
        ? array_slice(array_values(array_filter(array_map('strval', $entity['synonyms']), static fn(string $value): bool => trim($value) !== '')), 0, 3)
        : [];
    $facts = isset($entity['facts']) && is_array($entity['facts'])
        ? array_slice(array_values(array_filter(array_map('strval', $entity['facts']), static fn(string $value): bool => trim($value) !== '')), 0, 3)
        : [];

    $renderEntities[] = [
        'name' => $name,
        'summary' => $summary,
        'synonyms' => $synonyms,
        'facts' => $facts,
    ];
}

$renderReferences = [];
foreach ($initialInsightReferencesList as $reference) {
    if (!is_array($reference)) {
        continue;
    }

    $renderReferences[] = [
        'label' => isset($reference['label']) ? trim((string) $reference['label']) : '',
        'url' => isset($reference['url']) ? trim((string) $reference['url']) : '',
        'type' => isset($reference['type']) ? trim((string) $reference['type']) : '',
        'id' => isset($reference['id']) ? trim((string) $reference['id']) : '',
        'preview' => isset($reference['preview']) ? trim((string) $reference['preview']) : '',
        'fetched_at' => isset($reference['fetched_at']) ? trim((string) $reference['fetched_at']) : '',
    ];
}

$docCount = isset($initialReport['document_count']) && is_numeric($initialReport['document_count']) ? (int) $initialReport['document_count'] : 0;
$generatedLabel = $formatDate($initialReport['generated_at'] ?? null);
$insightGeneratedAt = isset($initialInsight['generated_at']) && is_string($initialInsight['generated_at']) ? $initialInsight['generated_at'] : ($initialReport['generated_at'] ?? null);
$insightGeneratedLabel = $formatDate($insightGeneratedAt);

$initialInsightMetaParts = [];
$focusLabel = isset($initialInsight['query']) && is_string($initialInsight['query']) ? trim($initialInsight['query']) : '';
$initialInsightMetaParts[] = $focusLabel !== '' ? 'Focus: “' . $focusLabel . '”' : 'Focus: Latest coverage';
if ($docCount > 0) {
    $initialInsightMetaParts[] = $formatNumber($docCount) . ' sources';
}
if ($insightGeneratedLabel !== null) {
    $initialInsightMetaParts[] = 'Generated ' . $insightGeneratedLabel;
}
$initialInsightMeta = implode(' · ', array_filter($initialInsightMetaParts));

$initialBriefMetaParts = [];
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

$initialInsightHasSections = $renderSections !== [];
$initialInsightIsEmpty = !$initialInsightHasSections && $renderEntities === [] && $renderReferences === [];
$initialInsightClass = 'news-insight' . ($initialInsightIsEmpty ? ' is-empty' : '');

$initialStatus = 'Enter a focus area to generate an insight briefing.';
if ($initialHighlights !== []) {
    $initialStatus = 'Showing the latest insight briefing. Enter a focus area to refine it.';
} elseif ($sources !== []) {
    $initialStatus = 'Showing stored sources from the knowledge graph. Enter a focus area to generate a briefing.';
}

$statusCardHeading = ($docCount > 0 || $initialHighlights !== [])
    ? 'Insight workspace is ready'
    : 'Prep your first insight briefing';
$statusCardDescription = $initialHighlights !== []
    ? 'Keep refining the focus to surface fresh intelligence across your monitored sources.'
    : 'Choose a starter template or enter a focus area to generate a personalised insight briefing.';

$primaryEntity = '';
if ($renderEntities !== []) {
    $firstEntity = $renderEntities[0]['name'] ?? '';
    if (is_string($firstEntity)) {
        $primaryEntity = trim($firstEntity);
    }
}
if ($primaryEntity === '' && $trendingChips !== []) {
    $firstChip = $trendingChips[0]['entity'] ?? '';
    if (is_string($firstChip)) {
        $primaryEntity = trim($firstChip);
    }
}

$insightSummaryPoints = [];
if ($focusLabel !== '') {
    $insightSummaryPoints[] = 'Focus area: “' . $focusLabel . '”';
}
if ($docCount > 0) {
    $insightSummaryPoints[] = $formatNumber($docCount) . ' sources analysed';
} elseif ($sources !== []) {
    $insightSummaryPoints[] = $formatNumber(count($sources)) . ' stored source' . (count($sources) === 1 ? '' : 's');
}
if ($initialHighlights !== []) {
    $insightSummaryPoints[] = $formatNumber(count($initialHighlights)) . ' curated highlight' . (count($initialHighlights) === 1 ? '' : 's');
}
if ($primaryEntity !== '') {
    $insightSummaryPoints[] = 'Top entity: ' . $primaryEntity;
}
if ($generatedLabel !== null) {
    $insightSummaryPoints[] = 'Brief generated ' . $generatedLabel;
} elseif ($insightGeneratedLabel !== null) {
    $insightSummaryPoints[] = 'Insight generated ' . $insightGeneratedLabel;
}

$insightSummaryPoints = array_values(array_filter(
    array_unique(array_map('trim', $insightSummaryPoints)),
    static fn(string $value): bool => $value !== ''
));
if (count($insightSummaryPoints) > 4) {
    $insightSummaryPoints = array_slice($insightSummaryPoints, 0, 4);
}
if ($insightSummaryPoints === []) {
    $insightSummaryPoints = [
        'Use popular topics or watchlist entities to jump into a curated insight.',
        'Upload documents or add URLs to expand the knowledge graph coverage.',
    ];
}

$initialState = [
    'endpoints' => [
        'insight' => $apiPath,
        'search' => $apiPath,
        'report' => $apiPath,
    ],
    'initial' => [
        'insight' => $initialInsight,
        'report' => $initialReport,
        'search' => $initialSearch,
        'entities' => $initialEntities,
        'top' => $topEntities,
        'sources' => $sources,
    ],
];

$initialJson = json_encode($initialState, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if (!is_string($initialJson)) {
    $initialJson = '{}';
}

$fallbackLimit = $initialHighlights === [] ? 6 : 3;
$fallbackSources = array_slice($sources, 0, $fallbackLimit);

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
                    <p class="news-search__eyebrow">Autopilot workspace</p>
                    <h1 class="news-search__title">Real-time news intelligence</h1>
                    <p class="news-search__lead">Blend live coverage with the shared knowledge graph to brief stakeholders in seconds.</p>
                    <div class="news-search__controls">
                        <form class="news-search__form" data-search-form role="search">
                            <label class="visually-hidden" for="news-query">Search focus</label>
                            <input id="news-query" name="q" type="search" placeholder="Search companies, topics, or emerging themes" autocomplete="off" spellcheck="false" data-search-input>
                            <button type="submit" class="news-search__submit">Search</button>
                        </form>
                        <div class="news-search__filters-bar">
                            <p class="news-search__filters-note" data-filter-summary>Filtering last 7d · All signals · Any tone</p>
                            <button type="button" class="news-search__filters-toggle" data-filter-toggle aria-expanded="false" aria-controls="news-search-filters">Refine filters</button>
                        </div>
                    </div>
                    <div class="news-search__filters" id="news-search-filters" data-filter-panel hidden>
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
                    <p class="news-search__status news-search__status--info" data-search-status aria-live="polite"><?= $escape($initialStatus) ?></p>
                    <?php if ($insightSummaryPoints !== []): ?>
                        <ul class="news-search__summary" data-status-summary>
                            <?php foreach ($insightSummaryPoints as $point): ?>
                                <li><?= $escape($point) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <div class="news-search__layout">
        <div class="news-search__main">
        <section class="<?= $escape($initialInsightClass) ?>" data-insight>
            <header class="news-insight__header">
                <h2 class="news-insight__title" data-insight-title><?= $escape($initialDocumentTitle) ?></h2>
                <p class="news-insight__meta" data-insight-meta><?= $escape($initialInsightMeta) ?></p>
            </header>
            <div class="news-insight__document" data-insight-body>
                <?php foreach ($renderSections as $section): ?>
                    <?php
                        $sectionHeading = isset($section['heading']) ? (string) $section['heading'] : '';
                        $sectionType = isset($section['type']) ? (string) $section['type'] : 'bullets';
                        $items = isset($section['items']) && is_array($section['items']) ? $section['items'] : [];
                        $sectionClass = 'news-insight__section';
                        if ($sectionType !== '') {
                            $sectionClass .= ' news-insight__section--' . preg_replace('/[^a-z0-9_-]+/i', '', $sectionType);
                        }
                    ?>
                    <section class="<?= $escape($sectionClass) ?>">
                        <?php if ($sectionHeading !== ''): ?>
                            <h3><?= $escape($sectionHeading) ?></h3>
                        <?php endif; ?>
                        <?php if ($sectionType === 'topics'): ?>
                            <ul class="news-insight__topics">
                                <?php foreach ($items as $topic): ?>
                                    <?php
                                        $label = isset($topic['label']) ? (string) $topic['label'] : '';
                                        $count = isset($topic['count']) ? (int) $topic['count'] : 0;
                                        $citations = isset($topic['citations']) && is_array($topic['citations']) ? $topic['citations'] : [];
                                    ?>
                                    <li>
                                        <span class="news-insight__topic-label"><?= $escape($label) ?></span>
                                        <?php if ($count > 0): ?>
                                            <span class="news-insight__topic-count"><?= $escape($formatNumber($count)) ?> mention<?= $count === 1 ? '' : 's' ?></span>
                                        <?php endif; ?>
                                        <?php if ($citations !== []): ?>
                                            <span class="news-insight__topic-citations">Citations: <?= $escape(implode(', ', $citations)) ?></span>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <ul class="news-insight__bullet-list">
                                <?php foreach ($items as $item): ?>
                                    <?php
                                        $text = isset($item['text']) ? (string) $item['text'] : '';
                                        $citation = isset($item['citation']) ? (string) $item['citation'] : '';
                                    ?>
                                    <li>
                                        <?= $escape($text) ?>
                                        <?php if ($citation !== ''): ?>
                                            <span class="news-insight__citation">(<?= $escape($citation) ?>)</span>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </section>
                <?php endforeach; ?>
            </div>
            <p class="news-insight__empty" data-insight-empty<?= $initialInsightIsEmpty ? '' : ' hidden' ?>>Start typing to generate an insight brief that blends multiple sources with the knowledge graph.</p>
            <div class="news-insight__columns">
                <div class="news-insight__column">
                    <h3 class="news-insight__column-title">Key entities</h3>
                    <ul class="news-insight__entity-list" data-insight-entities>
                        <?php foreach ($renderEntities as $entity): ?>
                            <?php
                                $entityName = $entity['name'] ?? '';
                                $entitySummary = $entity['summary'] ?? '';
                                $entitySynonyms = isset($entity['synonyms']) && is_array($entity['synonyms']) ? $entity['synonyms'] : [];
                                $entityFacts = isset($entity['facts']) && is_array($entity['facts']) ? $entity['facts'] : [];
                            ?>
                            <li>
                                <p class="news-insight__entity-name"><?= $escape($entityName) ?></p>
                                <?php if ($entitySummary !== ''): ?>
                                    <p class="news-insight__entity-summary"><?= $escape($entitySummary) ?></p>
                                <?php endif; ?>
                                <?php if ($entitySynonyms !== []): ?>
                                    <p class="news-insight__entity-synonyms">Also known as <?= $escape(implode(', ', $entitySynonyms)) ?></p>
                                <?php endif; ?>
                                <?php if ($entityFacts !== []): ?>
                                    <ul class="news-insight__fact-list">
                                        <?php foreach ($entityFacts as $fact): ?>
                                            <li><?= $escape($fact) ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="news-insight__column-empty" data-insight-entities-empty<?= $renderEntities !== [] ? ' hidden' : '' ?>>No entities matched yet. Enter a focus area to populate this list.</p>
                </div>
                <div class="news-insight__column">
                    <h3 class="news-insight__column-title">References &amp; sources</h3>
                    <ul class="news-insight__reference-list" data-insight-references>
                        <?php foreach ($renderReferences as $reference): ?>
                            <?php
                                $referenceLabel = $reference['label'] ?? '';
                                $referenceUrl = $reference['url'] ?? '';
                                $referenceType = $reference['type'] ?? '';
                                $referenceId = $reference['id'] ?? '';
                                $referencePreview = $reference['preview'] ?? '';
                                $referenceFetched = $reference['fetched_at'] ?? '';
                                $referenceMetaParts = [];
                                if ($referenceType === 'citation' && $referenceId !== '') {
                                    $referenceMetaParts[] = 'Citation ' . $referenceId;
                                } elseif ($referenceType === 'source') {
                                    $referenceMetaParts[] = 'Stored source';
                                }
                                $formattedFetched = $referenceFetched !== '' ? $formatDate($referenceFetched) : null;
                                if ($formattedFetched !== null) {
                                    $referenceMetaParts[] = $formattedFetched;
                                }
                                $referenceLabelOutput = $referenceLabel !== '' ? $referenceLabel : ($referenceType === 'citation' ? 'Citation' : 'Source');
                            ?>
                            <li>
                                <div class="news-insight__reference-title">
                                    <?php if ($referenceUrl !== ''): ?>
                                        <a href="<?= $escape($referenceUrl) ?>" target="_blank" rel="noopener"><?= $escape($referenceLabelOutput !== '' ? $referenceLabelOutput : $getHost($referenceUrl)) ?></a>
                                    <?php else: ?>
                                        <?= $escape($referenceLabelOutput) ?>
                                    <?php endif; ?>
                                </div>
                                <?php if ($referenceMetaParts !== []): ?>
                                    <span class="news-insight__reference-meta"><?= $escape(implode(' · ', $referenceMetaParts)) ?></span>
                                <?php endif; ?>
                                <?php if ($referencePreview !== ''): ?>
                                    <p class="news-insight__reference-preview"><?= $escape($referencePreview) ?></p>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="news-insight__column-empty" data-insight-references-empty<?= $renderReferences !== [] ? ' hidden' : '' ?>>No references yet. Add sources to your knowledge graph to see them here.</p>
                </div>
            </div>
        </section>

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
                <?php if ($initialHighlights === [] && $fallbackSources === []): ?>
                    <?php /* Empty state handled outside */ ?>
                <?php else: ?>
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
                                <span class="news-card__badge">Curated highlight</span>
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
                                <span class="news-card__badge news-card__badge--source">Knowledge graph</span>
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
        <section class="news-search__toolkit">
            <details class="news-search__toolkit-panel" open>
                <summary>
                    Workspace toolkit
                    <span>Quick actions, templates, and the freshest stored sources</span>
                </summary>
                <div class="news-search__toolkit-grid">
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
                </div>
            </details>
        </section>
        <section class="news-search__context" data-search-extras<?= $trendingChips === [] && $watchlistEntities === [] ? ' hidden' : '' ?>>
            <div class="news-search__context-header">
                <h2>Workspace context</h2>
                <p>Popular topics, watchlist entries, and starter templates</p>
            </div>
            <div class="news-search__extras-grid">
                <section class="news-search__extras-section" data-trending<?= $trendingChips === [] ? ' hidden' : '' ?>>
                    <h3>Popular topics</h3>
                    <div class="news-search__chip-row" data-trending-list>
                        <?php foreach ($trendingChips as $chip): ?>
                            <?php $chipName = isset($chip['entity']) ? (string) $chip['entity'] : ''; ?>
                            <?php if ($chipName === '') { continue; } ?>
                            <button type="button" class="news-search__chip" data-entity="<?= $escape($chipName) ?>"><?= $escape($chipName) ?></button>
                        <?php endforeach; ?>
                    </div>
                </section>
                <section class="news-search__extras-section news-search__extras-section--watchlist">
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
                </section>
                <section class="news-search__extras-section news-search__extras-section--metrics">
                    <h3>Live metrics</h3>
                    <div class="news-toolbar" data-toolbar>
                        <?php foreach ($toolbarMetrics as $metric): ?>
                            <div class="news-toolbar__metric">
                                <span class="news-toolbar__value" data-metric="<?= $escape($metric['key']) ?>"><?= $escape($metric['value']) ?></span>
                                <span class="news-toolbar__label"><?= $escape($metric['label']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
                <section class="news-search__extras-section news-search__extras-section--templates">
                    <h3>Research starters</h3>
                    <div class="news-search__templates-grid">
                        <?php foreach ($workspaceTemplates as $template): ?>
                            <button type="button" class="news-template" data-search-template="<?= $escape($template['query']) ?>">
                                <span class="news-template__title"><?= $escape($template['label']) ?></span>
                                <span class="news-template__text"><?= $escape($template['description']) ?></span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </section>
            </div>
        </section>
        </div>
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
