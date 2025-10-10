<?php

declare(strict_types=1);

require __DIR__ . '/../src/Ricktorious/Markets/bootstrap.php';

use Ricktorious\Markets\Realtime\FileCache;
use Ricktorious\Markets\Realtime\HttpJsonClient;
use Ricktorious\Markets\Realtime\RealtimeMarketClient;

header('Content-Type: application/json');
header('Cache-Control: max-age=300, public');

$query = (string) ($_GET['q'] ?? $_GET['query'] ?? '');
$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 10;

try {
    $cache = new FileCache(__DIR__ . '/../storage/cache/market');
    $http = new HttpJsonClient('SignalLedger/1.0 (+https://signal-ledger.local)');
    $client = new RealtimeMarketClient($http, $cache, getenv('ALPHAVANTAGE_KEY') ?: null);

    $results = $client->search($query, $limit);

    echo json_encode([
        'query' => $query,
        'results' => $results,
        'generated_at' => date(DATE_ATOM),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Unable to search companies.',
        'details' => $error->getMessage(),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
