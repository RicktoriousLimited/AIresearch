<?php

declare(strict_types=1);

require __DIR__ . '/../src/Ricktorious/Markets/bootstrap.php';

use Ricktorious\Markets\Realtime\FileCache;
use Ricktorious\Markets\Realtime\HttpJsonClient;
use Ricktorious\Markets\Realtime\RealtimeMarketClient;

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');

$symbol = (string) ($_GET['symbol'] ?? $_GET['ticker'] ?? $_GET['q'] ?? '');
$symbol = strtoupper(trim($symbol));

if ($symbol === '') {
    http_response_code(400);
    echo json_encode([
        'error' => 'Missing symbol parameter.',
    ]);
    return;
}

try {
    $cache = new FileCache(__DIR__ . '/../storage/cache/market');
    $http = new HttpJsonClient('SignalLedger/1.0 (+https://signal-ledger.local)');
    $client = new RealtimeMarketClient($http, $cache, getenv('ALPHAVANTAGE_KEY') ?: null);

    $payload = $client->companyInsights($symbol);
    $payload['requested_at'] = date(DATE_ATOM);

    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Unable to load company insights.',
        'details' => $error->getMessage(),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
