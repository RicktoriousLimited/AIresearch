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
                <button type="submit">Run crawl now</button>
            </div>
            <p class="muted" style="margin-top: 0.5rem;">Set the auto-refresh interval to keep the crawler running while this tab remains open.</p>
        </form>
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

<script>
(function () {
    const form = document.getElementById('crawler-form');
    if (!form) {
        return;
    }

    const intervalField = form.querySelector('[name="auto_interval"]');
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
