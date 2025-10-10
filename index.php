<?php

declare(strict_types=1);

function esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

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
$scriptPath = $assetBase . '/assets/market.js';
$stylesVersion = file_exists(__DIR__ . '/assets/styles.css') ? (string) filemtime(__DIR__ . '/assets/styles.css') : (string) time();
$scriptVersion = file_exists(__DIR__ . '/assets/market.js') ? (string) filemtime(__DIR__ . '/assets/market.js') : (string) time();

$defaultWatchlist = ['AAPL', 'MSFT', 'NVDA', 'GOOGL', 'AMZN'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Signal Ledger · Live market intelligence</title>
    <link rel="stylesheet" href="<?= esc($stylesPath . '?v=' . $stylesVersion); ?>">
    <script>
        window.SignalLedger = window.SignalLedger || {};
        window.SignalLedger.watchlist = <?= json_encode($defaultWatchlist, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    </script>
    <script src="<?= esc($scriptPath . '?v=' . $scriptVersion); ?>" defer></script>
</head>
<body class="market-page">
<header class="site-header">
    <div class="shell header-shell">
        <div class="brand">Signal Ledger</div>
        <nav class="primary-nav" aria-label="Primary">
            <a href="#overview">Overview</a>
            <a href="#movers">Movers</a>
            <a href="#sentiment">Sentiment</a>
            <a href="#news">Narrative</a>
        </nav>
        <div class="header-actions">
            <a class="button ghost" href="/company.php">Company deep-dive</a>
        </div>
    </div>
</header>

<main data-page="market">
    <section class="hero" id="overview">
        <div class="shell hero-shell">
            <div class="hero-copy">
                <p class="eyebrow">Market command centre</p>
                <h1>Actionable market telemetry</h1>
                <p class="lead">Monitor price action, sentiment and coverage trends without leaving the workspace.</p>
                <div class="hero-metrics">
                    <div>
                        <p class="metric-label">Avg. change</p>
                        <p class="metric-value" data-dashboard="average-change">—</p>
                    </div>
                    <div>
                        <p class="metric-label">Avg. sentiment</p>
                        <p class="metric-value" data-dashboard="average-sentiment">—</p>
                    </div>
                    <div>
                        <p class="metric-label">Volatility</p>
                        <p class="metric-value" data-dashboard="volatility">—</p>
                    </div>
                </div>
                <p class="muted" data-dashboard="generated">Refreshing market data…</p>
            </div>
            <div class="hero-card" id="global-search">
                <h2>Open a company brief</h2>
                <p class="muted">Stay inside Signal Ledger – we surface price, sentiment and coverage automatically.</p>
                <form method="get" action="/company.php" autocomplete="off" data-role="global-search-form">
                    <label class="search-label" for="global-query">Company or ticker</label>
                    <div class="search-input">
                        <input id="global-query" name="q" type="search" placeholder="e.g. TSLA" data-role="global-search-input">
                        <button type="submit" class="button primary">Launch brief</button>
                    </div>
                </form>
                <div class="search-suggestions" data-role="global-suggestions" hidden></div>
                <div class="search-hints">
                    <p class="muted">Popular watchlist</p>
                    <div class="pill-group" data-role="watchlist">
                        <?php foreach ($defaultWatchlist as $ticker): ?>
                            <button type="button" class="pill" data-symbol="<?= esc($ticker); ?>"><?= esc($ticker); ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="section" id="movers">
        <div class="shell">
            <div class="section-header">
                <h2>Watchlist pulse</h2>
                <p class="muted">Ranking the strongest moves across the curated watchlist.</p>
            </div>
            <div class="card-grid" data-dashboard="movers">
                <div class="empty-state">
                    <p>Tracking tickers…</p>
                </div>
            </div>
        </div>
    </section>

    <section class="section" id="sentiment">
        <div class="shell">
            <div class="section-header">
                <h2>Sentiment leaders</h2>
                <p class="muted">Where the market narrative is heating up.</p>
            </div>
            <div class="sentiment-columns">
                <div>
                    <h3>Bullish</h3>
                    <ul class="sentiment-list" data-dashboard="bullish"></ul>
                </div>
                <div>
                    <h3>Bearish</h3>
                    <ul class="sentiment-list" data-dashboard="bearish"></ul>
                </div>
            </div>
        </div>
    </section>

    <section class="section" id="news">
        <div class="shell">
            <div class="section-header">
                <h2>Market narrative</h2>
                <p class="muted">Stay in the flow with in-line summaries.</p>
            </div>
            <article class="headline-card" data-dashboard="headline">
                <header>
                    <span class="badge" data-headline-symbol>—</span>
                    <p class="muted" data-headline-meta>Waiting for coverage…</p>
                    <h3 data-headline-title>Loading headline insight…</h3>
                </header>
                <p data-headline-summary>We keep the key points here so you can stay inside the workspace.</p>
                <footer>
                    <a class="button ghost" href="/company.php" data-headline-action>Open company brief</a>
                </footer>
            </article>
        </div>
    </section>
</main>

<footer class="site-footer">
    <div class="shell">
        <p>Signal Ledger is built to retain attention – deep context, zero tab sprawl.</p>
    </div>
</footer>
</body>
</html>
