<?php

declare(strict_types=1);

require __DIR__ . '/../src/Ricktorious/Markets/bootstrap.php';

use Ricktorious\Markets\Insights\Report;

$company = trim((string) ($_GET['company'] ?? 'Acme Corp'));
$report = null;
$error = null;
$stylesPath = __DIR__ . '/assets/styles.css';
$stylesVersion = is_file($stylesPath) ? (string) filemtime($stylesPath) : (string) time();

try {
    $kernel = ricktorious_markets_kernel();
    $builder = $kernel->reportBuilder();

    if ($company !== '') {
        $report = $builder->build($company, ['limit' => 40]);
    }
} catch (\Throwable $exception) {
    $error = $exception->getMessage();
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

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Ricktorious Markets Intelligence</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="assets/styles.css?v=<?php echo esc($stylesVersion); ?>">
</head>
<body>
    <header class="site-header">
        <div class="shell header-shell">
            <div class="brand">Ricktorious Markets</div>
            <nav class="primary-nav">
                <a href="/index.php">Studio</a>
                <a href="/search.php">Discovery</a>
                <a href="/knowledge-graph.php">Knowledge graph</a>
                <a href="/markets.php" class="active">Markets</a>
            </nav>
            <div class="header-actions">
                <a class="button ghost" href="https://github.com/" target="_blank" rel="noopener">Docs</a>
                <a class="button primary" href="#report">Generate report</a>
            </div>
        </div>
    </header>

    <main>
        <section class="hero">
            <div class="shell hero-shell">
                <div class="hero-copy">
                    <p class="eyebrow">Autonomous capital markets intelligence</p>
                    <h1>Continuously monitor investor sentiment for any listed company.</h1>
                    <p class="lead">Ricktorious Markets scans trusted sources, enriches the shared knowledge graph, and packages sentiment across investor personas with no manual effort.</p>
                    <form method="get" class="query-form">
                        <label>
                            <span>Company or ticker</span>
                            <input type="text" name="company" value="<?php echo esc($company); ?>" placeholder="Acme Corp or ACME">
                        </label>
                        <button type="submit" class="button primary">Analyse coverage</button>
                    </form>
                </div>
                <div class="hero-card">
                    <h2>What you get</h2>
                    <ul class="feature-list">
                        <li>Latest headlines grouped by investor persona.</li>
                        <li>Sentiment trajectory to plot shifts over time.</li>
                        <li>Highlights you can share with your desk in seconds.</li>
                    </ul>
                </div>
            </div>
        </section>

        <section class="shell" id="report">
            <?php if ($error !== null): ?>
                <div class="messages">
                    <div class="message error"><?php echo esc($error); ?></div>
                </div>
            <?php elseif ($report === null): ?>
                <div class="empty-state">
                    <h2>Enter a company to begin</h2>
                    <p>We will trawl analyst notes, institutional briefings, and retail chatter automatically.</p>
                </div>
            <?php else: ?>
                <section class="insights">
                    <h2>Market pulse overview</h2>
                    <p class="muted"><?php echo esc($report->overview()); ?></p>
                    <p class="muted generated-at">Generated <?php echo esc($report->generatedAt()); ?></p>

                    <?php if ($highlights !== []): ?>
                        <ul class="tag-list">
                            <?php foreach ($highlights as $highlight): ?>
                                <li>• <?php echo esc($highlight); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <?php if ($sentimentSegments !== []): ?>
                        <div class="insight-grid sentiment-grid">
                            <?php foreach ($sentimentSegments as $segment => $data): ?>
                                <article class="insight-card">
                                    <h3><?php echo esc(ucfirst((string) $segment)); ?> investors</h3>
                                    <p class="metric <?php echo sentiment_class((string) ($data['current_label'] ?? 'neutral')); ?>"><?php echo esc(sprintf('%.2f', (float) ($data['current_score'] ?? 0.0))); ?></p>
                                    <p class="muted">Current tone: <?php echo esc((string) ($data['current_label'] ?? 'neutral')); ?></p>
                                    <p>Average: <strong><?php echo esc(sprintf('%.2f', (float) ($data['average_score'] ?? 0.0))); ?></strong> (<?php echo esc((string) ($data['average_label'] ?? 'neutral')); ?>)</p>
                                    <?php if (($data['latest_headline'] ?? '') !== ''): ?>
                                        <p class="muted">Latest signal: “<?php echo esc((string) $data['latest_headline']); ?>”</p>
                                    <?php endif; ?>
                                    <p class="muted">Signals tracked: <?php echo esc((string) ($data['article_count'] ?? 0)); ?></p>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="timeline">
                    <h2>Sentiment trajectory</h2>
                    <?php if ($timeline === []): ?>
                        <p class="muted">Not enough coverage yet to chart a trajectory.</p>
                    <?php else: ?>
                        <div class="timeline-table-wrapper">
                            <table class="timeline-table">
                                <thead>
                                    <tr>
                                        <th scope="col">Date</th>
                                        <th scope="col">Investor type</th>
                                        <th scope="col">Score</th>
                                        <th scope="col">Signals</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($timeline as $entry): ?>
                                        <tr>
                                            <?php $score = (float) ($entry['score'] ?? 0.0); $label = score_label($score); ?>
                                            <td><?php echo esc((string) ($entry['date'] ?? '')); ?></td>
                                            <td><?php echo esc(ucfirst((string) ($entry['segment'] ?? ''))); ?></td>
                                            <td class="<?php echo sentiment_class($label); ?>"><?php echo esc(sprintf('%.2f', $score)); ?></td>
                                            <td><?php echo esc(implode(', ', (array) ($entry['articles'] ?? []))); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="articles">
                    <h2>Signals analysed</h2>
                    <?php if ($articles === []): ?>
                        <p class="muted">No individual news items were retrieved.</p>
                    <?php else: ?>
                        <div class="article-grid">
                            <?php foreach ($articles as $article): ?>
                                <article class="article-card">
                                    <header>
                                        <h3><a href="<?php echo esc((string) ($article['url'] ?? '#')); ?>" target="_blank" rel="noopener"><?php echo esc((string) ($article['title'] ?? '')); ?></a></h3>
                                        <p class="muted"><?php echo esc((string) ($article['source'] ?? '')); ?> · <?php echo esc((string) ($article['published_at'] ?? '')); ?></p>
                                    </header>
                                    <p><?php echo esc((string) ($article['summary'] ?? '')); ?></p>
                                    <?php $sentiment = $article['sentiment'] ?? []; ?>
                                    <p class="muted">Sentiment: <span class="badge <?php echo sentiment_class((string) ($sentiment['label'] ?? 'neutral')); ?>"><?php echo esc((string) ($sentiment['label'] ?? 'neutral')); ?></span> (<?php echo esc(sprintf('%.2f', (float) ($sentiment['score'] ?? 0.0))); ?>)</p>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
