<?php
require_once __DIR__ . '/../src/SemanticEngine.php';

$lexicon = EnglishLexicon::loadDefault();
assertTrue($lexicon->contains('analysis'), 'Expected analysis to exist in lexicon');
assertTrue(!$lexicon->contains('qwertyasdf'), 'Unexpected gibberish token found in lexicon');

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
$crossReferences = $engine->buildCrossReferences();
assertTrue(isset($crossReferences['red fox']), 'Cross references should expose synonym relationships');
assertTrue(in_array('vulpes vulpes', $crossReferences['red fox']['synonyms'], true), 'Expected synonym in cross reference payload');
assertTrue(isset($crossReferences['red fox']['ranking']), 'Expected ranking metadata for cross references');
assertTrue(is_float($crossReferences['red fox']['ranking']['score']), 'Ranking score should be numeric');
assertTrue(isset($crossReferences['red fox']['ranking']['signals']['authority']), 'Authority signal must be present');
assertTrue(is_bool($crossReferences['red fox']['ranking']['eligible']), 'Eligible flag must be boolean');
assertTrue(isset($crossReferences['red fox']['related_terms']), 'Cross references should expose related term placeholder');

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
$text = 'Jane Doe (JD) leads Bright Labs.';
$engine->extractRelations($text);
assertTrue(in_array('jd', $engine->querySynonyms('jane doe'), true), 'Parenthetical alias should be captured as synonym');

$engine = new SemanticEngine();
$engine->addTriple('Jane Doe', 'works_at', 'Bright Labs');
$engine->addTriple('Bright Labs', 'locatedin', 'London');
$engine->registerKeywordCooccurrence([
    ['token' => 'Jane Doe', 'count' => 3],
    ['token' => 'Bright Labs', 'count' => 2],
    ['token' => 'London', 'count' => 1],
]);
$relatedMap = $engine->getRelatedTerms();
assertTrue(isset($relatedMap['jane doe']), 'Expected related map entry for Jane Doe');
$janeRelated = array_column($relatedMap['jane doe'], 'entity');
assertTrue(in_array('bright labs', $janeRelated, true), 'Bright Labs should appear as related term for Jane Doe');
$crossReferences = $engine->buildCrossReferences();
assertTrue(isset($crossReferences['jane doe']['related_terms']), 'Cross references should include related terms');

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
$crossReferences = $engine->buildCrossReferences();
assertTrue(isset($crossReferences['alice smith']), 'Cross references should include Alice Smith');
assertContains(['direction' => 'outgoing', 'relation' => 'worksat', 'counterpart' => 'ricktorious limited'], $crossReferences['alice smith']['facts']);
assertContains(['direction' => 'outgoing', 'relation' => 'livesin', 'counterpart' => 'birmingham'], $crossReferences['alice smith']['facts']);
assertContains(['direction' => 'outgoing', 'relation' => 'collaborateswith', 'counterpart' => 'bob hernandez'], $crossReferences['alice smith']['facts']);
assertTrue(isset($crossReferences['bob hernandez']), 'Cross references should include Bob Hernandez');
assertContains(['direction' => 'incoming', 'relation' => 'collaborateswith', 'counterpart' => 'alice smith'], $crossReferences['bob hernandez']['facts']);
assertEquals(['collaborateswith' => 1, 'livesin' => 1, 'worksat' => 1], $crossReferences['alice smith']['context']['as_subject']);
assertEquals([], $crossReferences['alice smith']['context']['as_object']);
$aliceRanking = $crossReferences['alice smith']['ranking'];
assertTrue(is_bool($aliceRanking['eligible']), 'Ranking eligibility must be boolean');
assertTrue($aliceRanking['signals']['authority'] >= 0 && $aliceRanking['signals']['authority'] <= 1, 'Authority signal should be normalised');
assertTrue($aliceRanking['support']['incoming_links'] >= 0, 'Incoming links count must be non-negative');

$engine = new SemanticEngine();
$text = 'Dr. Alice Smith developed neural algorithms. Neural algorithms power robotics platforms.';
$triples = $engine->extractRelations($text);
assertContains(['alice smith', 'action-develop', 'neural algorithms'], $triples);
assertContains(['neural algorithms', 'action-power', 'robotics platforms'], $triples);

$profiles = $engine->getEntityProfiles();
assertTrue(isset($profiles['alice smith']), 'Expected profile for Alice Smith');
assertTrue(isset($profiles['neural algorithms']), 'Expected profile for neural algorithms');
assertTrue(isset($profiles['robotics platforms']), 'Expected profile for robotics platforms');
assertEquals(['action-develop' => 1], $profiles['alice smith']['as_subject']);
assertEquals(['action-develop' => 1], $profiles['neural algorithms']['as_object']);
assertEquals(['action-power' => 1], $profiles['neural algorithms']['as_subject']);
assertEquals(['action-power' => 1], $profiles['robotics platforms']['as_object']);

$verbs = $engine->getVerbLexicon();
assertTrue(in_array('develop', $verbs, true));
assertTrue(in_array('power', $verbs, true));

$engine = new SemanticEngine();
$text = 'Google Accounts: Sign in. Google Maps – Find local businesses.';
$triples = $engine->extractRelations($text);
assertContains(['google accounts', 'tagline', 'sign in'], $triples);
assertContains(['google maps', 'tagline', 'find local businesses'], $triples);

$engine = new SemanticEngine();
$text = 'Prosecutors questioned 61-year-old Karen Spragg during the hearing.';
$triples = $engine->extractRelations($text);
foreach ($triples as $triple) {
    if ($triple[1] === 'tagline') {
        throw new AssertionError('Hyphenated ages should not trigger tagline extraction');
    }
}

$engine = new SemanticEngine();
$text = 'Skip to main content accessibility feedback Google AI mode all image short videos videos news shopping more tools https://www.google.com';
$triples = $engine->extractRelations($text);
foreach ($triples as $triple) {
    if (strpos($triple[1], 'action-') === 0) {
        throw new AssertionError('Unexpected action triple extracted from navigation chrome.');
    }
}

$engine = new SemanticEngine();
$text = 'xyzzypq glormed qwertyasdf.';
assertEquals([], $engine->extractRelations($text), 'Gibberish should not generate triples');

echo "All tests passed\n";
