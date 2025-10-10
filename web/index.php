<?php

declare(strict_types=1);

require __DIR__ . '/../src/Ricktorious/Markets/bootstrap.php';

$kernel = ricktorious_markets_kernel();
$overviewService = $kernel->overviewService();
$newsService = $kernel->companyNewsService();
$searchService = $kernel->searchService();

$overview = $overviewService->snapshot();
$latestNews = $newsService->latestAcrossMarket(12);
$companies = $searchService->companies();
$suggestionData = [];
foreach ($companies as $company) {
    $suggestionData[] = [
        'symbol' => $company->symbol(),
        'name' => $company->name(),
        'sector' => $company->sector(),
    ];
}

/**
 * @param float $value
 */
function format_number($value, int $decimals = 2): string
{
    return number_format($value, $decimals, '.', ',');
}

function esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function change_badge(float $change): string
{
    if ($change > 0) {
        return 'change-up';
    }

    if ($change < 0) {
        return 'change-down';
    }

    return 'change-flat';
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

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Markets intelligence home</title>
    <link rel="stylesheet" href="<?= esc($stylesPath . '?v=' . $stylesVersion); ?>">
</head>
<body>
<header class="site-header">
    <div class="shell header-shell">
        <div class="brand">Signal Ledger</div>
        <nav class="primary-nav" aria-label="Primary">
            <a href="#overview">Overview</a>
            <a href="#movers">Top movers</a>
            <a href="#news">Market news</a>
        </nav>
        <div class="header-actions">
            <a class="button ghost" href="#search">Search the market</a>
            <a class="button primary" href="#news">Latest headlines</a>
        </div>
    </div>
</header>

<main>
    <section class="hero" id="overview">
        <div class="shell hero-shell">
            <div class="hero-copy">
                <p class="eyebrow">Live coverage without third-party feeds</p>
                <h1>Market overview, curated intelligence, zero external calls.</h1>
                <p class="lead">Signal Ledger blends internal knowledge graphs with evergreen market datasets so you can monitor sentiment and headlines offline.</p>
                <div class="hero-metrics">
                    <div>
                        <p class="metric-label">Total market cap</p>
                        <p class="metric-value">$<?= esc(format_number(($overview['total_market_cap'] ?? 0.0) / 1_000_000_000, 1)); ?>B</p>
                    </div>
                    <div>
                        <p class="metric-label">Avg change</p>
                        <p class="metric-value <?= change_badge((float) ($overview['average_change_percent'] ?? 0.0)); ?>">
                            <?= esc(sprintf('%+.2f%%', (float) ($overview['average_change_percent'] ?? 0.0))); ?>
                        </p>
                    </div>
                    <div>
                        <p class="metric-label">Advancers vs decliners</p>
                        <p class="metric-value"><?= esc((string) ($overview['advancers'] ?? 0)); ?> / <?= esc((string) ($overview['decliners'] ?? 0)); ?></p>
                    </div>
                </div>
                <p class="muted">Last updated <?= esc((string) ($overview['last_updated'] ?? 'recently')); ?></p>
            </div>
            <div class="hero-card" id="search">
                <h2>Find company coverage</h2>
                <p class="muted">Type a ticker, company name, or sector to jump straight to curated briefings.</p>
                <form method="get" action="/company.php" class="search-form" autocomplete="off">
                    <label class="search-label" for="company-query">Company or ticker</label>
                    <div class="search-input">
                        <input id="company-query" name="q" type="search" placeholder="e.g. ACME or Delta" list="company-suggestions">
                        <button type="submit" class="button primary">View coverage</button>
                    </div>
                    <datalist id="company-suggestions">
                        <?php foreach ($companies as $company): ?>
                            <option value="<?= esc($company->symbol()); ?>"><?= esc($company->name()); ?></option>
                            <option value="<?= esc($company->name()); ?>"><?= esc($company->symbol()); ?></option>
                        <?php endforeach; ?>
                    </datalist>
                </form>
                <div class="search-hints">
                    <p class="muted">Popular right now:</p>
                    <ul>
                        <?php foreach (array_slice($overview['top_movers'] ?? [], 0, 3) as $mover): ?>
                            <li><span class="badge <?= change_badge((float) ($mover['change_percent'] ?? 0.0)); ?>">
                                <?= esc((string) ($mover['symbol'] ?? '')); ?></span> <?= esc((string) ($mover['name'] ?? '')); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    <section class="section" id="indices">
        <div class="shell">
            <div class="section-header">
                <h2>Index snapshot</h2>
                <p class="muted">Benchmarks refreshed from the offline market cache.</p>
            </div>
            <div class="indices-grid">
                <?php foreach (($overview['indices'] ?? []) as $index): ?>
                    <article class="index-card">
                        <h3><?= esc((string) ($index['name'] ?? '')); ?></h3>
                        <p class="index-price"><?= esc(format_number((float) ($index['price'] ?? 0.0), 2)); ?></p>
                        <p class="index-change <?= change_badge((float) ($index['change'] ?? 0.0)); ?>">
                            <?= esc(sprintf('%+.2f ( %+.2f%% )', (float) ($index['change'] ?? 0.0), (float) ($index['change_percent'] ?? 0.0))); ?>
                        </p>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="section" id="movers">
        <div class="shell">
            <div class="section-header">
                <h2>Top movers</h2>
                <p class="muted">Quickly surface outsized swings across your tracked universe.</p>
            </div>
            <div class="movers-grid">
                <?php foreach (($overview['top_movers'] ?? []) as $mover): ?>
                    <article class="mover-card">
                        <header>
                            <span class="badge <?= change_badge((float) ($mover['change_percent'] ?? 0.0)); ?>">
                                <?= esc((string) ($mover['symbol'] ?? '')); ?>
                            </span>
                            <h3><?= esc((string) ($mover['name'] ?? '')); ?></h3>
                        </header>
                        <p class="mover-price">$<?= esc(format_number((float) ($mover['price'] ?? 0.0), 2)); ?></p>
                        <p class="mover-change <?= change_badge((float) ($mover['change_percent'] ?? 0.0)); ?>">
                            <?= esc(sprintf('%+.2f (%+.2f%%)', (float) ($mover['change'] ?? 0.0), (float) ($mover['change_percent'] ?? 0.0))); ?>
                        </p>
                        <p class="muted">Sector: <?= esc((string) ($mover['sector'] ?? '')); ?></p>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="section" id="sectors">
        <div class="shell">
            <div class="section-header">
                <h2>Sector pulse</h2>
                <p class="muted">Internal analytics highlight which sectors are leading the session.</p>
            </div>
            <div class="sectors-grid">
                <?php foreach (array_slice($overview['sectors'] ?? [], 0, 6) as $sector): ?>
                    <article class="sector-card">
                        <h3><?= esc((string) ($sector['sector'] ?? '')); ?></h3>
                        <p class="sector-change <?= change_badge((float) ($sector['avg_change'] ?? 0.0)); ?>">
                            <?= esc(sprintf('%+.2f%%', (float) ($sector['avg_change'] ?? 0.0))); ?>
                        </p>
                        <p class="muted">Constituents: <?= esc((string) ($sector['count'] ?? 0)); ?></p>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="section" id="news">
        <div class="shell">
            <div class="section-header">
                <h2>Latest market news</h2>
                <p class="muted">Every story is stored locally so briefing rooms keep working without the open web.</p>
            </div>
            <?php if ($latestNews === []): ?>
                <div class="empty-state">
                    <p>No articles available in the cache yet. Add more companies to expand coverage.</p>
                </div>
            <?php else: ?>
                <div class="news-grid">
                    <?php foreach ($latestNews as $item): ?>
                        <article class="news-card">
                            <header>
                                <span class="badge <?= change_badge((float) ($item['sentiment_score'] ?? 0.0)); ?>">
                                    <?= esc((string) ($item['company']['symbol'] ?? '')); ?>
                                </span>
                                <p class="news-source"><?= esc((string) ($item['news']['source'] ?? '')); ?> · <?= esc(date('M j, H:i', strtotime((string) ($item['news']['published_at'] ?? '')))); ?></p>
                                <h3><a href="<?= esc((string) ($item['news']['url'] ?? '#')); ?>" target="_blank" rel="noopener">
                                    <?= esc((string) ($item['news']['title'] ?? '')); ?></a></h3>
                            </header>
                            <p><?= esc((string) ($item['news']['summary'] ?? '')); ?></p>
                            <footer>
                                <a class="button ghost" href="/company.php?q=<?= urlencode((string) ($item['company']['symbol'] ?? '')); ?>">View company brief</a>
                            </footer>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>
</main>

<footer class="site-footer">
    <div class="shell">
        <p>Signal Ledger keeps teams informed even when the network drops.</p>
    </div>
</footer>

<script type="application/json" id="company-dataset"><?= json_encode($suggestionData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?></script>
<script src="<?= esc($scriptPath . '?v=' . $scriptVersion); ?>" defer></script>
</body>
</html>
