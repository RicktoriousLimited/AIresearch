<?php
require_once __DIR__ . '/../AiResearch/infer.php';

use function AiResearch\infer;

ini_set('assert.exception', '1');

$ppmi = [
    1 => [1 => 1.0, 2 => 1.0],
    2 => [1 => 1.0, 2 => 1.0],
    3 => [2 => 1.0, 3 => 1.0],
    4 => [4 => 2.0],
];

$idToWord = [
    1 => 'alpha',
    2 => 'beta',
    3 => 'gamma',
    4 => 'delta',
];

$result = infer($ppmi, $idToWord, 0.5);


$expected = [
    ['alpha', 'beta', 1.0],
    ['alpha', 'gamma', 0.5],
    ['beta', 'gamma', 0.5],
];

if (count($result) !== count($expected)) {
    throw new AssertionError('Unexpected number of results: ' . var_export($result, true));
}

foreach ($expected as $index => [$left, $right, $score]) {
    [$actualLeft, $actualRight, $actualScore] = $result[$index] ?? [null, null, null];
    if ($actualLeft !== $left || $actualRight !== $right || abs($actualScore - $score) > 1e-9) {
        throw new AssertionError('Unexpected inference output: ' . var_export($result, true));
    }
}

echo "infer() tests passed\n";
