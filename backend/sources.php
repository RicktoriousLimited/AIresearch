<?php

declare(strict_types=1);

session_start();

require __DIR__ . '/../src/App/bootstrap.php';
require __DIR__ . '/../src/Ricktorious/Ecommerce/bootstrap.php';
require_once __DIR__ . '/../src/App/Crawler/HiddenCrawler.php';

use App\Crawler\HiddenCrawler;
use App\KnowledgeGraph\ResearchService;

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
    <link rel="stylesheet" href="/assets/styles.css">
    <style>
        body { background: #0f172a; color: #e2e8f0; font-family: 'Inter', system-ui, sans-serif; }
        main { max-width: 1120px; margin: 0 auto; padding: 2rem 1rem 3rem; }
        .card { background: rgba(15, 23, 42, 0.82); border: 1px solid rgba(148, 163, 184, 0.2); border-radius: 16px; padding: 1.75rem; margin-bottom: 1.75rem; box-shadow: 0 24px 48px rgba(15, 23, 42, 0.45); }
        h1 { font-size: 2rem; margin-bottom: 0.75rem; }
        h2 { font-size: 1.45rem; margin-bottom: 1rem; }
        .muted { color: rgba(148, 163, 184, 0.9); }
        .summary-grid { display: grid; gap: 1.2rem; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); }
        .summary-card { background: rgba(30, 41, 59, 0.65); border: 1px solid rgba(148, 163, 184, 0.25); border-radius: 14px; padding: 1.1rem 1.25rem; }
        .summary-card h3 { margin: 0 0 0.25rem; font-size: 1rem; color: #c7d2fe; }
        .summary-card p { margin: 0.2rem 0 0; font-size: 0.9rem; }
        .pill-link { display: inline-flex; align-items: center; gap: 0.35rem; padding: 0.45rem 0.9rem; border-radius: 999px; background: rgba(99, 102, 241, 0.18); border: 1px solid rgba(99, 102, 241, 0.4); color: #c7d2fe; font-weight: 600; text-decoration: none; }
        .pill-link:hover { text-decoration: underline; }
        table { width: 100%; border-collapse: collapse; margin-top: 0.75rem; }
        th, td { text-align: left; padding: 0.65rem 0.5rem; }
        th { font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.08em; color: rgba(148, 163, 184, 0.9); }
        tbody tr { border-top: 1px solid rgba(148, 163, 184, 0.2); }
        tbody tr:first-of-type { border-top: none; }
        tbody tr:hover { background: rgba(30, 41, 59, 0.55); }
        .badge { display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.2rem 0.55rem; border-radius: 999px; background: rgba(99, 102, 241, 0.18); border: 1px solid rgba(99, 102, 241, 0.4); font-size: 0.75rem; color: #c7d2fe; }
        .badge--success { background: rgba(16, 185, 129, 0.18); border-color: rgba(16, 185, 129, 0.4); color: #6ee7b7; }
        .badge--warm { background: rgba(245, 158, 11, 0.18); border-color: rgba(245, 158, 11, 0.4); color: #fcd34d; }
        .sources-chip { display: inline-flex; align-items: center; gap: 0.25rem; padding: 0.25rem 0.6rem; border-radius: 999px; background: rgba(59, 130, 246, 0.18); border: 1px solid rgba(59, 130, 246, 0.35); font-size: 0.75rem; color: #bfdbfe; }
        .table-note { font-size: 0.85rem; color: rgba(148, 163, 184, 0.85); margin-top: 0.6rem; }
        .login-warning { background: rgba(248, 113, 113, 0.16); border: 1px solid rgba(248, 113, 113, 0.4); border-radius: 12px; padding: 1rem 1.2rem; color: #fecaca; }
    </style>
</head>
<body>
<main>
    <header class="card">
        <h1>Source intelligence</h1>
        <p class="muted">Monitor the strongest domains, inbound link paths and authority signals discovered by the crawler. Data refreshed <?= esc($generatedAt); ?>.</p>
        <div style="margin-top: 1rem; display: flex; gap: 0.75rem; flex-wrap: wrap;">
            <a class="pill-link" href="/backend/crawler.php">Return to crawler control</a>
            <a class="pill-link" href="/backend/crawler-progress.php" target="_blank" rel="noopener">Live progress JSON</a>
        </div>
    </header>

    <?php if (!isset($_SESSION['backend_user'])): ?>
        <section class="card">
            <div class="login-warning">
                <strong>Authentication required.</strong>
                <p style="margin: 0.6rem 0 0;">Sign in from the crawler control page to view detailed source analytics.</p>
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
                                <div style="display:flex; flex-direction:column;">
                                    <strong><?= esc((string) ($domainRow['domain'] ?? '')); ?></strong>
                                    <?php if (!empty($domainRow['top_page']['url'] ?? '')): ?>
                                        <span class="muted" style="font-size:0.8rem;">Top page: <a href="<?= esc((string) $domainRow['top_page']['url']); ?>" target="_blank" rel="noopener" style="color:#c7d2fe; text-decoration:none;">view</a></span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <span class="badge badge--success"><?= esc(number_format((float) ($domainRow['domain_authority'] ?? 0) * 100, 1)); ?></span>
                                <div class="muted" style="font-size:0.75rem;">Baseline <?= esc(number_format((float) ($domainRow['baseline'] ?? 0) * 100, 1)); ?> · Avg <?= esc(number_format((float) ($domainRow['average_page_authority'] ?? 0) * 100, 1)); ?></div>
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
                                <a href="<?= esc((string) ($pageRow['url'] ?? '#')); ?>" target="_blank" rel="noopener" style="color:#c7d2fe; text-decoration:none;">
                                    <?= esc((string) ($pageRow['title'] ?? $pageRow['url'] ?? 'Untitled page')); ?>
                                </a>
                            </td>
                            <td><?= esc((string) ($pageRow['domain'] ?? '')); ?></td>
                            <td>
                                <span class="badge"><?= esc(number_format((float) ($pageRow['page_authority'] ?? 0) * 100, 1)); ?></span>
                                <div class="muted" style="font-size:0.75rem;">Domain <?= esc(number_format((float) ($pageRow['domain_authority'] ?? 0) * 100, 1)); ?></div>
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
