<?php

declare(strict_types=1);

require __DIR__ . '/../../src/App/bootstrap.php';

use App\Extraction\Extractor;

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

$text = '';
$documents = [];

if (isset($payload['text']) && is_string($payload['text'])) {
    $text = trim($payload['text']);
}

if (isset($payload['documents']) && is_array($payload['documents'])) {
    foreach ($payload['documents'] as $document) {
        if (!is_string($document)) {
            continue;
        }
        $documents[] = $document;
    }
}

if ($documents === [] && $text !== '') {
    $documents[] = $text;
}

if ($documents === []) {
    http_response_code(422);
    echo json_encode(['error' => 'No documents supplied. Provide "text" or "documents".'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return;
}

$include = null;
if (isset($payload['include'])) {
    if (is_string($payload['include'])) {
        $include = [$payload['include']];
    } elseif (is_array($payload['include'])) {
        $include = [];
        foreach ($payload['include'] as $item) {
            if (is_string($item)) {
                $include[] = $item;
            }
        }
    }
}

$state = null;
if (isset($payload['state']) && is_array($payload['state'])) {
    $state = $payload['state'];
}

try {
    $extractor = new Extractor();
    $result = $extractor->analyseMany($documents, $state);
} catch (\Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to analyse documents.',
        'details' => $exception->getMessage(),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return;
}

$data = $result->toArray();

if (is_array($include) && $include !== []) {
    $allowedKeys = ['triples', 'synonyms', 'relations', 'entities', 'summary', 'state'];
    $filtered = [];
    foreach ($include as $key) {
        if (!in_array($key, $allowedKeys, true)) {
            continue;
        }
        if (array_key_exists($key, $data)) {
            $filtered[$key] = $data[$key];
        }
    }
    if ($filtered !== []) {
        $data = $filtered;
    }
}

echo json_encode([
    'data' => $data,
    'meta' => [
        'documents' => count($documents),
        'documents_processed' => $result->summary()['documents_processed'] ?? null,
        'processing_time_ms' => (int) round((microtime(true) - $startTime) * 1000),
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
