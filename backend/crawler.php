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
use Ricktorious\Ecommerce\User\OneTimePasswordManager;
use Ricktorious\Ecommerce\User\UserService;

/**
 * Simple escape helper for HTML context.
 */
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

/** @var UserService $userService */
$userService = $container->get(UserService::class);
/** @var OneTimePasswordManager $otpManager */
$otpManager = $container->get(OneTimePasswordManager::class);

$paths = PathResolver::resolve();
$assetBase = PathResolver::normalizeBase($paths['assetBase']);
$sharedStylesPath = PathResolver::url($assetBase, 'assets/styles.css');
$sharedStylesVersion = file_exists(__DIR__ . '/../assets/styles.css') ? (string) filemtime(__DIR__ . '/../assets/styles.css') : (string) time();
$adminStylesPath = PathResolver::url($assetBase, 'assets/admin.css');
$adminStylesVersion = file_exists(__DIR__ . '/../assets/admin.css') ? (string) filemtime(__DIR__ . '/../assets/admin.css') : (string) time();
$navigationLinks = AdminNavigation::resolve();
$navigationLinks['crawler'] = $navigationLinks['crawler'] ?? ['label' => 'Crawler', 'href' => PathResolver::url($assetBase, 'backend/crawler.php')];

$crawlerStorage = __DIR__ . '/../storage/backend/crawler-history.json';
$crawler = new HiddenCrawler($crawlerStorage, null, null, new ResearchService());

$messages = [];
$errors = [];
$generatedOtp = null;
$autoInterval = (int) ($_SESSION['backend_auto_interval'] ?? 0);
$depth = (int) ($_SESSION['backend_depth'] ?? 0);
$autoStart = isset($_SESSION['backend_auto_start']) ? (bool) $_SESSION['backend_auto_start'] : false;
$refreshAfter = (int) ($_SESSION['backend_refresh_after'] ?? 0);
$scheduledLimit = (int) ($_SESSION['backend_scheduled_limit'] ?? 5);
if ($scheduledLimit <= 0) {
    $scheduledLimit = 5;
}
$scheduledDepth = (int) ($_SESSION['backend_scheduled_depth'] ?? ($depth > 0 ? $depth : 1));
if ($scheduledDepth < 0) {
    $scheduledDepth = 0;
}
$bulkScheduleLimit = (int) ($_SESSION['backend_bulk_schedule_limit'] ?? 12);
if ($bulkScheduleLimit <= 0) {
    $bulkScheduleLimit = 12;
}
$bulkScheduleDepth = (int) ($_SESSION['backend_bulk_schedule_depth'] ?? 1);
if ($bulkScheduleDepth < 0) {
    $bulkScheduleDepth = 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $autoInterval = max(0, (int) ($_POST['auto_interval'] ?? $autoInterval));
    $depth = max(0, (int) ($_POST['depth'] ?? 0));
    $autoStart = isset($_POST['auto_start'])
        ? in_array(strtolower((string) $_POST['auto_start']), ['1', 'true', 'yes', 'on'], true)
        : false;
    $refreshAfter = max(0, (int) ($_POST['refresh_after'] ?? $refreshAfter));
    if (isset($_POST['scheduled_limit'])) {
        $scheduledLimit = max(1, (int) $_POST['scheduled_limit']);
    }
    if (isset($_POST['scheduled_depth'])) {
        $scheduledDepth = max(0, (int) $_POST['scheduled_depth']);
    }
    if (isset($_POST['bulk_limit'])) {
        $bulkScheduleLimit = max(1, (int) $_POST['bulk_limit']);
    }
    if (isset($_POST['bulk_depth'])) {
        $bulkScheduleDepth = max(0, (int) $_POST['bulk_depth']);
    }
}

$_SESSION['backend_auto_interval'] = $autoInterval;
$_SESSION['backend_depth'] = $depth;
$_SESSION['backend_auto_start'] = $autoStart;
$_SESSION['backend_refresh_after'] = $refreshAfter;
$_SESSION['backend_scheduled_limit'] = $scheduledLimit;
$_SESSION['backend_scheduled_depth'] = $scheduledDepth;
$_SESSION['backend_bulk_schedule_limit'] = $bulkScheduleLimit;
$_SESSION['backend_bulk_schedule_depth'] = $bulkScheduleDepth;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    switch ($action) {
        case 'login':
            $email = (string) ($_POST['email'] ?? '');
            $password = (string) ($_POST['password'] ?? '');

            try {
                $user = $userService->authenticate($email, $password);
                $_SESSION['backend_user'] = $user;
                $messages[] = 'Welcome back, ' . esc((string) ($user['profile']['name'] ?? $user['email'] ?? 'user')) . '.';
            } catch (Throwable $exception) {
                $errors[] = 'Unable to authenticate: ' . $exception->getMessage();
            }
            break;

        case 'logout':
            unset($_SESSION['backend_user']);
            $sessionUser = null;
            $messages[] = 'Session closed successfully.';
            break;

        case 'request-otp':
            $email = (string) ($_POST['email'] ?? '');

            if ($userService->findByEmail($email) === null) {
                $errors[] = 'No account is registered with that email address.';
                break;
            }

            try {
                $otp = $otpManager->issue($email);
                $generatedOtp = $otp['otp'];
                $messages[] = 'A one-time password has been issued. Check your email to continue.';
            } catch (Throwable $exception) {
                $errors[] = 'Unable to generate one-time password: ' . $exception->getMessage();
            }
            break;

        case 'reset-password':
            $email = (string) ($_POST['email'] ?? '');
            $otpCode = (string) ($_POST['otp'] ?? '');
            $password = (string) ($_POST['password'] ?? '');

            if (!$otpManager->verify($email, $otpCode)) {
                $errors[] = 'Invalid or expired one-time password.';
                break;
            }

            try {
                $user = $userService->resetPassword($email, $password);
                $otpManager->consume($email);
                $_SESSION['backend_user'] = $user;
                $messages[] = 'Password updated successfully. You are now signed in as ' . esc((string) ($user['email'] ?? 'user')) . '.';
            } catch (Throwable $exception) {
                $errors[] = 'Unable to reset password: ' . $exception->getMessage();
            }
            break;

        case 'crawl':
            if (!isset($_SESSION['backend_user'])) {
                $errors[] = 'You must be signed in to run the crawler.';
                break;
            }

            $urlsInput = (string) ($_POST['urls'] ?? '');
            $urlLines = preg_split('/\r?\n/', $urlsInput) ?: [];
            $targets = array_values(array_filter(array_map('trim', $urlLines), static fn(string $value): bool => $value !== ''));

            if ($targets === []) {
                $errors[] = 'Provide at least one URL to crawl.';
                break;
            }

            try {
                $results = $crawler->crawl($targets, $depth, $autoInterval, $autoStart, $refreshAfter);
                $processedCount = 0;
                $failedCount = 0;
                $failureMessages = [];

                foreach ($results as $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }

                    $errorMessage = (string) ($entry['error'] ?? '');
                    if ($errorMessage !== '') {
                        $failedCount++;
                        $failureMessages[] = 'Failed to crawl ' . esc((string) ($entry['url'] ?? '')) . ': ' . esc($errorMessage) . '.';
                        continue;
                    }

                    $processedCount++;
                }

                $statusMessage = 'Crawler fetched ' . $processedCount . ' page(s).';
                if ($failedCount > 0) {
                    $statusMessage .= ' ' . $failedCount . ' page(s) failed.';
                }

                $messages[] = $statusMessage;

                foreach ($failureMessages as $failureMessage) {
                    $errors[] = $failureMessage;
                }
            } catch (Throwable $exception) {
                $errors[] = 'Crawler failed: ' . $exception->getMessage();
            }
            break;

        case 'continue-scheduled':
            if (!isset($_SESSION['backend_user'])) {
                $errors[] = 'You must be signed in to run the crawler.';
                break;
            }

            try {
                $run = $crawler->runScheduledQueue($scheduledLimit, $scheduledDepth, $autoInterval, $autoStart, $refreshAfter);
                $targetsRun = is_array($run['targets'] ?? null) ? $run['targets'] : [];
                if ($targetsRun === []) {
                    $messages[] = 'No scheduled pages were available to process.';
                    break;
                }

                $results = is_array($run['results'] ?? null) ? $run['results'] : [];
                $processedCount = (int) ($run['processed'] ?? count($results));
                $failedCount = 0;
                $failureMessages = [];

                foreach ($results as $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }

                    $errorMessage = (string) ($entry['error'] ?? '');
                    if ($errorMessage !== '') {
                        $failedCount++;
                        $failureMessages[] = 'Failed to crawl ' . esc((string) ($entry['url'] ?? '')) . ': ' . esc($errorMessage) . '.';
                        continue;
                    }
                }

                if ($processedCount > 0) {
                    $messages[] = 'Processed ' . $processedCount . ' scheduled page(s).';
                }
                if ($failedCount > 0) {
                    $messages[] = $failedCount . ' scheduled page(s) failed.';
                }
                $remaining = (int) ($run['scheduled_remaining'] ?? 0);
                if ($remaining > 0) {
                    $messages[] = $remaining . ' page(s) remain in the backlog.';
                } elseif ($processedCount > 0) {
                    $messages[] = 'Scheduled backlog is now empty.';
                }

                foreach ($failureMessages as $failureMessage) {
                    $errors[] = $failureMessage;
                }
            } catch (Throwable $exception) {
                $errors[] = 'Unable to run scheduled crawl: ' . $exception->getMessage();
            }
            break;

        case 'schedule-discoveries':
            if (!isset($_SESSION['backend_user'])) {
                $errors[] = 'You must be signed in to run the crawler.';
                break;
            }

            try {
                $summary = $crawler->scheduleRecommended($bulkScheduleLimit, $bulkScheduleDepth);
                $scheduledCount = (int) ($summary['scheduled'] ?? 0);
                $totalBacklog = (int) ($summary['total'] ?? 0);

                if ($scheduledCount > 0) {
                    $messages[] = 'Scheduled ' . $scheduledCount . ' discovered page(s) for future crawling.';
                } else {
                    $messages[] = 'No new discoveries were scheduled; the backlog already includes the recommended links.';
                }

                $messages[] = 'Backlog now contains ' . $totalBacklog . ' page(s).';
            } catch (Throwable $exception) {
                $errors[] = 'Unable to schedule discoveries: ' . $exception->getMessage();
            }
            break;
    }
}

$sessionUser = $_SESSION['backend_user'] ?? null;
$history = $crawler->history();
$progress = $crawler->progress();
$progressJson = json_encode($progress, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
if (!is_string($progressJson)) {
    $progressJson = '{}';
}
$refreshAfter = isset($progress['refresh_after'])
    ? max(0, (int) $progress['refresh_after'])
    : $refreshAfter;
$urlsDefault = trim((string) ($_POST['urls'] ?? $_SESSION['backend_urls'] ?? "https://news.ycombinator.com\nhttps://www.bbc.com/news\nhttps://techcrunch.com"));
$_SESSION['backend_urls'] = $urlsDefault;
$autoInterval = max(0, (int) ($_SESSION['backend_auto_interval'] ?? 0));
$refreshAfter = max(0, (int) ($_SESSION['backend_refresh_after'] ?? $refreshAfter));

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Signal Ledger · Hidden crawler control</title>
    <link rel="stylesheet" href="<?= esc($sharedStylesPath . '?v=' . $sharedStylesVersion); ?>">
    <link rel="stylesheet" href="<?= esc($adminStylesPath . '?v=' . $adminStylesVersion); ?>">
</head>
<body class="backend-admin">
<?php SiteLayout::renderHeader($navigationLinks, 'crawler'); ?>
<main class="backend-admin__main">
    <header class="card admin-page-header">
        <div>
            <h1>Hidden crawler control centre</h1>
            <p class="admin-page-header__meta">Keep this tab open to let the crawler refresh insights in the background. Configure your targets, enable auto-refresh and monitor the extraction pipeline.</p>
        </div>
        <div class="admin-page-header__actions">
            <a class="pill-link ghost" href="/backend/sources.php">View source intelligence</a>
        </div>
    </header>

    <?php if ($messages !== []): ?>
        <div class="messages">
            <ul>
                <?php foreach ($messages as $message): ?>
                    <li><?= esc($message); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($errors !== []): ?>
        <div class="errors">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= esc($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($generatedOtp !== null): ?>
        <div class="messages">
            <strong>One-time password</strong>
            <div class="otp-code"><?= esc($generatedOtp); ?></div>
            <p class="muted">For production use, wire this into your email service. Displayed here for quick testing.</p>
        </div>
    <?php endif; ?>

    <section class="card">
        <h2>Account access</h2>
        <?php if (!isset($_SESSION['backend_user'])): ?>
            <div class="history-grid">
                <form method="post" class="card card--ghost">
                    <input type="hidden" name="action" value="login">
                    <label for="login-email">Email address</label>
                    <input id="login-email" name="email" type="email" required value="<?= esc((string) ($_POST['email'] ?? 'waheed.rahman@ricktorious.com')); ?>">
                    <label for="login-password">Password</label>
                    <input id="login-password" name="password" type="password" required>
                    <button type="submit">Sign in</button>
                </form>
                <form method="post" class="card card--ghost">
                    <input type="hidden" name="action" value="request-otp">
                    <label for="otp-email">Request one-time password</label>
                    <input id="otp-email" name="email" type="email" required value="<?= esc((string) ($_POST['email'] ?? 'waheed.rahman@ricktorious.com')); ?>">
                    <button type="submit">Send OTP</button>
                </form>
                <form method="post" class="card card--ghost">
                    <input type="hidden" name="action" value="reset-password">
                    <label for="reset-email">Email address</label>
                    <input id="reset-email" name="email" type="email" required value="<?= esc((string) ($_POST['email'] ?? 'waheed.rahman@ricktorious.com')); ?>">
                    <label for="reset-otp">One-time password</label>
                    <input id="reset-otp" name="otp" type="text" required>
                    <label for="reset-password-input">New password</label>
                    <input id="reset-password-input" name="password" type="password" required>
                    <button type="submit">Reset &amp; sign in</button>
                </form>
            </div>
        <?php else: ?>
            <p>Signed in as <strong><?= esc((string) ($_SESSION['backend_user']['email'] ?? 'user')); ?></strong>.</p>
            <form method="post" class="admin-space-top-lg">
                <input type="hidden" name="action" value="logout">
                <button class="logout" type="submit">Log out</button>
            </form>
        <?php endif; ?>
    </section>

    <section class="card">
        <h2>Configure crawler</h2>
        <form method="post" id="crawler-form">
            <input type="hidden" name="action" value="crawl">
            <label for="urls">Seed URLs (one per line)</label>
            <textarea id="urls" name="urls" spellcheck="false"><?= esc($urlsDefault); ?></textarea>
            <div class="form-grid form-grid--fluid">
                <div class="input-group">
                    <label for="auto_interval">Auto-refresh interval (minutes)</label>
                    <input id="auto_interval" name="auto_interval" type="number" min="0" value="<?= esc((string) $autoInterval); ?>">
                </div>
                <div class="input-group">
                    <label for="crawl_depth">Link depth</label>
                    <input id="crawl_depth" name="depth" type="number" min="0" max="6" value="<?= esc((string) $depth); ?>">
                </div>
                <div class="input-group">
                    <label for="refresh_after">Refresh pages older than (minutes)</label>
                    <input id="refresh_after" name="refresh_after" type="number" min="0" value="<?= esc((string) $refreshAfter); ?>">
                </div>
                <div class="input-group input-group--checkbox">
                    <label class="auto-start-toggle" for="auto_start">
                        <input id="auto_start" name="auto_start" type="checkbox" value="1" <?= $autoStart ? 'checked' : ''; ?>>
                        <span>Auto-start the next run when scheduled</span>
                    </label>
                </div>
                <div class="input-group input-group--actions">
                    <button type="submit">Run crawl now</button>
                </div>
            </div>
            <p class="muted admin-space-top-sm">Set the auto-refresh interval to keep the crawler running while this tab remains open. Increase the link depth to explore trusted links discovered on each page. Use the refresh field to automatically revisit pages that have gone stale.</p>
        </form>
    </section>

    <?php
        $scheduledTotalInitial = (int) ($progress['scheduled_total'] ?? 0);
        $scheduledPreviewInitial = is_array($progress['scheduled_preview'] ?? null) ? $progress['scheduled_preview'] : [];
    ?>
    <section class="card">
        <h2>Scheduled backlog</h2>
        <p class="muted">Continue exploring previously discovered links or queue up recommendations for later runs. Backlog items keep their discovery depth so you can pace long explorations.</p>
        <div class="history-grid">
            <form method="post" class="card card--ghost">
                <input type="hidden" name="action" value="continue-scheduled">
                <div class="form-grid form-grid--compact">
                    <div class="input-group">
                        <label for="scheduled-limit">Process next (pages)</label>
                        <input id="scheduled-limit" name="scheduled_limit" type="number" min="1" max="60" value="<?= esc((string) $scheduledLimit); ?>">
                    </div>
                    <div class="input-group">
                        <label for="scheduled-depth">Explore linked depth</label>
                        <input id="scheduled-depth" name="scheduled_depth" type="number" min="0" max="6" value="<?= esc((string) $scheduledDepth); ?>">
                    </div>
                    <div class="input-group input-group--actions">
                        <button type="submit">Run scheduled crawl</button>
                    </div>
                </div>
            </form>
            <form method="post" class="card card--ghost">
                <input type="hidden" name="action" value="schedule-discoveries">
                <div class="form-grid form-grid--compact">
                    <div class="input-group">
                        <label for="bulk-limit">Add discoveries (count)</label>
                        <input id="bulk-limit" name="bulk_limit" type="number" min="1" max="60" value="<?= esc((string) $bulkScheduleLimit); ?>">
                    </div>
                    <div class="input-group">
                        <label for="bulk-depth">Assign depth level</label>
                        <input id="bulk-depth" name="bulk_depth" type="number" min="0" max="6" value="<?= esc((string) $bulkScheduleDepth); ?>">
                    </div>
                    <div class="input-group input-group--actions">
                        <button type="submit">Schedule discoveries</button>
                    </div>
                </div>
            </form>
        </div>
        <p class="muted admin-space-top-md">Backlog contains <strong><?= esc((string) $scheduledTotalInitial); ?></strong> page(s).</p>
        <div class="task-list">
            <h3>Backlog preview</h3>
            <p class="muted<?= $scheduledPreviewInitial === [] ? '' : ' is-hidden'; ?>" id="scheduled-empty">No scheduled pages waiting.</p>
            <ul class="task-items" id="scheduled-items">
                <?php foreach (array_slice($scheduledPreviewInitial, 0, 12) as $queuedItem): ?>
                    <?php if (!is_array($queuedItem)) { continue; }
                        $queuedUrl = (string) ($queuedItem['url'] ?? '');
                        $queuedDomain = (string) ($queuedItem['domain'] ?? ($queuedUrl !== '' ? parse_url($queuedUrl, PHP_URL_HOST) : ''));
                        $queuedDepth = (int) ($queuedItem['depth'] ?? 0);
                        $queuedPriority = (float) ($queuedItem['priority'] ?? 0.0);
                        $queuedAt = (string) ($queuedItem['queued_at'] ?? '');
                        $queuedSeed = !empty($queuedItem['seed'] ?? false);
                        $queuedDueAt = (string) ($queuedItem['due_at'] ?? '');
                        $queuedFreshState = (string) ($queuedItem['freshness_state'] ?? '');
                        $queuedFreshLabel = trim((string) ($queuedItem['freshness_label'] ?? ''));
                        $queuedQueuedLabel = trim((string) ($queuedItem['queued_label'] ?? ''));
                        $queuedLastSeen = trim((string) ($queuedItem['last_seen_at'] ?? ''));
                    ?>
                    <li class="task-item">
                        <strong>
                            <?php if ($queuedUrl !== ''): ?>
                                <a href="<?= esc($queuedUrl); ?>" target="_blank" rel="noopener"><?= esc($queuedUrl); ?></a>
                            <?php else: ?>
                                Scheduled page
                            <?php endif; ?>
                        </strong>
                        <div class="task-meta">
                            <?php if ($queuedDomain !== ''): ?><span><?= esc($queuedDomain); ?></span><?php endif; ?>
                            <span>Depth <?= esc((string) $queuedDepth); ?></span>
                            <span>Priority <?= esc(number_format($queuedPriority, 2)); ?></span>
                            <?php if ($queuedSeed): ?><span class="task-chip task-chip--seed">Seed</span><?php endif; ?>
                            <?php if ($queuedFreshLabel !== ''): ?>
                                <span class="task-chip"<?php if ($queuedFreshState !== ''): ?> data-state="<?= esc($queuedFreshState); ?>"<?php endif; ?><?php if ($queuedDueAt !== ''): ?> title="<?= esc($queuedDueAt); ?>"<?php endif; ?>>
                                    <?= esc($queuedFreshLabel); ?>
                                </span>
                            <?php endif; ?>
                            <?php if ($queuedQueuedLabel !== ''): ?><span class="muted"><?= esc($queuedQueuedLabel); ?></span><?php endif; ?>
                            <?php if ($queuedLastSeen !== ''): ?><span class="muted">Last seen <?= esc($queuedLastSeen); ?></span><?php endif; ?>
                            <?php if ($queuedAt !== '' && $queuedQueuedLabel === ''): ?><span class="muted">Queued <?= esc($queuedAt); ?></span><?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </section>

    <section class="card">
        <h2>Live crawl status</h2>
        <?php
            $progressStatus = isset($progress['status']) ? (string) $progress['status'] : 'idle';
            $normalizedStatus = in_array($progressStatus, ['fetching', 'initialising'], true) ? 'running' : ($progressStatus === 'idle' ? 'idle' : ($progressStatus === 'error' ? 'error' : $progressStatus));
            $statusLabel = ucfirst($progressStatus);
            $progressMessage = (string) ($progress['message'] ?? 'Idle');
            $processedCount = (int) ($progress['processed'] ?? 0);
            $totalCount = (int) ($progress['total'] ?? 0);
            $currentUrl = (string) ($progress['current_url'] ?? '');
            $discoveredCount = (int) ($progress['discovered'] ?? 0);
            $lastRunAt = (string) ($progress['last_run_at'] ?? '');
            $nextRunDue = (string) ($progress['next_run_due_at'] ?? '');
            $lastResult = is_array($progress['last_result'] ?? null) ? $progress['last_result'] : null;
            $progressErrors = is_array($progress['errors'] ?? null) ? $progress['errors'] : [];
            $taskTotalsRaw = is_array($progress['task_totals'] ?? null) ? $progress['task_totals'] : [];
            $taskTotals = array_merge(['queued' => 0, 'running' => 0, 'completed' => 0, 'failed' => 0], $taskTotalsRaw);
            $scheduledTotal = (int) ($progress['scheduled_total'] ?? 0);
            $scheduledPreview = is_array($progress['scheduled_preview'] ?? null) ? $progress['scheduled_preview'] : [];
        ?>
        <div class="status-pill" data-state="<?= esc($normalizedStatus); ?>" id="progress-status-pill">
            <span></span>
            <strong id="progress-status-label"><?= esc($statusLabel); ?></strong>
        </div>
        <p class="progress-message" id="progress-message" data-state="<?= esc($normalizedStatus === 'error' ? 'error' : ''); ?>"><?= esc($progressMessage); ?></p>
        <dl class="progress-grid" id="progress-grid">
            <div>
                <dt>Processed</dt>
                <dd id="progress-count"><?= esc((string) $processedCount); ?> / <?= esc((string) ($totalCount > 0 ? $totalCount : max($totalCount, $processedCount))); ?></dd>
            </div>
            <div>
                <dt>Current URL</dt>
                <dd id="progress-url"><?= $currentUrl !== '' ? '<a href="' . esc($currentUrl) . '" target="_blank" rel="noopener">' . esc($currentUrl) . '</a>' : '—'; ?></dd>
            </div>
            <div>
                <dt>Discovered</dt>
                <dd id="progress-discovered"><?= esc((string) $discoveredCount); ?></dd>
            </div>
            <div>
                <dt>Tasks</dt>
                <dd id="progress-task-summary"><?= esc((string) $taskTotals['queued']); ?> queued · <?= esc((string) $taskTotals['running']); ?> running</dd>
            </div>
            <div>
                <dt>Scheduled backlog</dt>
                <dd id="progress-scheduled-count"><?= esc((string) $scheduledTotal); ?></dd>
            </div>
            <div>
                <dt>Last run</dt>
                <dd id="progress-last-run"><?= $lastRunAt !== '' ? esc($lastRunAt) : '—'; ?></dd>
            </div>
            <div>
                <dt>Next scheduled</dt>
                <dd id="progress-next-run"><?= $nextRunDue !== '' ? esc($nextRunDue) : 'Not scheduled'; ?></dd>
            </div>
        </dl>
        <div class="progress-last-result<?= $lastResult ? ' active' : ''; ?>" id="progress-last-result">
            <h3 id="progress-last-title"><?= esc((string) ($lastResult['title'] ?? ($lastResult['url'] ?? 'Recent page'))); ?></h3>
            <p id="progress-last-url"><?= $lastResult && isset($lastResult['url']) ? '<a href="' . esc((string) $lastResult['url']) . '" target="_blank" rel="noopener">' . esc((string) $lastResult['url']) . '</a>' : ''; ?></p>
            <p id="progress-last-quality">Quality score: <?= esc(number_format((float) ($lastResult['quality'] ?? 0), 1)); ?> · Revision <?= esc((string) ($lastResult['revision'] ?? 0)); ?></p>
            <p id="progress-last-authority">Authority: <?= esc(number_format((float) ($lastResult['authority'] ?? 0) * 100, 1)); ?> · Domain <?= esc(number_format((float) ($lastResult['domain_authority'] ?? 0) * 100, 1)); ?> · Inbound <?= esc((string) ($lastResult['inbound_links'] ?? 0)); ?></p>
            <p id="progress-last-ingest"><?= !empty($lastResult['ingested']) ? 'Ingested into knowledge graph' : 'Held locally'; ?></p>
            <p id="progress-last-error" class="error-text<?= $lastResult && !empty($lastResult['error']) ? '' : ' is-hidden'; ?>">
                <?= $lastResult && !empty($lastResult['error']) ? esc((string) $lastResult['error']) : ''; ?>
            </p>
        </div>
        <ul class="progress-errors" id="progress-errors">
            <?php if ($progressErrors !== []): ?>
                <?php foreach (array_slice($progressErrors, -5) as $errorItem): ?>
                    <?php if (!is_array($errorItem)) { continue; }
                        $errorMessage = (string) ($errorItem['message'] ?? '');
                        if ($errorMessage === '') { continue; }
                    ?>
                    <li><?= esc($errorMessage); ?></li>
                <?php endforeach; ?>
            <?php endif; ?>
        </ul>
        <div class="task-list" id="progress-task-container">
            <h3>Task queue</h3>
            <p class="muted" id="progress-task-empty">Tasks will appear here as the crawler runs.</p>
            <ul class="task-items" id="progress-task-items"></ul>
        </div>
        <?php
            $discoverySummary = is_array($progress['discovery_summary'] ?? null) ? $progress['discovery_summary'] : [];
            $discoveryTotalsRaw = is_array($discoverySummary['totals'] ?? null) ? $discoverySummary['totals'] : [];
            $discoveryTotals = array_merge([
                'links' => 0,
                'domains' => 0,
                'fresh' => 0,
                'recent' => 0,
                'stale' => 0,
                'overdue' => 0,
                'queued' => 0,
                'running' => 0,
                'new' => 0,
                'failed' => 0,
                'unknown' => 0,
            ], $discoveryTotalsRaw);
            $discoveryDomains = is_array($discoverySummary['domains'] ?? null) ? $discoverySummary['domains'] : [];
            $discoveryLinks = is_array($discoverySummary['links'] ?? null) ? $discoverySummary['links'] : [];
        ?>
        <div class="crawler-discovery" id="progress-discovery">
            <div class="crawler-discovery__header">
                <h3>Discovery coverage</h3>
                <p class="muted" id="progress-discovery-updated">
                    <?= isset($discoverySummary['generated_at']) && $discoverySummary['generated_at'] !== ''
                        ? 'Updated ' . esc((string) $discoverySummary['generated_at'])
                        : 'Waiting for crawl data'; ?>
                </p>
            </div>
            <dl class="crawler-discovery__totals">
                <div>
                    <dt>Total links</dt>
                    <dd id="progress-discovery-total-links"><?= esc((string) $discoveryTotals['links']); ?></dd>
                </div>
                <div>
                    <dt>Domains</dt>
                    <dd id="progress-discovery-total-domains"><?= esc((string) $discoveryTotals['domains']); ?></dd>
                </div>
                <div>
                    <dt>Queued</dt>
                    <dd id="progress-discovery-total-queued"><?= esc((string) $discoveryTotals['queued']); ?></dd>
                </div>
                <div>
                    <dt>Running</dt>
                    <dd id="progress-discovery-total-running"><?= esc((string) $discoveryTotals['running']); ?></dd>
                </div>
                <div>
                    <dt>Fresh</dt>
                    <dd id="progress-discovery-total-fresh"><?= esc((string) $discoveryTotals['fresh']); ?></dd>
                </div>
                <div>
                    <dt>Overdue</dt>
                    <dd id="progress-discovery-total-overdue"><?= esc((string) $discoveryTotals['overdue']); ?></dd>
                </div>
                <div>
                    <dt>Stale</dt>
                    <dd id="progress-discovery-total-stale"><?= esc((string) $discoveryTotals['stale']); ?></dd>
                </div>
            </dl>
            <div class="crawler-discovery__grid">
                <div class="crawler-discovery__column">
                    <h4>By domain</h4>
                    <p class="muted" id="progress-discovery-domains-empty"<?= $discoveryDomains === [] ? '' : ' hidden'; ?>>No domains discovered yet.</p>
                    <ul class="crawler-discovery__domain-list" id="progress-discovery-domains">
                        <?php foreach (array_slice($discoveryDomains, 0, 15) as $domainRow):
                            if (!is_array($domainRow)) { continue; }
                            $domainName = (string) ($domainRow['domain'] ?? '');
                            $domainTotal = (int) ($domainRow['total'] ?? 0);
                            $domainOverdue = (int) ($domainRow['overdue'] ?? 0);
                            $domainQueued = (int) ($domainRow['queued'] ?? 0);
                            $domainFresh = (int) ($domainRow['fresh'] ?? 0);
                            $domainLast = (string) ($domainRow['last_crawled_at'] ?? '');
                            $domainNext = isset($domainRow['next_due_at']) ? (string) $domainRow['next_due_at'] : '';
                        ?>
                            <li>
                                <span class="label"><?= esc($domainName !== '' ? $domainName : 'Unknown domain'); ?></span>
                                <span class="value"><?= esc((string) $domainTotal); ?> links</span>
                                <div class="crawler-discovery__meta">
                                    <span><?= esc((string) $domainQueued); ?> queued · <?= esc((string) $domainOverdue); ?> overdue · <?= esc((string) $domainFresh); ?> fresh</span>
                                    <?php if ($domainLast !== ''): ?>
                                        <span>Last crawl <?= esc($domainLast); ?></span>
                                    <?php endif; ?>
                                    <?php if ($domainNext !== ''): ?>
                                        <span>Next due <?= esc($domainNext); ?></span>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <div class="crawler-discovery__column">
                    <h4>Tracked links</h4>
                    <p class="muted" id="progress-discovery-links-empty"<?= $discoveryLinks === [] ? '' : ' hidden'; ?>>No discovery links recorded yet.</p>
                    <ul class="crawler-discovery__link-list" id="progress-discovery-links">
                        <?php foreach (array_slice($discoveryLinks, 0, 20) as $linkRow):
                            if (!is_array($linkRow)) { continue; }
                            $linkUrl = (string) ($linkRow['url'] ?? '');
                            $linkDomain = (string) ($linkRow['domain'] ?? '');
                            $linkStatus = (string) ($linkRow['status'] ?? 'unknown');
                            $linkLast = (string) ($linkRow['last_crawled_at'] ?? '');
                            $linkDue = isset($linkRow['next_due_at']) ? (string) $linkRow['next_due_at'] : '';
                        ?>
                            <li>
                                <p class="crawler-discovery__link">
                                    <span class="discovery-status" data-status="<?= esc($linkStatus); ?>"><?= esc(ucfirst($linkStatus)); ?></span>
                                    <?php if ($linkUrl !== ''): ?>
                                        <a href="<?= esc($linkUrl); ?>" target="_blank" rel="noopener"><?= esc($linkUrl); ?></a>
                                    <?php else: ?>
                                        <span><?= esc($linkDomain !== '' ? $linkDomain : 'Unknown link'); ?></span>
                                    <?php endif; ?>
                                </p>
                                <div class="crawler-discovery__meta">
                                    <?php if ($linkLast !== ''): ?>
                                        <span>Last crawl <?= esc($linkLast); ?></span>
                                    <?php endif; ?>
                                    <?php if ($linkDue !== ''): ?>
                                        <span>Next due <?= esc($linkDue); ?></span>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    <section class="card">
        <h2>Recent crawl history</h2>
        <?php if ($history === []): ?>
            <p class="muted">No crawl results yet. Launch a crawl to populate this feed.</p>
        <?php else: ?>
            <?php foreach ($history as $item): ?>
                <article class="history-item">
                    <h3><a class="admin-link" href="<?= esc((string) ($item['url'] ?? '#')); ?>" target="_blank" rel="noopener">
                        <?= esc((string) ($item['title'] ?? $item['url'] ?? 'Untitled page')); ?>
                    </a></h3>
                    <p class="muted">Fetched <?= esc((string) ($item['fetched_at'] ?? 'unknown')); ?></p>
                    <?php
                        $categoryRaw = isset($item['category']) ? (string) $item['category'] : 'global';
                        $categoryNormalised = $categoryRaw === 'financial' ? 'financial' : 'global';
                        $topicsRaw = $item['topics'] ?? [];
                        $topicChips = [];
                        if (is_array($topicsRaw)) {
                            foreach ($topicsRaw as $topicValue) {
                                if (!is_string($topicValue)) {
                                    continue;
                                }

                                $topicValue = trim($topicValue);
                                if ($topicValue === '') {
                                    continue;
                                }

                                $topicChips[] = $topicValue;
                            }
                        }
                        $qualityScore = isset($item['quality_score']) ? (float) $item['quality_score'] : 0.0;
                        $qualityLabelRaw = isset($item['quality_label']) ? (string) $item['quality_label'] : '';
                        $qualityLabel = $qualityLabelRaw !== '' ? $qualityLabelRaw : 'Scored';
                        $ingestDecision = !empty($item['ingest']);
                        $qualityBadgeClass = $ingestDecision ? 'quality-pill quality-pill--approved' : 'quality-pill quality-pill--held';
                        $domainLabel = isset($item['source_domain']) ? (string) $item['source_domain'] : '';
                        $qualityReasons = [];
                        if (isset($item['quality_reasons']) && is_array($item['quality_reasons'])) {
                            foreach ($item['quality_reasons'] as $reason) {
                                if (!is_string($reason)) {
                                    continue;
                                }

                                $reason = trim($reason);
                                if ($reason === '') {
                                    continue;
                                }

                                $qualityReasons[] = $reason;
                            }
                        }
                        $recommendedSources = [];
                        if (isset($item['recommended_sources']) && is_array($item['recommended_sources'])) {
                            foreach ($item['recommended_sources'] as $sourceRow) {
                                if (!is_array($sourceRow)) {
                                    continue;
                                }

                                $sourceUrl = (string) ($sourceRow['url'] ?? '');
                                $sourceDomain = (string) ($sourceRow['domain'] ?? '');
                                $trust = (float) ($sourceRow['trust_score'] ?? 0.0);

                                if ($sourceUrl === '' || $sourceDomain === '') {
                                    continue;
                                }

                                $recommendedSources[] = [
                                    'url' => $sourceUrl,
                                    'domain' => $sourceDomain,
                                    'trust' => $trust,
                                ];
                            }
                        }
                        $thumbnailUrl = '';
                        if (isset($item['thumbnail']) && is_string($item['thumbnail'])) {
                            $thumbnailCandidate = trim($item['thumbnail']);
                            if ($thumbnailCandidate !== '') {
                                $thumbnailUrl = $thumbnailCandidate;
                            }
                        }
                        $contentTypeRaw = isset($item['content_type']) ? (string) $item['content_type'] : 'page';
                        $contentTypeLabel = ucfirst(str_replace('_', ' ', $contentTypeRaw));
                        $revision = isset($item['revision']) ? (int) $item['revision'] : 1;
                        $lastChecked = isset($item['last_checked_at']) ? (string) $item['last_checked_at'] : '';
                        $changeSummary = '';
                        if (isset($item['changes'])) {
                            if (is_array($item['changes'])) {
                                $changeSummary = (string) ($item['changes']['summary'] ?? '');
                            } elseif (is_string($item['changes'])) {
                                $changeSummary = trim($item['changes']);
                            }
                        }
                        $versionEntries = [];
                        if (isset($item['versions']) && is_array($item['versions'])) {
                            foreach ($item['versions'] as $versionRow) {
                                if (!is_array($versionRow)) {
                                    continue;
                                }

                                $versionEntries[] = [
                                    'revision' => (int) ($versionRow['revision'] ?? 0),
                                    'title' => (string) ($versionRow['title'] ?? ($versionRow['url'] ?? 'Untitled version')),
                                    'fetched_at' => (string) ($versionRow['fetched_at'] ?? ''),
                                    'summary' => (string) ($versionRow['summary'] ?? ($versionRow['preview'] ?? '')),
                                    'changes' => is_array($versionRow['changes'] ?? null)
                                        ? (string) ($versionRow['changes']['summary'] ?? '')
                                        : (string) ($versionRow['changes'] ?? ''),
                                ];
                            }
                        }
                        $normalizedUrl = isset($item['normalized_url']) ? (string) $item['normalized_url'] : '';
                        $ranking = is_array($item['ranking'] ?? null) ? $item['ranking'] : [];
                        $pageAuthorityScore = isset($ranking['page_authority']) ? (float) $ranking['page_authority'] : 0.0;
                        $domainAuthorityScore = isset($ranking['domain_authority']) ? (float) $ranking['domain_authority'] : 0.0;
                        $inboundLinkCount = isset($ranking['inbound_links']) ? (int) $ranking['inbound_links'] : 0;
                        $uniqueSourceCount = isset($ranking['unique_sources']) ? (int) $ranking['unique_sources'] : 0;
                        $discoveryMeta = is_array($item['discovery'] ?? null) ? $item['discovery'] : [];
                        $firstDiscoveredAt = (string) ($discoveryMeta['first_seen_at'] ?? '');
                        $lastDiscoveredAt = (string) ($discoveryMeta['last_seen_at'] ?? '');
                        $discoverySeed = !empty($discoveryMeta['seed'] ?? false);
                        $discoverySources = [];
                        if (isset($discoveryMeta['sources']) && is_array($discoveryMeta['sources'])) {
                            $limit = 4;
                            foreach ($discoveryMeta['sources'] as $sourceRow) {
                                if ($limit <= 0 || !is_array($sourceRow)) {
                                    continue;
                                }

                                $sourceUrl = (string) ($sourceRow['url'] ?? '');
                                $sourceDomain = (string) ($sourceRow['domain'] ?? '');
                                $sourceCount = (int) ($sourceRow['count'] ?? 0);

                                if ($sourceUrl === '' && $sourceDomain === '') {
                                    continue;
                                }

                                if ($sourceDomain === '' && $sourceUrl !== '') {
                                    $parsedDomain = parse_url($sourceUrl, PHP_URL_HOST);
                                    if (is_string($parsedDomain)) {
                                        $sourceDomain = $parsedDomain;
                                    }
                                }

                                $discoverySources[] = [
                                    'url' => $sourceUrl,
                                    'domain' => $sourceDomain !== '' ? $sourceDomain : $sourceUrl,
                                    'count' => $sourceCount,
                                ];

                                $limit--;
                            }
                        }
                    ?>
                    <div class="history-item-meta">
                        <span class="category-label category-label--<?= esc($categoryNormalised); ?>"><?= esc(ucfirst($categoryNormalised)); ?></span>
                        <?php if ($topicChips !== []): ?>
                            <span class="muted">Topics:</span>
                            <?php foreach ($topicChips as $topic): ?>
                                <span class="topic-chip"><?= esc($topic); ?></span>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <div class="history-flags">
                        <span class="type-pill type-pill--<?= esc($contentTypeRaw); ?>"><?= esc($contentTypeLabel); ?></span>
                        <span class="type-pill type-pill--revision">Rev <?= esc((string) $revision); ?></span>
                    </div>
                    <div class="quality-row">
                        <span class="<?= esc($qualityBadgeClass); ?>">
                            <?= esc($qualityLabel); ?>
                            <span><?= esc(number_format($qualityScore, 1)); ?>/100</span>
                        </span>
                        <?php if ($domainLabel !== ''): ?>
                            <span class="muted">Source: <?= esc($domainLabel); ?></span>
                        <?php endif; ?>
                        <span class="ingest-flag ingest-flag--<?= esc($ingestDecision ? 'yes' : 'no'); ?>"><?= $ingestDecision ? 'Auto-ingested' : 'Held for review'; ?></span>
                    </div>
                    <?php if ($thumbnailUrl !== ''): ?>
                        <div class="history-thumb">
                            <img src="<?= esc($thumbnailUrl); ?>" alt="" loading="lazy">
                        </div>
                    <?php endif; ?>
                        <?php if (isset($item['error'])): ?>
                        <p class="errors admin-space-top-sm"><?= esc((string) $item['error']); ?></p>
                    <?php else: ?>
                        <p class="history-timing">
                            Fetched <?= esc((string) ($item['fetched_at'] ?? 'unknown')); ?>
                            <?php if ($lastChecked !== '' && $lastChecked !== ($item['fetched_at'] ?? '')): ?>
                                · Last checked <?= esc($lastChecked); ?>
                            <?php endif; ?>
                            <?php if ($normalizedUrl !== '' && $normalizedUrl !== (string) ($item['url'] ?? '')): ?>
                                · Canonical <?= esc($normalizedUrl); ?>
                            <?php endif; ?>
                        </p>
                        <div class="authority-row">
                            <strong>Authority</strong>
                            <span>Page <?= esc(number_format($pageAuthorityScore * 100, 1)); ?></span>
                            <span>Domain <?= esc(number_format($domainAuthorityScore * 100, 1)); ?></span>
                            <span><?= esc((string) $inboundLinkCount); ?> inbound</span>
                            <span><?= esc((string) $uniqueSourceCount); ?> source<?= $uniqueSourceCount === 1 ? '' : 's'; ?></span>
                        </div>
                        <p class="muted admin-space-top-xxs admin-no-margin-bottom">
                            <?= $discoverySeed ? 'Seed target' : 'Discovered'; ?>
                            <?php if ($firstDiscoveredAt !== ''): ?>
                                <?= $discoverySeed ? ' · Added ' . esc($firstDiscoveredAt) : ' · ' . esc($firstDiscoveredAt); ?>
                            <?php endif; ?>
                            <?php if ($lastDiscoveredAt !== '' && $lastDiscoveredAt !== $firstDiscoveredAt): ?>
                                · Updated <?= esc($lastDiscoveredAt); ?>
                            <?php endif; ?>
                        </p>
                        <?php if ($discoverySources !== []): ?>
                            <div class="discovery-sources">
                                <?php foreach ($discoverySources as $source): ?>
                                    <?php
                                        $label = (string) ($source['domain'] ?? '');
                                        $countValue = (int) ($source['count'] ?? 0);
                                        $countSuffix = $countValue > 1 ? ' ×' . $countValue : '';
                                    ?>
                                    <?php if ($source['url'] !== ''): ?>
                                        <a class="discovery-source" href="<?= esc((string) $source['url']); ?>" target="_blank" rel="noopener">
                                            <?= esc($label . $countSuffix); ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="discovery-source"><?= esc($label . $countSuffix); ?></span>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <p><?= esc((string) ($item['preview'] ?? '')); ?></p>
                        <?php if ($changeSummary !== ''): ?>
                            <p class="history-change"><?= esc($changeSummary); ?></p>
                        <?php endif; ?>
                        <?php if (!empty($item['keywords'])): ?>
                            <div class="keywords">
                                <?php foreach ($item['keywords'] as $keyword): ?>
                                    <span><?= esc((string) ($keyword['token'] ?? '')); ?> · <?= esc((string) ($keyword['count'] ?? '0')); ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($item['entities'])): ?>
                            <div class="entities">
                                <?php foreach ($item['entities'] as $entity): ?>
                                    <span><?= esc((string) ($entity['label'] ?? '')); ?><?= isset($entity['type']) && $entity['type'] !== '' ? ' · ' . esc((string) $entity['type']) : ''; ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($qualityReasons !== []): ?>
                            <ul class="quality-reasons">
                                <?php foreach ($qualityReasons as $reason): ?>
                                    <li><?= esc($reason); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        <?php if ($recommendedSources !== []): ?>
                            <div class="recommended">
                                <span>Trusted exits</span>
                                <?php foreach ($recommendedSources as $source): ?>
                                    <a href="<?= esc($source['url']); ?>" target="_blank" rel="noopener">
                                        <?= esc($source['domain']); ?> · <?= esc(number_format($source['trust'], 2)); ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($versionEntries !== []): ?>
                            <details class="history-versions">
                                <summary>Revision archive (<?= esc((string) count($versionEntries)); ?>)</summary>
                                <ol>
                                    <?php foreach ($versionEntries as $version): ?>
                                        <li>
                                            <div class="history-version-header">
                                                <span class="revision-badge">Rev <?= esc((string) $version['revision']); ?></span>
                                                <?php if ($version['fetched_at'] !== ''): ?>
                                                    <span class="muted"><?= esc($version['fetched_at']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <?php if ($version['summary'] !== ''): ?>
                                                <p><?= esc($version['summary']); ?></p>
                                            <?php endif; ?>
                                            <?php if ($version['changes'] !== ''): ?>
                                                <p class="history-change"><?= esc($version['changes']); ?></p>
                                            <?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ol>
                            </details>
                        <?php endif; ?>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>
</main>

<script type="application/json" id="progress-data"><?= $progressJson; ?></script>
<script>
(function () {
    const progressDataElement = document.getElementById('progress-data');
    let initialProgress = {};
    if (progressDataElement && progressDataElement.textContent) {
        try {
            initialProgress = JSON.parse(progressDataElement.textContent);
        } catch (error) {
            initialProgress = {};
        }
    }

    const state = {
        data: initialProgress,
        isRunning: false,
        pendingReload: false,
        lastRunAt: typeof initialProgress.last_run_at === 'string' ? initialProgress.last_run_at : null,
        pollTimer: null,
    };

    const statusPill = document.getElementById('progress-status-pill');
    const statusLabel = document.getElementById('progress-status-label');
    const messageEl = document.getElementById('progress-message');
    const processedEl = document.getElementById('progress-count');
    const urlEl = document.getElementById('progress-url');
    const discoveredEl = document.getElementById('progress-discovered');
    const lastRunEl = document.getElementById('progress-last-run');
    const nextRunEl = document.getElementById('progress-next-run');
    const scheduledCountEl = document.getElementById('progress-scheduled-count');
    const lastResultEl = document.getElementById('progress-last-result');
    const lastTitleEl = document.getElementById('progress-last-title');
    const lastUrlEl = document.getElementById('progress-last-url');
    const lastQualityEl = document.getElementById('progress-last-quality');
    const lastAuthorityEl = document.getElementById('progress-last-authority');
    const lastIngestEl = document.getElementById('progress-last-ingest');
    const lastErrorEl = document.getElementById('progress-last-error');
    const errorsEl = document.getElementById('progress-errors');
    const taskSummaryEl = document.getElementById('progress-task-summary');
    const taskListEl = document.getElementById('progress-task-items');
    const taskEmptyEl = document.getElementById('progress-task-empty');
    const scheduledListEl = document.getElementById('scheduled-items');
    const scheduledEmptyEl = document.getElementById('scheduled-empty');
    const discoverySection = document.getElementById('progress-discovery');
    const discoveryUpdatedEl = document.getElementById('progress-discovery-updated');
    const discoveryTotalsLinksEl = document.getElementById('progress-discovery-total-links');
    const discoveryTotalsDomainsEl = document.getElementById('progress-discovery-total-domains');
    const discoveryTotalsQueuedEl = document.getElementById('progress-discovery-total-queued');
    const discoveryTotalsRunningEl = document.getElementById('progress-discovery-total-running');
    const discoveryTotalsFreshEl = document.getElementById('progress-discovery-total-fresh');
    const discoveryTotalsOverdueEl = document.getElementById('progress-discovery-total-overdue');
    const discoveryTotalsStaleEl = document.getElementById('progress-discovery-total-stale');
    const discoveryDomainsList = document.getElementById('progress-discovery-domains');
    const discoveryDomainsEmpty = document.getElementById('progress-discovery-domains-empty');
    const discoveryLinksList = document.getElementById('progress-discovery-links');
    const discoveryLinksEmpty = document.getElementById('progress-discovery-links-empty');
    const form = document.getElementById('crawler-form');
    const runButton = form ? form.querySelector('button[type="submit"]') : null;

    function normaliseStatus(value) {
        const status = (value || '').toString().toLowerCase();
        if (status === 'fetching' || status === 'initialising') {
            return 'running';
        }
        if (status === 'idle' || status === 'error') {
            return status;
        }
        return 'running';
    }

    function formatCount(processed, total) {
        const processedValue = Number.isFinite(processed) ? processed : 0;
        const totalValue = Number.isFinite(total) ? total : 0;
        const denominator = totalValue > 0 ? totalValue : Math.max(totalValue, processedValue);
        return processedValue + ' / ' + denominator;
    }

    function escapeHtml(value) {
        const stringValue = value == null ? '' : String(value);
        return stringValue
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function formatTimestamp(value) {
        if (!value || typeof value !== 'string') {
            return '';
        }

        const parsed = new Date(value);
        if (Number.isNaN(parsed.getTime())) {
            return value;
        }

        return parsed.toLocaleString();
    }

    function formatFreshness(minutes) {
        if (!Number.isFinite(minutes)) {
            return '';
        }

        if (minutes < 1) {
            return 'Just checked';
        }

        if (minutes < 60) {
            return `${Math.round(minutes)} min old`;
        }

        if (minutes < 1440) {
            const hours = Math.round(minutes / 60);
            return `${hours} hr${hours === 1 ? '' : 's'} old`;
        }

        const days = Math.round(minutes / 1440);
        return `${days} day${days === 1 ? '' : 's'} old`;
    }

    function formatDue(minutes, timestamp) {
        if (Number.isFinite(minutes)) {
            const rounded = Math.round(minutes);
            if (rounded === 0) {
                return 'Due now';
            }

            if (rounded < 0) {
                const overdue = Math.abs(rounded);
                if (overdue < 60) {
                    return `${overdue} min overdue`;
                }
                if (overdue < 1440) {
                    const overdueHours = Math.round(overdue / 60);
                    return `${overdueHours} hr${overdueHours === 1 ? '' : 's'} overdue`;
                }
                const overdueDays = Math.round(overdue / 1440);
                return `${overdueDays} day${overdueDays === 1 ? '' : 's'} overdue`;
            }

            if (rounded < 60) {
                return `Due in ${rounded} min`;
            }

            if (rounded < 1440) {
                const hours = Math.round(rounded / 60);
                return `Due in ${hours} hr${hours === 1 ? '' : 's'}`;
            }

            const days = Math.round(rounded / 1440);
            return `Due in ${days} day${days === 1 ? '' : 's'}`;
        }

        if (timestamp && typeof timestamp === 'string') {
            const formatted = formatTimestamp(timestamp);
            return formatted !== '' ? `Next due ${formatted}` : '';
        }

        return '';
    }

    function formatStatusLabel(status) {
        const map = {
            running: 'Running',
            queued: 'Queued',
            overdue: 'Overdue',
            failed: 'Failed',
            fresh: 'Fresh',
            recent: 'Recent',
            stale: 'Stale',
            new: 'New',
            unknown: 'Unknown',
        };

        const normalised = typeof status === 'string' ? status.toLowerCase() : 'unknown';
        return map[normalised] || normalised.charAt(0).toUpperCase() + normalised.slice(1);
    }

    function setStatusPill(stateName) {
        if (statusPill) {
            statusPill.setAttribute('data-state', stateName);
        }
    }

    function setMessage(text, isError) {
        if (!messageEl) {
            return;
        }
        messageEl.textContent = text;
        if (isError) {
            messageEl.setAttribute('data-state', 'error');
        } else {
            messageEl.removeAttribute('data-state');
        }
    }

    function renderScheduled(preview, total) {
        if (scheduledCountEl) {
            scheduledCountEl.textContent = String(Number(total ?? 0));
        }

        if (!scheduledListEl || !scheduledEmptyEl) {
            return;
        }

        scheduledListEl.innerHTML = '';

        const list = Array.isArray(preview) ? preview.slice(0, 12) : [];
        if (list.length === 0) {
            scheduledEmptyEl.style.display = 'block';
            return;
        }

        scheduledEmptyEl.style.display = 'none';

        list.forEach(function (item) {
            if (!item || typeof item !== 'object') {
                return;
            }

            const url = typeof item.url === 'string' ? item.url : '';
            const domain = typeof item.domain === 'string' ? item.domain : '';
            const depth = Number.isFinite(item.depth) ? Number(item.depth) : 0;
            const priority = Number.isFinite(item.priority) ? Number(item.priority) : 0;
            const queuedAt = typeof item.queued_at === 'string' ? item.queued_at : '';
            const seed = Boolean(item.seed);
            const dueAt = typeof item.due_at === 'string' ? item.due_at : '';
            const freshnessState = typeof item.freshness_state === 'string' ? item.freshness_state : '';
            const freshnessLabel = typeof item.freshness_label === 'string' ? item.freshness_label.trim() : '';
            const queuedLabel = typeof item.queued_label === 'string' ? item.queued_label.trim() : '';
            const lastSeen = typeof item.last_seen_at === 'string' ? item.last_seen_at.trim() : '';

            const li = document.createElement('li');
            li.className = 'task-item';

            const strong = document.createElement('strong');
            if (url !== '') {
                const anchor = document.createElement('a');
                anchor.href = url;
                anchor.target = '_blank';
                anchor.rel = 'noopener';
                anchor.textContent = url;
                strong.appendChild(anchor);
            } else {
                strong.textContent = 'Scheduled page';
            }
            li.appendChild(strong);

            const meta = document.createElement('div');
            meta.className = 'task-meta';

            if (domain !== '') {
                const domainSpan = document.createElement('span');
                domainSpan.textContent = domain;
                meta.appendChild(domainSpan);
            }

            const depthSpan = document.createElement('span');
            depthSpan.textContent = 'Depth ' + depth;
            meta.appendChild(depthSpan);

            const prioritySpan = document.createElement('span');
            prioritySpan.textContent = 'Priority ' + priority.toFixed(2);
            meta.appendChild(prioritySpan);

            if (seed) {
                const seedSpan = document.createElement('span');
                seedSpan.className = 'task-chip task-chip--seed';
                seedSpan.textContent = 'Seed';
                meta.appendChild(seedSpan);
            }

            if (freshnessLabel !== '') {
                const freshnessSpan = document.createElement('span');
                freshnessSpan.className = 'task-chip';
                if (freshnessState !== '') {
                    freshnessSpan.setAttribute('data-state', freshnessState);
                }
                if (dueAt !== '') {
                    freshnessSpan.title = dueAt;
                }
                freshnessSpan.textContent = freshnessLabel;
                meta.appendChild(freshnessSpan);
            }

            if (queuedLabel !== '') {
                const queuedSpan = document.createElement('span');
                queuedSpan.className = 'muted';
                queuedSpan.textContent = queuedLabel;
                meta.appendChild(queuedSpan);
            }

            if (lastSeen !== '') {
                const lastSeenSpan = document.createElement('span');
                lastSeenSpan.className = 'muted';
                lastSeenSpan.textContent = 'Last seen ' + lastSeen;
                meta.appendChild(lastSeenSpan);
            }

            if (queuedAt !== '' && queuedLabel === '') {
                const queuedSpan = document.createElement('span');
                queuedSpan.className = 'muted';
                queuedSpan.textContent = 'Queued ' + queuedAt;
                meta.appendChild(queuedSpan);
            }

            li.appendChild(meta);
            scheduledListEl.appendChild(li);
        });
    }

    function renderDiscovery(summary) {
        if (!discoverySection) {
            return;
        }

        const hasSummary = summary && typeof summary === 'object';
        const totals = hasSummary && typeof summary.totals === 'object' ? summary.totals : {};
        const domains = hasSummary && Array.isArray(summary.domains) ? summary.domains : [];
        const links = hasSummary && Array.isArray(summary.links) ? summary.links : [];

        if (discoveryUpdatedEl) {
            const generatedAt = hasSummary && typeof summary.generated_at === 'string' && summary.generated_at !== ''
                ? `Updated ${formatTimestamp(summary.generated_at) || summary.generated_at}`
                : 'Waiting for crawl data';
            discoveryUpdatedEl.textContent = generatedAt;
        }

        const counters = [
            [discoveryTotalsLinksEl, totals.links],
            [discoveryTotalsDomainsEl, totals.domains],
            [discoveryTotalsQueuedEl, totals.queued],
            [discoveryTotalsRunningEl, totals.running],
            [discoveryTotalsFreshEl, totals.fresh],
            [discoveryTotalsOverdueEl, totals.overdue],
            [discoveryTotalsStaleEl, totals.stale],
        ];

        counters.forEach(function (tuple) {
            const el = tuple[0];
            const value = tuple[1];
            if (el) {
                el.textContent = String(Number(value ?? 0));
            }
        });

        if (discoveryDomainsList) {
            discoveryDomainsList.innerHTML = '';
            if (domains.length === 0) {
                if (discoveryDomainsEmpty) {
                    discoveryDomainsEmpty.hidden = false;
                }
            } else {
                const fragment = document.createDocumentFragment();
                domains.forEach(function (domain) {
                    if (!domain || typeof domain !== 'object') {
                        return;
                    }

                    const name = typeof domain.domain === 'string' && domain.domain !== ''
                        ? domain.domain
                        : 'Unknown domain';
                    const total = Number(domain.total ?? 0);
                    const queued = Number(domain.queued ?? 0);
                    const overdue = Number(domain.overdue ?? 0);
                    const fresh = Number(domain.fresh ?? 0);
                    const lastCrawl = formatTimestamp(domain.last_crawled_at);
                    const nextDueLabel = typeof domain.next_due_at === 'string' && domain.next_due_at !== ''
                        ? formatTimestamp(domain.next_due_at)
                        : '';

                    const li = document.createElement('li');

                    const label = document.createElement('span');
                    label.className = 'label';
                    label.textContent = name;
                    li.appendChild(label);

                    const valueEl = document.createElement('span');
                    valueEl.className = 'value';
                    valueEl.textContent = `${total} link${total === 1 ? '' : 's'}`;
                    li.appendChild(valueEl);

                    const meta = document.createElement('div');
                    meta.className = 'crawler-discovery__meta';
                    const metaParts = [
                        `${queued} queued`,
                        `${overdue} overdue`,
                        `${fresh} fresh`,
                    ];

                    if (lastCrawl) {
                        metaParts.push(`Last crawl ${lastCrawl}`);
                    }

                    if (nextDueLabel) {
                        metaParts.push(`Next due ${nextDueLabel}`);
                    }

                    meta.textContent = metaParts.join(' · ');
                    li.appendChild(meta);

                    fragment.appendChild(li);
                });

                discoveryDomainsList.appendChild(fragment);
                if (discoveryDomainsEmpty) {
                    discoveryDomainsEmpty.hidden = true;
                }
            }
        }

        if (discoveryLinksList) {
            discoveryLinksList.innerHTML = '';
            if (links.length === 0) {
                if (discoveryLinksEmpty) {
                    discoveryLinksEmpty.hidden = false;
                }
            } else {
                const fragment = document.createDocumentFragment();
                links.forEach(function (link) {
                    if (!link || typeof link !== 'object') {
                        return;
                    }

                    const url = typeof link.url === 'string' ? link.url : '';
                    const domain = typeof link.domain === 'string' ? link.domain : '';
                    const status = typeof link.status === 'string' ? link.status : 'unknown';
                    const freshnessMinutes = Number.isFinite(link.freshness_minutes)
                        ? Number(link.freshness_minutes)
                        : null;
                    const dueMinutes = Number.isFinite(link.due_in_minutes)
                        ? Number(link.due_in_minutes)
                        : null;
                    const lastCrawl = formatTimestamp(link.last_crawled_at);
                    const dueLabel = formatDue(dueMinutes, link.next_due_at);
                    const freshnessLabel = freshnessMinutes !== null ? formatFreshness(freshnessMinutes) : '';

                    const li = document.createElement('li');

                    const header = document.createElement('p');
                    header.className = 'crawler-discovery__link';

                    const statusBadge = document.createElement('span');
                    statusBadge.className = 'discovery-status';
                    statusBadge.setAttribute('data-status', status);
                    statusBadge.textContent = formatStatusLabel(status);
                    header.appendChild(statusBadge);

                    if (url) {
                        const anchor = document.createElement('a');
                        anchor.href = url;
                        anchor.target = '_blank';
                        anchor.rel = 'noopener';
                        anchor.textContent = url;
                        header.appendChild(anchor);
                    } else {
                        const span = document.createElement('span');
                        span.textContent = domain || 'Unknown link';
                        header.appendChild(span);
                    }

                    li.appendChild(header);

                    const meta = document.createElement('div');
                    meta.className = 'crawler-discovery__meta';
                    const metaParts = [];
                    if (domain) {
                        metaParts.push(domain);
                    }
                    if (freshnessLabel) {
                        metaParts.push(freshnessLabel);
                    }
                    if (lastCrawl) {
                        metaParts.push(`Last crawl ${lastCrawl}`);
                    }
                    if (dueLabel) {
                        metaParts.push(dueLabel);
                    }
                    meta.textContent = metaParts.join(' · ');
                    li.appendChild(meta);

                    fragment.appendChild(li);
                });

                discoveryLinksList.appendChild(fragment);
                if (discoveryLinksEmpty) {
                    discoveryLinksEmpty.hidden = true;
                }
            }
        }
    }

    function renderLastResult(result) {
        if (!lastResultEl) {
            return;
        }

        if (!result || typeof result !== 'object') {
            lastResultEl.classList.remove('active');
            if (lastTitleEl) {
                lastTitleEl.textContent = 'Recent page';
            }
            if (lastUrlEl) {
                lastUrlEl.innerHTML = '';
            }
            if (lastQualityEl) {
                lastQualityEl.textContent = 'Quality score: 0.0 · Revision 0';
            }
            if (lastAuthorityEl) {
                lastAuthorityEl.textContent = 'Authority: 0.0 · Domain 0.0 · Inbound 0';
            }
            if (lastIngestEl) {
                lastIngestEl.textContent = 'Held locally';
            }
            if (lastErrorEl) {
                lastErrorEl.style.display = 'none';
                lastErrorEl.textContent = '';
            }
            return;
        }

        lastResultEl.classList.add('active');

        if (lastTitleEl) {
            const title = typeof result.title === 'string' && result.title !== ''
                ? result.title
                : (typeof result.url === 'string' ? result.url : 'Recent page');
            lastTitleEl.textContent = title;
        }

        if (lastUrlEl) {
            const url = typeof result.url === 'string' ? result.url : '';
            lastUrlEl.innerHTML = url !== ''
                ? '<a href="' + url + '" target="_blank" rel="noopener">' + url + '</a>'
                : '';
        }

        if (lastQualityEl) {
            const quality = Number.isFinite(result.quality) ? Number(result.quality) : 0;
            const revision = Number.isFinite(result.revision) ? Number(result.revision) : 0;
            lastQualityEl.textContent = 'Quality score: ' + quality.toFixed(1) + ' · Revision ' + revision;
        }

        if (lastAuthorityEl) {
            const authority = Number.isFinite(result.authority) ? Number(result.authority) : 0;
            const domainAuthority = Number.isFinite(result.domain_authority) ? Number(result.domain_authority) : 0;
            const inbound = Number.isFinite(result.inbound_links) ? Number(result.inbound_links) : 0;
            lastAuthorityEl.textContent = 'Authority: ' + (authority * 100).toFixed(1) + ' · Domain ' + (domainAuthority * 100).toFixed(1) + ' · Inbound ' + inbound;
        }

        if (lastIngestEl) {
            lastIngestEl.textContent = result.ingested ? 'Ingested into knowledge graph' : 'Held locally';
        }

        if (lastErrorEl) {
            const hasError = typeof result.error === 'string' && result.error !== '';
            if (hasError) {
                lastErrorEl.style.display = '';
                lastErrorEl.textContent = result.error;
            } else {
                lastErrorEl.style.display = 'none';
                lastErrorEl.textContent = '';
            }
        }
    }

    function renderErrors(errorList) {
        if (!errorsEl) {
            return;
        }
        errorsEl.innerHTML = '';
        if (!Array.isArray(errorList) || errorList.length === 0) {
            return;
        }

        const recent = errorList.slice(-5);
        recent.forEach(function (entry) {
            if (!entry || typeof entry !== 'object') {
                return;
            }
            const message = typeof entry.message === 'string' ? entry.message : '';
            if (!message) {
                return;
            }
            const item = document.createElement('li');
            item.textContent = message;
            errorsEl.appendChild(item);
        });
    }

    function renderTaskSummary(totals) {
        if (!taskSummaryEl) {
            return;
        }
        const defaults = { queued: 0, running: 0, completed: 0, failed: 0 };
        const merged = Object.assign({}, defaults, (totals && typeof totals === 'object') ? totals : {});
        taskSummaryEl.textContent = merged.queued + ' queued · ' + merged.running + ' running';
    }

    function renderTasks(tasks) {
        if (!taskListEl || !taskEmptyEl) {
            return;
        }

        taskListEl.innerHTML = '';
        let list = [];

        if (Array.isArray(tasks)) {
            list = tasks.slice();
        } else if (tasks && typeof tasks === 'object') {
            list = Object.values(tasks);
        }

        if (list.length === 0) {
            taskEmptyEl.style.display = 'block';
            return;
        }

        taskEmptyEl.style.display = 'none';

        const order = { running: 0, queued: 1, failed: 2, completed: 3 };
        list.sort(function (left, right) {
            const leftStatus = typeof left.status === 'string' ? left.status.toLowerCase() : 'queued';
            const rightStatus = typeof right.status === 'string' ? right.status.toLowerCase() : 'queued';
            const leftOrder = order[leftStatus] ?? 4;
            const rightOrder = order[rightStatus] ?? 4;
            if (leftOrder === rightOrder) {
                const leftQueued = typeof left.queued_at === 'string' ? left.queued_at : '';
                const rightQueued = typeof right.queued_at === 'string' ? right.queued_at : '';
                return rightQueued.localeCompare(leftQueued);
            }
            return leftOrder - rightOrder;
        });

        list.slice(0, 20).forEach(function (task) {
            if (!task || typeof task !== 'object') {
                return;
            }

            const li = document.createElement('li');
            li.className = 'task-item';

            const url = typeof task.url === 'string' ? task.url : '';
            const title = document.createElement('strong');
            title.textContent = url !== '' ? url : 'Task';
            li.appendChild(title);

            const meta = document.createElement('div');
            meta.className = 'task-meta';

            const statusValue = (typeof task.status === 'string' ? task.status : 'queued').toLowerCase();
            const status = document.createElement('span');
            status.className = 'task-status';
            status.setAttribute('data-state', statusValue);
            status.textContent = statusValue.charAt(0).toUpperCase() + statusValue.slice(1);
            meta.appendChild(status);

            const depth = Number.isFinite(task.depth) ? Number(task.depth) : null;
            if (depth !== null) {
                const depthSpan = document.createElement('span');
                depthSpan.textContent = 'Depth ' + depth;
                meta.appendChild(depthSpan);
            }

            if (task.seed) {
                const seedSpan = document.createElement('span');
                seedSpan.textContent = 'Seed';
                meta.appendChild(seedSpan);
            }

            if (task.refresh) {
                const refreshSpan = document.createElement('span');
                refreshSpan.textContent = 'Refresh';
                meta.appendChild(refreshSpan);
            }

            const attempts = Number(task.attempts ?? 0);
            if (attempts > 1) {
                const attemptsSpan = document.createElement('span');
                attemptsSpan.textContent = attempts + ' attempts';
                meta.appendChild(attemptsSpan);
            }

            const characters = Number(task.characters ?? 0);
            if (characters > 0) {
                const charSpan = document.createElement('span');
                charSpan.textContent = characters.toLocaleString() + ' chars';
                meta.appendChild(charSpan);
            }

            const started = typeof task.started_at === 'string' ? task.started_at : null;
            const finished = typeof task.finished_at === 'string' ? task.finished_at : null;
            if (finished) {
                const finishedSpan = document.createElement('span');
                finishedSpan.textContent = 'Finished ' + new Date(finished).toLocaleTimeString();
                meta.appendChild(finishedSpan);
            } else if (started) {
                const startedSpan = document.createElement('span');
                startedSpan.textContent = 'Started ' + new Date(started).toLocaleTimeString();
                meta.appendChild(startedSpan);
            }

            li.appendChild(meta);
            taskListEl.appendChild(li);
        });
    }

    function updateView(data) {
        data = data || {};
        state.data = data;

        const rawStatus = typeof data.status === 'string' ? data.status : 'idle';
        const statusNormalised = normaliseStatus(rawStatus);
        setStatusPill(statusNormalised);

        if (statusLabel) {
            statusLabel.textContent = rawStatus.charAt(0).toUpperCase() + rawStatus.slice(1);
        }

        const message = typeof data.message === 'string' && data.message !== ''
            ? data.message
            : (statusNormalised === 'running' ? 'Crawler is running…' : 'Idle');
        setMessage(message, statusNormalised === 'error');

        if (processedEl) {
            processedEl.textContent = formatCount(Number(data.processed ?? 0), Number(data.total ?? 0));
        }

        if (urlEl) {
            const url = typeof data.current_url === 'string' ? data.current_url : '';
            urlEl.innerHTML = url !== ''
                ? '<a href="' + url + '" target="_blank" rel="noopener">' + url + '</a>'
                : '—';
        }

        if (discoveredEl) {
            discoveredEl.textContent = String(Number(data.discovered ?? 0));
        }

        if (lastRunEl) {
            lastRunEl.textContent = typeof data.last_run_at === 'string' && data.last_run_at !== '' ? data.last_run_at : '—';
        }

        if (nextRunEl) {
            nextRunEl.textContent = typeof data.next_run_due_at === 'string' && data.next_run_due_at !== ''
                ? data.next_run_due_at
                : 'Not scheduled';
        }

        renderLastResult(data.last_result);
        renderErrors(data.errors);
        renderTaskSummary(data.task_totals);
        renderTasks(data.tasks);
        renderDiscovery(data.discovery_summary);
        renderScheduled(data.scheduled_preview, data.scheduled_total);

        if (state.pendingReload && statusNormalised === 'idle' && typeof data.last_run_at === 'string') {
            if (state.lastRunAt === null || state.lastRunAt !== data.last_run_at) {
                state.pendingReload = false;
                state.lastRunAt = data.last_run_at;
                setTimeout(function () {
                    window.location.reload();
                }, 800);
            }
        } else if (typeof data.last_run_at === 'string') {
            state.lastRunAt = data.last_run_at;
        }

        maybeAutoStart(data, statusNormalised);
    }

    async function fetchProgress() {
        try {
            const response = await fetch('/backend/crawler-progress.php', { credentials: 'same-origin' });
            if (!response.ok) {
                let message = 'Unable to fetch progress';
                try {
                    const errorPayload = await response.json();
                    if (errorPayload && typeof errorPayload.error === 'string' && errorPayload.error.trim() !== '') {
                        message = errorPayload.error.trim();
                    }
                } catch (jsonError) {
                    console.warn('Failed to parse crawler progress error response', jsonError);
                }

                if (response.status === 403 && state.pollTimer) {
                    clearInterval(state.pollTimer);
                    state.pollTimer = null;
                }

                throw new Error(message);
            }

            const payload = await response.json();
            if (payload && typeof payload.progress === 'object') {
                updateView(payload.progress);
            }
        } catch (error) {
            if (!state.isRunning) {
                const message = error instanceof Error && typeof error.message === 'string' && error.message !== ''
                    ? error.message
                    : 'Unable to refresh crawl status.';
                setMessage(message, true);
            }
        }
    }

    function startPolling() {
        fetchProgress();
        state.pollTimer = setInterval(fetchProgress, 5000);
    }

    startPolling();
    updateView(initialProgress);

    async function startRun(formData, options) {
        options = options || {};
        if (state.isRunning) {
            return;
        }

        state.isRunning = true;
        if (runButton) {
            runButton.disabled = true;
            runButton.textContent = 'Running…';
        }

        try {
            const response = await fetch('/backend/crawler-run.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
            });

            const payload = await response.json();
            if (payload && payload.success) {
                if (options.reloadOnFinish !== false) {
                    state.pendingReload = true;
                }
            } else if (payload && payload.error) {
                setMessage(payload.error, true);
            } else {
                setMessage('Crawler request failed.', true);
            }
        } catch (error) {
            setMessage('Crawler request failed.', true);
        } finally {
            state.isRunning = false;
            if (runButton) {
                runButton.disabled = false;
                runButton.textContent = 'Run crawl now';
            }
        }
    }

    function maybeAutoStart(data, statusNormalised) {
        if (!data || !data.auto_start || statusNormalised !== 'idle' || state.isRunning) {
            return;
        }

        if (!data.next_run_due_at) {
            return;
        }

        const due = Date.parse(data.next_run_due_at);
        if (Number.isNaN(due) || due > Date.now()) {
            return;
        }

        if (!Array.isArray(data.seed_urls) || data.seed_urls.length === 0) {
            return;
        }

        const formData = new FormData();
        formData.set('action', 'crawl');
        formData.set('urls', data.seed_urls.join('\n'));
        formData.set('depth', String(data.options && typeof data.options.depth === 'number' ? data.options.depth : 0));
        formData.set('auto_interval', String(Number(data.auto_interval || 0)));
        formData.set('auto_start', '1');
        const refreshValue = data.options && typeof data.options.refresh_after === 'number'
            ? data.options.refresh_after
            : Number(data.refresh_after || 0);
        formData.set('refresh_after', String(Math.max(0, Number(refreshValue || 0))));

        state.pendingReload = true;
        startRun(formData, { reloadOnFinish: true });
    }

    if (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            const formData = new FormData(form);
            formData.set('action', 'crawl');
            state.pendingReload = true;
            startRun(formData, { reloadOnFinish: true });
        });
    }
})();
</script>
</body>
</html>
