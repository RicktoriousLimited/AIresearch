<?php

declare(strict_types=1);

require __DIR__ . '/../../src/App/bootstrap.php';

use App\Extraction\Extractor;
use App\KnowledgeGraph\GraphRepository;
use App\Scraping\WebScraper;

$startTime = microtime(true);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Use POST.'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return;
}

$raw = file_get_contents('php://input');
$payload = null;

if (is_string($raw) && trim($raw) !== '') {
    $decoded = json_decode($raw, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        $payload = $decoded;
    }
}

if ($payload === null) {
    $payload = $_POST;
}

if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request payload.'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return;
}

$url = '';
if (isset($payload['url']) && is_string($payload['url'])) {
    $url = trim($payload['url']);
}

if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
    http_response_code(422);
    echo json_encode(['error' => 'Supply a valid URL to scrape.'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return;
}

try {
    $scraper = new WebScraper();
    $scrapeResult = $scraper->scrape($url);
} catch (\Throwable $exception) {
    http_response_code(502);
    echo json_encode([
        'error' => 'Failed to fetch the requested URL.',
        'details' => $exception->getMessage(),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return;
}

$repository = new GraphRepository();
$current = $repository->load();
$state = null;

if (isset($current['graph']['state']) && is_array($current['graph']['state'])) {
    $state = $current['graph']['state'];
}

try {
    $extractor = new Extractor();
    $result = $extractor->analyse($scrapeResult->text(), $state);
} catch (\Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to analyse the scraped document.',
        'details' => $exception->getMessage(),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return;
}

$sources = $current['sources'] ?? [];
$sourceRecord = array_merge(
    $scrapeResult->toMetaArray(),
    [
        'fetched_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
    ]
);

$sources = $repository->upsertSource($sources, $sourceRecord);

try {
    $repository->save($result, $sources);
} catch (\Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Unable to persist knowledge graph.',
        'details' => $exception->getMessage(),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return;
}

echo json_encode([
    'data' => [
        'source' => $sourceRecord,
        'graph' => $result->toArray(),
        'sources' => $sources,
    ],
    'meta' => [
        'processing_time_ms' => (int) round((microtime(true) - $startTime) * 1000),
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
