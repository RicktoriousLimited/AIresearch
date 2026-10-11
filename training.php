<?php

declare(strict_types=1);

require __DIR__ . '/src/App/bootstrap.php';

use App\Web\PathResolver;
use App\Web\SiteLayout;

function esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$paths = PathResolver::resolve();
$assetBase = PathResolver::normalizeBase($paths['assetBase']);

$themePath = PathResolver::url($assetBase, 'assets/theme.css');
$stylesPath = PathResolver::url($assetBase, 'assets/styles.css');
$trainingStylesPath = PathResolver::url($assetBase, 'assets/training.css');

$themeVersion = file_exists(__DIR__ . '/assets/theme.css') ? (string) filemtime(__DIR__ . '/assets/theme.css') : (string) time();
$stylesVersion = file_exists(__DIR__ . '/assets/styles.css') ? (string) filemtime(__DIR__ . '/assets/styles.css') : (string) time();
$trainingStylesVersion = file_exists(__DIR__ . '/assets/training.css') ? (string) filemtime(__DIR__ . '/assets/training.css') : (string) time();

$homePath = PathResolver::url($assetBase, 'index.php');
$searchPath = PathResolver::url($assetBase, 'search.php');
$graphPath = PathResolver::url($assetBase, 'knowledge-graph.php');
$docsPath = PathResolver::url($assetBase, 'docs');

$navigationLinks = [
    'home' => ['label' => 'Home', 'href' => $homePath],
    'search' => ['label' => 'Search', 'href' => $searchPath],
    'graph' => ['label' => 'Knowledge graph', 'href' => $graphPath],
    'training' => ['label' => 'Training', 'href' => PathResolver::url($assetBase, 'training.php')],
];

$footerLinks = [
    ['label' => 'Home', 'href' => $homePath],
    ['label' => 'Search', 'href' => $searchPath],
    ['label' => 'Knowledge graph', 'href' => $graphPath],
    ['label' => 'Research console', 'href' => PathResolver::url($assetBase, 'knowledge-graph-research.php')],
    ['label' => 'Docs', 'href' => $docsPath],
];

$heroMetrics = [
    [
        'label' => 'Analysts onboarded',
        'value' => '48',
        'detail' => '+12 this quarter',
    ],
    [
        'label' => 'Mean time to insight',
        'value' => '32 min',
        'detail' => 'Down 41% post-training',
    ],
    [
        'label' => 'Briefings published',
        'value' => '126',
        'detail' => 'With graph citations',
    ],
];

$learningTracks = [
    [
        'title' => 'Signal fundamentals',
        'duration' => '90 minutes',
        'description' => 'Master the unified search workflow, pivoting from raw crawls to curated entity views.',
        'outcomes' => [
            'Frame answerable questions using the crawler health dashboard.',
            'Apply smart filters to surface trustworthy, high-signal sources.',
            'Compose short research notes using cached highlights and snippets.',
        ],
    ],
    [
        'title' => 'Graph investigations',
        'duration' => '2 hours',
        'description' => 'Link entities, relations, and supporting passages to build defensible narratives.',
        'outcomes' => [
            'Interpret relation histograms to spot emerging competitors or partners.',
            'Trace citation chains back to original documents for verification.',
            'Export knowledge graph slices for stakeholder briefings.',
        ],
    ],
    [
        'title' => 'Automation playbooks',
        'duration' => '75 minutes',
        'description' => 'Automate ingestion and briefing prep with reusable command-line routines.',
        'outcomes' => [
            'Schedule ingestion refreshes with `research.php` and guardrails.',
            'Batch score and archive entity facts for weekly reporting.',
            'Trigger Autopilot briefs with custom prompt templates.',
        ],
    ],
];

$journeyPhases = [
    [
        'title' => 'Discover',
        'summary' => 'Kick off with a live crawl preview and learn to scope research questions.',
        'activities' => [
            'Live walkthrough of crawler controls and ingestion metrics.',
            'Group exercise drafting investigation prompts in the search workspace.',
        ],
    ],
    [
        'title' => 'Investigate',
        'summary' => 'Blend search, graph, and entity insights to assemble a confident storyline.',
        'activities' => [
            'Hands-on lab mapping entity relations to spot blind spots.',
            'Pair exercise reviewing confidence scores and source overlap.',
        ],
    ],
    [
        'title' => 'Deliver',
        'summary' => 'Package insights for executives with reusable reporting patterns.',
        'activities' => [
            'Autopilot brief creation with inline edits and citations.',
            'Export clinic covering CSV, PDF, and workspace share links.',
        ],
    ],
];

$workshops = [
    [
        'title' => 'Crawler operations clinic',
        'cadence' => 'Tuesdays',
        'time' => '15:00 UTC',
        'duration' => '45 min',
        'focus' => 'Tune ingestion rules, manage retries, and monitor pipeline health.',
    ],
    [
        'title' => 'Graph storytelling lab',
        'cadence' => 'Wednesdays',
        'time' => '17:00 UTC',
        'duration' => '60 min',
        'focus' => 'Translate entity clusters into narrative arcs with supporting evidence.',
    ],
    [
        'title' => 'Briefing automation sprint',
        'cadence' => 'Thursdays',
        'time' => '16:30 UTC',
        'duration' => '40 min',
        'focus' => 'Use templates and command-line tooling to ship publish-ready briefs.',
    ],
];

$resourceLibrary = [
    [
        'title' => 'AIresearch operator handbook',
        'type' => 'Guide',
        'length' => '32 pages',
        'href' => PathResolver::url($assetBase, 'docs/deployment.md'),
        'description' => 'Platform architecture, deployment recipes, and access controls for admins.',
    ],
    [
        'title' => 'Crawler quality checklist',
        'type' => 'Checklist',
        'length' => '12 steps',
        'href' => PathResolver::url($assetBase, 'docs/data-quality-roadmap.md'),
        'description' => 'Daily review cadence to keep ingestion clean, deduplicated, and reliable.',
    ],
    [
        'title' => 'Workbench quickstart',
        'type' => 'Playbook',
        'length' => '15 minutes',
        'href' => PathResolver::url($assetBase, 'docs/workbench.md'),
        'description' => 'Spin up the analyst workbench, replay searches, and annotate findings.',
    ],
];

$ctaLinks = [
    [
        'label' => 'Book a cohort',
        'href' => 'mailto:training@airesearch.example',
        'class' => 'button--primary',
    ],
    [
        'label' => 'Launch workspace',
        'href' => $searchPath,
        'class' => 'button--ghost',
    ],
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Training &ndash; AIresearch</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="<?= esc($themePath . '?v=' . $themeVersion) ?>">
    <link rel="stylesheet" href="<?= esc($stylesPath . '?v=' . $stylesVersion) ?>">
    <link rel="stylesheet" href="<?= esc($trainingStylesPath . '?v=' . $trainingStylesVersion) ?>">
</head>
<body class="site training-site">
<?php SiteLayout::renderHeader($navigationLinks, 'training', [
    ['label' => 'Launch search', 'href' => $searchPath, 'class' => 'button--ghost'],
]); ?>
<main class="site-main training-main">
    <section class="training-hero">
        <div class="training-container">
            <div class="training-hero__content">
                <p class="eyebrow">Analyst enablement</p>
                <h1>Level up every investigation with guided practice</h1>
                <p class="lead">From first crawl to executive-ready briefings, the AIresearch training programme equips your team with a repeatable workflow, live coaching, and ready-to-share templates.</p>
                <div class="training-hero__actions">
                    <?php foreach ($ctaLinks as $cta): ?>
                        <?php if (!isset($cta['label'], $cta['href'])) { continue; } ?>
                        <?php $ctaClassSuffix = isset($cta['class']) && is_string($cta['class']) ? trim($cta['class']) : ''; ?>
                        <?php $ctaClasses = trim('button ' . $ctaClassSuffix); ?>
                        <a class="<?= esc($ctaClasses) ?>" href="<?= esc($cta['href']) ?>"><?= esc($cta['label']) ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php if ($heroMetrics !== []): ?>
                <div class="training-hero__metrics" aria-label="Training impact metrics">
                    <?php foreach ($heroMetrics as $metric): ?>
                        <?php $metricLabel = (string) ($metric['label'] ?? ''); ?>
                        <?php $metricValue = (string) ($metric['value'] ?? ''); ?>
                        <?php $metricDetail = (string) ($metric['detail'] ?? ''); ?>
                        <?php if ($metricLabel === '' || $metricValue === '') { continue; } ?>
                        <article class="training-hero__metric">
                            <span class="training-hero__metric-value"><?= esc($metricValue) ?></span>
                            <span class="training-hero__metric-label"><?= esc($metricLabel) ?></span>
                            <?php if ($metricDetail !== ''): ?>
                                <span class="training-hero__metric-detail"><?= esc($metricDetail) ?></span>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="training-section">
        <div class="training-container">
            <header class="training-section__header">
                <p class="eyebrow">Programme tracks</p>
                <h2>Focused learning paths for every role</h2>
                <p class="section-lead">Blend self-paced modules with live workshops. Each track includes ready-to-use exercises so analysts can apply new skills to their active investigations.</p>
            </header>
            <div class="training-grid">
                <?php foreach ($learningTracks as $track): ?>
                    <?php $title = (string) ($track['title'] ?? ''); ?>
                    <?php $description = (string) ($track['description'] ?? ''); ?>
                    <?php if ($title === '' || $description === '') { continue; } ?>
                    <article class="training-track">
                        <header class="training-track__header">
                            <h3><?= esc($title) ?></h3>
                            <?php $duration = (string) ($track['duration'] ?? ''); ?>
                            <?php if ($duration !== ''): ?>
                                <span class="training-track__duration"><?= esc($duration) ?></span>
                            <?php endif; ?>
                        </header>
                        <p><?= esc($description) ?></p>
                        <?php if (isset($track['outcomes']) && is_array($track['outcomes']) && $track['outcomes'] !== []): ?>
                            <ul class="training-track__list">
                                <?php foreach ($track['outcomes'] as $outcome): ?>
                                    <?php if (!is_string($outcome)) { continue; } ?>
                                    <?php $trimmed = trim($outcome); ?>
                                    <?php if ($trimmed === '') { continue; } ?>
                                    <li><?= esc($trimmed) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="training-section training-section--journey">
        <div class="training-container">
            <header class="training-section__header">
                <p class="eyebrow">Experience arc</p>
                <h2>Guided journey from discovery to delivery</h2>
                <p class="section-lead">Every cohort rotates through themed labs that mirror the analyst workflow. Facilitators stay with the same group to provide context-aware coaching.</p>
            </header>
            <div class="training-journey">
                <?php foreach ($journeyPhases as $index => $phase): ?>
                    <?php $phaseTitle = (string) ($phase['title'] ?? ''); ?>
                    <?php if ($phaseTitle === '') { continue; } ?>
                    <article class="training-journey__phase">
                        <div class="training-journey__badge" aria-hidden="true">Phase <?= esc((string) ($index + 1)) ?></div>
                        <h3><?= esc($phaseTitle) ?></h3>
                        <?php $summary = (string) ($phase['summary'] ?? ''); ?>
                        <?php if ($summary !== ''): ?>
                            <p><?= esc($summary) ?></p>
                        <?php endif; ?>
                        <?php if (isset($phase['activities']) && is_array($phase['activities']) && $phase['activities'] !== []): ?>
                            <ul>
                                <?php foreach ($phase['activities'] as $activity): ?>
                                    <?php if (!is_string($activity)) { continue; } ?>
                                    <?php $activityText = trim($activity); ?>
                                    <?php if ($activityText === '') { continue; } ?>
                                    <li><?= esc($activityText) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="training-section training-section--workshops">
        <div class="training-container">
            <header class="training-section__header">
                <p class="eyebrow">Live sessions</p>
                <h2>Weekly workshops to reinforce best practice</h2>
                <p class="section-lead">Join structured labs that pair short demos with guided implementation time. Sessions repeat monthly so new hires can plug in without waiting for the next cohort.</p>
            </header>
            <div class="training-table-wrapper">
                <table class="training-table">
                    <caption class="visually-hidden">Training workshops schedule</caption>
                    <thead>
                        <tr>
                            <th scope="col">Workshop</th>
                            <th scope="col">Cadence</th>
                            <th scope="col">Time</th>
                            <th scope="col">Duration</th>
                            <th scope="col">Focus</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($workshops as $workshop): ?>
                            <?php $title = (string) ($workshop['title'] ?? ''); ?>
                            <?php $focus = (string) ($workshop['focus'] ?? ''); ?>
                            <?php if ($title === '' || $focus === '') { continue; } ?>
                            <tr>
                                <th scope="row"><?= esc($title) ?></th>
                                <td><?= esc((string) ($workshop['cadence'] ?? '')) ?></td>
                                <td><?= esc((string) ($workshop['time'] ?? '')) ?></td>
                                <td><?= esc((string) ($workshop['duration'] ?? '')) ?></td>
                                <td><?= esc($focus) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section class="training-section training-section--resources">
        <div class="training-container">
            <header class="training-section__header">
                <p class="eyebrow">Resource library</p>
                <h2>Keep momentum between sessions</h2>
                <p class="section-lead">Reference guides, checklists, and playbooks stay updated with every product release. Share them in your team workspace or link directly from onboarding docs.</p>
            </header>
            <div class="training-resources">
                <?php foreach ($resourceLibrary as $resource): ?>
                    <?php $resourceTitle = (string) ($resource['title'] ?? ''); ?>
                    <?php $href = (string) ($resource['href'] ?? ''); ?>
                    <?php if ($resourceTitle === '' || $href === '') { continue; } ?>
                    <article class="training-resource">
                        <header>
                            <p class="training-resource__type"><?= esc((string) ($resource['type'] ?? '')) ?></p>
                            <h3><a href="<?= esc($href) ?>"><?= esc($resourceTitle) ?></a></h3>
                        </header>
                        <p><?= esc((string) ($resource['description'] ?? '')) ?></p>
                        <?php $length = (string) ($resource['length'] ?? ''); ?>
                        <?php if ($length !== ''): ?>
                            <p class="training-resource__length"><?= esc($length) ?></p>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
</main>
<?php SiteLayout::renderFooter($footerLinks, 'Fast briefings start with confident analysts.'); ?>
</body>
</html>
