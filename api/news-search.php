<?php

declare(strict_types=1);

require __DIR__ . '/../src/App/bootstrap.php';
require_once __DIR__ . '/../src/App/Crawler/HiddenCrawler.php';
require_once __DIR__ . '/../src/App/News/NewsSearchService.php';

use App\Crawler\HiddenCrawler;
use App\News\NewsSearchService;

header('Content-Type: application/json; charset=utf-8');

try {
    $storage = __DIR__ . '/../storage/backend/crawler-history.json';
    $crawler = new HiddenCrawler($storage);
    $service = new NewsSearchService($crawler);

    $query = isset($_GET['q']) ? (string) $_GET['q'] : '';
    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 24;
    $minQuality = isset($_GET['min_quality']) ? (float) $_GET['min_quality'] : 60.0;

    $payload = $service->search($query, [
        'limit' => $limit,
        'min_quality' => $minQuality,
    ]);

    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
} catch (\Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'error' => 'News search failed: ' . $exception->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}
