<?php

declare(strict_types=1);

use App\Web\PathResolver;
use App\Web\SiteLayout;

$state = require __DIR__ . '/../knowledge-graph-state.php';

$assetBase = $state['assetBase'];
$assets = $state['assets'];
$versions = $state['versions'];
$navigationLinks = $state['navigationLinks'];
$heroDigest = $state['heroDigest'];
$graphCoverageSignals = $state['graphCoverageSignals'];
$graphTimeline = $state['graphTimeline'];
$spotlight = is_array($state['spotlight'] ?? null) ? $state['spotlight'] : [];
$trendingTopics = array_slice($state['trendingTopics'], 0, 8);
$initialState = $state['initialState'];

$overviewPath = PathResolver::url($assetBase, 'knowledge-graph-overview.php');
$autopilotPath = PathResolver::url($assetBase, 'knowledge-graph-autopilot.php');
$researchPath = PathResolver::url($assetBase, 'knowledge-graph-research.php');

$graphRepositoryPath = (string) ($initialState['paths']['graph'] ?? '');

$sharedStylesPath = $assets['styles'] ?? PathResolver::url($assetBase, 'assets/styles.css');
$sharedStylesVersion = $versions['styles'] ?? (file_exists(__DIR__ . '/../assets/styles.css') ? (string) filemtime(__DIR__ . '/../assets/styles.css') : (string) time());
$adminStylesPath = PathResolver::url($assetBase, 'assets/admin.css');
$adminStylesVersion = file_exists(__DIR__ . '/../assets/admin.css') ? (string) filemtime(__DIR__ . '/../assets/admin.css') : (string) time();

$workspaceCards = [
    [
        'title' => 'Insight overview',
        'description' => 'Review coverage, entity growth, and supporting evidence in one glance.',
        'href' => $overviewPath,
        'action' => 'Open overview',
    ],
    [
        'title' => 'Autopilot briefs',
        'description' => 'Spin up graph-backed briefs with curated citations and highlights.',
        'href' => $autopilotPath,
        'action' => 'Launch brief builder',
    ],
    [
        'title' => 'Research console',
        'description' => 'Monitor crawls, ingest fresh sources, and prioritise new leads.',
        'href' => $researchPath,
        'action' => 'Visit console',
    ],
];

$siteIntegrations = [
    [
        'title' => 'Search workspace',
        'description' => 'Query the graph, autocomplete trending topics, and compare sources side-by-side.',
        'href' => $state['searchPath'],
    ],
    [
        'title' => 'Data preparation studio',
        'description' => 'Scrape fresh URLs, clean content, and push structured data into the graph.',
        'href' => $state['homePath'],
    ],
    [
        'title' => 'Graph documentation',
        'description' => 'Review API endpoints, schema guidance, and automation workflows.',
        'href' => $state['docsPath'],
    ],
];

function esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Signal Ledger · Knowledge graph workspace</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="<?= esc($sharedStylesPath . '?v=' . $sharedStylesVersion); ?>">
    <link rel="stylesheet" href="<?= esc($adminStylesPath . '?v=' . $adminStylesVersion); ?>">
</head>
<body class="backend-admin">
<?php SiteLayout::renderHeader($navigationLinks, 'graph'); ?>
<main class="backend-admin__main">
    <header class="card admin-page-header">
        <div>
            <h1>Knowledge graph workspace</h1>
            <p class="admin-page-header__meta">Monitor overall graph health, jump into focused workspaces, and keep ingestion moving.</p>
        </div>
        <div class="admin-page-header__actions">
            <a class="pill-link" href="<?= esc($overviewPath); ?>">Open overview</a>
            <a class="pill-link ghost" href="<?= esc($researchPath); ?>">Research console</a>
        </div>
    </header>

    <section class="card">
        <h2>Graph health summary</h2>
        <?php if ($heroDigest !== []): ?>
            <div class="summary-grid">
                <?php foreach ($heroDigest as $metric): ?>
                    <?php $metricLabel = (string) ($metric['label'] ?? ''); ?>
                    <?php $metricValue = (string) ($metric['value'] ?? ''); ?>
                    <?php if ($metricLabel === '' || $metricValue === '') { continue; } ?>
                    <div class="summary-card">
                        <h3><?= esc($metricLabel); ?></h3>
                        <p><strong><?= esc($metricValue); ?></strong></p>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="muted">Run a crawl or ingest sources to populate the knowledge graph.</p>
        <?php endif; ?>

        <?php if ($graphCoverageSignals !== []): ?>
            <dl class="progress-grid">
                <?php foreach ($graphCoverageSignals as $signal): ?>
                    <?php $label = (string) ($signal['label'] ?? ''); ?>
                    <?php $value = (string) ($signal['value'] ?? ''); ?>
                    <?php $hint = (string) ($signal['hint'] ?? ''); ?>
                    <?php if ($label === '' || $value === '') { continue; } ?>
                    <div>
                        <dt><?= esc($label); ?></dt>
                        <dd><?= esc($value); ?></dd>
                        <?php if ($hint !== ''): ?>
                            <p class="muted admin-text-xxs admin-space-top-xxs"><?= esc($hint); ?></p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </dl>
        <?php endif; ?>

        <?php if ($graphTimeline !== []): ?>
            <h3 class="admin-space-top-md admin-text-sm">Recent ingestion pace</h3>
            <dl class="progress-grid">
                <?php foreach ($graphTimeline as $bucket): ?>
                    <?php $label = (string) ($bucket['label'] ?? ''); ?>
                    <?php $count = (int) ($bucket['count'] ?? 0); ?>
                    <?php if ($label === '') { continue; } ?>
                    <div>
                        <dt><?= esc($label); ?></dt>
                        <dd><?= esc(number_format($count)); ?> sources</dd>
                    </div>
                <?php endforeach; ?>
            </dl>
        <?php endif; ?>
    </section>

    <section class="card">
        <h2>Search &amp; suggested prompts</h2>
        <form class="admin-space-top-sm" method="get" action="<?= esc($overviewPath); ?>">
            <label for="graph-search">Search the knowledge graph</label>
            <input id="graph-search" name="q" type="search" placeholder="Search people, organisations, relations&hellip;" spellcheck="false">
            <button type="submit">Search graph</button>
        </form>
        <?php if ($trendingTopics !== []): ?>
            <div class="recommended">
                <span>Suggested queries</span>
                <?php foreach ($trendingTopics as $topic): ?>
                    <?php $topic = (string) $topic; ?>
                    <?php if ($topic === '') { continue; } ?>
                    <?php $href = $overviewPath . '?' . http_build_query(['q' => $topic]); ?>
                    <a href="<?= esc($href); ?>" class="pill-link ghost"><?= esc($topic); ?></a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="card">
        <h2>Workspace shortcuts</h2>
        <div class="history-grid">
            <?php foreach ($workspaceCards as $card): ?>
                <?php if (!isset($card['title'], $card['description'], $card['href'], $card['action'])) { continue; } ?>
                <article class="card card--ghost">
                    <h3><?= esc((string) $card['title']); ?></h3>
                    <p class="muted"><?= esc((string) $card['description']); ?></p>
                    <a class="pill-link" href="<?= esc((string) $card['href']); ?>"><?= esc((string) $card['action']); ?></a>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="card">
        <h2>Graph spotlight</h2>
        <?php if ($spotlight !== []): ?>
            <p class="muted">Highlighted triple sourced from the latest ingestion run.</p>
            <div class="admin-space-top-sm">
                <strong><?= esc((string) ($spotlight['subject'] ?? '')); ?></strong>
                <span class="muted"><?= esc((string) ($spotlight['relation'] ?? '')); ?></span>
                <strong><?= esc((string) ($spotlight['object'] ?? '')); ?></strong>
            </div>
            <?php $sourceTitle = (string) ($spotlight['source_title'] ?? ''); ?>
            <?php $sourceUrl = (string) ($spotlight['source_url'] ?? ''); ?>
            <?php $sourcePreview = (string) ($spotlight['source_preview'] ?? ''); ?>
            <div class="admin-space-top-sm">
                <?php if ($sourceTitle !== '' && $sourceUrl !== ''): ?>
                    <a class="admin-link" href="<?= esc($sourceUrl); ?>" target="_blank" rel="noopener"><?= esc($sourceTitle); ?></a>
                <?php elseif ($sourceTitle !== ''): ?>
                    <span class="muted"><?= esc($sourceTitle); ?></span>
                <?php endif; ?>
                <?php if ($sourcePreview !== ''): ?>
                    <p class="muted admin-space-top-xxs"><?= esc($sourcePreview); ?></p>
                <?php endif; ?>
                <?php $fetchedAt = (string) ($spotlight['fetched_at'] ?? ''); ?>
                <?php if ($fetchedAt !== ''): ?>
                    <p class="muted admin-text-xxs admin-no-margin-bottom">Fetched <?= esc($fetchedAt); ?></p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <p class="muted">The crawler has not surfaced a featured triple yet. Keep ingestion running to populate this section.</p>
        <?php endif; ?>
    </section>

    <section class="card">
        <h2>Use the graph across the site</h2>
        <div class="history-grid">
            <?php foreach ($siteIntegrations as $integration): ?>
                <?php $title = (string) ($integration['title'] ?? ''); ?>
                <?php $description = (string) ($integration['description'] ?? ''); ?>
                <?php $href = (string) ($integration['href'] ?? ''); ?>
                <?php if ($title === '' || $href === '') { continue; } ?>
                <article class="card card--ghost">
                    <h3><?= esc($title); ?></h3>
                    <?php if ($description !== ''): ?>
                        <p class="muted"><?= esc($description); ?></p>
                    <?php endif; ?>
                    <a class="pill-link ghost" href="<?= esc($href); ?>">Visit</a>
                </article>
            <?php endforeach; ?>
        </div>
        <?php if ($graphRepositoryPath !== ''): ?>
            <p class="muted admin-space-top-md">Snapshots live at <code><?= esc($graphRepositoryPath); ?></code>.</p>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
