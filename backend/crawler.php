<?php

declare(strict_types=1);

session_start();

require __DIR__ . '/../src/App/bootstrap.php';
require __DIR__ . '/../src/Ricktorious/Ecommerce/bootstrap.php';
require_once __DIR__ . '/../src/App/Crawler/HiddenCrawler.php';

use App\Crawler\HiddenCrawler;
use App\KnowledgeGraph\ResearchService;
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

$crawlerStorage = __DIR__ . '/../storage/backend/crawler-history.json';
$crawler = new HiddenCrawler($crawlerStorage, null, null, new ResearchService());

$messages = [];
$errors = [];
$generatedOtp = null;
$autoInterval = (int) ($_SESSION['backend_auto_interval'] ?? 0);
$depth = (int) ($_SESSION['backend_depth'] ?? 0);
$autoStart = isset($_SESSION['backend_auto_start']) ? (bool) $_SESSION['backend_auto_start'] : false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $autoInterval = max(0, (int) ($_POST['auto_interval'] ?? $autoInterval));
    $depth = max(0, (int) ($_POST['depth'] ?? 0));
    $autoStart = isset($_POST['auto_start'])
        ? in_array(strtolower((string) $_POST['auto_start']), ['1', 'true', 'yes', 'on'], true)
        : false;
}

$_SESSION['backend_auto_interval'] = $autoInterval;
$_SESSION['backend_depth'] = $depth;
$_SESSION['backend_auto_start'] = $autoStart;

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
                $results = $crawler->crawl($targets, $depth, $autoInterval, $autoStart);
                $messages[] = 'Crawler fetched ' . count($results) . ' page(s).';
            } catch (Throwable $exception) {
                $errors[] = 'Crawler failed: ' . $exception->getMessage();
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
$urlsDefault = trim((string) ($_POST['urls'] ?? $_SESSION['backend_urls'] ?? "https://news.ycombinator.com\nhttps://www.bbc.com/news\nhttps://techcrunch.com"));
$_SESSION['backend_urls'] = $urlsDefault;
$autoInterval = max(0, (int) ($_SESSION['backend_auto_interval'] ?? 0));

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Signal Ledger · Hidden crawler control</title>
    <link rel="stylesheet" href="/assets/styles.css">
    <style>
        body { background: #0f172a; color: #e2e8f0; font-family: 'Inter', system-ui, sans-serif; }
        main { max-width: 960px; margin: 0 auto; padding: 2rem 1rem; }
        .card { background: rgba(15, 23, 42, 0.8); border: 1px solid rgba(148, 163, 184, 0.2); border-radius: 12px; padding: 1.5rem; margin-bottom: 1.5rem; box-shadow: 0 20px 40px rgba(15, 23, 42, 0.4); }
        h1 { font-size: 1.8rem; margin-bottom: 1rem; }
        h2 { font-size: 1.4rem; margin-bottom: 0.75rem; }
        label { display: block; margin-bottom: 0.5rem; font-weight: 600; }
        input[type="text"], input[type="email"], input[type="password"], textarea { width: 100%; padding: 0.75rem; border-radius: 8px; border: 1px solid rgba(148, 163, 184, 0.3); background: rgba(15, 23, 42, 0.6); color: inherit; }
        textarea { min-height: 140px; resize: vertical; }
        button { background: linear-gradient(135deg, #6366f1, #8b5cf6); color: #fff; border: none; border-radius: 8px; padding: 0.75rem 1.5rem; font-weight: 600; cursor: pointer; box-shadow: 0 10px 20px rgba(99, 102, 241, 0.4); }
        button:hover { opacity: 0.9; }
        .messages, .errors { margin: 0 0 1rem; padding: 0.75rem 1rem; border-radius: 8px; }
        .messages { background: rgba(34, 197, 94, 0.15); border: 1px solid rgba(74, 222, 128, 0.4); }
        .errors { background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(252, 165, 165, 0.5); }
        .history-item { border-top: 1px solid rgba(148, 163, 184, 0.2); padding: 1rem 0; }
        .history-item:first-of-type { border-top: none; }
        .history-item h3 { margin: 0 0 0.5rem; font-size: 1.1rem; }
        .history-grid { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); }
        .muted { color: rgba(148, 163, 184, 0.9); }
        .keywords span { display: inline-flex; margin: 0.2rem 0.4rem 0.2rem 0; padding: 0.25rem 0.5rem; border-radius: 999px; background: rgba(99, 102, 241, 0.2); }
        .entities span { display: inline-flex; margin: 0.2rem 0.4rem 0.2rem 0; padding: 0.25rem 0.5rem; border-radius: 999px; background: rgba(34, 197, 94, 0.15); }
        .otp-code { font-family: 'JetBrains Mono', monospace; font-size: 1.4rem; letter-spacing: 0.2rem; background: rgba(15, 23, 42, 0.7); padding: 0.5rem 1rem; border-radius: 8px; display: inline-block; margin-top: 0.5rem; }
        .logout { background: rgba(248, 113, 113, 0.2); border: 1px solid rgba(248, 113, 113, 0.4); color: #fecaca; }
        .card.card--ghost { background: transparent; border: 1px dashed rgba(148, 163, 184, 0.3); box-shadow: none; }
        .form-controls { display: flex; gap: 1rem; align-items: center; margin-top: 1rem; flex-wrap: wrap; }
        .history-item-meta { display: flex; align-items: center; flex-wrap: wrap; gap: 0.5rem; margin: 0.5rem 0; }
        .category-label { display: inline-flex; align-items: center; padding: 0.2rem 0.7rem; border-radius: 999px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; border: 1px solid transparent; }
        .category-label--financial { background: rgba(16, 185, 129, 0.18); border-color: rgba(16, 185, 129, 0.45); color: #34d399; }
        .category-label--global { background: rgba(96, 165, 250, 0.18); border-color: rgba(96, 165, 250, 0.45); color: #93c5fd; }
        .auto-start-toggle { display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.65rem 0.9rem; border-radius: 8px; border: 1px solid rgba(148, 163, 184, 0.3); background: rgba(15, 23, 42, 0.6); color: rgba(203, 213, 225, 0.9); font-size: 0.85rem; }
        .auto-start-toggle input { width: auto; }
        .progress-grid { display: grid; gap: 0.75rem; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); margin: 0.85rem 0; }
        .progress-grid div { background: rgba(15, 23, 42, 0.55); border: 1px solid rgba(148, 163, 184, 0.25); border-radius: 12px; padding: 0.75rem; }
        .progress-grid dt { margin: 0; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.08em; color: rgba(148, 163, 184, 0.85); }
        .progress-grid dd { margin: 0.35rem 0 0; font-size: 1rem; font-weight: 600; color: #e2e8f0; }
        .status-pill { display: inline-flex; align-items: center; gap: 0.45rem; padding: 0.35rem 0.85rem; border-radius: 999px; font-weight: 600; font-size: 0.85rem; border: 1px solid rgba(148, 163, 184, 0.35); background: rgba(148, 163, 184, 0.18); color: #e2e8f0; }
        .status-pill[data-state="running"] { background: rgba(59, 130, 246, 0.2); border-color: rgba(96, 165, 250, 0.45); color: #bfdbfe; }
        .status-pill[data-state="idle"] { background: rgba(34, 197, 94, 0.18); border-color: rgba(34, 197, 94, 0.45); color: #bbf7d0; }
        .status-pill[data-state="error"] { background: rgba(248, 113, 113, 0.2); border-color: rgba(248, 113, 113, 0.45); color: #fecaca; }
        .status-pill span { display: inline-flex; width: 0.6rem; height: 0.6rem; border-radius: 999px; background: currentColor; }
        .progress-message { margin-top: 0.75rem; font-size: 0.95rem; color: rgba(203, 213, 225, 0.9); }
        .progress-message[data-state="error"] { color: #fecaca; }
        .progress-last-result { margin-top: 0.85rem; padding: 0.85rem; border-radius: 10px; background: rgba(30, 41, 59, 0.65); border: 1px solid rgba(99, 102, 241, 0.35); display: none; }
        .progress-last-result.active { display: block; }
        .progress-last-result h3 { margin: 0 0 0.35rem; font-size: 1rem; color: #c7d2fe; }
        .progress-last-result p { margin: 0.2rem 0; font-size: 0.85rem; color: rgba(226, 232, 240, 0.85); }
        .progress-last-result .error-text { color: #fecaca; }
        .progress-errors { margin-top: 0.85rem; padding-left: 1.2rem; color: #fecaca; font-size: 0.85rem; }
        .progress-errors li { margin-bottom: 0.3rem; }
        .topic-chip { display: inline-flex; align-items: center; padding: 0.2rem 0.6rem; border-radius: 999px; background: rgba(148, 163, 184, 0.12); border: 1px solid rgba(148, 163, 184, 0.3); font-size: 0.75rem; }
        .quality-row { display: flex; flex-wrap: wrap; align-items: center; gap: 0.6rem 1rem; margin: 0.6rem 0; }
        .quality-pill { display: inline-flex; align-items: center; gap: 0.35rem; padding: 0.3rem 0.8rem; border-radius: 999px; font-weight: 600; font-size: 0.85rem; background: rgba(99, 102, 241, 0.18); border: 1px solid rgba(129, 140, 248, 0.45); color: #c7d2fe; }
        .quality-pill span { font-weight: 500; font-size: 0.8rem; opacity: 0.85; }
        .quality-pill--approved { background: rgba(34, 197, 94, 0.18); border-color: rgba(34, 197, 94, 0.45); color: #bbf7d0; }
        .quality-pill--held { background: rgba(248, 113, 113, 0.18); border-color: rgba(248, 113, 113, 0.35); color: #fecaca; }
        .ingest-flag { display: inline-flex; align-items: center; padding: 0.25rem 0.6rem; border-radius: 999px; font-size: 0.75rem; font-weight: 600; letter-spacing: 0.04em; text-transform: uppercase; border: 1px solid transparent; }
        .ingest-flag--yes { background: rgba(16, 185, 129, 0.18); border-color: rgba(16, 185, 129, 0.45); color: #6ee7b7; }
        .ingest-flag--no { background: rgba(244, 114, 182, 0.12); border-color: rgba(244, 114, 182, 0.4); color: #fbcfe8; }
        .quality-reasons { margin: 0.75rem 0 0; padding-left: 1.1rem; color: rgba(148, 163, 184, 0.9); font-size: 0.9rem; }
        .quality-reasons li { margin-bottom: 0.35rem; }
        .recommended { display: flex; flex-wrap: wrap; align-items: center; gap: 0.4rem 0.8rem; margin-top: 0.6rem; }
        .recommended span { font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.08em; color: rgba(148, 163, 184, 0.9); }
        .recommended a { display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.25rem 0.6rem; border-radius: 999px; background: rgba(59, 130, 246, 0.16); border: 1px solid rgba(96, 165, 250, 0.4); color: #bfdbfe; font-size: 0.75rem; text-decoration: none; }
        .recommended a:hover { text-decoration: underline; }
        .history-thumb { margin: 0.5rem 0; max-width: 220px; border-radius: 8px; overflow: hidden; border: 1px solid rgba(148, 163, 184, 0.2); }
        .history-thumb img { display: block; width: 100%; height: auto; object-fit: cover; background: rgba(148, 163, 184, 0.1); }
        .history-flags { display: flex; flex-wrap: wrap; gap: 0.45rem; margin: 0.25rem 0 0.5rem; }
        .type-pill { display: inline-flex; align-items: center; padding: 0.2rem 0.6rem; border-radius: 999px; border: 1px solid rgba(148, 163, 184, 0.35); background: rgba(148, 163, 184, 0.15); font-size: 0.7rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: rgba(226, 232, 240, 0.9); }
        .type-pill--article { background: rgba(34, 197, 94, 0.18); border-color: rgba(34, 197, 94, 0.45); color: #bbf7d0; }
        .type-pill--page { background: rgba(96, 165, 250, 0.18); border-color: rgba(96, 165, 250, 0.4); color: #bfdbfe; }
        .type-pill--non_article { background: rgba(248, 113, 113, 0.18); border-color: rgba(248, 113, 113, 0.4); color: #fecaca; }
        .type-pill--error { background: rgba(244, 114, 182, 0.2); border-color: rgba(244, 114, 182, 0.45); color: #fbcfe8; }
        .type-pill--revision { background: rgba(129, 140, 248, 0.18); border-color: rgba(129, 140, 248, 0.4); color: #c7d2fe; }
        .history-timing { margin: 0.5rem 0; font-size: 0.85rem; color: rgba(148, 163, 184, 0.9); }
        .history-change { margin: 0.35rem 0; font-size: 0.85rem; color: rgba(248, 250, 252, 0.88); font-weight: 500; }
        .history-versions { margin-top: 0.85rem; }
        .history-versions summary { cursor: pointer; font-size: 0.85rem; color: rgba(148, 163, 184, 0.9); }
        .history-versions ol { margin: 0.6rem 0 0; padding-left: 1.1rem; }
        .history-versions li { margin-bottom: 0.55rem; }
        .history-version-header { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.2rem; }
        .revision-badge { display: inline-flex; align-items: center; padding: 0.15rem 0.55rem; border-radius: 999px; background: rgba(129, 140, 248, 0.18); border: 1px solid rgba(129, 140, 248, 0.45); font-size: 0.7rem; letter-spacing: 0.05em; text-transform: uppercase; color: #c7d2fe; }
    </style>
</head>
<body>
<main>
    <header class="card">
        <h1>Hidden crawler control centre</h1>
        <p class="muted">Keep this tab open to let the crawler refresh insights in the background. Configure your targets, enable auto-refresh and monitor the extraction pipeline.</p>
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
            <form method="post" style="margin-top: 1rem;">
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
            <div class="form-controls">
                <div>
                    <label for="auto_interval">Auto-refresh interval (minutes)</label>
                    <input id="auto_interval" name="auto_interval" type="number" min="0" value="<?= esc((string) $autoInterval); ?>">
                </div>
                <div>
                    <label for="crawl_depth">Link depth</label>
                    <input id="crawl_depth" name="depth" type="number" min="0" max="6" value="<?= esc((string) $depth); ?>">
                </div>
                <label class="auto-start-toggle" for="auto_start">
                    <input id="auto_start" name="auto_start" type="checkbox" value="1" <?= $autoStart ? 'checked' : ''; ?>>
                    Auto-start the next run when scheduled
                </label>
                <button type="submit">Run crawl now</button>
            </div>
            <p class="muted" style="margin-top: 0.5rem;">Set the auto-refresh interval to keep the crawler running while this tab remains open. Increase the link depth to explore trusted links discovered on each page.</p>
        </form>
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
            <p id="progress-last-ingest"><?= !empty($lastResult['ingested']) ? 'Ingested into knowledge graph' : 'Held locally'; ?></p>
            <p id="progress-last-error" class="error-text"<?= $lastResult && !empty($lastResult['error']) ? '' : ' style="display:none;"'; ?>>
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
    </section>

    <section class="card">
        <h2>Recent crawl history</h2>
        <?php if ($history === []): ?>
            <p class="muted">No crawl results yet. Launch a crawl to populate this feed.</p>
        <?php else: ?>
            <?php foreach ($history as $item): ?>
                <article class="history-item">
                    <h3><a href="<?= esc((string) ($item['url'] ?? '#')); ?>" target="_blank" rel="noopener" style="color: #c4b5fd; text-decoration: none;">
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
                        <p class="errors" style="margin-top: 0.5rem;"><?= esc((string) $item['error']); ?></p>
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
    const lastResultEl = document.getElementById('progress-last-result');
    const lastTitleEl = document.getElementById('progress-last-title');
    const lastUrlEl = document.getElementById('progress-last-url');
    const lastQualityEl = document.getElementById('progress-last-quality');
    const lastIngestEl = document.getElementById('progress-last-ingest');
    const lastErrorEl = document.getElementById('progress-last-error');
    const errorsEl = document.getElementById('progress-errors');
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
                throw new Error('Unable to fetch progress');
            }
            const payload = await response.json();
            if (payload && typeof payload.progress === 'object') {
                updateView(payload.progress);
            }
        } catch (error) {
            if (!state.isRunning) {
                setMessage('Unable to refresh crawl status.', true);
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
