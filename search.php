<?php

declare(strict_types=1);

require __DIR__ . '/src/App/bootstrap.php';

use App\KnowledgeGraph\GraphRepository;
use App\KnowledgeGraph\GraphResearcher;
use App\KnowledgeGraph\ResearchService;
use App\Web\PathResolver;

$paths = PathResolver::resolve();
$assetBase = $paths['assetBase'];
$basePath = $paths['basePath'];

$stylesPath = PathResolver::url($assetBase, 'assets/styles.css');
$scriptPath = PathResolver::url($assetBase, 'assets/search.js');
$stylesVersion = file_exists(__DIR__ . '/assets/styles.css') ? (string) filemtime(__DIR__ . '/assets/styles.css') : (string) time();
$scriptVersion = file_exists(__DIR__ . '/assets/search.js') ? (string) filemtime(__DIR__ . '/assets/search.js') : (string) time();

$homePath = PathResolver::url($assetBase, 'index.php');
$searchPath = PathResolver::url($assetBase, 'search.php');
$graphPath = PathResolver::url($assetBase, 'knowledge-graph.php');
$apiEndpoint = PathResolver::url($assetBase, 'api/research.php');

$repository = new GraphRepository();
$researcher = new GraphResearcher($repository);
$service = new ResearchService($repository);

$escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

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

$formatRelative = static function (?string $value): ?string {
    if ($value === null || trim($value) === '') {
        return null;
    }

    try {
        $date = new DateTimeImmutable($value);
    } catch (Exception $exception) {
        return null;
    }

    $diff = time() - $date->getTimestamp();
    if ($diff <= 0) {
        return 'just now';
    }

    $minutes = (int) floor($diff / 60);
    if ($minutes < 1) {
        return 'just now';
    }
    if ($minutes < 60) {
        return $minutes === 1 ? '1 minute ago' : $minutes . ' minutes ago';
    }

    $hours = (int) floor($minutes / 60);
    if ($hours < 24) {
        return $hours === 1 ? '1 hour ago' : $hours . ' hours ago';
    }

    $days = (int) floor($hours / 24);
    if ($days < 7) {
        return $days === 1 ? '1 day ago' : $days . ' days ago';
    }

    $weeks = (int) floor($days / 7);
    if ($weeks < 5) {
        return $weeks === 1 ? '1 week ago' : $weeks . ' weeks ago';
    }

    $months = (int) floor($days / 30);
    if ($months < 12) {
        return $months === 1 ? '1 month ago' : $months . ' months ago';
    }

    $years = (int) floor($days / 365);
    return $years === 1 ? '1 year ago' : $years . ' years ago';
};

$initialInsight = $service->buildInsightDocument('', 6);
$initialReport = isset($initialInsight['report']) && is_array($initialInsight['report'])
    ? $initialInsight['report']
    : $service->buildResearchBrief('', 5);
$initialSearch = isset($initialInsight['search']) && is_array($initialInsight['search'])
    ? $initialInsight['search']
    : $researcher->searchGraph('', 18);
$initialEntities = isset($initialSearch['entities']) && is_array($initialSearch['entities']) ? $initialSearch['entities'] : [];

$sources = [];
if (isset($initialInsight['references']['sources']) && is_array($initialInsight['references']['sources'])) {
    $sources = $initialInsight['references']['sources'];
} elseif (isset($initialSearch['sources']) && is_array($initialSearch['sources'])) {
    $sources = $initialSearch['sources'];
}
$fallbackSources = array_slice($sources, 0, 6);

$topEntities = $service->listTopEntities(12);
$entityNames = [];
foreach ($topEntities as $entityRow) {
    if (!is_array($entityRow)) {
        continue;
    }
    $name = isset($entityRow['entity']) && is_string($entityRow['entity']) ? trim($entityRow['entity']) : '';
    if ($name !== '') {
        $entityNames[] = $name;
    }
}

$curatedQueries = [
    'intelligent search for AI market shifts',
    'foundation model evaluation frameworks',
    'emerging biotech partnerships',
    'climate risk scenario planning',
    'synthetic data governance policies',
    'quantum compute hardware vendors',
    'customer experience AI benchmarks',
];

$trendingSuggestions = [];
foreach ($entityNames as $entityName) {
    $trendingSuggestions[] = $entityName;
}
foreach ($curatedQueries as $query) {
    $trendingSuggestions[] = $query;
}
$trendingSuggestions = array_values(array_unique(array_filter($trendingSuggestions, static fn(string $value): bool => trim($value) !== '')));
$trendingSuggestions = array_slice($trendingSuggestions, 0, 12);

$generatedAt = isset($initialReport['generated_at']) && is_string($initialReport['generated_at']) ? $initialReport['generated_at'] : null;
$docCount = isset($initialReport['document_count']) ? $formatNumber($initialReport['document_count']) : '0';
$generatedLabel = $formatDate($generatedAt) ?? 'Not yet generated';
$relativeLabel = $formatRelative($generatedAt);

$initialState = [
    'insight' => $initialInsight,
    'report' => $initialReport,
    'search' => $initialSearch,
    'sources' => $fallbackSources,
    'trending' => $trendingSuggestions,
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
    <title>Intelligent Search &ndash; AIresearch</title>
    <link rel="stylesheet" href="<?= $escape($stylesPath . '?v=' . $stylesVersion) ?>">
</head>
<body class="site site--search">
<header class="site-header">
    <div class="site-header__inner">
        <a class="site-brand" href="<?= $escape($homePath) ?>">AIresearch</a>
        <nav class="site-nav" aria-label="Primary navigation">
            <a class="site-nav__link" href="<?= $escape($homePath) ?>">Home</a>
            <a class="site-nav__link site-nav__link--active" href="<?= $escape($searchPath) ?>">Intelligent search</a>
            <a class="site-nav__link" href="<?= $escape($graphPath) ?>">Knowledge graph</a>
        </nav>
    </div>
</header>
<main class="search-view" id="main">
    <section class="search-bar" aria-label="Search">
        <form class="search-bar__form" data-search-form role="search">
            <label class="visually-hidden" for="search-query">Search the knowledge graph</label>
            <input id="search-query" name="q" type="search" placeholder="Search entities, relationships, or open questions" autocomplete="off" spellcheck="false" data-search-input>
            <button type="submit" class="search-bar__submit">Search</button>
        </form>
        <p class="search-bar__status" role="status" data-search-status></p>
        <div class="search-bar__meta">
            <span><?= $escape($docCount) ?> sources analysed</span>
            <span>Last generated <?= $escape($generatedLabel) ?><?= $relativeLabel !== null ? ' · ' . $escape($relativeLabel) : '' ?></span>
        </div>
    </section>

    <div class="search-view__layout">
        <section class="search-results" data-results-section>
            <header class="search-results__header">
                <h1 class="search-results__title">Results</h1>
                <p class="search-results__meta" data-results-meta>Showing intelligent matches from the knowledge graph.</p>
            </header>
            <ol class="result-list" data-results></ol>
            <p class="result-list__empty" data-results-empty hidden>We haven&apos;t indexed matching facts yet. Try a different phrase or connect more sources.</p>
            <section class="fact-panel">
                <h2 class="fact-panel__title">Graph facts</h2>
                <ul class="fact-panel__list" data-fact-list></ul>
                <p class="fact-panel__empty" data-fact-empty hidden>When data is ingested, we&apos;ll show relationship triples here.</p>
            </section>
        </section>

        <aside class="search-sidebar">
            <section class="insight-card" data-insight>
                <h2 class="insight-card__title" data-insight-title>Insight briefing</h2>
                <p class="insight-card__meta" data-insight-meta></p>
                <div class="insight-card__body" data-insight-body></div>
                <p class="insight-card__empty" data-insight-empty hidden>Generate a search to see briefing highlights grounded in citations.</p>
            </section>
            <section class="sidebar-card">
                <h3 class="sidebar-card__title">Key entities</h3>
                <ul class="sidebar-card__list" data-entity-list></ul>
                <p class="sidebar-card__empty" data-entity-empty hidden>We&apos;ll highlight referenced entities after your first search.</p>
            </section>
            <section class="sidebar-card">
                <h3 class="sidebar-card__title">Trusted sources</h3>
                <ul class="sidebar-card__list" data-source-list></ul>
                <p class="sidebar-card__empty" data-source-empty hidden>Connect your news feeds or filings to unlock source previews.</p>
            </section>
        </aside>
    </div>

    <section class="search-trending" data-trending>
        <h2 class="search-trending__title">Suggested searches</h2>
        <div class="search-trending__chips" data-trending-list></div>
    </section>
</main>
<script>
    window.AISearch = {
        endpoints: {
            insight: '<?= $escape($apiEndpoint) ?>?action=insight',
            search: '<?= $escape($apiEndpoint) ?>?action=search',
            report: '<?= $escape($apiEndpoint) ?>?action=report'
        },
        initial: <?= $initialJson ?>
    };
</script>
<script src="<?= $escape($scriptPath . '?v=' . $scriptVersion) ?>" defer></script>
</body>
</html>
