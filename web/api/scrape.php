<?php

declare(strict_types=1);

require __DIR__ . '/../../src/App/bootstrap.php';

use App\KnowledgeGraph\ResearchService;

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

$service = new ResearchService();

try {
    $ingestion = $service->ingestFromUrl($url);
} catch (\Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Unable to ingest source.',
        'details' => $exception->getMessage(),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return;
}

$sourceRecord = sanitiseSource($ingestion['source']);
$sources = array_map('sanitiseSource', $ingestion['sources']);

echo json_encode([
    'data' => [
        'source' => $sourceRecord,
        'graph' => $ingestion['graph'],
        'sources' => $sources,
    ],
    'meta' => [
        'processing_time_ms' => (int) round((microtime(true) - $startTime) * 1000),
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

/**
 * @param array<string, mixed> $source
 * @return array<string, mixed>
 */
function sanitiseSource(array $source): array
{
    unset($source['content']);
    return $source;
}
