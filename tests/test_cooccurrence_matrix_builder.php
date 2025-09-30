<?php
require_once __DIR__ . '/../AiResearch/CoOccurrenceMatrixBuilder.php';

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

echo "CoOccurrenceMatrixBuilder tests passed\n";
