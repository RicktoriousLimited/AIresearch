<?php

declare(strict_types=1);

session_start();

require __DIR__ . '/../src/App/bootstrap.php';
require __DIR__ . '/../src/Ricktorious/Ecommerce/bootstrap.php';
require_once __DIR__ . '/../src/App/Crawler/HiddenCrawler.php';

use App\Crawler\HiddenCrawler;
use Ricktorious\Ecommerce\User\OneTimePasswordManager;
use Ricktorious\Ecommerce\User\UserService;
use Throwable;

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
$crawler = new HiddenCrawler($crawlerStorage);

$messages = [];
$errors = [];
$generatedOtp = null;
$autoInterval = max(0, (int) ($_POST['auto_interval'] ?? ($_SESSION['backend_auto_interval'] ?? 0)));
$_SESSION['backend_auto_interval'] = $autoInterval;

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
                $results = $crawler->crawl($targets);
                $messages[] = 'Crawler fetched ' . count($results) . ' page(s).';
            } catch (Throwable $exception) {
                $errors[] = 'Crawler failed: ' . $exception->getMessage();
            }
            break;
    }
}

$sessionUser = $_SESSION['backend_user'] ?? null;
$history = $crawler->history();
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
        .history-grid { display: grid; gap: 1rem; }
        .muted { color: rgba(148, 163, 184, 0.9); }
        .keywords span { display: inline-flex; margin: 0.2rem 0.4rem 0.2rem 0; padding: 0.25rem 0.5rem; border-radius: 999px; background: rgba(99, 102, 241, 0.2); }
        .entities span { display: inline-flex; margin: 0.2rem 0.4rem 0.2rem 0; padding: 0.25rem 0.5rem; border-radius: 999px; background: rgba(34, 197, 94, 0.15); }
        .otp-code { font-family: 'JetBrains Mono', monospace; font-size: 1.4rem; letter-spacing: 0.2rem; background: rgba(15, 23, 42, 0.7); padding: 0.5rem 1rem; border-radius: 8px; display: inline-block; margin-top: 0.5rem; }
        .logout { background: rgba(248, 113, 113, 0.2); border: 1px solid rgba(248, 113, 113, 0.4); color: #fecaca; }
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
                <form method="post" class="card" style="background: transparent; border: 1px dashed rgba(148, 163, 184, 0.3);">
                    <input type="hidden" name="action" value="login">
                    <label for="login-email">Email address</label>
                    <input id="login-email" name="email" type="email" required value="<?= esc((string) ($_POST['email'] ?? 'waheed.rahman@ricktorious.com')); ?>">
                    <label for="login-password">Password</label>
                    <input id="login-password" name="password" type="password" required>
                    <button type="submit">Sign in</button>
                </form>
                <form method="post" class="card" style="background: transparent; border: 1px dashed rgba(148, 163, 184, 0.3);">
                    <input type="hidden" name="action" value="request-otp">
                    <label for="otp-email">Request one-time password</label>
                    <input id="otp-email" name="email" type="email" required value="<?= esc((string) ($_POST['email'] ?? 'waheed.rahman@ricktorious.com')); ?>">
                    <button type="submit">Send OTP</button>
                </form>
                <form method="post" class="card" style="background: transparent; border: 1px dashed rgba(148, 163, 184, 0.3);">
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
            <div style=\"display: flex; gap: 1rem; align-items: center; margin-top: 1rem; flex-wrap: wrap;\">
                <div>
                    <label for=\"auto_interval\">Auto-refresh interval (minutes)</label>
                    <input id=\"auto_interval\" name=\"auto_interval\" type=\"number\" min=\"0\" value=\"<?= esc((string) $autoInterval); ?>\">
                </div>
                <button type=\"submit\">Run crawl now</button>
            </div>
            <p class=\"muted\" style=\"margin-top: 0.5rem;\">Set the auto-refresh interval to keep the crawler running while this tab remains open.</p>
        </form>
    </section>

    <section class=\"card\">
        <h2>Recent crawl history</h2>
        <?php if ($history === []): ?>
            <p class=\"muted\">No crawl results yet. Launch a crawl to populate this feed.</p>
        <?php else: ?>
            <?php foreach ($history as $item): ?>
                <article class=\"history-item\">
                    <h3><a href=\"<?= esc((string) ($item['url'] ?? '#')); ?>\" target=\"_blank\" rel=\"noopener\" style=\"color: #c4b5fd; text-decoration: none;\">
                        <?= esc((string) ($item['title'] ?? $item['url'] ?? 'Untitled page')); ?>
                    </a></h3>
                    <p class=\"muted\">Fetched <?= esc((string) ($item['fetched_at'] ?? 'unknown')); ?></p>
                    <?php if (isset($item['error'])): ?>
                        <p class=\"errors\" style=\"margin-top: 0.5rem;\"><?= esc((string) $item['error']); ?></p>
                    <?php else: ?>
                        <p><?= esc((string) ($item['preview'] ?? '')); ?></p>
                        <?php if (!empty($item['keywords'])): ?>
                            <div class=\"keywords\">
                                <?php foreach ($item['keywords'] as $keyword): ?>
                                    <span><?= esc((string) ($keyword['token'] ?? '')); ?> · <?= esc((string) ($keyword['count'] ?? '0')); ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($item['entities'])): ?>
                            <div class=\"entities\">
                                <?php foreach ($item['entities'] as $entity): ?>
                                    <span><?= esc((string) ($entity['label'] ?? '')); ?><?= isset($entity['type']) && $entity['type'] !== '' ? ' · ' . esc((string) $entity['type']) : ''; ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>
</main>

<script>
(function () {
    const form = document.getElementById('crawler-form');
    if (!form) {
        return;
    }

    const intervalField = form.querySelector('[name=\"auto_interval\"]');
    const interval = intervalField ? parseInt(intervalField.value, 10) : 0;
    if (!interval || Number.isNaN(interval) || interval <= 0) {
        return;
    }

    setTimeout(function trigger() {
        form.submit();
    }, interval * 60 * 1000);
})();
</script>
</body>
</html>
