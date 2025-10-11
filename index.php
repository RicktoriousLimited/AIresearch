<?php

declare(strict_types=1);

require __DIR__ . '/src/App/bootstrap.php';

use App\KnowledgeGraph\GraphRepository;
use App\KnowledgeGraph\GraphResearcher;
use App\KnowledgeGraph\ResearchService;
use App\Web\PathResolver;

function esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$paths = PathResolver::resolve();
$basePath = $paths['basePath'];
$assetBase = $paths['assetBase'];

$stylesPath = PathResolver::url($assetBase, 'assets/styles.css');
$scriptPath = PathResolver::url($assetBase, 'assets/home.js');
$stylesVersion = file_exists(__DIR__ . '/assets/styles.css') ? (string) filemtime(__DIR__ . '/assets/styles.css') : (string) time();
$scriptVersion = file_exists(__DIR__ . '/assets/home.js') ? (string) filemtime(__DIR__ . '/assets/home.js') : (string) time();

$homePath = PathResolver::url($assetBase, 'index.php');
$searchPath = PathResolver::url($assetBase, 'search.php');
$graphPath = PathResolver::url($assetBase, 'knowledge-graph.php');
$docsPath = PathResolver::url($assetBase, 'docs');
$getStartedPath = PathResolver::url($assetBase, 'docs/guides/getting-started.md');
$apiPath = PathResolver::url($assetBase, 'api');
$researchCliPath = PathResolver::url($assetBase, 'research.php');
$healthPath = PathResolver::url($assetBase, 'health.php');

$repository = new GraphRepository();
$researcher = new GraphResearcher($repository);
$service = new ResearchService($repository);

$initialSearch = $researcher->searchGraph('', 18);
$topEntities = $service->listTopEntities(12);

$summary = isset($initialSearch['summary']) && is_array($initialSearch['summary']) ? $initialSearch['summary'] : [];
$sources = isset($initialSearch['sources']) && is_array($initialSearch['sources']) ? $initialSearch['sources'] : [];
$updatedAt = isset($initialSearch['updated_at']) && is_string($initialSearch['updated_at']) ? $initialSearch['updated_at'] : null;

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

$documentsProcessed = $formatNumber($summary['documents_processed'] ?? 0);
$sourcesTracked = $formatNumber(count($sources));
$triplesExtracted = $formatNumber($summary['triples'] ?? count($initialSearch['triples'] ?? []));
$uniqueEntities = $formatNumber($summary['unique_entities'] ?? count($initialSearch['entities'] ?? []));
$synonymGroups = $formatNumber($summary['synonym_groups'] ?? count($initialSearch['synonyms'] ?? []));
$updatedLabel = $formatDate($updatedAt) ?? $updatedAt;
$homeStatus = sprintf(
    'Tracking %s sources · %s documents analysed%s',
    $sourcesTracked,
    $documentsProcessed,
    $updatedLabel !== null ? ' · Updated ' . $updatedLabel : ''
);

$entityNames = [];
foreach ($topEntities as $entityRow) {
    if (!is_array($entityRow)) {
        continue;
    }

    $name = isset($entityRow['entity']) && is_string($entityRow['entity']) ? trim($entityRow['entity']) : '';
    if ($name === '') {
        continue;
    }

    $entityNames[] = $name;
}

$curatedQueries = [
    'foundation model evaluation frameworks',
    'emerging biotech partnerships',
    'autonomous vehicle safety breakthroughs',
    'climate risk scenario planning',
    'synthetic data governance policies',
    'quantum compute hardware vendors',
    'customer experience AI benchmarks',
];

$trendingQueries = [];
foreach ($entityNames as $entityName) {
    $trendingQueries[] = $entityName;
}
foreach ($curatedQueries as $query) {
    $trendingQueries[] = $query;
}
$trendingQueries = array_values(array_unique(array_filter($trendingQueries, static fn(string $value): bool => trim($value) !== '')));
$trendingQueries = array_slice($trendingQueries, 0, 10);

$placeholderPhrases = $trendingQueries !== [] ? $trendingQueries : $curatedQueries;
$placeholderJson = json_encode($placeholderPhrases, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if (!is_string($placeholderJson)) {
    $placeholderJson = '[]';
}

$statGroups = [
    [
        'label' => 'Documents analysed',
        'value' => $documentsProcessed,
    ],
    [
        'label' => 'Knowledge triples',
        'value' => $triplesExtracted,
    ],
    [
        'label' => 'Entities indexed',
        'value' => $uniqueEntities,
    ],
    [
        'label' => 'Synonym groups',
        'value' => $synonymGroups,
    ],
    [
        'label' => 'Curated sources',
        'value' => $sourcesTracked,
    ],
];

if ($updatedLabel !== null) {
    $statGroups[] = [
        'label' => 'Last refreshed',
        'value' => $updatedLabel,
    ];
}

$featureHighlights = [
    [
        'title' => 'Briefs grounded in citations',
        'description' => 'Autopilot produces executive-ready narratives with inline citations and linked evidence for every claim.',
    ],
    [
        'title' => 'Graph-native search',
        'description' => 'Blend entity awareness, relation scoring, and semantic retrieval to answer complex research prompts quickly.',
    ],
    [
        'title' => 'Continuous signal tracking',
        'description' => 'Monitor filings, posts, and analyst notes so your coverage area stays fresh without manual stitching.',
    ],
];

$quickLinks = [
    [
        'label' => 'Run an Autopilot brief',
        'href' => $searchPath,
    ],
    [
        'label' => 'Explore the knowledge graph',
        'href' => $graphPath,
    ],
    [
        'label' => 'Read the documentation',
        'href' => $docsPath,
    ],
];

$homeShortcuts = [
    [
        'title' => 'Benchmark competitors',
        'description' => 'Line up product launches, pricing moves, and market traction across a peer set.',
        'query' => 'competitive intelligence for vector database vendors',
    ],
    [
        'title' => 'Map funding momentum',
        'description' => 'Surface the newest venture rounds, investor sentiment, and growth signals.',
        'query' => 'latest funding momentum in applied robotics startups',
    ],
    [
        'title' => 'Track regulatory shifts',
        'description' => 'Follow policy updates, compliance milestones, and expert commentary.',
        'query' => 'global AI safety regulation updates',
    ],
];

$researchPlaybooks = [
    [
        'label' => 'Autonomous mobility safety heatmap',
        'description' => 'Identify critical incidents, mitigation strategies, and regulatory deadlines.',
        'query' => 'autonomous vehicle safety breakthroughs',
    ],
    [
        'label' => 'Synthetic data supply chain scan',
        'description' => 'Understand vendors, policies, and enterprise adoption signals.',
        'query' => 'synthetic data governance policies',
    ],
    [
        'label' => 'Enterprise CX benchmark pack',
        'description' => 'Gather reference wins, sentiment drivers, and capability gaps.',
        'query' => 'customer experience AI benchmarks',
    ],
    [
        'label' => 'Climate resilience briefing',
        'description' => 'Monitor transition risk disclosures and adaptation investments.',
        'query' => 'climate risk scenario planning',
    ],
];

$insightStreams = array_slice($entityNames, 0, 6);

$workflowStages = [
    [
        'title' => 'Monitor signals',
        'items' => [
            'Entity change log with provenance',
            'Policy and regulation digests',
            'Funding and partnership heatmap',
        ],
    ],
    [
        'title' => 'Synthesize briefs',
        'items' => [
            'Narratives grounded in citations',
            'Auto-generated charts and callouts',
            'Exportable executive summaries',
        ],
    ],
    [
        'title' => 'Activate insights',
        'items' => [
            'Share playbooks with stakeholders',
            'Push updates to Slack and email',
            'Schedule refreshes and alerts',
        ],
    ],
];

$evidencePillars = [
    [
        'title' => 'Coverage spotlight',
        'description' => 'See which domains, companies, or scientists are dominating the conversation right now.',
    ],
    [
        'title' => 'Emerging questions',
        'description' => 'AI surfaces the hard questions decision makers are asking so you can answer them first.',
    ],
    [
        'title' => 'Source transparency',
        'description' => 'Trace every claim back to filings, research papers, community posts, and analyst notes.',
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AIresearch · Search the collective research graph</title>
    <link rel="stylesheet" href="<?= esc($stylesPath . '?v=' . $stylesVersion); ?>">
</head>
<body class="home-page">
<header class="site-header site-header--compact">
    <div class="shell header-shell header-shell--compact">
        <a class="brand" href="<?= esc($homePath); ?>">AIresearch</a>
        <nav class="primary-nav" aria-label="Primary">
            <a href="<?= esc($searchPath); ?>" class="primary-nav__link">Autopilot search</a>
            <a href="<?= esc($graphPath); ?>" class="primary-nav__link">Knowledge graph</a>
            <a href="<?= esc($docsPath); ?>" class="primary-nav__link">Documentation</a>
        </nav>
        <div class="header-actions">
            <a class="button primary" href="<?= esc($searchPath); ?>">Launch search</a>
        </div>
    </div>
</header>

<main class="home-main home-main--search">
    <section class="home-search" id="search">
        <div class="shell home-search__shell">
            <div class="home-search__grid">
                <div class="home-search__column home-search__column--primary">
                    <div class="home-search__brand">
                        <p class="home-search__eyebrow">Autopilot workspace</p>
                        <h1 class="home-search__title">Research autopilot for live intelligence</h1>
                        <p class="home-search__lead">Blend live coverage with the shared knowledge graph to brief stakeholders in seconds.</p>
                    </div>
                    <div class="home-search__controls">
                        <form class="search-form home-search__form" method="get" action="<?= esc($searchPath); ?>" role="search" data-home-search>
                            <label class="visually-hidden" for="home-search-input">Search the AIresearch graph</label>
                            <div class="search-form__field">
                                <input id="home-search-input" name="q" type="search" placeholder="Try &ldquo;<?= esc($placeholderPhrases[0] ?? 'emerging AI research hubs'); ?>&rdquo;" autocomplete="off" spellcheck="false" data-home-search-input data-home-phrases='<?= esc($placeholderJson); ?>'>
                                <button type="submit" class="button primary">Autopilot brief</button>
                            </div>
                        </form>
                        <p class="home-search__status"><?= esc($homeStatus); ?></p>
                    </div>
                    <?php if ($trendingQueries !== []): ?>
                    <div class="home-search__chips" data-home-trending>
                        <span class="home-search__label">Popular queries</span>
                        <div class="home-search__list">
                            <?php foreach ($trendingQueries as $query): ?>
                                <button type="button" class="chip" data-home-chip="<?= esc($query); ?>"><?= esc($query); ?></button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="home-search__suggestions">
                        <span class="home-search__label">Jump to a template</span>
                        <div class="home-search__suggestion-list">
                            <?php foreach ($researchPlaybooks as $playbook): ?>
                                <button type="button" class="home-suggestion" data-home-suggestion="<?= esc($playbook['query']); ?>">
                                    <span class="home-suggestion__name"><?= esc($playbook['label']); ?></span>
                                    <span class="home-suggestion__description"><?= esc($playbook['description']); ?></span>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="home-search__links">
                        <?php foreach ($quickLinks as $link): ?>
                            <a class="home-search__link" href="<?= esc($link['href']); ?>"><?= esc($link['label']); ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <aside class="home-search__column home-search__column--secondary">
                    <div class="home-panel">
                        <h3 class="home-panel__title">Workspace shortcuts</h3>
                        <ul class="home-shortcuts" data-home-shortcuts>
                            <?php foreach ($homeShortcuts as $shortcut): ?>
                                <li class="home-shortcut">
                                    <button type="button" data-home-suggestion="<?= esc($shortcut['query']); ?>">
                                        <span class="home-shortcut__title"><?= esc($shortcut['title']); ?></span>
                                        <span class="home-shortcut__description"><?= esc($shortcut['description']); ?></span>
                                    </button>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <div class="home-panel">
                        <h3 class="home-panel__title">Trending insight streams</h3>
                        <ul class="home-streams">
                            <?php foreach ($insightStreams as $stream): ?>
                                <li>
                                    <button type="button" class="home-stream" data-home-suggestion="<?= esc($stream); ?>">
                                        <span class="home-stream__name"><?= esc($stream); ?></span>
                                        <span class="home-stream__meta">Follow live signals instantly</span>
                                    </button>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </aside>
            </div>
        </div>
    </section>

    <section class="home-intel">
        <div class="shell home-intel__shell">
            <div class="home-intel__grid" aria-live="polite">
                <?php foreach ($statGroups as $stat): ?>
                    <article class="home-intel__card">
                        <span class="home-intel__label"><?= esc($stat['label']); ?></span>
                        <span class="home-intel__value"><?= esc((string) $stat['value']); ?></span>
                        <span class="home-intel__hint">Continuously refreshed from the research graph</span>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="home-workflow">
        <div class="shell home-workflow__shell">
            <div class="home-workflow__intro">
                <h2>Design your research autopilot</h2>
                <p class="muted">Blend live monitoring, synthesis, and distribution without leaving the workspace.</p>
            </div>
            <div class="home-workflow__grid">
                <?php foreach ($workflowStages as $stage): ?>
                    <article class="home-workflow__stage">
                        <h3><?= esc($stage['title']); ?></h3>
                        <ul>
                            <?php foreach ($stage['items'] as $item): ?>
                                <li><?= esc($item); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="home-updates">
        <div class="shell home-updates__shell">
            <div class="home-updates__grid">
                <div class="home-updates__panel">
                    <h2>Evidence board</h2>
                    <p class="muted">Pin live streams, trigger alerts, and share reports from one place.</p>
                    <ul class="home-updates__list">
                        <?php foreach ($evidencePillars as $pillar): ?>
                            <li>
                                <span class="home-updates__item-title"><?= esc($pillar['title']); ?></span>
                                <span class="home-updates__item-text"><?= esc($pillar['description']); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <div class="home-updates__panel home-updates__panel--streams">
                    <h2>Active streams</h2>
                    <ol class="home-updates__streams" data-home-trending-list>
                        <?php foreach ($placeholderPhrases as $phrase): ?>
                            <li><button type="button" data-home-suggestion="<?= esc($phrase); ?>"><?= esc($phrase); ?></button></li>
                        <?php endforeach; ?>
                    </ol>
                    <p class="home-updates__note">Tap any stream to pre-fill the search canvas.</p>
                </div>
            </div>
        </div>
    </section>

    <section class="home-highlights">
        <div class="shell home-highlights__shell">
            <h2>Why teams choose Autopilot</h2>
            <div class="home-highlights__grid">
                <?php foreach ($featureHighlights as $feature): ?>
                    <article class="home-highlight">
                        <h3><?= esc($feature['title']); ?></h3>
                        <p><?= esc($feature['description']); ?></p>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
</main>

<footer class="site-footer">
    <div class="shell footer-shell">
        <div class="footer-brand">
            <a href="<?= esc($homePath); ?>">AIresearch</a>
            <p class="muted">Search, trace, and export the insights that matter. Built for research teams that demand transparent evidence.</p>
        </div>
        <div class="footer-links">
            <div>
                <h4>Platform</h4>
                <ul>
                    <li><a href="<?= esc($searchPath); ?>">Graph search</a></li>
                    <li><a href="<?= esc($graphPath); ?>">Knowledge graph</a></li>
                    <li><a href="<?= esc($researchCliPath); ?>">Research CLI</a></li>
                </ul>
            </div>
            <div>
                <h4>Resources</h4>
                <ul>
                    <li><a href="<?= esc($docsPath); ?>">Documentation</a></li>
                    <li><a href="<?= esc($getStartedPath); ?>">Getting started</a></li>
                    <li><a href="<?= esc($apiPath); ?>">API</a></li>
                </ul>
            </div>
            <div>
                <h4>Support</h4>
                <ul>
                    <li><a href="<?= esc($healthPath); ?>">System health</a></li>
                    <li><a href="mailto:support@airesearch.local">Contact</a></li>
                </ul>
            </div>
        </div>
    </div>
</footer>

<script src="<?= esc($scriptPath . '?v=' . $scriptVersion); ?>" defer></script>
</body>
</html>
