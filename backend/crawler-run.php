<?php

declare(strict_types=1);

session_start();

require __DIR__ . '/../src/App/bootstrap.php';
require __DIR__ . '/../src/Ricktorious/Ecommerce/bootstrap.php';
require_once __DIR__ . '/../src/App/Crawler/HiddenCrawler.php';

use App\Crawler\HiddenCrawler;
use App\KnowledgeGraph\ResearchService;

ignore_user_abort(true);
set_time_limit(0);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Unsupported method.',
    ]);
    return;
}

if (!isset($_SESSION['backend_user'])) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => 'Authentication required.',
    ]);
    return;
}

$crawlerStorage = __DIR__ . '/../storage/backend/crawler-history.json';
$crawler = new HiddenCrawler($crawlerStorage, null, null, new ResearchService());

$payload = $_POST;
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$rawInput = file_get_contents('php://input');
if (is_string($rawInput) && trim($rawInput) !== '' && stripos($contentType, 'application/json') !== false) {
    $decoded = json_decode($rawInput, true);
    if (is_array($decoded)) {
        $payload = $decoded;
    }
}

$action = strtolower((string) ($payload['action'] ?? 'crawl'));

$progress = null;
try {
    $progress = $crawler->progress();
} catch (Throwable $exception) {
    $progress = null;
}
$progressData = is_array($progress) ? $progress : [];
$progressOptions = is_array($progressData['options'] ?? null) ? $progressData['options'] : [];

$autoInterval = array_key_exists('auto_interval', $payload)
    ? (int) $payload['auto_interval']
    : (int) ($_SESSION['backend_auto_interval'] ?? ($progressOptions['auto_interval'] ?? ($progressData['auto_interval'] ?? 0)));
$autoInterval = max(0, $autoInterval);

if (array_key_exists('auto_start', $payload)) {
    $autoStart = in_array(strtolower((string) $payload['auto_start']), ['1', 'true', 'yes', 'on'], true);
} elseif (isset($_SESSION['backend_auto_start'])) {
    $autoStart = (bool) $_SESSION['backend_auto_start'];
} else {
    $autoStart = (bool) ($progressOptions['auto_start'] ?? ($progressData['auto_start'] ?? false));
}

$refreshAfter = array_key_exists('refresh_after', $payload)
    ? (int) $payload['refresh_after']
    : (int) ($_SESSION['backend_refresh_after'] ?? ($progressOptions['refresh_after'] ?? ($progressData['refresh_after'] ?? 0)));
$refreshAfter = max(0, $refreshAfter);

if ($action === 'queue') {
    $urlsRaw = $payload['urls'] ?? '';
    $targetsRaw = $payload['targets'] ?? null;
    $targets = [];

    if (is_array($targetsRaw)) {
        foreach ($targetsRaw as $target) {
            if (!is_string($target)) {
                continue;
            }
            $candidate = trim($target);
            if ($candidate === '') {
                continue;
            }
            $targets[] = $candidate;
        }
    } elseif (is_string($urlsRaw)) {
        $lines = preg_split('/\r?\n/', $urlsRaw) ?: [];
        foreach ($lines as $line) {
            $candidate = trim($line);
            if ($candidate === '') {
                continue;
            }
            $targets[] = $candidate;
        }
    }

    if ($targets === [] && is_array($progressData['seed_urls'] ?? null)) {
        foreach ($progressData['seed_urls'] as $seed) {
            if (!is_string($seed)) {
                continue;
            }

            $candidate = trim($seed);
            if ($candidate === '') {
                continue;
            }

            $targets[] = $candidate;
        }
    }

    if ($targets === []) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'error' => 'Provide at least one URL to crawl.',
        ]);
        return;
    }

    $depth = array_key_exists('depth', $payload)
        ? (int) $payload['depth']
        : (int) ($_SESSION['backend_depth'] ?? ($progressOptions['depth'] ?? 0));
    $depth = max(0, $depth);

    $_SESSION['backend_urls'] = implode("\n", $targets);
    $_SESSION['backend_depth'] = $depth;
    $_SESSION['backend_auto_interval'] = $autoInterval;
    $_SESSION['backend_auto_start'] = $autoStart;
    $_SESSION['backend_refresh_after'] = $refreshAfter;

    try {
        $summary = $crawler->queueManualRun($targets, $depth, $autoInterval, $autoStart, $refreshAfter);
        $scheduled = (int) ($summary['scheduled'] ?? 0);
        $pendingRun = is_array($summary['pending_run'] ?? null) ? $summary['pending_run'] : null;
        $message = $scheduled > 0
            ? 'Queued ' . $scheduled . ' page(s) for crawling.'
            : 'No valid targets were queued.';

        $responsePayload = [
            'success' => true,
            'queued' => $scheduled,
            'message' => $message,
            'scheduled_total' => (int) ($summary['scheduled_total'] ?? 0),
        ];

        if ($pendingRun !== null) {
            $responsePayload['pending_run'] = $pendingRun;
        }

        $json = json_encode($responsePayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new RuntimeException('Failed to encode queue response.');
        }

        echo $json;
    } catch (Throwable $exception) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Unable to queue crawl: ' . $exception->getMessage(),
        ]);
    }

    return;
}

if ($action === 'run-scheduled') {
    $scheduledLimit = array_key_exists('scheduled_limit', $payload)
        ? (int) $payload['scheduled_limit']
        : (int) ($_SESSION['backend_scheduled_limit'] ?? 5);
    if ($scheduledLimit <= 0) {
        $scheduledLimit = 5;
    }

    $scheduledDepth = array_key_exists('scheduled_depth', $payload)
        ? (int) $payload['scheduled_depth']
        : (int) ($_SESSION['backend_scheduled_depth'] ?? ($progressOptions['depth'] ?? 1));
    if ($scheduledDepth < 0) {
        $scheduledDepth = 0;
    }

    $_SESSION['backend_scheduled_limit'] = $scheduledLimit;
    $_SESSION['backend_scheduled_depth'] = $scheduledDepth;
    $_SESSION['backend_auto_interval'] = $autoInterval;
    $_SESSION['backend_auto_start'] = $autoStart;
    $_SESSION['backend_refresh_after'] = $refreshAfter;

    try {
        $run = $crawler->runScheduledQueue($scheduledLimit, $scheduledDepth, $autoInterval, $autoStart, $refreshAfter);
        $results = is_array($run['results'] ?? null) ? $run['results'] : [];
        $processedCount = 0;
        $failedCount = 0;
        $failedDetails = [];

        foreach ($results as $result) {
            if (!is_array($result)) {
                continue;
            }

            $errorMessage = (string) ($result['error'] ?? '');
            if ($errorMessage !== '') {
                $failedCount++;
                $failedDetails[] = [
                    'url' => (string) ($result['url'] ?? ''),
                    'error' => $errorMessage,
                ];
                continue;
            }

            $processedCount++;
        }

        $targetsRun = is_array($run['targets'] ?? null) ? $run['targets'] : [];
        $remaining = (int) ($run['scheduled_remaining'] ?? 0);

        if ($processedCount === 0 && $targetsRun === []) {
            $message = 'No scheduled pages were available to process.';
        } else {
            $messageParts = [];
            if ($processedCount > 0) {
                $messageParts[] = 'Processed ' . $processedCount . ' scheduled page(s).';
            }
            if ($failedCount > 0) {
                $messageParts[] = $failedCount . ' scheduled page(s) failed.';
            }
            if ($remaining > 0) {
                $messageParts[] = $remaining . ' page(s) remain in the backlog.';
            } elseif ($processedCount > 0 && $remaining === 0) {
                $messageParts[] = 'Scheduled backlog is now empty.';
            }

            if ($messageParts === []) {
                $messageParts[] = 'Scheduled crawl finished.';
            }

            $message = implode(' ', $messageParts);
        }

        $currentProgress = $crawler->progress();
        $context = [
            'scheduled_total' => (int) ($run['scheduled_total'] ?? $remaining),
            'scheduled_preview' => is_array($run['scheduled_preview'] ?? null) ? $run['scheduled_preview'] : [],
        ];

        if (is_array($currentProgress['last_result'] ?? null)) {
            $context['last_result'] = $currentProgress['last_result'];
        }

        if (is_array($currentProgress['errors'] ?? null)) {
            $context['errors'] = $currentProgress['errors'];
        }

        if (is_array($currentProgress['tasks'] ?? null)) {
            $context['tasks'] = $currentProgress['tasks'];
        }

        if (is_array($currentProgress['task_totals'] ?? null)) {
            $context['task_totals'] = $currentProgress['task_totals'];
        }

        $pendingRun = $crawler->updatePendingRunProgress(
            $remaining,
            [
                'depth' => $scheduledDepth,
                'auto_interval' => $autoInterval,
                'auto_start' => $autoStart,
                'refresh_after' => $refreshAfter,
            ],
            $context
        );

        $responsePayload = [
            'success' => $failedCount === 0,
            'count' => $processedCount,
            'failed' => $failedCount,
            'message' => $message,
            'scheduled_remaining' => $remaining,
            'targets' => $targetsRun,
        ];

        if ($pendingRun !== null) {
            $responsePayload['pending_run'] = $pendingRun;
        }

        if ($failedDetails !== []) {
            $responsePayload['errors'] = $failedDetails;
        }

        $json = json_encode($responsePayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new RuntimeException('Failed to encode scheduled crawler response.');
        }

        echo $json;
    } catch (Throwable $exception) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Scheduled crawl failed: ' . $exception->getMessage(),
        ]);
    }

    return;
}

$urlsRaw = $payload['urls'] ?? '';
$targetsRaw = $payload['targets'] ?? null;
$targets = [];

if (is_array($targetsRaw)) {
    foreach ($targetsRaw as $target) {
        if (!is_string($target)) {
            continue;
        }
        $candidate = trim($target);
        if ($candidate === '') {
            continue;
        }
        $targets[] = $candidate;
    }
} elseif (is_string($urlsRaw)) {
    $lines = preg_split('/\r?\n/', $urlsRaw) ?: [];
    foreach ($lines as $line) {
        $candidate = trim($line);
        if ($candidate === '') {
            continue;
        }
        $targets[] = $candidate;
    }
}

if ($targets === [] && is_array($progressData['seed_urls'] ?? null)) {
    foreach ($progressData['seed_urls'] as $seed) {
        if (!is_string($seed)) {
            continue;
        }

        $candidate = trim($seed);
        if ($candidate === '') {
            continue;
        }

        $targets[] = $candidate;
    }
}

if ($targets === []) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'error' => 'Provide at least one URL to crawl.',
    ]);
    return;
}

$depth = array_key_exists('depth', $payload)
    ? (int) $payload['depth']
    : (int) ($_SESSION['backend_depth'] ?? ($progressOptions['depth'] ?? 0));
$depth = max(0, $depth);

$_SESSION['backend_urls'] = implode("\n", $targets);
$_SESSION['backend_depth'] = $depth;
$_SESSION['backend_auto_interval'] = $autoInterval;
$_SESSION['backend_auto_start'] = $autoStart;
$_SESSION['backend_refresh_after'] = $refreshAfter;

try {
    $results = $crawler->crawl($targets, $depth, $autoInterval, $autoStart, $refreshAfter);
    $processedCount = 0;
    $failedCount = 0;
    $failedDetails = [];

    foreach ($results as $result) {
        if (!is_array($result)) {
            continue;
        }

        $errorMessage = (string) ($result['error'] ?? '');
        if ($errorMessage !== '') {
            $failedCount++;
            $failedDetails[] = [
                'url' => (string) ($result['url'] ?? ''),
                'error' => $errorMessage,
            ];
            continue;
        }

        $processedCount++;
    }

    $message = 'Crawler processed ' . $processedCount . ' page(s).';
    if ($failedCount > 0) {
        $message .= ' ' . $failedCount . ' page(s) failed.';
    }

    $responsePayload = [
        'success' => $failedCount === 0,
        'count' => $processedCount,
        'failed' => $failedCount,
        'message' => $message,
    ];

    if ($failedDetails !== []) {
        $responsePayload['errors'] = $failedDetails;
    }

    $json = json_encode($responsePayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) {
        throw new RuntimeException('Failed to encode crawler response.');
    }
    echo $json;
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Crawler failed: ' . $exception->getMessage(),
    ]);
}
