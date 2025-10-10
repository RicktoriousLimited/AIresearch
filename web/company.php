<?php

declare(strict_types=1);

require __DIR__ . '/../src/Ricktorious/Markets/bootstrap.php';

$kernel = ricktorious_markets_kernel();
$newsService = $kernel->companyNewsService();
$overviewService = $kernel->overviewService();
$searchService = $kernel->searchService();

$query = trim((string) ($_GET['q'] ?? ''));
$company = $query === '' ? null : $newsService->company($query);
$news = $company ? $newsService->newsForCompany($company) : [];
$overview = $overviewService->snapshot();
$companies = $searchService->companies();
$suggestionData = [];
foreach ($companies as $item) {
    $suggestionData[] = [
        'symbol' => $item->symbol(),
        'name' => $item->name(),
        'sector' => $item->sector(),
    ];
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

function format_number(float $value, int $decimals = 2): string
{
    return number_format($value, $decimals, '.', ',');
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
$scriptPath = $assetBase . '/assets/market.js';
$stylesVersion = file_exists(__DIR__ . '/assets/styles.css') ? (string) filemtime(__DIR__ . '/assets/styles.css') : (string) time();
$scriptVersion = file_exists(__DIR__ . '/assets/market.js') ? (string) filemtime(__DIR__ . '/assets/market.js') : (string) time();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $company ? esc($company->name()) . ' coverage' : 'Company coverage search'; ?></title>
    <link rel="stylesheet" href="<?= esc($stylesPath . '?v=' . $stylesVersion); ?>">
</head>
<body>
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

<main>
    <section class="hero" id="company">
        <div class="shell hero-shell">
            <div class="hero-copy">
                <p class="eyebrow">Curated company intelligence</p>
                <h1><?= $company ? esc($company->name()) : 'Search the market'; ?></h1>
                <?php if ($company): ?>
                    <p class="lead">Sector: <?= esc($company->sector()); ?> · Ticker: <?= esc($company->symbol()); ?></p>
                    <div class="hero-metrics">
                        <div>
                            <p class="metric-label">Price</p>
                            <p class="metric-value">$<?= esc(format_number($company->price(), 2)); ?></p>
                        </div>
                        <div>
                            <p class="metric-label">Change</p>
                            <p class="metric-value <?= change_badge($company->changePercent()); ?>">
                                <?= esc(sprintf('%+.2f (%+.2f%%)', $company->change(), $company->changePercent())); ?>
                            </p>
                        </div>
                        <div>
                            <p class="metric-label">Market cap</p>
                            <p class="metric-value">$<?= esc(format_number($company->marketCap() / 1_000_000_000, 1)); ?>B</p>
                        </div>
                    </div>
                    <p class="muted">Last updated <?= esc((string) ($overview['last_updated'] ?? 'recently')); ?></p>
                <?php else: ?>
                    <p class="lead">Enter a company or ticker to pull its locally stored coverage and sentiment.</p>
                <?php endif; ?>
            </div>
            <div class="hero-card" id="search">
                <h2>Switch company</h2>
                <p class="muted">No external calls required – everything runs from the offline cache.</p>
                <form method="get" class="search-form" autocomplete="off">
                    <label class="search-label" for="company-query">Company or ticker</label>
                    <div class="search-input">
                        <input id="company-query" name="q" type="search" value="<?= esc($query); ?>" placeholder="e.g. ACME or Delta" list="company-suggestions">
                        <button type="submit" class="button primary">Load coverage</button>
                    </div>
                    <datalist id="company-suggestions">
                        <?php foreach ($companies as $item): ?>
                            <option value="<?= esc($item->symbol()); ?>"><?= esc($item->name()); ?></option>
                            <option value="<?= esc($item->name()); ?>"><?= esc($item->symbol()); ?></option>
                        <?php endforeach; ?>
                    </datalist>
                </form>
                <div class="search-hints">
                    <p class="muted">Leaders today:</p>
                    <ul>
                        <?php foreach (array_slice($overview['top_movers'] ?? [], 0, 3) as $mover): ?>
                            <li><span class="badge <?= change_badge((float) ($mover['change_percent'] ?? 0.0)); ?>"><?= esc((string) ($mover['symbol'] ?? '')); ?></span> <?= esc((string) ($mover['name'] ?? '')); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    <section class="section" id="news">
        <div class="shell">
            <div class="section-header">
                <h2><?= $company ? 'Latest news for ' . esc($company->symbol()) : 'No company selected'; ?></h2>
                <?php if ($company): ?>
                    <p class="muted">These articles are cached locally so your workflow keeps running offline.</p>
                <?php endif; ?>
            </div>
            <?php if (!$company): ?>
                <div class="empty-state">
                    <p>Use the search panel to load coverage for a company.</p>
                </div>
            <?php elseif ($news === []): ?>
                <div class="empty-state">
                    <p>No cached articles found yet. Add more sources to the offline dataset.</p>
                </div>
            <?php else: ?>
                <div class="news-grid">
                    <?php foreach ($news as $item): ?>
                        <article class="news-card">
                            <header>
                                <span class="badge <?= change_badge((float) ($item['sentiment_score'] ?? 0.0)); ?>"><?= esc($company->symbol()); ?></span>
                                <p class="news-source"><?= esc((string) ($item['news']['source'] ?? '')); ?> · <?= esc(date('M j, H:i', strtotime((string) ($item['news']['published_at'] ?? '')))); ?></p>
                                <h3><a href="<?= esc((string) ($item['news']['url'] ?? '#')); ?>" target="_blank" rel="noopener"> <?= esc((string) ($item['news']['title'] ?? '')); ?></a></h3>
                            </header>
                            <p><?= esc((string) ($item['news']['summary'] ?? '')); ?></p>
                            <p class="muted">Sentiment: <?= esc((string) ($item['sentiment_label'] ?? 'neutral')); ?> (<?= esc(sprintf('%+.2f', (float) ($item['sentiment_score'] ?? 0.0))); ?>)</p>
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
