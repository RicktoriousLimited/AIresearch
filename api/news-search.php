<?php

declare(strict_types=1);

require __DIR__ . '/../src/App/bootstrap.php';
require_once __DIR__ . '/../src/App/Crawler/HiddenCrawler.php';
require_once __DIR__ . '/../src/App/News/NewsSearchService.php';
require_once __DIR__ . '/../src/App/KnowledgeGraph/GraphRepository.php';

use App\Crawler\HiddenCrawler;
use App\News\NewsSearchService;
use App\KnowledgeGraph\GraphRepository;

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    $storage = __DIR__ . '/../storage/backend/crawler-history.json';
    $crawler = new HiddenCrawler($storage);
    $service = new NewsSearchService($crawler, new GraphRepository());

    if ($method === 'POST') {
        $input = file_get_contents('php://input');
        $decoded = is_string($input) && $input !== '' ? json_decode($input, true) : [];
        if (!is_array($decoded)) {
            $decoded = [];
        }

        $action = isset($decoded['action']) ? (string) $decoded['action'] : '';
        if ($action === '' && isset($_POST['action'])) {
            $action = (string) $_POST['action'];
        }

        if ($action !== 'continue') {
            http_response_code(400);
            echo json_encode([
                'error' => 'Unsupported action.',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

            return;
        }

        $limit = isset($decoded['limit']) ? (int) $decoded['limit'] : (isset($_POST['limit']) ? (int) $_POST['limit'] : 5);
        $maxDepth = isset($decoded['depth']) ? (int) $decoded['depth'] : (isset($_POST['depth']) ? (int) $_POST['depth'] : 1);

        $result = $crawler->continueDiscovery($limit, $maxDepth);

        echo json_encode([
            'status' => 'ok',
            'processed' => (int) ($result['processed'] ?? 0),
            'targets' => isset($result['targets']) && is_array($result['targets']) ? $result['targets'] : [],
            'errors' => isset($result['errors']) && is_array($result['errors']) ? $result['errors'] : [],
            'discovery' => isset($result['discovery']) && is_array($result['discovery']) ? $result['discovery'] : $crawler->discoveryTree(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        return;
    }

    $query = isset($_GET['q']) ? (string) $_GET['q'] : '';
    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 24;

    $payload = $service->search($query, [
        'limit' => $limit,
    ]);

    if (!isset($payload['discovery']) || !is_array($payload['discovery'])) {
        $payload['discovery'] = $crawler->discoveryTree();
    }

    if (!isset($payload['meta']) || !is_array($payload['meta'])) {
        $payload['meta'] = [];
    }

    $payload['meta']['discovery'] = $payload['discovery'];

    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
} catch (\Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'error' => 'News search failed: ' . $exception->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}
