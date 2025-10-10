<?php

declare(strict_types=1);

require __DIR__ . '/../src/Ricktorious/Markets/bootstrap.php';

use Ricktorious\Markets\Insights\Report;

$scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php');
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
$stylesVersion = file_exists(__DIR__ . '/assets/styles.css') ? (string) filemtime(__DIR__ . '/assets/styles.css') : (string) time();
$company = trim((string) ($_GET['company'] ?? 'Acme Corp'));

$report = null;
$error = null;

try {
    $kernel = ricktorious_markets_kernel();
    $builder = $kernel->reportBuilder();

    if ($company !== '') {
        $report = $builder->build($company, ['limit' => 40]);
    }
} catch (\Throwable $exception) {
    $error = $exception->getMessage();
}

/**
 * @param array<int, array<string, mixed>> $timeline
 * @return array<int, array<string, mixed>>
 */
function slice_latest_entries(array $timeline, int $limit): array
{
    if ($timeline === []) {
        return [];
    }

    return array_slice($timeline, 0, $limit);
}

function esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function sentiment_class(string $label): string
{
    return match ($label) {
        'positive' => 'sentiment-positive',
        'negative' => 'sentiment-negative',
        default => 'sentiment-neutral',
    };
}

function score_label(float $score): string
{
    if ($score > 0.2) {
        return 'positive';
    }

    if ($score < -0.2) {
        return 'negative';
    }

    return 'neutral';
}

$sentimentSegments = $report instanceof Report ? $report->sentimentBySegment() : [];
$timeline = $report instanceof Report ? $report->timeline() : [];
$articles = $report instanceof Report ? $report->articles() : [];
$highlights = $report instanceof Report ? $report->highlights() : [];

$latestTimeline = slice_latest_entries($timeline, 5);
$latestArticles = array_slice($articles, 0, 6);

$signalsTracked = 0;
$currentAverage = null;

if ($sentimentSegments !== []) {
    $scores = [];
    foreach ($sentimentSegments as $data) {
        $signalsTracked += (int) ($data['article_count'] ?? 0);
        if (isset($data['current_score'])) {
            $scores[] = (float) $data['current_score'];
        }
    }

    if ($scores !== []) {
        $currentAverage = array_sum($scores) / count($scores);
    }
}

$latestSignal = $latestTimeline[0] ?? null;
$latestArticle = $latestArticles[0] ?? null;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ricktorious Markets Intelligence</title>
    <link rel="stylesheet" href="<?= esc($stylesPath . '?v=' . $stylesVersion); ?>">
</head>
<body>
    <header class="site-header">
        <div class="shell header-shell">
            <div class="brand">Ricktorious Markets</div>
            <nav class="primary-nav" aria-label="Primary">
                <a href="#overview">Overview</a>
                <a href="#sentiment">Sentiment</a>
                <a href="#signals">Signals</a>
                <a href="#updates">Latest updates</a>
            </nav>
            <div class="header-actions">
                <a class="button ghost" href="#signals">Jump to news</a>
                <a class="button primary" href="#report">Generate report</a>
            </div>
        </div>
    </header>

    <main>
        <section class="hero" id="report">
            <div class="shell hero-shell">
                <div class="hero-copy">
                    <p class="eyebrow">Autonomous capital markets intelligence</p>
                    <h1>Live market coverage for fast trading decisions.</h1>
                    <p class="lead">Track sentiment, breaking headlines, and persona-specific signals for any ticker without leaving the dashboard.</p>
                    <form method="get" class="query-form" autocomplete="off">
                        <label>
                            <span>Company or ticker</span>
                            <input type="text" name="company" value="<?= esc($company); ?>" placeholder="Acme Corp or ACME">
                        </label>
                        <button type="submit" class="button primary">Analyse coverage</button>
                    </form>
                    <?php if ($report instanceof Report): ?>
                        <dl class="metrics">
                            <div>
                                <dt>Average sentiment</dt>
                                <dd><?= $currentAverage === null ? '–' : esc(sprintf('%.2f', $currentAverage)); ?></dd>
                            </div>
                            <div>
                                <dt>Signals tracked</dt>
                                <dd><?= esc((string) $signalsTracked); ?></dd>
                            </div>
                            <div>
                                <dt>Latest refresh</dt>
                                <dd><?= esc($report->generatedAt()); ?></dd>
                            </div>
                        </dl>
                    <?php endif; ?>
                </div>
                <div class="hero-card">
                    <h2>What you get</h2>
                    <ul class="feature-list">
                        <li>Persona-level sentiment with intraday shifts.</li>
                        <li>Ranked highlights and narrative drivers.</li>
                        <li>Instant access to the underlying headlines.</li>
                    </ul>
                    <?php if ($latestArticle !== null): ?>
                        <div class="hero-highlight">
                            <p class="hero-highlight__label">Most recent headline</p>
                            <p class="hero-highlight__title"><?= esc((string) ($latestArticle['title'] ?? '')); ?></p>
                            <p class="hero-highlight__meta"><?= esc((string) ($latestArticle['source'] ?? '')); ?> · <?= esc((string) ($latestArticle['published_at'] ?? '')); ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <div class="shell">
            <?php if ($error !== null): ?>
                <div class="messages">
                    <div class="message error"><?= esc($error); ?></div>
                </div>
            <?php elseif ($report === null): ?>
                <div class="empty-state">
                    <h2>Enter a company to begin</h2>
                    <p>We will trawl analyst notes, institutional briefings, and retail chatter automatically.</p>
                </div>
            <?php else: ?>
                <section class="dashboard" id="overview">
                    <div class="section-header">
                        <div>
                            <h2>Market pulse overview</h2>
                            <p class="muted"><?= esc($report->overview()); ?></p>
                        </div>
                        <div class="overview-meta">
                            <span class="badge"><?= esc($report->company()); ?></span>
                            <span class="muted">Generated <?= esc($report->generatedAt()); ?></span>
                        </div>
                    </div>

                    <?php if ($highlights !== []): ?>
                        <ul class="tag-list highlights">
                            <?php foreach ($highlights as $highlight): ?>
                                <li>• <?= esc($highlight); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <div class="scoreboard">
                        <article class="score-card">
                            <h3>Live sentiment</h3>
                            <p class="score-value <?= sentiment_class(score_label((float) ($currentAverage ?? 0.0))); ?>">
                                <?= $currentAverage === null ? 'No data' : esc(sprintf('%.2f', $currentAverage)); ?>
                            </p>
                            <p class="muted">Across <?= esc((string) count($sentimentSegments)); ?> investor segments.</p>
                        </article>
                        <article class="score-card">
                            <h3>Signals processed</h3>
                            <p class="score-value"><?= esc((string) $signalsTracked); ?></p>
                            <p class="muted">Latest run captured from curated news and notes.</p>
                        </article>
                        <article class="score-card">
                            <h3>Freshest signal</h3>
                            <?php if ($latestSignal !== null): ?>
                                <p class="score-value"><?= esc((string) ($latestSignal['date'] ?? '')); ?></p>
                                <p class="muted">Focus: <?= esc(ucfirst((string) ($latestSignal['segment'] ?? ''))); ?></p>
                            <?php else: ?>
                                <p class="score-value">No feed</p>
                                <p class="muted">Waiting for the first sentiment entry.</p>
                            <?php endif; ?>
                        </article>
                    </div>
                </section>

                <section class="insights" id="sentiment">
                    <div class="section-header">
                        <div>
                            <h2>Investor sentiment</h2>
                            <p class="muted">Compare how each desk feels about <?= esc($report->company()); ?>.</p>
                        </div>
                    </div>

                    <?php if ($sentimentSegments === []): ?>
                        <div class="empty-state">
                            <p>No sentiment records yet.</p>
                        </div>
                    <?php else: ?>
                        <div class="insight-grid sentiment-grid">
                            <?php foreach ($sentimentSegments as $segment => $data): ?>
                                <article class="insight-card">
                                    <h3><?= esc(ucfirst((string) $segment)); ?> investors</h3>
                                    <p class="metric <?= sentiment_class((string) ($data['current_label'] ?? 'neutral')); ?>">
                                        <?= esc(sprintf('%.2f', (float) ($data['current_score'] ?? 0.0))); ?>
                                    </p>
                                    <p class="muted">Current tone: <?= esc((string) ($data['current_label'] ?? 'neutral')); ?></p>
                                    <p>Average: <strong><?= esc(sprintf('%.2f', (float) ($data['average_score'] ?? 0.0))); ?></strong> (<?= esc((string) ($data['average_label'] ?? 'neutral')); ?>)</p>
                                    <?php if (($data['latest_headline'] ?? '') !== ''): ?>
                                        <p class="muted">Latest signal: “<?= esc((string) $data['latest_headline']); ?>”</p>
                                    <?php endif; ?>
                                    <p class="muted">Signals tracked: <?= esc((string) ($data['article_count'] ?? 0)); ?></p>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="timeline" id="updates">
                    <div class="section-header">
                        <div>
                            <h2>Latest updates</h2>
                            <p class="muted">Spot how sentiment evolved in the most recent coverage.</p>
                        </div>
                    </div>

                    <?php if ($latestTimeline === []): ?>
                        <div class="empty-state">
                            <p>Not enough coverage yet to chart a trajectory.</p>
                        </div>
                    <?php else: ?>
                        <div class="updates-grid">
                            <?php foreach ($latestTimeline as $entry): ?>
                                <?php $score = (float) ($entry['score'] ?? 0.0); $label = score_label($score); ?>
                                <article class="update-card">
                                    <header>
                                        <p class="update-date"><?= esc((string) ($entry['date'] ?? '')); ?></p>
                                        <span class="badge <?= sentiment_class($label); ?>"><?= esc($label); ?></span>
                                    </header>
                                    <h3><?= esc(ucfirst((string) ($entry['segment'] ?? ''))); ?> desk</h3>
                                    <p class="update-score">Score <?= esc(sprintf('%.2f', $score)); ?></p>
                                    <?php $signals = (array) ($entry['articles'] ?? []); ?>
                                    <?php if ($signals !== []): ?>
                                        <ul class="update-signals">
                                            <?php foreach ($signals as $signal): ?>
                                                <li><?= esc($signal); ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="articles" id="signals">
                    <div class="section-header">
                        <div>
                            <h2>Signals analysed</h2>
                            <p class="muted">Dive into the underlying headlines powering the sentiment scores.</p>
                        </div>
                    </div>

                    <?php if ($latestArticles === []): ?>
                        <div class="empty-state">
                            <p>No individual news items were retrieved.</p>
                        </div>
                    <?php else: ?>
                        <div class="article-grid">
                            <?php foreach ($latestArticles as $article): ?>
                                <article class="article-card">
                                    <header>
                                        <h3><a href="<?= esc((string) ($article['url'] ?? '#')); ?>" target="_blank" rel="noopener"><?= esc((string) ($article['title'] ?? '')); ?></a></h3>
                                        <p class="muted"><?= esc((string) ($article['source'] ?? '')); ?> · <?= esc((string) ($article['published_at'] ?? '')); ?></p>
                                    </header>
                                    <p><?= esc((string) ($article['summary'] ?? '')); ?></p>
                                    <?php $sentiment = $article['sentiment'] ?? []; ?>
                                    <p class="muted">Sentiment: <span class="badge <?= sentiment_class((string) ($sentiment['label'] ?? 'neutral')); ?>"><?= esc((string) ($sentiment['label'] ?? 'neutral')); ?></span> (<?= esc(sprintf('%.2f', (float) ($sentiment['score'] ?? 0.0))); ?>)</p>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        </div>
    </main>

    <footer class="site-footer">
        <div class="shell">
            <p>Built for analysts who need conviction in seconds.</p>
        </div>
    </footer>
</body>
</html>
