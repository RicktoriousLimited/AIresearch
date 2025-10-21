<?php

declare(strict_types=1);

require __DIR__ . '/src/App/bootstrap.php';

use App\Web\PathResolver;
use App\Intelligence\InsightEngine;

function esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$paths = PathResolver::resolve();
$basePath = $paths['basePath'];
$assetBase = $paths['assetBase'];

$homeStylesPath = PathResolver::url($assetBase, 'assets/home-page.css');
$homeScriptPath = PathResolver::url($assetBase, 'assets/home.js');
$homeStylesVersion = file_exists(__DIR__ . '/assets/home-page.css') ? (string) filemtime(__DIR__ . '/assets/home-page.css') : (string) time();
$homeScriptVersion = file_exists(__DIR__ . '/assets/home.js') ? (string) filemtime(__DIR__ . '/assets/home.js') : (string) time();

$homePath = PathResolver::url($assetBase, 'index.php');
$searchPath = PathResolver::url($assetBase, 'search.php');
$knowledgePath = PathResolver::url($assetBase, 'knowledge-graph.php');
$marketsPath = PathResolver::url($assetBase, 'markets.php');
$researchPath = PathResolver::url($assetBase, 'research.php');

$sampleQueries = [
    'emerging ai regulation',
    'semiconductor supply chain',
    'climate tech investments',
    'cybersecurity breach response',
];

$systemHighlights = [
    [
        'kicker' => 'Crawler control',
        'title' => 'Signals ready when you are',
        'description' => 'Autonomous crawls watch high-signal domains and enrich every article with summaries, sentiment and context.',
        'points' => [
            'Smart retry handling and content deduplication to keep feeds clean.',
            'Quality scoring at ingest so teams can triage faster.',
            'Live cache ensures the app remains responsive even during restarts.',
        ],
    ],
    [
        'kicker' => 'Knowledge graph',
        'title' => 'Every mention mapped',
        'description' => 'Entities, organisations and topics are linked automatically so analysts can pivot coverage without manual tagging.',
        'points' => [
            'Topic clustering to surface unexpected angles in seconds.',
            'Publisher intelligence that highlights who is leading each story.',
            'Faceted filters that stay in sync across the entire experience.',
        ],
    ],
    [
        'kicker' => 'Research workspace',
        'title' => 'Briefings on tap',
        'description' => 'The research console compiles highlights, embeds and citations into ready-to-share narratives.',
        'points' => [
            'Guided playbooks turn raw crawls into clear story outlines.',
            'Inline AI assistance to clean copy and suggest follow-ups.',
            'Export options designed for quick stakeholder updates.',
        ],
    ],
];

$workflowSteps = [
    [
        'title' => 'Capture',
        'description' => 'HiddenCrawler sweeps priority sources every few minutes and stores the raw text with canonical metadata.',
    ],
    [
        'title' => 'Enrich',
        'description' => 'The Semantic Engine scores quality, extracts entities and syncs fresh concepts to the knowledge graph.',
    ],
    [
        'title' => 'Compose',
        'description' => 'Search, Markets and Research modules feed off the same cache so investigators see one coherent story.',
    ],
    [
        'title' => 'Share',
        'description' => 'Briefings, exports and alerts ship the latest intelligence to stakeholders without extra formatting.',
    ],
];

$homeIntelligence = [
    'snapshots' => [],
    'model_version' => null,
    'generated_at' => null,
];
try {
    $insightEngine = new InsightEngine();
    $homeIntelligence = $insightEngine->overview($sampleQueries, 3);
} catch (Throwable $exception) {
    $homeIntelligence['error'] = $exception->getMessage();
}

$intelligenceSnapshots = isset($homeIntelligence['snapshots']) && is_array($homeIntelligence['snapshots'])
    ? $homeIntelligence['snapshots']
    : [];
$intelligenceModelVersion = isset($homeIntelligence['model_version']) && is_string($homeIntelligence['model_version'])
    ? $homeIntelligence['model_version']
    : null;
$intelligenceGeneratedAt = isset($homeIntelligence['generated_at']) && is_string($homeIntelligence['generated_at'])
    ? $homeIntelligence['generated_at']
    : null;

$sampleQueriesJson = json_encode(
    $sampleQueries,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
if (!is_string($sampleQueriesJson)) {
    $sampleQueriesJson = '[]';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AIresearch – Search intelligence</title>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="<?= esc($homeStylesPath . '?v=' . $homeStylesVersion) ?>">
</head>
<body class="home-page">
    <header class="home-header" id="topbar">
        <div class="home-header__inner">
            <a class="brand" href="<?= esc($homePath) ?>" aria-label="AIresearch home">AI<span>research</span></a>
            <nav class="home-nav" aria-label="Primary">
                <a class="home-nav__link" href="<?= esc($searchPath) ?>">Search</a>
                <a class="home-nav__link" href="<?= esc($knowledgePath) ?>">Knowledge graph</a>
                <a class="home-nav__link" href="<?= esc($marketsPath) ?>">Markets</a>
                <a class="home-nav__link" href="<?= esc($researchPath) ?>">Research</a>
            </nav>
            <a class="btn btn--ghost" href="<?= esc($searchPath) ?>">Launch search</a>
        </div>
    </header>
    <main class="home" id="home">
        <section class="hero">
            <div class="hero__inner">
                <div class="hero__content">
                    <p class="hero__eyebrow">Unified research pipeline</p>
                    <h1 class="hero__title">Complete intelligence frame for every investigation</h1>
                    <p class="hero__subtitle">
                        AIresearch connects crawling, enrichment and briefing tools so analysts land confident answers in minutes.
                        Start in search, pivot through the knowledge graph, and package your story without leaving the workspace.
                    </p>
                    <form class="searchbox hero__search" action="<?= esc($searchPath) ?>" method="get" role="search" aria-label="Search AIresearch" data-home-search>
                        <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M15.5 14h-.79l-.28-.27A6.471 6.471 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79L20 21.5 21.5 20l-6-6zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>
                        <label for="home-query" class="sr-only">Search</label>
                        <input id="home-query" type="search" name="q" placeholder="Search AIresearch" autofocus data-home-search-input data-home-phrases='<?= esc($sampleQueriesJson) ?>' required />
                        <button class="btn" type="submit">Start search</button>
                    </form>
                    <?php if ($sampleQueries !== []): ?>
                        <div class="hero__suggestions">
                            <span>Popular now:</span>
                            <div class="hero__chips">
                                <?php foreach ($sampleQueries as $query): ?>
                                    <button type="button" class="chip" data-home-suggestion="<?= esc($query) ?>"><?= esc($query) ?></button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                <aside class="hero__panel">
                    <div class="panel">
                        <h2>How the stack works together</h2>
                        <ul class="panel__list">
                            <li><strong>Fresh crawls:</strong> automated fetches deliver new briefings every few minutes.</li>
                            <li><strong>Context on tap:</strong> entity linking keeps topics, markets and people connected.</li>
                            <li><strong>Shareable outputs:</strong> export clean summaries with citations in one click.</li>
                        </ul>
                        <div class="panel__stats">
                            <div class="panel__stat">
                                <span class="panel__value">90s</span>
                                <span class="panel__label">average refresh cadence</span>
                            </div>
                            <div class="panel__stat">
                                <span class="panel__value">1 cache</span>
                                <span class="panel__label">shared across every module</span>
                            </div>
                            <div class="panel__stat">
                                <span class="panel__value">0</span>
                                <span class="panel__label">manual hand-offs required</span>
                            </div>
                        </div>
                    </div>
                </aside>
            </div>
        </section>
        <section class="home-section">
            <div class="home-section__inner">
                <div class="section-heading">
                    <h2>From crawl to briefing without leaving the frame</h2>
                    <p>Each module is tuned for research teams but powered by the same live intelligence feed.</p>
                </div>
                <div class="feature-grid">
                    <?php foreach ($systemHighlights as $highlight): ?>
                        <article class="feature-card">
                            <span class="feature-card__kicker"><?= esc($highlight['kicker']) ?></span>
                            <h3 class="feature-card__title"><?= esc($highlight['title']) ?></h3>
                            <p class="feature-card__description"><?= esc($highlight['description']) ?></p>
                            <?php if (($highlight['points'] ?? []) !== []): ?>
                                <ul class="feature-card__list">
                                    <?php foreach ($highlight['points'] as $point): ?>
                                        <li><?= esc($point) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
        <?php if ($intelligenceSnapshots !== []): ?>
        <section class="home-section home-section--intelligence">
            <div class="home-section__inner">
                <div class="section-heading">
                    <h2>Intelligence orchestrator</h2>
                    <p>Machine learning keeps crawl, graph and research surfaces aligned in real time.</p>
                </div>
                <div class="intelligence-meta">
                    <?php if ($intelligenceGeneratedAt !== null): ?>
                        <span>Generated <?= esc($intelligenceGeneratedAt) ?></span>
                    <?php endif; ?>
                    <?php if ($intelligenceModelVersion !== null): ?>
                        <span>Model <?= esc(substr($intelligenceModelVersion, 0, 12)) ?></span>
                    <?php endif; ?>
                </div>
                <div class="intelligence-grid">
                    <?php foreach ($intelligenceSnapshots as $snapshot): ?>
                        <?php
                        $snapshotHighlights = isset($snapshot['highlights']) && is_array($snapshot['highlights']) ? $snapshot['highlights'] : [];
                        $snapshotEntities = isset($snapshot['entities']) && is_array($snapshot['entities']) ? $snapshot['entities'] : [];
                        $snapshotSources = isset($snapshot['sources']) && is_array($snapshot['sources']) ? $snapshot['sources'] : [];
                        $snapshotActions = isset($snapshot['next_actions']) && is_array($snapshot['next_actions']) ? $snapshot['next_actions'] : [];
                        $scoreValue = isset($snapshot['score']) ? (float) $snapshot['score'] : 0.0;
                        $scorePercent = $scoreValue * 100;
                        ?>
                        <article class="intelligence-card">
                            <header class="intelligence-card__header">
                                <div>
                                    <span class="intelligence-card__eyebrow">Query</span>
                                    <h3 class="intelligence-card__title"><?= esc($snapshot['query'] ?? '') ?></h3>
                                </div>
                                <div class="intelligence-card__score">
                                    <span class="intelligence-card__score-value"><?= esc(number_format($scorePercent, 0)) ?><span class="intelligence-card__score-unit">%</span></span>
                                    <span class="intelligence-card__score-label"><?= esc($snapshot['label'] ?? '') ?></span>
                                </div>
                            </header>
                            <?php if ($snapshotEntities !== []): ?>
                                <ul class="intelligence-card__entities">
                                    <?php foreach (array_slice($snapshotEntities, 0, 3) as $entity): ?>
                                        <li><?= esc($entity) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                            <?php if ($snapshotSources !== []): ?>
                                <p class="intelligence-card__sources"><strong>Sources:</strong> <?= esc(implode(', ', array_slice($snapshotSources, 0, 4))) ?></p>
                            <?php endif; ?>
                            <?php if ($snapshotHighlights !== []): ?>
                                <ul class="intelligence-card__highlights">
                                    <?php foreach (array_slice($snapshotHighlights, 0, 3) as $highlight): ?>
                                        <li><?= esc($highlight) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                            <?php if ($snapshotActions !== []): ?>
                                <footer class="intelligence-card__footer">
                                    <h4>Recommended actions</h4>
                                    <ul>
                                        <?php foreach (array_slice($snapshotActions, 0, 2) as $action): ?>
                                            <?php if (!is_array($action)) { continue; } ?>
                                            <li><strong><?= esc($action['label'] ?? '') ?>:</strong> <?= esc($action['reason'] ?? '') ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </footer>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <section class="home-section home-section--workflow">
            <div class="home-section__inner">
                <div class="section-heading">
                    <h2>Everything stays in sync</h2>
                    <p>Our workflow keeps analysts in the loop even when the crawler pauses for maintenance.</p>
                </div>
                <ol class="workflow">
                    <?php foreach ($workflowSteps as $index => $step): ?>
                        <li class="workflow__step">
                            <span class="workflow__index">0<?= esc((string) ($index + 1)) ?></span>
                            <h3><?= esc($step['title']) ?></h3>
                            <p><?= esc($step['description']) ?></p>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </div>
        </section>
        <section class="home-section home-section--cta">
            <div class="home-section__inner home-section__inner--cta">
                <div class="cta-panel">
                    <h2>Put the intelligence frame to work</h2>
                    <p>Drop a question into the search bar and watch the crawler, graph and briefings align automatically.</p>
                    <form class="searchbox cta-panel__search" action="<?= esc($searchPath) ?>" method="get" role="search" aria-label="Search AIresearch" data-home-search>
                        <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M15.5 14h-.79l-.28-.27A6.471 6.471 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79L20 21.5 21.5 20l-6-6zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>
                        <label for="cta-query" class="sr-only">Search</label>
                        <input id="cta-query" type="search" name="q" placeholder="Ask about any sector" data-home-search-input data-home-phrases='<?= esc($sampleQueriesJson) ?>' required />
                        <button class="btn" type="submit">Run query</button>
                    </form>
                    <div class="cta-panel__links">
                        <a href="<?= esc($searchPath) ?>">Explore live results</a>
                        <a href="<?= esc($knowledgePath) ?>">Review the latest knowledge graph</a>
                    </div>
                </div>
            </div>
        </section>
    </main>
    <footer class="home-footer">© <?= date('Y') ?> AIresearch · Fast briefings from your crawler</footer>
    <script src="<?= esc($homeScriptPath . '?v=' . $homeScriptVersion) ?>" defer></script>
</body>
</html>
