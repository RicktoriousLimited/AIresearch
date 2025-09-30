<?php
require_once __DIR__ . '/../AiResearch/CoOccurrenceMatrixBuilder.php';
require_once __DIR__ . '/../AiResearch/infer.php';

use AiResearch\CoOccurrenceMatrixBuilder;

ini_set('assert.exception', '1');

$builder = new CoOccurrenceMatrixBuilder(2, 'inverse');
$builder->addDocument([1, 2, 3]);
$result = $builder->finalize();

$counts = $result['counts'];

$expectedCounts = [
    1 => [2 => 1.0, 3 => 0.5],
    2 => [1 => 1.0, 3 => 1.0],
    3 => [2 => 1.0, 1 => 0.5],
];

if ($counts != $expectedCounts) {
    throw new AssertionError('Unexpected counts: ' . var_export($counts, true));
}

if ($result['sumAll'] !== 5.0) {
    throw new AssertionError('sumAll expected 5.0 but got ' . var_export($result['sumAll'], true));
}

$expectedRow = [1 => 1.5, 2 => 2.0, 3 => 1.5];
if ($result['sumRow'] !== $expectedRow) {
    throw new AssertionError('Unexpected row sums: ' . var_export($result['sumRow'], true));
}

$expectedCol = [2 => 2.0, 3 => 1.5, 1 => 1.5];
if ($result['sumCol'] !== $expectedCol) {
    throw new AssertionError('Unexpected column sums: ' . var_export($result['sumCol'], true));
}

$builder = new CoOccurrenceMatrixBuilder(3);
$builder->setDecayPolicy('linear', 0.25);
$builder->addDocument(['a', 'b', 'c', 'd']);
$final = $builder->finalize();

$policy = $builder->getDecayPolicy();
if ($policy['policy'] !== 'linear' || abs($policy['strength'] - 0.25) > 1e-9) {
    throw new AssertionError('Unexpected decay policy: ' . var_export($policy, true));
}

$weightDistance1 = $final['counts']['b']['a'];
$weightDistance2 = $final['counts']['a']['c'];

if (abs($weightDistance1 - 1.0) > 1e-9) {
    throw new AssertionError('Linear decay distance1 mismatch: ' . $weightDistance1);
}

if (abs($weightDistance2 - 0.75) > 1e-9) {
    throw new AssertionError('Linear decay distance2 mismatch: ' . $weightDistance2);
}

$builder = new CoOccurrenceMatrixBuilder(1);
$corpus = [
    [1, 3, 2, 3],
    [2, 3, 1, 3],
    [1, 3, 2, 3],
];

foreach ($corpus as $document) {
    $builder->addDocument($document);
}

$ppmi = $builder->computePpmi();

$idToWord = [
    1 => 'alpha',
    2 => 'beta',
    3 => 'gamma',
];

$pairs = \AiResearch\infer($ppmi, $idToWord, 0.0);

if (empty($pairs)) {
    throw new AssertionError('Expected synonym pairs but none were inferred.');
}

$found = false;
foreach ($pairs as [$left, $right, $score]) {
    if ($left === 'alpha' && $right === 'beta') {
        if ($score <= 0.0) {
            throw new AssertionError('Expected positive similarity score for alpha/beta pair.');
        }
        $found = true;
        break;
    }
}

if (!$found) {
    throw new AssertionError('Expected alpha/beta synonym pair not found: ' . var_export($pairs, true));
}

echo "CoOccurrenceMatrixBuilder tests passed\n";
