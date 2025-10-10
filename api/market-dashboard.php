<?php

declare(strict_types=1);

require __DIR__ . '/../src/App/bootstrap.php';
require __DIR__ . '/../src/Ricktorious/Markets/bootstrap.php';

use App\Markets\MarketIntelligenceBuilder;
use Ricktorious\Markets\Realtime\FileCache;
use Ricktorious\Markets\Realtime\HttpJsonClient;
use Ricktorious\Markets\Realtime\RealtimeMarketClient;

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');

$symbolsParam = (string) ($_GET['symbols'] ?? '');
$symbols = $symbolsParam !== '' ? array_filter(array_map('trim', explode(',', $symbolsParam))) : null;

try {
    $cache = new FileCache(__DIR__ . '/../storage/cache/market');
    $http = new HttpJsonClient('SignalLedger/1.0 (+https://signal-ledger.local)');
    $client = new RealtimeMarketClient($http, $cache, getenv('ALPHAVANTAGE_KEY') ?: null);

    $historyPath = __DIR__ . '/../storage/backend/crawler-history.json';
    $builder = new MarketIntelligenceBuilder($client, $historyPath);
    $payload = $builder->dashboard($symbols);

    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Unable to build market dashboard.',
        'details' => $error->getMessage(),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
