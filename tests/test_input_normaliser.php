<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/App/bootstrap.php';

use App\Support\InputNormaliser;

ini_set('assert.exception', '1');

function assertSameValues($expected, $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        $prefix = $message !== '' ? $message . ' ' : '';
        throw new AssertionError($prefix . 'Expected ' . var_export($actual, true) . ' to strictly equal ' . var_export($expected, true));
    }
}

$selectorsFromString = InputNormaliser::selectors("https://example.com/a\nhttps://example.com/b, https://example.com/a");
assertSameValues(
    ['https://example.com/a', 'https://example.com/b'],
    $selectorsFromString,
    'Selectors should split comma and newline separated strings.'
);

$selectorsFromArray = InputNormaliser::selectors([' https://example.com/c ', '', 'https://example.com/a']);
assertSameValues(
    ['https://example.com/c', 'https://example.com/a'],
    $selectorsFromArray,
    'Selectors should trim entries and remove blanks.'
);

$seeds = InputNormaliser::seeds("https://seed-one.test, https://seed-two.test\nhttps://seed-one.test");
assertSameValues(
    ['https://seed-one.test', 'https://seed-two.test'],
    $seeds,
    'Seeds should deduplicate values.'
);

$emptySelectors = InputNormaliser::selectors(null);
assertSameValues([], $emptySelectors, 'Null should produce an empty selector list.');

$emptySeeds = InputNormaliser::seeds(['   ', '']);
assertSameValues([], $emptySeeds, 'Empty strings should be filtered from seed input.');
