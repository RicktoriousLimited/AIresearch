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

$messages = [];
$errors = [];

$formMetrics = $heroDigest;
$formTopics = $trendingTopics;
$spotlightDefaults = [
    'subject' => (string) ($spotlight['subject'] ?? ''),
    'relation' => (string) ($spotlight['relation'] ?? ''),
    'object' => (string) ($spotlight['object'] ?? ''),
    'source_title' => (string) ($spotlight['source_title'] ?? ''),
    'source_url' => (string) ($spotlight['source_url'] ?? ''),
    'source_preview' => (string) ($spotlight['source_preview'] ?? ''),
    'fetched_at' => (string) ($spotlight['fetched_at'] ?? ''),
];
$formSpotlight = $spotlightDefaults;

$generatedJson = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawMetrics = $_POST['metrics'] ?? [];
    $submittedMetrics = [];
    if (is_array($rawMetrics)) {
        foreach ($rawMetrics as $row) {
            if (!is_array($row)) {
                continue;
            }

            $label = trim((string) ($row['label'] ?? ''));
            $value = trim((string) ($row['value'] ?? ''));
            $hint = trim((string) ($row['hint'] ?? ''));

            if ($label === '' && $value === '' && $hint === '') {
                continue;
            }

            if ($label === '' || $value === '') {
                $errors[] = 'Each graph metric requires both a label and value.';
                continue;
            }

            $metric = ['label' => $label, 'value' => $value];
            if ($hint !== '') {
                $metric['hint'] = $hint;
            }

            $submittedMetrics[] = $metric;
        }
    }

    $topicsRaw = isset($_POST['topics_raw']) ? (string) $_POST['topics_raw'] : '';
    $topicsLines = preg_split('/\r\n|\r|\n/', $topicsRaw) ?: [];
    $submittedTopics = [];
    foreach ($topicsLines as $line) {
        $topic = trim((string) $line);
        if ($topic !== '') {
            $submittedTopics[] = $topic;
        }
    }
    $submittedTopics = array_values(array_unique($submittedTopics));

    $rawSpotlight = isset($_POST['spotlight']) && is_array($_POST['spotlight']) ? $_POST['spotlight'] : [];
    $submittedSpotlight = [
        'subject' => trim((string) ($rawSpotlight['subject'] ?? '')),
        'relation' => trim((string) ($rawSpotlight['relation'] ?? '')),
        'object' => trim((string) ($rawSpotlight['object'] ?? '')),
        'source_title' => trim((string) ($rawSpotlight['source_title'] ?? '')),
        'source_url' => trim((string) ($rawSpotlight['source_url'] ?? '')),
        'source_preview' => trim((string) ($rawSpotlight['source_preview'] ?? '')),
        'fetched_at' => trim((string) ($rawSpotlight['fetched_at'] ?? '')),
    ];

    $formMetrics = $submittedMetrics !== [] ? $submittedMetrics : $formMetrics;
    $formTopics = $topicsRaw !== '' ? $submittedTopics : $formTopics;
    $formSpotlight = array_merge($spotlightDefaults, $submittedSpotlight);

    $hasPayload = $submittedMetrics !== [] || $submittedTopics !== []
        || array_filter($submittedSpotlight, static fn(string $value): bool => $value !== '') !== [];

    if (!$hasPayload) {
        $errors[] = 'Provide at least one metric, topic, or spotlight value before generating the payload.';
    }

    if ($errors === []) {
        $payload = [];
        if ($submittedMetrics !== []) {
            $payload['heroDigest'] = $submittedMetrics;
        }
        if ($submittedTopics !== []) {
            $payload['trendingTopics'] = $submittedTopics;
        }
        $spotlightPayload = array_filter(
            $submittedSpotlight,
            static fn(string $value): bool => $value !== ''
        );
        if ($spotlightPayload !== []) {
            $payload['spotlight'] = $spotlightPayload;
        }

        $payload['generated_at'] = (new DateTimeImmutable())->format(DATE_ATOM);

        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded)) {
            $errors[] = 'Unable to encode the payload as JSON. Please check the submitted values.';
        } else {
            $generatedJson = $encoded;
            $messages[] = 'Draft payload generated. Copy the JSON below to update the knowledge-graph state or feed an automation.';
        }
    }
}

$overviewPath = PathResolver::url($assetBase, 'knowledge-graph-overview.php');
$autopilotPath = PathResolver::url($assetBase, 'knowledge-graph-autopilot.php');
$researchPath = PathResolver::url($assetBase, 'knowledge-graph-research.php');

$metricRows = max(count($formMetrics), 4);
$formTopicsString = $formTopics !== [] ? implode(PHP_EOL, $formTopics) : '';

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

    <?php if ($messages !== []): ?>
        <div class="messages" role="status">
            <p><?= esc($messages[0]); ?></p>
            <?php if (count($messages) > 1): ?>
                <ul>
                    <?php foreach (array_slice($messages, 1) as $message): ?>
                        <li><?= esc($message); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($errors !== []): ?>
        <div class="errors" role="alert">
            <p><?= esc($errors[0]); ?></p>
            <?php if (count($errors) > 1): ?>
                <ul>
                    <?php foreach (array_slice($errors, 1) as $error): ?>
                        <li><?= esc($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <nav class="admin-subnav" aria-label="Workspace sections">
        <a class="admin-subnav__link" href="#graph-health">Graph health</a>
        <a class="admin-subnav__link" href="#graph-spotlight">Spotlight triple</a>
        <a class="admin-subnav__link" href="#graph-editor">Data entry</a>
        <a class="admin-subnav__link" href="#graph-search">Graph search</a>
    </nav>

    <div class="admin-layout-grid">
        <section class="admin-panel admin-panel--primary">
            <article class="card admin-panel__section" id="graph-health">
                <header class="admin-panel__header">
                    <div>
                        <h2>Graph health</h2>
                        <p class="muted admin-text-xs">Digest key coverage signals and ingestion pace at a glance.</p>
                    </div>
                </header>
                <div class="admin-panel__body">
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
                        <div>
                            <h3 class="admin-card__title">Coverage signals</h3>
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
                        </div>
                    <?php endif; ?>

                    <?php if ($graphTimeline !== []): ?>
                        <div>
                            <h3 class="admin-card__title">Recent ingestion</h3>
                            <dl class="progress-grid progress-grid--timeline">
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
                        </div>
                    <?php endif; ?>
                </div>
            </article>

            <article class="card admin-panel__section" id="graph-spotlight">
                <h2>Graph spotlight</h2>
                <?php if ($spotlight !== []): ?>
                    <?php $sourceTitle = (string) ($spotlight['source_title'] ?? ''); ?>
                    <?php $sourceUrl = (string) ($spotlight['source_url'] ?? ''); ?>
                    <?php $sourcePreview = (string) ($spotlight['source_preview'] ?? ''); ?>
                    <?php $fetchedAt = (string) ($spotlight['fetched_at'] ?? ''); ?>
                    <div class="admin-spotlight">
                        <div class="admin-spotlight__triple">
                            <strong class="admin-spotlight__entity"><?= esc((string) ($spotlight['subject'] ?? '')); ?></strong>
                            <span class="admin-spotlight__relation"><?= esc((string) ($spotlight['relation'] ?? '')); ?></span>
                            <strong class="admin-spotlight__entity"><?= esc((string) ($spotlight['object'] ?? '')); ?></strong>
                        </div>
                        <?php if ($sourceTitle !== '' || $sourcePreview !== '' || $fetchedAt !== ''): ?>
                            <div class="admin-spotlight__meta">
                                <?php if ($sourceTitle !== '' && $sourceUrl !== ''): ?>
                                    <a class="admin-link" href="<?= esc($sourceUrl); ?>" target="_blank" rel="noopener"><?= esc($sourceTitle); ?></a>
                                <?php elseif ($sourceTitle !== ''): ?>
                                    <span class="muted"><?= esc($sourceTitle); ?></span>
                                <?php endif; ?>
                                <?php if ($sourcePreview !== ''): ?>
                                    <p class="muted admin-space-top-xxs"><?= esc($sourcePreview); ?></p>
                                <?php endif; ?>
                                <?php if ($fetchedAt !== ''): ?>
                                    <p class="muted admin-text-xxs admin-no-margin-bottom">Fetched <?= esc($fetchedAt); ?></p>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <p class="muted">The crawler has not surfaced a featured triple yet. Keep ingestion running to populate this section.</p>
                <?php endif; ?>
            </article>

            <article class="card admin-panel__section" id="graph-editor">
                <header class="admin-panel__header">
                    <div>
                        <h2>Graph data entry</h2>
                        <p class="muted admin-text-xs">Capture the metrics, suggested prompts, and spotlight triple you want to publish.</p>
                    </div>
                </header>
                <div class="admin-panel__body">
                    <form method="post" class="admin-form" action="#graph-editor">
                        <div class="admin-form-grid">
                            <fieldset class="admin-fieldset">
                                <legend>Hero metrics</legend>
                                <p class="muted admin-text-xs admin-space-top-xs">Provide up to four key stats for the hero header. Leave rows blank to skip them.</p>
                                <div class="admin-form-columns">
                                    <?php for ($index = 0; $index < $metricRows; $index++): ?>
                                        <?php $metric = $formMetrics[$index] ?? ['label' => '', 'value' => '', 'hint' => '']; ?>
                                        <?php $metricLabel = (string) ($metric['label'] ?? ''); ?>
                                        <?php $metricValue = (string) ($metric['value'] ?? ''); ?>
                                        <?php $metricHint = (string) ($metric['hint'] ?? ''); ?>
                                        <div class="admin-form-row">
                                            <label for="metric-label-<?= $index; ?>">Label</label>
                                            <input id="metric-label-<?= $index; ?>" name="metrics[<?= $index; ?>][label]" type="text" value="<?= esc($metricLabel); ?>" placeholder="Entities tracked">
                                            <label for="metric-value-<?= $index; ?>" class="admin-space-top-xs">Value</label>
                                            <input id="metric-value-<?= $index; ?>" name="metrics[<?= $index; ?>][value]" type="text" value="<?= esc($metricValue); ?>" placeholder="1,204">
                                            <label for="metric-hint-<?= $index; ?>" class="admin-space-top-xs">Hint <span class="muted">(optional)</span></label>
                                            <input id="metric-hint-<?= $index; ?>" name="metrics[<?= $index; ?>][hint]" type="text" value="<?= esc($metricHint); ?>" placeholder="Documents processed in the last 30 days">
                                        </div>
                                    <?php endfor; ?>
                                </div>
                            </fieldset>

                            <fieldset class="admin-fieldset">
                                <legend>Trending topics</legend>
                                <p class="muted admin-text-xs admin-space-top-xs">Paste one query per line to refresh the quick links in the sidebar.</p>
                                <textarea name="topics_raw" rows="8" placeholder="Semiconductor supply chain&#10;Emerging AI regulation"><?= esc($formTopicsString); ?></textarea>
                            </fieldset>
                        </div>

                        <fieldset class="admin-fieldset admin-fieldset--wide">
                            <legend>Spotlight triple</legend>
                            <div class="admin-form-columns admin-form-columns--triple">
                                <div class="admin-form-row">
                                    <label for="spotlight-subject">Subject</label>
                                    <input id="spotlight-subject" name="spotlight[subject]" type="text" value="<?= esc($formSpotlight['subject']); ?>" placeholder="Entity name">
                                </div>
                                <div class="admin-form-row">
                                    <label for="spotlight-relation">Relation</label>
                                    <input id="spotlight-relation" name="spotlight[relation]" type="text" value="<?= esc($formSpotlight['relation']); ?>" placeholder="announced">
                                </div>
                                <div class="admin-form-row">
                                    <label for="spotlight-object">Object</label>
                                    <input id="spotlight-object" name="spotlight[object]" type="text" value="<?= esc($formSpotlight['object']); ?>" placeholder="Supporting entity">
                                </div>
                            </div>
                            <div class="admin-form-columns admin-form-columns--meta">
                                <div class="admin-form-row">
                                    <label for="spotlight-source-title">Source title</label>
                                    <input id="spotlight-source-title" name="spotlight[source_title]" type="text" value="<?= esc($formSpotlight['source_title']); ?>" placeholder="Publication headline">
                                </div>
                                <div class="admin-form-row">
                                    <label for="spotlight-source-url">Source URL</label>
                                    <input id="spotlight-source-url" name="spotlight[source_url]" type="url" value="<?= esc($formSpotlight['source_url']); ?>" placeholder="https://example.com/story">
                                </div>
                                <div class="admin-form-row">
                                    <label for="spotlight-fetched">Fetched at</label>
                                    <input id="spotlight-fetched" name="spotlight[fetched_at]" type="text" value="<?= esc($formSpotlight['fetched_at']); ?>" placeholder="2024-03-12T15:22:00Z">
                                </div>
                            </div>
                            <label for="spotlight-preview">Source preview <span class="muted">(optional)</span></label>
                            <textarea id="spotlight-preview" name="spotlight[source_preview]" rows="4" placeholder="Key excerpt from the supporting document."><?= esc($formSpotlight['source_preview']); ?></textarea>
                        </fieldset>

                        <div class="admin-form-actions">
                            <button type="submit">Generate payload</button>
                            <button type="reset" class="button ghost">Reset form</button>
                        </div>
                    </form>

                    <?php if ($generatedJson !== null): ?>
                        <section class="admin-json-preview" aria-live="polite">
                            <header>
                                <h3>Generated JSON payload</h3>
                                <p class="muted admin-text-xs">Copy this block into <code>knowledge-graph-state.php</code> or send it to an ingestion endpoint.</p>
                            </header>
                            <textarea readonly rows="10" class="admin-json-output"><?= esc($generatedJson); ?></textarea>
                        </section>
                    <?php endif; ?>
                </div>
            </article>
        </section>

        <aside class="admin-panel admin-panel--secondary" aria-label="Workspace navigation">
            <section class="card admin-panel__section">
                <h2>Workspace shortcuts</h2>
                <nav aria-label="Workspace shortcuts">
                    <ul class="admin-quick-links">
                        <?php foreach ($workspaceCards as $card): ?>
                            <?php if (!isset($card['title'], $card['href'])) { continue; } ?>
                            <?php $title = (string) $card['title']; ?>
                            <?php $description = (string) ($card['description'] ?? ''); ?>
                            <?php $href = (string) $card['href']; ?>
                            <?php $action = (string) ($card['action'] ?? 'Open'); ?>
                            <?php if ($title === '' || $href === '') { continue; } ?>
                            <li>
                                <a class="admin-quick-link" href="<?= esc($href); ?>">
                                    <span class="admin-quick-link__title"><?= esc($title); ?></span>
                                    <?php if ($description !== ''): ?>
                                        <span class="admin-quick-link__description muted"><?= esc($description); ?></span>
                                    <?php endif; ?>
                                    <?php if ($action !== ''): ?>
                                        <span class="admin-quick-link__action"><?= esc($action); ?></span>
                                    <?php endif; ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </nav>
            </section>

            <section class="card admin-panel__section" id="graph-search">
                <h2>Search the graph</h2>
                <form method="get" action="<?= esc($overviewPath); ?>">
                    <label for="graph-search">Run a graph query</label>
                    <div class="admin-input-row">
                        <input id="graph-search" name="q" type="search" placeholder="Search people, organisations, relations&hellip;" spellcheck="false">
                        <button type="submit">Search</button>
                    </div>
                </form>
                <?php if ($trendingTopics !== []): ?>
                    <p class="muted admin-text-xxs admin-space-top-sm">Suggested queries</p>
                    <div class="admin-chip-group" role="list">
                        <?php foreach ($trendingTopics as $topic): ?>
                            <?php $topic = (string) $topic; ?>
                            <?php if ($topic === '') { continue; } ?>
                            <?php $href = $overviewPath . '?' . http_build_query(['q' => $topic]); ?>
                            <a class="admin-chip" href="<?= esc($href); ?>" role="listitem"><?= esc($topic); ?></a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <?php if ($siteIntegrations !== [] || $graphRepositoryPath !== ''): ?>
                <section class="card admin-panel__section" id="graph-integrations">
                    <h2>Integrations &amp; docs</h2>
                    <?php if ($siteIntegrations !== []): ?>
                        <ul class="admin-quick-links admin-quick-links--compact">
                            <?php foreach ($siteIntegrations as $integration): ?>
                                <?php $title = (string) ($integration['title'] ?? ''); ?>
                                <?php $description = (string) ($integration['description'] ?? ''); ?>
                                <?php $href = (string) ($integration['href'] ?? ''); ?>
                                <?php $action = (string) ($integration['action'] ?? 'Visit'); ?>
                                <?php if ($title === '' || $href === '') { continue; } ?>
                                <li>
                                    <a class="admin-quick-link" href="<?= esc($href); ?>">
                                        <span class="admin-quick-link__title"><?= esc($title); ?></span>
                                        <?php if ($description !== ''): ?>
                                            <span class="admin-quick-link__description muted"><?= esc($description); ?></span>
                                        <?php endif; ?>
                                        <?php if ($action !== ''): ?>
                                            <span class="admin-quick-link__action"><?= esc($action); ?></span>
                                        <?php endif; ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <?php if ($graphRepositoryPath !== ''): ?>
                        <p class="muted admin-text-xs admin-space-top-sm">Snapshots live at <code><?= esc($graphRepositoryPath); ?></code>.</p>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        </aside>
    </div>
</main>
</body>
</html>
