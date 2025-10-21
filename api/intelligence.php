<?php

declare(strict_types=1);

require __DIR__ . '/../src/App/bootstrap.php';
require_once __DIR__ . '/../src/App/Crawler/HiddenCrawler.php';
require_once __DIR__ . '/../src/App/KnowledgeGraph/GraphRepository.php';
require_once __DIR__ . '/../src/App/KnowledgeGraph/GraphResearcher.php';
require_once __DIR__ . '/../src/App/News/NewsSearchService.php';
require_once __DIR__ . '/../src/App/Text/TextRefiner.php';
require_once __DIR__ . '/../src/App/Intelligence/FeatureExtractor.php';
require_once __DIR__ . '/../src/App/Intelligence/LogisticModel.php';
require_once __DIR__ . '/../src/App/Intelligence/InsightEngine.php';

use App\Intelligence\InsightEngine;

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    $engine = new InsightEngine();

    if ($method === 'POST') {
        $input = file_get_contents('php://input');
        $payload = is_string($input) && $input !== '' ? json_decode($input, true) : [];
        if (!is_array($payload)) {
            $payload = [];
        }

        $action = isset($payload['action']) ? (string) $payload['action'] : '';
        if ($action === '' && isset($_POST['action'])) {
            $action = (string) $_POST['action'];
        }

        if ($action === 'train') {
            $examples = isset($payload['examples']) && is_array($payload['examples']) ? $payload['examples'] : [];
            $iterations = isset($payload['iterations']) ? (int) $payload['iterations'] : 120;
            $learningRate = isset($payload['learning_rate']) ? (float) $payload['learning_rate'] : 0.15;

            $result = $engine->train($examples, $iterations, $learningRate);

            echo json_encode([
                'status' => 'ok',
                'updated' => $result['updated'] ?? 0,
                'model_version' => $result['model_version'] ?? null,
                'weights' => $result['weights'] ?? null,
                'bias' => $result['bias'] ?? null,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

            return;
        }

        if ($action === 'overview') {
            $queries = isset($payload['queries']) && is_array($payload['queries']) ? $payload['queries'] : [];
            $limit = isset($payload['limit']) ? (int) $payload['limit'] : 3;

            $overview = $engine->overview($queries, $limit);

            echo json_encode($overview, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

            return;
        }

        http_response_code(400);
        echo json_encode([
            'error' => 'Unsupported action.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        return;
    }

    $query = isset($_GET['q']) ? (string) $_GET['q'] : '';
    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 12;

    $payload = $engine->generate($query, [
        'limit' => $limit,
    ]);

    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
} catch (\Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to orchestrate intelligence: ' . $exception->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}
