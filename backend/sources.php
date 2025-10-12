<?php

declare(strict_types=1);

session_start();

require __DIR__ . '/../src/App/bootstrap.php';
require __DIR__ . '/../src/Ricktorious/Ecommerce/bootstrap.php';
require_once __DIR__ . '/../src/App/Crawler/HiddenCrawler.php';

use App\Crawler\HiddenCrawler;
use App\KnowledgeGraph\ResearchService;
use App\Web\AdminNavigation;
use App\Web\PathResolver;
use App\Web\SiteLayout;

function esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$sessionUser = $_SESSION['backend_user'] ?? null;
$userId = is_array($sessionUser) ? (string) ($sessionUser['id'] ?? 'guest') : 'guest';

$kernel = ricktorious_ecommerce_kernel([
    'session' => [
        'user_id' => $userId,
        'cart' => [],
    ],
]);
$container = $kernel->container();

$paths = PathResolver::resolve();
$assetBase = PathResolver::normalizeBase($paths['assetBase']);
$sharedStylesPath = PathResolver::url($assetBase, 'assets/styles.css');
$sharedStylesVersion = file_exists(__DIR__ . '/../assets/styles.css') ? (string) filemtime(__DIR__ . '/../assets/styles.css') : (string) time();
$adminStylesPath = PathResolver::url($assetBase, 'assets/admin.css');
$adminStylesVersion = file_exists(__DIR__ . '/../assets/admin.css') ? (string) filemtime(__DIR__ . '/../assets/admin.css') : (string) time();
$navigationLinks = AdminNavigation::resolve();
$navigationLinks['sources'] = $navigationLinks['sources'] ?? ['label' => 'Sources', 'href' => PathResolver::url($assetBase, 'backend/sources.php')];

$crawlerStorage = __DIR__ . '/../storage/backend/crawler-history.json';
$crawler = new HiddenCrawler($crawlerStorage, null, null, new ResearchService());

$directory = $crawler->sourceDirectory();
$domainEntries = $directory['domains'] ?? [];
$pageEntries = $directory['pages'] ?? [];
$generatedAt = (string) ($directory['generated_at'] ?? date(DATE_ATOM));

$totalDomains = count($domainEntries);
$totalPages = count($pageEntries);
$topDomains = array_slice($domainEntries, 0, 12);
$topPages = array_slice($pageEntries, 0, 20);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Signal Ledger · Source intelligence</title>
    <link rel="stylesheet" href="<?= esc($sharedStylesPath . '?v=' . $sharedStylesVersion); ?>">
    <link rel="stylesheet" href="<?= esc($adminStylesPath . '?v=' . $adminStylesVersion); ?>">
</head>
<body class="backend-admin">
<?php SiteLayout::renderHeader($navigationLinks, 'sources'); ?>
<main class="backend-admin__main">
    <header class="card admin-page-header">
        <div>
            <h1>Source intelligence</h1>
            <p class="admin-page-header__meta">Monitor the strongest domains, inbound link paths and authority signals discovered by the crawler. Data refreshed <?= esc($generatedAt); ?>.</p>
        </div>
        <div class="admin-page-header__actions">
            <a class="pill-link ghost" href="/backend/crawler.php">Return to crawler control</a>
            <a class="pill-link" href="/backend/crawler-progress.php" target="_blank" rel="noopener">Live progress JSON</a>
        </div>
    </header>

    <?php if (!isset($_SESSION['backend_user'])): ?>
        <section class="card">
            <div class="login-warning">
                <strong>Authentication required.</strong>
                <p class="admin-space-top-md admin-no-margin-bottom">Sign in from the crawler control page to view detailed source analytics.</p>
            </div>
        </section>
    <?php else: ?>
        <section class="card">
            <h2>Network overview</h2>
            <div class="summary-grid">
                <div class="summary-card">
                    <h3>Total crawled domains</h3>
                    <p><strong><?= esc(number_format($totalDomains)); ?></strong> with authority signatures.</p>
                </div>
                <div class="summary-card">
                    <h3>Total indexed pages</h3>
                    <p><strong><?= esc(number_format($totalPages)); ?></strong> processed entries in the ledger.</p>
                </div>
                <?php if ($topDomains !== []): ?>
                    <?php $topDomain = $topDomains[0]; ?>
                    <div class="summary-card">
                        <h3>Leading domain</h3>
                        <p><strong><?= esc((string) ($topDomain['domain'] ?? '')); ?></strong> · Authority <?= esc(number_format((float) ($topDomain['domain_authority'] ?? 0) * 100, 1)); ?></p>
                    </div>
                <?php endif; ?>
                <?php if ($topPages !== []): ?>
                    <?php $topPage = $topPages[0]; ?>
                    <div class="summary-card">
                        <h3>Top page</h3>
                        <p><strong><?= esc((string) ($topPage['title'] ?? $topPage['url'] ?? '')); ?></strong><br><span class="muted">Authority <?= esc(number_format((float) ($topPage['page_authority'] ?? 0) * 100, 1)); ?> · <?= esc((string) ($topPage['domain'] ?? '')); ?></span></p>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <section class="card">
            <h2>Domain authority leaderboard</h2>
            <table>
                <thead>
                    <tr>
                        <th>Domain</th>
                        <th>Authority</th>
                        <th>Pages</th>
                        <th>Inbound links</th>
                        <th>Unique sources</th>
                        <th>Last seen</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($topDomains === []): ?>
                    <tr>
                        <td colspan="6" class="muted">No domains have been processed yet.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($topDomains as $domainRow): ?>
                        <tr>
                            <td>
                                <div class="admin-flex-column">
                                    <strong><?= esc((string) ($domainRow['domain'] ?? '')); ?></strong>
                                    <?php if (!empty($domainRow['top_page']['url'] ?? '')): ?>
                                        <span class="muted admin-text-xs">Top page: <a class="admin-link" href="<?= esc((string) $domainRow['top_page']['url']); ?>" target="_blank" rel="noopener">view</a></span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <span class="badge badge--success"><?= esc(number_format((float) ($domainRow['domain_authority'] ?? 0) * 100, 1)); ?></span>
                                <div class="muted admin-text-xxs">Baseline <?= esc(number_format((float) ($domainRow['baseline'] ?? 0) * 100, 1)); ?> · Avg <?= esc(number_format((float) ($domainRow['average_page_authority'] ?? 0) * 100, 1)); ?></div>
                            </td>
                            <td><?= esc(number_format((int) ($domainRow['page_count'] ?? 0))); ?></td>
                            <td><?= esc(number_format((int) ($domainRow['inbound_links'] ?? 0))); ?></td>
                            <td><?= esc(number_format((int) ($domainRow['unique_sources'] ?? 0))); ?></td>
                            <td><?= esc((string) ($domainRow['last_seen_at'] ?? '')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
            <p class="table-note">Authority scores are scaled 0–100 and incorporate domain quality, inbound diversity and crawl depth.</p>
        </section>

        <section class="card">
            <h2>Page authority highlights</h2>
            <table>
                <thead>
                    <tr>
                        <th>Page</th>
                        <th>Domain</th>
                        <th>Authority</th>
                        <th>Inbound</th>
                        <th>Sources</th>
                        <th>Fetched</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($topPages === []): ?>
                    <tr>
                        <td colspan="6" class="muted">No page level insights available yet.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($topPages as $pageRow): ?>
                        <tr>
                            <td>
                                <a class="admin-link" href="<?= esc((string) ($pageRow['url'] ?? '#')); ?>" target="_blank" rel="noopener">
                                    <?= esc((string) ($pageRow['title'] ?? $pageRow['url'] ?? 'Untitled page')); ?>
                                </a>
                            </td>
                            <td><?= esc((string) ($pageRow['domain'] ?? '')); ?></td>
                            <td>
                                <span class="badge"><?= esc(number_format((float) ($pageRow['page_authority'] ?? 0) * 100, 1)); ?></span>
                                <div class="muted admin-text-xxs">Domain <?= esc(number_format((float) ($pageRow['domain_authority'] ?? 0) * 100, 1)); ?></div>
                            </td>
                            <td><?= esc(number_format((int) ($pageRow['inbound_links'] ?? 0))); ?></td>
                            <td><?= esc(number_format((int) ($pageRow['unique_sources'] ?? 0))); ?></td>
                            <td><?= esc((string) ($pageRow['fetched_at'] ?? '')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
            <p class="table-note">Inbound counts show total referencing links discovered during crawl sessions.</p>
        </section>
    <?php endif; ?>
</main>
</body>
</html>
