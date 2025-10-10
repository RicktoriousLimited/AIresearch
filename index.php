<?php

declare(strict_types=1);

require __DIR__ . '/src/App/bootstrap.php';

use App\KnowledgeGraph\GraphRepository;
use App\KnowledgeGraph\GraphResearcher;
use App\KnowledgeGraph\ResearchService;

function esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php');
$scriptDir = str_replace('\\', '/', dirname($scriptName));
if ($scriptDir === '.' || $scriptDir === '/' || $scriptDir === '\\\\') {
    $scriptDir = '';
}

$basePath = rtrim($scriptDir, '/');
if ($basePath !== '') {
    $basePath = '/' . ltrim($basePath, '/');
}

$assetBase = $basePath === '' ? '' : $basePath;

$stylesPath = $assetBase . '/assets/styles.css';
$scriptPath = $assetBase . '/assets/home.js';
$stylesVersion = file_exists(__DIR__ . '/assets/styles.css') ? (string) filemtime(__DIR__ . '/assets/styles.css') : (string) time();
$scriptVersion = file_exists(__DIR__ . '/assets/home.js') ? (string) filemtime(__DIR__ . '/assets/home.js') : (string) time();

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

$heroPillars = [
    'Continuously monitors filings, transcripts, technical blogs, and academic papers related to your mandate.',
    'Normalises entities, relationships, and metrics into a transparent graph with citations you can audit.',
    'Packages evidence into analyst-ready briefs, exports, and slides without leaving the workspace.',
];

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
        'title' => 'Entity intelligence without manual stitching',
        'description' => 'Resolve aliases, join biographies, and surface network relationships across every import, crawl, or analyst upload.',
    ],
    [
        'title' => 'Context-aware retrieval for specialists',
        'description' => 'Hybrid ranking blends semantic expansion with precision filters so niche questions return defensible evidence first.',
    ],
    [
        'title' => 'Transparent provenance on every insight',
        'description' => 'Trace each metric to the exact passage, document, and ingestion time to accelerate approvals and peer review.',
    ],
];

$insightStages = [
    [
        'step' => '01',
        'title' => 'Capture every signal',
        'description' => 'Ingest regulatory filings, product updates, community posts, and internal notes into a single research memory.',
    ],
    [
        'step' => '02',
        'title' => 'Model the landscape',
        'description' => 'Map entities, relationships, and velocity metrics to reveal emerging movements across your coverage area.',
    ],
    [
        'step' => '03',
        'title' => 'Deliver the briefing',
        'description' => 'Compose narratives, exports, and dashboards that stay linked to citations so stakeholders trust every recommendation.',
    ],
];

$useCases = [
    [
        'title' => 'Market landscaping dossiers',
        'description' => 'Build defensible market maps with transparent sourcing for executives and go-to-market leaders.',
        'items' => [
            'Funding rounds and partnership velocity',
            'Executive moves and hiring signals',
            'Product positioning and roadmap mentions',
        ],
    ],
    [
        'title' => 'Technical horizon scanning',
        'description' => 'Track the pace of innovation across labs, vendors, and standards bodies in one living brief.',
        'items' => [
            'Patent, paper, and benchmark coverage',
            'Competitor release cadence',
            'Risk and compliance commentary',
        ],
    ],
    [
        'title' => 'Executive decision memos',
        'description' => 'Answer urgent leadership questions with citations, comparable data, and a clear audit trail.',
        'items' => [
            'Auto-generated evidence packets',
            'Cross-team collaboration notes',
            'Export-ready appendices',
        ],
    ],
];

$spotlightCollections = [
    [
        'title' => 'AI safety & policy observatory',
        'description' => 'Comparative tracker for national frameworks, regulator briefings, and governance pledges as they happen.',
        'tags' => ['Governance', 'Risk'],
    ],
    [
        'title' => 'Sustainable industry transitions',
        'description' => 'Monitor climate tech investments, industrial pilots, and supply chain collaborations with weekly deltas.',
        'tags' => ['Climate', 'Operations'],
    ],
    [
        'title' => 'Automation adoption watchlist',
        'description' => 'Surface which platforms are actually deployed, adjacent vendors shortlisted, and the use cases they solve.',
        'tags' => ['Automation', 'Productivity'],
    ],
];

$workflowSteps = [
    [
        'step' => '01',
        'title' => 'Frame the research objective',
        'description' => 'Capture the strategic context, define evidence needs, and spin up the shared workspace for collaborators.',
    ],
    [
        'step' => '02',
        'title' => 'Interrogate the graph',
        'description' => 'Iterate on search prompts, pivot across entities, and bookmark passages that answer the core question.',
    ],
    [
        'step' => '03',
        'title' => 'Ship the deliverable',
        'description' => 'Turn curated findings into narratives, CSV extracts, or decks with confidence scoring and traceability intact.',
    ],
];

$researchPrinciples = [
    [
        'title' => 'Evidence-forward culture',
        'description' => 'Every insight is anchored to verifiable passages and document metadata so reviewers can double-click instantly.',
    ],
    [
        'title' => 'Collaboration without chaos',
        'description' => 'Shared annotations, saved queries, and change history keep research teams aligned across geographies.',
    ],
    [
        'title' => 'Secure by design',
        'description' => 'Granular access controls and air-gapped storage options ensure sensitive investigations stay private.',
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
<header class="site-header">
    <div class="shell header-shell">
        <a class="brand" href="<?= esc($assetBase . '/index.php'); ?>">AIresearch</a>
        <nav class="primary-nav" aria-label="Primary">
            <a href="#search" class="primary-nav__link">Home</a>
            <a href="<?= esc($assetBase . '/search.php'); ?>" class="primary-nav__link">Graph search</a>
            <a href="<?= esc($assetBase . '/knowledge-graph.php'); ?>" class="primary-nav__link">Knowledge graph</a>
            <a href="<?= esc($assetBase . '/docs'); ?>" class="primary-nav__link">Documentation</a>
        </nav>
        <div class="header-actions">
            <a class="button primary" href="<?= esc($assetBase . '/search.php'); ?>">Launch search</a>
        </div>
    </div>
</header>

<main class="home-main">
    <section class="search-hero" id="search">
        <div class="shell">
            <div class="search-hero__layout">
                <div class="search-hero__intro">
                    <p class="eyebrow">Focused research workspace</p>
                    <h1>Focus your analysis on evidence, not tab juggling</h1>
                    <p class="lead">AIresearch transforms continuous monitoring into shareable intelligence by unifying entities, relationships, and citations in a single research graph.</p>
                    <ul class="hero-pillars">
                        <?php foreach ($heroPillars as $pillar): ?>
                            <li><?= esc($pillar); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <form class="search-form" method="get" action="<?= esc($assetBase . '/search.php'); ?>" role="search" data-home-search>
                        <label class="visually-hidden" for="home-search-input">Search the AIresearch graph</label>
                        <div class="search-form__field">
                            <input id="home-search-input" name="q" type="search" placeholder="Try &ldquo;<?= esc($placeholderPhrases[0] ?? 'emerging AI research hubs'); ?>&rdquo;" autocomplete="off" spellcheck="false" data-home-search-input data-home-phrases='<?= esc($placeholderJson); ?>'>
                            <button type="submit" class="button primary">Search graph</button>
                        </div>
                    </form>
                    <?php if ($trendingQueries !== []): ?>
                    <div class="search-trending" data-home-trending>
                        <span class="search-trending__label">Trending research prompts</span>
                        <div class="search-trending__chips">
                            <?php foreach ($trendingQueries as $query): ?>
                                <button type="button" class="chip" data-home-chip="<?= esc($query); ?>"><?= esc($query); ?></button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <aside class="search-hero__panel">
                    <div class="panel-card" aria-live="polite">
                        <h2>Knowledge graph at a glance</h2>
                        <p class="panel-card__lead">Live coverage from crawls, analyst uploads, and trusted research partners.</p>
                        <dl class="panel-card__metrics">
                            <?php foreach ($statGroups as $stat): ?>
                                <div class="panel-card__metric">
                                    <dt><?= esc($stat['label']); ?></dt>
                                    <dd><?= esc($stat['value']); ?></dd>
                                </div>
                            <?php endforeach; ?>
                        </dl>
                        <p class="panel-card__note">Need deeper context? <a href="<?= esc($assetBase . '/knowledge-graph.php'); ?>">Browse the live graph</a> or <a href="<?= esc($assetBase . '/docs'); ?>">review the ingestion playbook</a>.</p>
                    </div>
                </aside>
            </div>
        </div>
    </section>

    <section class="section section--features" id="features">
        <div class="shell">
            <div class="section-header">
                <h2>Built for focused research teams</h2>
                <p class="muted">Synthesise complex domains with tooling that keeps citations and context together.</p>
            </div>
            <div class="feature-grid">
                <?php foreach ($featureHighlights as $feature): ?>
                    <article class="feature-card">
                        <h3><?= esc($feature['title']); ?></h3>
                        <p><?= esc($feature['description']); ?></p>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="section section--insights" id="pipeline">
        <div class="shell">
            <div class="section-header">
                <h2>From signal intake to shareable intelligence</h2>
                <p class="muted">Follow a consistent pipeline to capture, enrich, and distribute research.</p>
            </div>
            <div class="insight-pipeline">
                <?php foreach ($insightStages as $stage): ?>
                    <article class="insight-card">
                        <span class="insight-card__index"><?= esc($stage['step']); ?></span>
                        <h3><?= esc($stage['title']); ?></h3>
                        <p><?= esc($stage['description']); ?></p>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="section section--usecases" id="use-cases">
        <div class="shell">
            <div class="section-header">
                <h2>Where teams rely on AIresearch</h2>
                <p class="muted">Each workspace packages the same knowledge graph around a specific decision.</p>
            </div>
            <div class="use-case-grid">
                <?php foreach ($useCases as $useCase): ?>
                    <article class="use-case-card">
                        <h3><?= esc($useCase['title']); ?></h3>
                        <p><?= esc($useCase['description']); ?></p>
                        <?php if (!empty($useCase['items'])): ?>
                            <ul>
                                <?php foreach ($useCase['items'] as $item): ?>
                                    <li><?= esc($item); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="section section--collections" id="collections">
        <div class="shell">
            <div class="section-header">
                <h2>Curated evidence libraries</h2>
                <p class="muted">Jump-start your next briefing with collections maintained by the research team.</p>
            </div>
            <div class="collection-grid">
                <?php foreach ($spotlightCollections as $collection): ?>
                    <article class="collection-card">
                        <h3><?= esc($collection['title']); ?></h3>
                        <p><?= esc($collection['description']); ?></p>
                        <?php if (!empty($collection['tags'])): ?>
                            <div class="collection-card__tags">
                                <?php foreach ($collection['tags'] as $tag): ?>
                                    <span class="tag" data-home-suggestion="<?= esc($tag); ?>"><?= esc($tag); ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="section section--workflow" id="workflow">
        <div class="shell">
            <div class="section-header">
                <h2>Deliver research with confidence</h2>
                <p class="muted">From discovery to deliverable, every step stays linked to provenance.</p>
            </div>
            <div class="workflow-grid">
                <?php foreach ($workflowSteps as $step): ?>
                    <article class="workflow-step">
                        <span class="workflow-step__number"><?= esc($step['step']); ?></span>
                        <h3><?= esc($step['title']); ?></h3>
                        <p><?= esc($step['description']); ?></p>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="section section--principles" id="principles">
        <div class="shell">
            <div class="section-header">
                <h2>Designed for research operations</h2>
                <p class="muted">Governance, collaboration, and auditability are built into the workflow.</p>
            </div>
            <div class="principle-grid">
                <?php foreach ($researchPrinciples as $principle): ?>
                    <article class="principle-card">
                        <h3><?= esc($principle['title']); ?></h3>
                        <p><?= esc($principle['description']); ?></p>
                    </article>
                <?php endforeach; ?>
            </div>
            <div class="cta-panel">
                <div>
                    <h3>Ready to explore the research graph?</h3>
                    <p class="muted">Launch the graph explorer or follow the onboarding checklist to seed your workspace.</p>
                </div>
                <div class="cta-panel__actions">
                    <a class="button primary" href="<?= esc($assetBase . '/search.php'); ?>">Launch search</a>
                    <a class="button ghost" href="<?= esc($assetBase . '/docs/guides/getting-started.md'); ?>">Onboarding guide</a>
                </div>
            </div>
        </div>
    </section>
</main>

<footer class="site-footer">
    <div class="shell footer-shell">
        <div class="footer-brand">
            <a href="<?= esc($assetBase . '/index.php'); ?>">AIresearch</a>
            <p class="muted">Search, trace, and export the insights that matter. Built for research teams that demand transparent evidence.</p>
        </div>
        <div class="footer-links">
            <div>
                <h4>Platform</h4>
                <ul>
                    <li><a href="<?= esc($assetBase . '/search.php'); ?>">Graph search</a></li>
                    <li><a href="<?= esc($assetBase . '/knowledge-graph.php'); ?>">Knowledge graph</a></li>
                    <li><a href="<?= esc($assetBase . '/research.php'); ?>">Research CLI</a></li>
                </ul>
            </div>
            <div>
                <h4>Resources</h4>
                <ul>
                    <li><a href="<?= esc($assetBase . '/docs'); ?>">Documentation</a></li>
                    <li><a href="<?= esc($assetBase . '/docs/guides/getting-started.md'); ?>">Getting started</a></li>
                    <li><a href="<?= esc($assetBase . '/api'); ?>">API</a></li>
                </ul>
            </div>
            <div>
                <h4>Support</h4>
                <ul>
                    <li><a href="<?= esc($assetBase . '/health.php'); ?>">System health</a></li>
                    <li><a href="mailto:support@airesearch.local">Contact</a></li>
                </ul>
            </div>
        </div>
    </div>
</footer>

<script src="<?= esc($scriptPath . '?v=' . $scriptVersion); ?>" defer></script>
</body>
</html>
