<?php

declare(strict_types=1);

require __DIR__ . '/../src/App/bootstrap.php';

use App\KnowledgeGraph\AutoCrawler;
use App\KnowledgeGraph\GraphResearcher;
use App\KnowledgeGraph\GraphRepository;
use App\KnowledgeGraph\ResearchService;
use App\Support\InputNormaliser;

$startTime = microtime(true);

header('Content-Type: application/json');

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = '';

if ($method === 'GET') {
    $action = isset($_GET['action']) && is_string($_GET['action']) ? strtolower($_GET['action']) : 'list';
} elseif ($method === 'POST') {
    $input = file_get_contents('php://input');
    $decoded = null;
    if (is_string($input) && trim($input) !== '') {
        $decoded = json_decode($input, true);
    }
    if (!is_array($decoded)) {
        $decoded = $_POST;
    }

    if (isset($decoded['action']) && is_string($decoded['action'])) {
        $action = strtolower($decoded['action']);
    }
}

$repository = new GraphRepository();
$service = new ResearchService($repository);
$researcher = new GraphResearcher($repository);

switch ($method) {
    case 'GET':
        switch ($action) {
            case 'list':
                $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 10;
                echo json_encode([
                    'data' => [
                        'entities' => $service->listTopEntities(max(1, $limit)),
                        'sources' => sanitiseSources($service->sources()),
                    ],
                    'meta' => ['processing_time_ms' => runtime($startTime)],
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                return;
            case 'compare':
                $limit = isset($_GET['limit']) ? max(1, (int) $_GET['limit']) : 12;
                $selectors = isset($_GET['sources']) ? InputNormaliser::selectors($_GET['sources']) : [];
                $comparison = $service->compareSources($selectors, $limit);

                echo json_encode([
                    'data' => [
                        'comparison' => $comparison,
                    ],
                    'meta' => ['processing_time_ms' => runtime($startTime)],
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                return;
            case 'insight':
                $limit = isset($_GET['limit']) ? max(1, (int) $_GET['limit']) : 6;
                $query = isset($_GET['q']) && is_string($_GET['q']) ? (string) $_GET['q'] : '';
                $selectors = isset($_GET['sources']) ? InputNormaliser::selectors($_GET['sources']) : [];
                $insight = $service->buildInsightDocument($query, $limit, $selectors);

                if (isset($insight['references']['sources']) && is_array($insight['references']['sources'])) {
                    $insight['references']['sources'] = sanitiseSources($insight['references']['sources']);
                }

                echo json_encode([
                    'data' => [
                        'insight' => $insight,
                    ],
                    'meta' => ['processing_time_ms' => runtime($startTime)],
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                return;
            case 'search':
                $limit = isset($_GET['limit']) ? max(1, (int) $_GET['limit']) : 12;
                $query = isset($_GET['q']) && is_string($_GET['q']) ? (string) $_GET['q'] : '';
                $search = $researcher->searchGraph($query, $limit);
                $search['sources'] = sanitiseSources($search['sources']);

                echo json_encode([
                    'data' => [
                        'search' => $search,
                    ],
                    'meta' => ['processing_time_ms' => runtime($startTime)],
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                return;
            case 'report':
                $query = isset($_GET['q']) && is_string($_GET['q']) ? (string) $_GET['q'] : '';
                $limit = isset($_GET['limit']) ? max(1, (int) $_GET['limit']) : 5;
                $selectors = isset($_GET['sources']) ? InputNormaliser::selectors($_GET['sources']) : [];
                $report = $service->buildResearchBrief($query, $limit, $selectors);

                echo json_encode([
                    'data' => [
                        'report' => $report,
                    ],
                    'meta' => ['processing_time_ms' => runtime($startTime)],
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                return;
            case 'summary':
                $entity = isset($_GET['entity']) && is_string($_GET['entity']) ? trim($_GET['entity']) : '';
                if ($entity === '') {
                    http_response_code(422);
                    echo json_encode(['error' => 'Provide an entity name via the "entity" query parameter.'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    return;
                }

                $factLimit = isset($_GET['facts']) ? max(1, (int) $_GET['facts']) : 12;
                $summary = $service->summariseEntity($entity, $factLimit);
                if ($summary === null) {
                    http_response_code(404);
                    echo json_encode(['error' => 'Entity not found in the knowledge graph.'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    return;
                }

                echo json_encode([
                    'data' => [
                        'entity' => $summary,
                    ],
                    'meta' => ['processing_time_ms' => runtime($startTime)],
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                return;
            case 'sources':
                echo json_encode([
                    'data' => [
                        'sources' => sanitiseSources($service->sources()),
                        'metadata' => $researcher->metadata(),
                    ],
                    'meta' => ['processing_time_ms' => runtime($startTime)],
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                return;
        }

        http_response_code(404);
        echo json_encode(['error' => 'Unsupported research action.'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return;

    case 'POST':
        switch ($action) {
            case 'refresh':
                $maxAge = isset($decoded['max_age_hours']) ? (int) $decoded['max_age_hours'] : 168;

                try {
                    $refresh = $service->refreshSources($maxAge);
                } catch (\Throwable $exception) {
                    http_response_code(500);
                    echo json_encode([
                        'error' => 'Failed to refresh sources.',
                        'details' => $exception->getMessage(),
                    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    return;
                }

                echo json_encode([
                    'data' => [
                        'summary' => $refresh['summary'],
                        'sources' => sanitiseSources($refresh['sources']),
                        'removed' => $refresh['removed_sources'],
                        'graph' => $refresh['graph'],
                    ],
                    'meta' => ['processing_time_ms' => runtime($startTime)],
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                return;

            case 'crawl':
                $seeds = [];
                if (isset($decoded['seeds'])) {
                    $seeds = InputNormaliser::seeds($decoded['seeds']);
                }

                if ($seeds === []) {
                    http_response_code(422);
                    echo json_encode(['error' => 'Provide one or more seed URLs via the "seeds" field.'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    return;
                }

                $limit = isset($decoded['limit']) ? (int) $decoded['limit'] : 6;
                $depth = isset($decoded['depth']) ? (int) $decoded['depth'] : 2;
                $allowCrossDomain = !empty($decoded['allow_cross_domain']);

                try {
                    $crawler = new AutoCrawler($service);
                    $result = $crawler->crawl($seeds, $limit, $depth, (bool) $allowCrossDomain);
                } catch (\Throwable $exception) {
                    http_response_code(500);
                    echo json_encode([
                        'error' => 'Auto crawl failed.',
                        'details' => $exception->getMessage(),
                    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    return;
                }

                echo json_encode([
                    'data' => [
                        'summary' => $result['summary'],
                        'ingested' => $result['ingested'],
                        'errors' => $result['errors'],
                        'discovered' => $result['discovered'],
                        'graph' => $result['graph'],
                        'queue' => $result['queue'],
                        'sources' => sanitiseSources($service->sources()),
                    ],
                    'meta' => ['processing_time_ms' => runtime($startTime)],
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                return;
        }

        http_response_code(404);
        echo json_encode(['error' => 'Unsupported research action.'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return;
}

http_response_code(405);
echo json_encode(['error' => 'Unsupported HTTP method.'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

/**
 * @param array<int, array<string, mixed>> $sources
 * @return array<int, array<string, mixed>>
 */
function sanitiseSources(array $sources): array
{
    return array_map(
        static function (array $source): array {
            unset($source['content']);
            return $source;
        },
        $sources
    );
}

function runtime(float $start): int
{
    return (int) round((microtime(true) - $start) * 1000);
}
