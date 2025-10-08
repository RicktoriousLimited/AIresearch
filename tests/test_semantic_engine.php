<?php
require_once __DIR__ . '/../src/SemanticEngine.php';

ini_set('assert.exception', 1);

function assertEquals($expected, $actual, string $message = ''): void {
    if ($expected != $actual) {
        $msg = $message !== '' ? $message . ' ' : '';
        throw new AssertionError($msg . 'Expected ' . var_export($expected, true) . ' but got ' . var_export($actual, true));
    }
}

function assertTrue(bool $value, string $message = ''): void {
    if (!$value) {
        throw new AssertionError($message !== '' ? $message : 'Expected true but got false');
    }
}

function assertContains(array $needle, array $haystack, string $message = ''): void {
    foreach ($haystack as $item) {
        if ($item === $needle) {
            return;
        }
    }
    $msg = $message !== '' ? $message . ' ' : '';
    throw new AssertionError($msg . 'Array ' . var_export($needle, true) . ' not found.');
}

$engine = new SemanticEngine();
assertEquals('red fox', $engine->normalizeEntity('Red Fox'));
assertEquals('blue-green', $engine->normalizeEntity('Blue-Green'));
assertEquals('high-speed train', $engine->normalizeEntity('  High-Speed  Train  '));

$engine = new SemanticEngine();
$engine->addTriple('Red Fox', 'isa', 'Wild Animal');
assertContains(['red fox', 'isa', 'wild animal'], $engine->iterTriples(), 'Triple not found');
assertTrue($engine->queryIsA('red fox', 'wild animal'));

$engine = new SemanticEngine();
$engine->addSynonym('Red Fox', 'Vulpes Vulpes');
assertEquals(['vulpes vulpes'], $engine->querySynonyms('red fox'));
assertEquals(['red fox'], $engine->querySynonyms('Vulpes Vulpes'));
$synonymTriples = $engine->iterTriples('synonym');
assertContains(['red fox', 'synonym', 'vulpes vulpes'], $synonymTriples);
assertContains(['vulpes vulpes', 'synonym', 'red fox'], $synonymTriples);

$engine = new SemanticEngine();
$text = 'The Red Fox is a Wild Animal. Red Fox also known as Vulpes Vulpes.';
$triples = $engine->extractRelations($text);
assertContains(['red fox', 'isa', 'wild animal'], $triples);
assertContains(['red fox', 'synonym', 'vulpes vulpes'], $triples);
assertTrue($engine->queryIsA('Red Fox', 'wild animal'));
assertEquals(['vulpes vulpes'], $engine->querySynonyms('red fox'));
$synonymTriples = $engine->iterTriples('synonym');
assertContains(['red fox', 'synonym', 'vulpes vulpes'], $synonymTriples);
assertContains(['vulpes vulpes', 'synonym', 'red fox'], $synonymTriples);

$engine = new SemanticEngine();
$text = 'High-speed train is a Transport System.';
$triples = $engine->extractRelations($text);
assertContains(['high-speed train', 'isa', 'transport system'], $triples);
assertTrue($engine->queryIsA('High-Speed Train', 'transport system'));

$engine = new SemanticEngine();
$text = 'Officials told the BBC they kept the arrangement because that\'s what works for their interests.';
$triples = $engine->extractRelations($text);
foreach ($triples as $triple) {
    if ($triple[1] === 'worksat') {
        throw new AssertionError('Unexpected worksat triple for complement clause subjects');
    }
}

$engine = new SemanticEngine();
$text = 'Alice Smith works at Ricktorious Limited. Alice Smith lives in Birmingham. Carla Rossi leads the Horizon Lab. Horizon Lab focuses on Responsible AI Research. Horizon Lab located in London, United Kingdom. Alice Smith collaborates with Bob Hernandez.';
$triples = $engine->extractRelations($text);
assertContains(['alice smith', 'worksat', 'ricktorious limited'], $triples);
assertContains(['alice smith', 'livesin', 'birmingham'], $triples);
assertContains(['carla rossi', 'leads', 'horizon lab'], $triples);
assertContains(['horizon lab', 'focuseson', 'responsible ai research'], $triples);
assertContains(['horizon lab', 'locatedin', 'london united kingdom'], $triples);
assertContains(['alice smith', 'collaborateswith', 'bob hernandez'], $triples);

$worksAtTriples = $engine->iterTriples('works_at');
assertContains(['alice smith', 'worksat', 'ricktorious limited'], $worksAtTriples);
$livesInTriples = $engine->iterTriples('lives_in');
assertContains(['alice smith', 'livesin', 'birmingham'], $livesInTriples);
$collaboratesTriples = $engine->iterTriples('collaborates_with');
assertContains(['alice smith', 'collaborateswith', 'bob hernandez'], $collaboratesTriples);

echo "All tests passed\n";
