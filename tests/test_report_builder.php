<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/App/bootstrap.php';

use App\KnowledgeGraph\GraphRepository;
use App\KnowledgeGraph\ReportBuilder;

ini_set('assert.exception', '1');

function assertArrayHasKey(string $key, array $array, string $message = ''): void
{
    if (!array_key_exists($key, $array)) {
        $prefix = $message !== '' ? $message . ' ' : '';
        throw new AssertionError($prefix . 'Expected key ' . $key . ' to be present.');
    }
}

function assertGreaterThan($expected, $actual, string $message = ''): void
{
    if (!($actual > $expected)) {
        $prefix = $message !== '' ? $message . ' ' : '';
        throw new AssertionError($prefix . 'Expected ' . var_export($actual, true) . ' to be greater than ' . var_export($expected, true));
    }
}

function assertSameValue($expected, $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        $prefix = $message !== '' ? $message . ' ' : '';
        throw new AssertionError($prefix . 'Expected ' . var_export($actual, true) . ' to strictly equal ' . var_export($expected, true));
    }
}

$tempFile = tempnam(sys_get_temp_dir(), 'report');
if ($tempFile === false) {
    throw new RuntimeException('Failed to create temporary graph file.');
}

$graphData = [
    'graph' => [
        'triples' => [],
        'synonyms' => [],
        'relations' => [],
        'entities' => [],
        'summary' => [],
    ],
    'sources' => [
        [
            'url' => 'https://example.com/ai-healthcare',
            'title' => 'AI transforms hospitals',
            'content' => 'AI is transforming healthcare with new diagnostics. Hospitals increased funding in 2024 for patient AI assistants.',
            'preview' => 'AI is transforming healthcare with new diagnostics.',
            'characters' => 132,
            'links' => ['https://example.com/assets/hospital.jpg'],
        ],
        [
            'url' => 'https://example.com/finance-ai',
            'title' => 'Financial firms adopt AI',
            'content' => 'Financial institutions deploy machine learning for fraud detection and compliance. Funding increased during 2023 across banks.',
            'preview' => 'Financial institutions deploy machine learning for fraud detection.',
            'characters' => 141,
            'links' => ['https://example.com/assets/finance.png'],
        ],
        [
            'url' => 'https://example.com/hospital-investment',
            'title' => 'Hospitals invest in imaging',
            'content' => 'Hospitals invest in diagnostic imaging, AI tools, and patient monitoring solutions to modernise care delivery.',
            'preview' => 'Hospitals invest in diagnostic imaging and AI tools.',
            'characters' => 118,
        ],
    ],
    'updated_at' => '2024-07-01T00:00:00+00:00',
];

$encoded = json_encode($graphData);
if ($encoded === false) {
    throw new RuntimeException('Failed to encode graph fixture.');
}

file_put_contents($tempFile, $encoded);

$repository = new GraphRepository($tempFile);
$builder = new ReportBuilder($repository);

$comparison = $builder->compareSources();
assertArrayHasKey('documents', $comparison, 'Comparison payload should include documents.');
assertGreaterThan(0, $comparison['document_count'], 'Expected comparison to analyse stored sources.');
assertGreaterThan(0, count($comparison['documents']), 'Expected document list to be non-empty.');
assertArrayHasKey('matrix', $comparison, 'Comparison payload should include similarity matrix.');

$orderedComparison = $builder->compareSources([
    'https://example.com/finance-ai',
    'https://example.com/ai-healthcare',
], 2);
assertGreaterThan(0, $orderedComparison['document_count'], 'Selector comparison should return results.');
assertSameValue('https://example.com/finance-ai', $orderedComparison['documents'][0]['url'] ?? null, 'First comparison document should respect selector order.');
assertSameValue('https://example.com/ai-healthcare', $orderedComparison['documents'][1]['url'] ?? null, 'Second comparison document should respect selector order.');

$report = $builder->buildReport('AI investments', 3);
assertArrayHasKey('highlights', $report, 'Report payload should include highlights.');
assertGreaterThan(0, count($report['highlights']), 'Expected at least one highlight in report.');
assertArrayHasKey('citations', $report, 'Report payload should include citations.');
assertGreaterThan(0, count($report['citations']), 'Citations should be populated.');
assertArrayHasKey('combined_summary', $report, 'Report should include combined summary.');
assertGreaterThan(0, count($report['combined_summary']), 'Combined summary should include entries.');

unlink($tempFile);
