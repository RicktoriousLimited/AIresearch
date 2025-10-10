<?php

declare(strict_types=1);

session_start();

require __DIR__ . '/../src/App/bootstrap.php';
require __DIR__ . '/../src/Ricktorious/Ecommerce/bootstrap.php';
require_once __DIR__ . '/../src/App/Crawler/HiddenCrawler.php';

use App\Crawler\HiddenCrawler;
use App\KnowledgeGraph\ResearchService;

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

if ($targets === []) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'error' => 'Provide at least one URL to crawl.',
    ]);
    return;
}

$depth = isset($payload['depth']) ? (int) $payload['depth'] : (int) ($_SESSION['backend_depth'] ?? 0);
$depth = max(0, $depth);
$autoInterval = isset($payload['auto_interval']) ? (int) $payload['auto_interval'] : (int) ($_SESSION['backend_auto_interval'] ?? 0);
$autoInterval = max(0, $autoInterval);
$autoStart = isset($payload['auto_start'])
    ? in_array(strtolower((string) $payload['auto_start']), ['1', 'true', 'yes', 'on'], true)
    : (bool) ($_SESSION['backend_auto_start'] ?? false);

$_SESSION['backend_urls'] = implode("\n", $targets);
$_SESSION['backend_depth'] = $depth;
$_SESSION['backend_auto_interval'] = $autoInterval;
$_SESSION['backend_auto_start'] = $autoStart;

$crawlerStorage = __DIR__ . '/../storage/backend/crawler-history.json';
$crawler = new HiddenCrawler($crawlerStorage, null, null, new ResearchService());

try {
    $results = $crawler->crawl($targets, $depth, $autoInterval, $autoStart);
    $json = json_encode([
        'success' => true,
        'count' => count($results),
        'message' => 'Crawler processed ' . count($results) . ' page(s).',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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
