<?php
require_once __DIR__ . '/../src/App/bootstrap.php';

use App\Extraction\Extractor;

ini_set('assert.exception', 1);

function assertTrue(bool $value, string $message = ''): void {
    if (!$value) {
        throw new AssertionError($message !== '' ? $message : 'Expected condition to be true.');
    }
}

function assertEquals($expected, $actual, string $message = ''): void {
    if ($expected != $actual) {
        $prefix = $message !== '' ? $message . ' ' : '';
        throw new AssertionError($prefix . 'Expected ' . var_export($expected, true) . ' but got ' . var_export($actual, true));
    }
}

$initialExtractor = new Extractor();
$firstResult = $initialExtractor->analyse('Alice Smith works at Example Labs.');

$firstTriples = $firstResult->triples();
assertTrue(count($firstTriples) >= 1, 'First extraction should yield at least one triple.');

$state = $firstResult->state();
assertTrue(isset($state['graph']), 'Exported state must include the graph payload.');

$secondExtractor = new Extractor();
$secondResult = $secondExtractor->analyse('Bob Johnson lives in Paris.', $state);

$combinedTriples = $secondResult->triples();
assertTrue(count($combinedTriples) >= 2, 'Combined extraction should accumulate triples from both runs.');

$firstTriple = $combinedTriples[0] ?? null;
if ($firstTriple !== null) {
    assertTrue(isset($firstTriple['confidence']), 'Triples should expose confidence scores.');
    assertTrue(is_float($firstTriple['confidence']) || is_int($firstTriple['confidence']), 'Confidence should be numeric.');
}

$subjects = array_map(static function (array $triple): string {
    return $triple['subject'] ?? '';
}, $combinedTriples);

assertTrue(in_array('alice smith', $subjects, true), 'Combined result must retain initial subject.');
assertTrue(in_array('bob johnson', $subjects, true), 'Combined result must include new subject.');

$summary = $secondResult->summary();
assertEquals(1, $summary['documents_processed'], 'documents_processed should count the new payload only.');
assertTrue(($summary['triples'] ?? 0) >= count($combinedTriples), 'Summary triple count should reflect accumulated knowledge.');

$documents = $secondResult->documents();
assertTrue(count($documents) >= 1, 'Expected at least one document analysis payload.');
$firstDocument = $documents[0];
assertTrue(isset($firstDocument['cleaned'], $firstDocument['rewritten']), 'Document analysis should expose cleaned and rewritten text.');
assertTrue(is_array($firstDocument['keywords']), 'Document keywords should be an array.');
assertTrue(is_array($firstDocument['spelling']), 'Document spelling insights should be an array.');
assertTrue(isset($firstDocument['boilerplate']), 'Document boilerplate insights should be present.');
assertTrue(isset($firstDocument['acronyms']), 'Document acronym insights should be present.');

$crossReferences = $secondResult->crossReferences();
assertTrue(isset($crossReferences['alice smith']), 'Cross references must track initial entities.');
assertTrue(isset($crossReferences['bob johnson']), 'Cross references must include newly processed entities.');
assertTrue(is_array($crossReferences['alice smith']['facts']), 'Cross reference facts should be an array payload.');
$aliceRanking = $crossReferences['alice smith']['ranking'];
assertTrue(isset($aliceRanking['signals']['quality']), 'Quality signal must exist.');
assertTrue(is_bool($aliceRanking['eligible']), 'Eligibility flag should be boolean.');
assertTrue(is_array($aliceRanking['support']), 'Support metadata should be provided.');

echo "Extractor state tests passed\n";
