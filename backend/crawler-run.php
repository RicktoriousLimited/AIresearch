<?php

declare(strict_types=1);

session_start();

require __DIR__ . '/../src/App/bootstrap.php';
require __DIR__ . '/../src/Ricktorious/Ecommerce/bootstrap.php';
require_once __DIR__ . '/../src/App/Crawler/HiddenCrawler.php';

use App\Crawler\HiddenCrawler;
use App\KnowledgeGraph\ResearchService;
use Throwable;

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

$progress = null;

if ($targets === []) {
    try {
        $progress = $crawler->progress();
    } catch (Throwable $exception) {
        $progress = null;
    }

    if (is_array($progress['seed_urls'] ?? null)) {
        foreach ($progress['seed_urls'] as $seed) {
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
}

if ($targets === []) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'error' => 'Provide at least one URL to crawl.',
    ]);
    return;
}

if ($progress === null) {
    try {
        $progress = $crawler->progress();
    } catch (Throwable $exception) {
        $progress = null;
    }
}

$progressOptions = is_array($progress['options'] ?? null) ? $progress['options'] : [];

$depth = array_key_exists('depth', $payload)
    ? (int) $payload['depth']
    : (int) ($_SESSION['backend_depth'] ?? ($progressOptions['depth'] ?? 0));
$depth = max(0, $depth);

$autoInterval = array_key_exists('auto_interval', $payload)
    ? (int) $payload['auto_interval']
    : (int) ($_SESSION['backend_auto_interval'] ?? ($progressOptions['auto_interval'] ?? $progress['auto_interval'] ?? 0));
$autoInterval = max(0, $autoInterval);

if (array_key_exists('auto_start', $payload)) {
    $autoStart = in_array(strtolower((string) $payload['auto_start']), ['1', 'true', 'yes', 'on'], true);
} elseif (isset($_SESSION['backend_auto_start'])) {
    $autoStart = (bool) $_SESSION['backend_auto_start'];
} else {
    $autoStart = (bool) ($progressOptions['auto_start'] ?? $progress['auto_start'] ?? false);
}

$refreshAfter = array_key_exists('refresh_after', $payload)
    ? (int) $payload['refresh_after']
    : (int) ($_SESSION['backend_refresh_after'] ?? ($progressOptions['refresh_after'] ?? $progress['refresh_after'] ?? 0));
$refreshAfter = max(0, $refreshAfter);

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

    $payload = [
        'success' => $failedCount === 0,
        'count' => $processedCount,
        'failed' => $failedCount,
        'message' => $message,
    ];

    if ($failedDetails !== []) {
        $payload['errors'] = $failedDetails;
    }

    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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
