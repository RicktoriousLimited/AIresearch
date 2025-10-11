<?php

declare(strict_types=1);

use App\Web\PathResolver;
use App\Web\SiteLayout;

$state = require __DIR__ . '/knowledge-graph-state.php';

$assetBase = $state['assetBase'];
$assets = $state['assets'];
$versions = $state['versions'];
$navigationPaths = $state['navigationPaths'];
$escape = $state['escape'];
$formatNumber = $state['formatNumber'];
$formatDate = $state['formatDate'];
$heroDigest = $state['heroDigest'];
$trendingTopics = array_slice($state['trendingTopics'], 0, 6);
$graphCoverageSignals = $state['graphCoverageSignals'];
$graphTimeline = $state['graphTimeline'];
$spotlight = $state['spotlight'];
$summary = $state['summary'];
$sources = $state['sources'];
$entities = $state['entities'];
$relations = $state['relations'];
$synonymGroups = $state['synonymGroups'];
$triples = $state['triples'];
$hasGraph = (bool) $state['hasGraph'];
$initialJson = $state['initialJson'];
$autocompleteJson = $state['autocompleteJson'];
$graphRepositoryPath = (string) ($state['initialState']['paths']['graph'] ?? '');

$hubPath = PathResolver::url($assetBase, 'knowledge-graph.php');
$overviewPath = PathResolver::url($assetBase, 'knowledge-graph-overview.php');
$autopilotPath = PathResolver::url($assetBase, 'knowledge-graph-autopilot.php');
$researchPath = PathResolver::url($assetBase, 'knowledge-graph-research.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Autopilot research briefs &ndash; AIresearch</title>
    <link rel="stylesheet" href="<?= $escape($assets['theme'] . '?v=' . $versions['theme']) ?>">
    <link rel="stylesheet" href="<?= $escape($assets['styles'] . '?v=' . $versions['styles']) ?>">
    <link rel="stylesheet" href="<?= $escape($assets['research'] . '?v=' . $versions['research']) ?>">
</head>
<body class="site site--graph">
<?php SiteLayout::renderHeader($navigationPaths, 'graph', [
    ['label' => 'Graph hub', 'href' => $hubPath],
    ['label' => 'Graph overview', 'href' => $overviewPath],
    ['label' => 'Research console', 'href' => $researchPath],
    ['label' => 'Launch search', 'href' => $state['searchPath']],
]); ?>
<main class="site-main graph-main graph-main--autopilot">
    <section class="autopilot-hero">
        <div class="site-container autopilot-hero__shell">
            <div class="autopilot-hero__intro">
                <span class="autopilot-hero__eyebrow">Report automation</span>
                <h1>Autopilot research briefs</h1>
                <p class="autopilot-hero__lead">Create a concise brief from the latest graph-backed evidence.</p>
            </div>
            <?php if ($trendingTopics !== []): ?>
                <div class="autopilot-hero__suggestions">
                    <span class="autopilot-hero__label">Suggested prompts</span>
                    <div class="autopilot-hero__chips">
                        <?php foreach ($trendingTopics as $topic): ?>
                            <button type="button" class="graph-suggestions__chip" data-graph-suggestion data-query="<?= $escape($topic) ?>"><?= $escape($topic) ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </section>
    <div class="graph-shell site-container">
        <section class="panel autopilot-panel">
            <header class="panel-header">
                <div>
                    <h2>Generate a brief</h2>
                    <p class="panel-subtitle">Provide a focus area and let the graph assemble the essentials.</p>
                </div>
            </header>
            <div class="grid autopilot-grid">
                <article class="card">
                    <h3>Compose a prompt</h3>
                    <form class="report-form autopilot-form" data-report-form>
                        <div class="form-group">
                            <label for="report-query">Focus area</label>
                            <textarea id="report-query" data-report-query placeholder="e.g. Cross-industry AI investments in 2024" spellcheck="false"></textarea>
                            <p class="help-text">Autopilot compares every stored source, keeping only the strongest citations.</p>
                        </div>
                        <div class="autopilot-form__actions">
                            <div class="autopilot-form__status">
                                <p class="status" data-report-status hidden></p>
                                <small>Reports stay cached so you can revisit them instantly.</small>
                            </div>
                            <button type="submit" class="button primary">Generate brief</button>
                        </div>
                    </form>
                </article>
                <article class="card">
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
            </div>
        </section>
    </div>
    <section class="graph-note site-container" aria-label="Knowledge graph storage">
        <?php if ($graphRepositoryPath !== ''): ?>
            <p>Snapshots live at <code><?= $escape($graphRepositoryPath) ?></code>. Scrape extra URLs from the <a href="<?= $escape($state['homePath']) ?>">Data Preparation Studio</a> whenever you need more context.</p>
        <?php else: ?>
            <p>Scrape additional URLs from the <a href="<?= $escape($state['homePath']) ?>">Data Preparation Studio</a> to enrich the shared graph.</p>
        <?php endif; ?>
    </section>
</main>
<?php SiteLayout::renderFooter($navigationPaths, 'Unified knowledge graph powering AIresearch intelligence.'); ?>
<script>
    window.AIKnowledgeGraph = <?= $initialJson ?>;
</script>
<script src="<?= $escape($assets['autocomplete'] . '?v=' . $versions['autocomplete']) ?>" defer></script>
<script src="<?= $escape($assets['script'] . '?v=' . $versions['script']) ?>" defer></script>
</body>
</html>
