<?php

declare(strict_types=1);

require __DIR__ . '/src/Ricktorious/Markets/bootstrap.php';

$kernel = ricktorious_markets_kernel();
$overviewService = $kernel->overviewService();
$newsService = $kernel->companyNewsService();
$searchService = $kernel->searchService();

$overview = $overviewService->snapshot();
$latestNews = array_map(
    static function (array $item): array {
        $news = $item['news'] ?? [];
        if (isset($news['published_at']) && $news['published_at'] instanceof DateTimeInterface) {
            $news['published_at'] = $news['published_at']->format(DATE_ATOM);
        }

        return [
            'company' => $item['company'] ?? [],
            'news' => $news,
            'sentiment_label' => $item['sentiment_label'] ?? 'neutral',
            'sentiment_score' => (float) ($item['sentiment_score'] ?? 0.0),
        ];
    },
    $newsService->latestAcrossMarket(12)
);
$companies = $searchService->companies();
$suggestionData = [];
foreach ($companies as $company) {
    $suggestionData[] = [
        'symbol' => $company->symbol(),
        'name' => $company->name(),
        'sector' => $company->sector(),
    ];
}

$companyCount = count($companies);
$newsCount = count($latestNews);
$watchlist = array_slice($overview['top_movers'] ?? [], 0, 5);
$sectorLeaders = array_slice($overview['sectors'] ?? [], 0, 6);

/**
 * @param DateTimeImmutable $time
 */
function relative_time(DateTimeImmutable $time): string
{
    $now = new DateTimeImmutable('now', $time->getTimezone());
    $diff = max(0, $now->getTimestamp() - $time->getTimestamp());

    if ($diff < 60) {
        return 'just now';
    }

    if ($diff < 3600) {
        $minutes = (int) floor($diff / 60);

        return $minutes === 1 ? '1 minute ago' : sprintf('%d minutes ago', $minutes);
    }

    if ($diff < 86400) {
        $hours = (int) floor($diff / 3600);

        return $hours === 1 ? '1 hour ago' : sprintf('%d hours ago', $hours);
    }

    $days = (int) floor($diff / 86400);

    return $days === 1 ? '1 day ago' : sprintf('%d days ago', $days);
}

$lastUpdatedLabel = 'recently';
$lastUpdatedIso = null;
$cacheAgeMinutes = null;
$rawLastUpdated = $overview['last_updated'] ?? null;
if (is_string($rawLastUpdated) && $rawLastUpdated !== '') {
    try {
        $lastUpdatedTime = new DateTimeImmutable($rawLastUpdated);
        $lastUpdatedIso = $lastUpdatedTime->format(DATE_ATOM);
        $lastUpdatedLabel = relative_time($lastUpdatedTime);
        $cacheAgeMinutes = (int) floor(max(0, (new DateTimeImmutable())->getTimestamp() - $lastUpdatedTime->getTimestamp()) / 60);
    } catch (Exception $exception) {
        $lastUpdatedLabel = $rawLastUpdated;
    }
}

$initialPulse = [
    'generated_at' => date(DATE_ATOM),
    'overview' => array_merge(
        $overview,
        [
            'company_count' => $companyCount,
            'news_count' => $newsCount,
            'cache_age_minutes' => $cacheAgeMinutes,
            'last_updated_iso' => $lastUpdatedIso,
            'last_updated_relative' => $lastUpdatedLabel,
        ]
    ),
    'latest_news' => $latestNews,
    'watchlist' => $watchlist,
    'sectors' => $sectorLeaders,
];

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
                <div class="live-toolbar">
                    <div class="live-indicator" data-live-indicator data-state="ready" aria-live="polite">
                        <span class="pulse-dot" aria-hidden="true"></span>
                        <span data-pulse-text="status">Cache synced <?= esc($lastUpdatedLabel); ?></span>
                    </div>
                    <div class="live-actions">
                        <button type="button" class="button ghost" data-action="refresh-pulse">Refresh now</button>
                        <span class="muted small" data-pulse-countdown>Next refresh in 60s</span>
                    </div>
                </div>
                <div class="hero-metrics">
                    <div>
                        <p class="metric-label">Total market cap</p>
                        <p class="metric-value" data-pulse-metric="total_market_cap" data-format="currency-billions">$<?= esc(format_number(($overview['total_market_cap'] ?? 0.0) / 1_000_000_000, 1)); ?>B</p>
                    </div>
                    <div>
                        <p class="metric-label">Avg change</p>
                        <p class="metric-value <?= change_badge((float) ($overview['average_change_percent'] ?? 0.0)); ?>" data-pulse-metric="average_change_percent" data-format="percent" data-change-badge>
                            <?= esc(sprintf('%+.2f%%', (float) ($overview['average_change_percent'] ?? 0.0))); ?>
                        </p>
                    </div>
                    <div>
                        <p class="metric-label">Advancers vs decliners</p>
                        <p class="metric-value" data-pulse-metric="advancers_decliners" data-format="advancers">
                            <?= esc((string) ($overview['advancers'] ?? 0)); ?> / <?= esc((string) ($overview['decliners'] ?? 0)); ?>
                        </p>
                    </div>
                </div>
                <p class="muted">Last updated
                    <span data-pulse-timestamp<?php if ($lastUpdatedIso !== null): ?> data-initial-iso="<?= esc($lastUpdatedIso); ?>"<?php endif; ?>>
                        <?= esc($lastUpdatedLabel); ?>
                    </span>
                </p>
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
                <div class="hero-meta">
                    <p class="muted small">Auto-refresh pulls from the offline cache every minute. Manual refreshes run instantly without touching external APIs.</p>
                </div>
            </div>
        </div>
    </section>

    <section class="section" id="operations">
        <div class="shell">
            <div class="section-header">
                <h2>Operations centre</h2>
                <p class="muted">Realtime health of the self-hosted market intelligence cache.</p>
            </div>
            <div class="operations-grid">
                <article class="ops-card">
                    <header>
                        <h3>Cache health</h3>
                        <p class="muted small">Monitored by the semantic engine</p>
                    </header>
                    <p class="ops-metric" data-pulse-metric="company_count" data-format="integer"><?= esc((string) $companyCount); ?></p>
                    <p class="muted">Companies with locally cached intelligence</p>
                    <ul class="ops-list" data-pulse-list="watchlist">
                        <?php foreach ($watchlist as $mover): ?>
                            <li>
                                <span class="badge <?= change_badge((float) ($mover['change_percent'] ?? 0.0)); ?>"><?= esc((string) ($mover['symbol'] ?? '')); ?></span>
                                <strong><?= esc((string) ($mover['name'] ?? '')); ?></strong>
                                <em><?= esc(sprintf('%+.2f%%', (float) ($mover['change_percent'] ?? 0.0))); ?></em>
                            </li>
                        <?php endforeach; ?>
                        <?php if ($watchlist === []): ?>
                            <li>No movers detected</li>
                        <?php endif; ?>
                    </ul>
                </article>
                <article class="ops-card">
                    <header>
                        <h3>Headline coverage</h3>
                        <p class="muted small">Always available offline</p>
                    </header>
                    <p class="ops-metric" data-pulse-metric="news_count" data-format="integer"><?= esc((string) $newsCount); ?></p>
                    <p class="muted">Stories stored in the local dataset</p>
                    <ul class="ops-list ops-list--compact" data-pulse-list="headlines">
                        <?php foreach (array_slice($latestNews, 0, 3) as $item): ?>
                            <li>
                                <strong><?= esc((string) ($item['news']['title'] ?? '')); ?></strong>
                                <em><?= esc((string) ($item['news']['source'] ?? '')); ?> · <?= esc(date('H:i', strtotime((string) ($item['news']['published_at'] ?? '')))); ?></em>
                            </li>
                        <?php endforeach; ?>
                        <?php if ($latestNews === []): ?>
                            <li>No headlines cached yet</li>
                        <?php endif; ?>
                    </ul>
                </article>
                <article class="ops-card ops-card--status">
                    <header>
                        <h3>Autonomy status</h3>
                        <p class="muted small">Designed to run fully self-sufficient</p>
                    </header>
                    <div class="ops-health" data-pulse-health>
                        <span class="pulse-dot" aria-hidden="true"></span>
                        <div>
                            <strong data-pulse-text="autonomy">All systems nominal</strong>
                            <p class="muted small">Cache synced <span data-pulse-timestamp<?php if ($lastUpdatedIso !== null): ?> data-initial-iso="<?= esc($lastUpdatedIso); ?>"<?php endif; ?>><?= esc($lastUpdatedLabel); ?></span></p>
                        </div>
                    </div>
                    <p class="muted small">Zero third-party dependencies. Export datasets or APIs continue to operate even without an internet connection.</p>
                    <div class="ops-actions">
                        <a class="button ghost" href="/knowledge-graph.php">View knowledge graph</a>
                        <a class="button ghost" href="/api/analyse.php" target="_blank" rel="noopener">API docs</a>
                    </div>
                </article>
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
            <div class="sectors-grid" data-pulse-list="sectors">
                <?php foreach ($sectorLeaders as $sector): ?>
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
                <div class="news-grid" data-pulse-region="news">
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

<script type="application/json" id="market-pulse"><?= json_encode($initialPulse, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?></script>
<script type="application/json" id="company-dataset"><?= json_encode($suggestionData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?></script>
<script src="<?= esc($scriptPath . '?v=' . $scriptVersion); ?>" defer></script>
</body>
</html>
