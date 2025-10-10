<?php

declare(strict_types=1);

function esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '/company.php');
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
$companyScriptPath = $assetBase . '/assets/company-insights.js';
$stylesVersion = file_exists(__DIR__ . '/assets/styles.css') ? (string) filemtime(__DIR__ . '/assets/styles.css') : (string) time();
$companyScriptVersion = file_exists(__DIR__ . '/assets/company-insights.js') ? (string) filemtime(__DIR__ . '/assets/company-insights.js') : (string) time();

$defaultSymbol = strtoupper(trim((string) ($_GET['symbol'] ?? $_GET['q'] ?? 'AAPL')));
if ($defaultSymbol === '') {
    $defaultSymbol = 'AAPL';
}

$popular = ['AAPL', 'MSFT', 'NVDA', 'GOOGL', 'AMZN', 'TSLA'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Company intelligence hub</title>
    <link rel="stylesheet" href="<?= esc($stylesPath . '?v=' . $stylesVersion); ?>">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.6/dist/chart.umd.min.js" defer></script>
    <script>
        window.SignalLedger = window.SignalLedger || {};
        window.SignalLedger.defaultSymbol = <?= json_encode($defaultSymbol, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
        window.SignalLedger.popularTickers = <?= json_encode($popular, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    </script>
    <script src="<?= esc($companyScriptPath . '?v=' . $companyScriptVersion); ?>" defer></script>
</head>
<body class="company-page">
<header class="site-header">
    <div class="shell header-shell">
        <div class="brand">Signal Ledger</div>
        <nav class="primary-nav" aria-label="Primary">
            <a href="/index.php#overview">Overview</a>
            <a href="/index.php#movers">Top movers</a>
            <a href="/index.php#news">Market news</a>
        </nav>
        <div class="header-actions">
            <a class="button ghost" href="/index.php">Back to home</a>
        </div>
    </div>
</header>

<main data-page="company" data-default-symbol="<?= esc($defaultSymbol); ?>">
    <section class="hero hero--company" id="company">
        <div class="shell hero-shell">
            <div class="hero-copy">
                <p class="eyebrow">Live company intelligence</p>
                <h1 data-company-name>Loading…</h1>
                <p class="lead" data-company-tagline>Real-time pricing, sentiment and curated coverage.</p>
                <div class="hero-metrics">
                    <div>
                        <p class="metric-label">Price</p>
                        <p class="metric-value" data-field="price">—</p>
                    </div>
                    <div>
                        <p class="metric-label">Daily change</p>
                        <p class="metric-value" data-field="change">—</p>
                    </div>
                    <div>
                        <p class="metric-label">Volume</p>
                        <p class="metric-value" data-field="volume">—</p>
                    </div>
                </div>
                <p class="muted" data-field="updated">Last updated moments ago</p>
            </div>
            <div class="hero-card" id="company-search">
                <h2>Find a company</h2>
                <p class="muted">Search for live coverage across equities without leaving Signal Ledger.</p>
                <form method="get" autocomplete="off" data-role="company-search-form">
                    <label class="search-label" for="company-query">Company or ticker</label>
                    <div class="search-input">
                        <input id="company-query" name="q" type="search" placeholder="e.g. NVDA" data-role="company-search-input">
                        <button type="submit" class="button primary">Load coverage</button>
                    </div>
                </form>
                <div class="search-suggestions" data-role="company-suggestions" hidden></div>
                <div class="search-hints">
                    <p class="muted">Popular tickers</p>
                    <div class="pill-group" data-role="popular-tickers">
                        <?php foreach ($popular as $ticker): ?>
                            <button type="button" class="pill" data-symbol="<?= esc($ticker); ?>"><?= esc($ticker); ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="section" id="snapshot">
        <div class="shell">
            <div class="section-header">
                <h2>Market snapshot</h2>
                <p class="muted" data-field="snapshot-meta">Waiting for live data…</p>
            </div>
            <div class="metrics-grid" data-region="snapshot-metrics">
                <article class="metric-card">
                    <h3>Day range</h3>
                    <p data-field="day-range">—</p>
                </article>
                <article class="metric-card">
                    <h3>52-week range</h3>
                    <p data-field="year-range">—</p>
                </article>
                <article class="metric-card">
                    <h3>1M performance</h3>
                    <p data-field="return-1m">—</p>
                </article>
                <article class="metric-card">
                    <h3>6M performance</h3>
                    <p data-field="return-6m">—</p>
                </article>
                <article class="metric-card">
                    <h3>Sentiment</h3>
                    <p data-field="sentiment-score">—</p>
                    <span class="badge" data-field="sentiment-label">neutral</span>
                </article>
                <article class="metric-card">
                    <h3>Volatility (30d)</h3>
                    <p data-field="volatility">—</p>
                </article>
            </div>
        </div>
    </section>

    <section class="section section--charts" id="charts">
        <div class="shell chart-grid">
            <article class="chart-card">
                <header>
                    <h2>Price action</h2>
                    <p class="muted" data-field="price-range-label">Last 6 months</p>
                </header>
                <div class="chart-frame">
                    <canvas id="price-chart"></canvas>
                </div>
                <footer data-field="price-summary" class="muted">Fetching history…</footer>
            </article>
            <article class="chart-card">
                <header>
                    <h2>Sentiment trend</h2>
                    <p class="muted" data-field="sentiment-trend-label">Rolling daily averages</p>
                </header>
                <div class="chart-frame">
                    <canvas id="sentiment-chart"></canvas>
                </div>
                <footer data-field="sentiment-summary" class="muted">Waiting for coverage…</footer>
            </article>
        </div>
    </section>

    <section class="section" id="insights">
        <div class="shell insights-grid">
            <article class="insight-card">
                <h2>Key takeaways</h2>
                <ul data-field="insight-points">
                    <li>Loading live intelligence…</li>
                </ul>
            </article>
            <article class="insight-card">
                <h2>Coverage topics</h2>
                <ul class="topic-list" data-field="topic-list">
                    <li class="muted">Analysing most mentioned themes…</li>
                </ul>
            </article>
            <article class="insight-card">
                <h2>Performance snapshot</h2>
                <dl class="insight-stats">
                    <div>
                        <dt>1 week</dt>
                        <dd data-field="return-1w">—</dd>
                    </div>
                    <div>
                        <dt>1 month</dt>
                        <dd data-field="return-1m-detail">—</dd>
                    </div>
                    <div>
                        <dt>3 months</dt>
                        <dd data-field="return-3m">—</dd>
                    </div>
                    <div>
                        <dt>6 months</dt>
                        <dd data-field="return-6m-detail">—</dd>
                    </div>
                </dl>
            </article>
        </div>
    </section>

    <section class="section" id="news">
        <div class="shell">
            <div class="section-header">
                <h2>Latest coverage</h2>
                <p class="muted" data-field="news-meta">Monitoring market narrative in real time.</p>
            </div>
            <div class="news-feed" data-field="news-list">
                <div class="empty-state">
                    <p>Scanning for relevant articles…</p>
                </div>
            </div>
        </div>
    </section>
</main>

<footer class="site-footer">
    <div class="shell">
        <p>Signal Ledger keeps teams informed with real-time sentiment and price intelligence.</p>
    </div>
</footer>
</body>
</html>
