<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/App/bootstrap.php';

use App\KnowledgeGraph\GraphRepository;
use App\KnowledgeGraph\GraphResearcher;

ini_set('assert.exception', '1');

function assertEquals($expected, $actual, string $message = ''): void
{
    if ($expected != $actual) {
        $prefix = $message !== '' ? $message . ' ' : '';
        throw new AssertionError($prefix . 'Expected ' . var_export($expected, true) . ' but got ' . var_export($actual, true));
    }
}

function assertTrue(bool $value, string $message = ''): void
{
    if (!$value) {
        throw new AssertionError($message !== '' ? $message : 'Expected true but got false');
    }
}

function assertNotNull($value, string $message = ''): void
{
    if ($value === null) {
        throw new AssertionError($message !== '' ? $message : 'Expected value to be non-null');
    }
}

$tempFile = tempnam(sys_get_temp_dir(), 'graph');
if ($tempFile === false) {
    throw new RuntimeException('Failed to create temporary graph file.');
}

$graphData = [
    'graph' => [
        'triples' => [
            ['subject' => 'alice smith', 'relation' => 'worksat', 'object' => 'horizon lab'],
            ['subject' => 'alice smith', 'relation' => 'collaborateswith', 'object' => 'bob hernandez'],
            ['subject' => 'horizon lab', 'relation' => 'locatedin', 'object' => 'london'],
        ],
        'synonyms' => [
            ['entity' => 'alice smith', 'synonyms' => ['dr alice smith']],
            ['entity' => 'horizon lab', 'synonyms' => ['horizon laboratory']],
        ],
        'relations' => [
            'collaborateswith' => 1,
            'locatedin' => 1,
            'worksat' => 1,
        ],
        'entities' => [
            'alice smith' => 3,
            'bob hernandez' => 1,
            'horizon lab' => 3,
            'london' => 1,
        ],
        'summary' => [
            'documents_processed' => 2,
            'triples' => 3,
        ],
        'cross_references' => [
            'alice smith' => [
                'entity' => 'alice smith',
                'facts' => [
                    ['direction' => 'outgoing', 'relation' => 'worksat', 'counterpart' => 'horizon lab'],
                    ['direction' => 'outgoing', 'relation' => 'collaborateswith', 'counterpart' => 'bob hernandez'],
                    ['direction' => 'incoming', 'relation' => 'locatedin', 'counterpart' => 'horizon lab'],
                ],
                'synonyms' => ['dr alice smith'],
                'context' => [
                    'as_subject' => ['worksat' => 1, 'collaborateswith' => 1],
                    'as_object' => ['locatedin' => 1],
                ],
                'ranking' => [
                    'score' => 0.72,
                    'eligible' => true,
                    'signals' => [
                        'uniqueness' => 0.62,
                        'freshness' => 0.58,
                        'quality' => 0.64,
                        'authority' => 0.71,
                        'consistency' => 0.6,
                    ],
                    'support' => [
                        'incoming_links' => 1,
                        'outgoing_links' => 2,
                        'fact_count' => 3,
                    ],
                ],
            ],
            'horizon lab' => [
                'entity' => 'horizon lab',
                'facts' => [
                    ['direction' => 'incoming', 'relation' => 'worksat', 'counterpart' => 'alice smith'],
                    ['direction' => 'outgoing', 'relation' => 'locatedin', 'counterpart' => 'london'],
                ],
                'synonyms' => ['horizon laboratory'],
                'context' => [
                    'as_subject' => ['locatedin' => 1],
                    'as_object' => ['worksat' => 1],
                ],
                'ranking' => [
                    'score' => 0.55,
                    'eligible' => false,
                    'signals' => [
                        'uniqueness' => 0.5,
                        'freshness' => 0.54,
                        'quality' => 0.57,
                        'authority' => 0.52,
                        'consistency' => 0.49,
                    ],
                    'support' => [
                        'incoming_links' => 1,
                        'outgoing_links' => 1,
                        'fact_count' => 2,
                    ],
                ],
            ],
        ],
    ],
    'sources' => [
        [
            'url' => 'https://example.com/article',
            'title' => 'Example Article',
            'characters' => 1200,
            'fetched_at' => '2024-06-01T00:00:00+00:00',
            'preview' => 'Example preview',
        ],
    ],
    'updated_at' => '2024-06-01T00:00:00+00:00',
];

$encoded = json_encode($graphData);
if ($encoded === false) {
    throw new RuntimeException('Failed to encode graph fixture.');
}

file_put_contents($tempFile, $encoded);

$repository = new GraphRepository($tempFile);
$researcher = new GraphResearcher($repository);

$snapshot = $researcher->snapshot();
assertEquals(3, count($snapshot['triples']));
assertEquals(['collaborateswith' => 1, 'locatedin' => 1, 'worksat' => 1], $snapshot['relations']);
assertEquals(['alice smith' => 3, 'bob hernandez' => 1, 'horizon lab' => 3, 'london' => 1], $snapshot['entities']);

$metadata = $researcher->metadata();
assertEquals(1, count($metadata['sources']));
assertEquals('2024-06-01T00:00:00+00:00', $metadata['updated_at']);

$top = $researcher->listTopEntities(5);
assertEquals(1, count($top), 'Only eligible entities should be returned in ranking list.');
assertEquals('alice smith', $top[0]['entity']);
assertTrue($top[0]['eligible']);
foreach ($top as $rankedEntity) {
    assertTrue($rankedEntity['eligible'], 'Ineligible entities should be filtered from rankings.');
}
assertEquals(3, $top[0]['fact_count']);
assertEquals(1, $top[0]['synonym_count']);

$summary = $researcher->summariseEntity('Alice Smith', 2);
assertNotNull($summary, 'Expected summary for Alice Smith.');
assertEquals('alice smith', $summary['entity']);
assertEquals(2, count($summary['facts']), 'Fact limit should be applied.');
assertEquals(3, $summary['fact_count']);
assertTrue(in_array('dr alice smith', $summary['synonyms'], true));
assertTrue(isset($summary['relation_counts']['worksat']));
assertTrue(isset($summary['counterpart_counts']['horizon lab']));
assertTrue(isset($summary['fact_descriptions']) && is_array($summary['fact_descriptions']), 'Expected fact descriptions in summary.');
assertEquals(2, count($summary['fact_descriptions']), 'Descriptions should respect fact limit.');
assertTrue($summary['fact_descriptions'][0] !== '', 'Fact description should be non-empty.');

$synonymSummary = $researcher->summariseEntity('Dr. Alice Smith', 3);
assertNotNull($synonymSummary, 'Expected synonym lookup to succeed.');
assertEquals('alice smith', $synonymSummary['entity']);

$fuzzySummary = $researcher->summariseEntity('Horizon', 3);
assertNotNull($fuzzySummary, 'Fuzzy lookup should match Horizon Lab.');
assertEquals('horizon lab', $fuzzySummary['entity']);

$exactSynonym = $researcher->summariseEntity('Horizon Laboratory', 3);
assertNotNull($exactSynonym, 'Expected synonym mapping for Horizon Laboratory.');
assertEquals('horizon lab', $exactSynonym['entity']);

$missing = $researcher->summariseEntity('Nonexistent Entity', 3);
assertTrue($missing === null, 'Unknown entities should return null.');

$searchResults = $researcher->searchGraph('Alice', 5);
assertTrue(isset($searchResults['entities']) && count($searchResults['entities']) > 0, 'Expected search results for Alice.');
$firstEntity = $searchResults['entities'][0];
assertTrue(isset($firstEntity['facts']) && is_array($firstEntity['facts']), 'Expected fact previews for search result.');
if ($firstEntity['facts'] !== []) {
    assertTrue(is_string($firstEntity['facts'][0]) && $firstEntity['facts'][0] !== '', 'Fact preview should be a non-empty string.');
}

unlink($tempFile);
