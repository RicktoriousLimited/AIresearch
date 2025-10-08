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
$engine->addTriple('Red Fox', 'isa', 'Wild Animal');
$engine->addTriple('Red Fox', 'located_in', 'Europe');
$engine->addSynonym('Red Fox', 'Vulpes Vulpes');

$exported = $engine->toArray();
assertTrue(isset($exported['graph']), 'Graph missing from export payload');
assertTrue(isset($exported['synonyms']), 'Synonyms missing from export payload');
assertTrue(isset($exported['profiles']), 'Profiles missing from export payload');
assertTrue(isset($exported['verbs']), 'Verb lexicon missing from export payload');

$profiles = $engine->getEntityProfiles();
assertTrue(isset($profiles['red fox']), 'Profile for red fox missing');
assertTrue(isset($profiles['vulpes vulpes']), 'Profile for vulpes vulpes missing');
assertEquals(['isa' => 1, 'locatedin' => 1, 'synonym' => 1], $profiles['red fox']['as_subject']);
assertEquals(['synonym' => 1], $profiles['vulpes vulpes']['as_object']);
assertEquals([], $exported['verbs']);

$restored = SemanticEngine::fromArray($exported);

assertTrue($restored->queryIsA('red fox', 'wild animal'), 'Restored engine lost isa relation');
assertContains(['red fox', 'locatedin', 'europe'], $restored->iterTriples('located_in'));
assertEquals(['vulpes vulpes'], $restored->querySynonyms('red fox'));
$restoredProfiles = $restored->getEntityProfiles();
assertTrue(isset($restoredProfiles['red fox']), 'Restored profile for red fox missing');
assertEquals($profiles['red fox'], $restoredProfiles['red fox']);

$allTriples = $restored->iterTriples();
assertContains(['red fox', 'isa', 'wild animal'], $allTriples);
assertContains(['red fox', 'synonym', 'vulpes vulpes'], $allTriples);

$roundTrip = SemanticEngine::fromArray($restored->toArray());
assertTrue($roundTrip->queryIsA('red fox', 'wild animal'));
assertEquals(['vulpes vulpes'], $roundTrip->querySynonyms('red fox'));
$lexicon = $roundTrip->getVerbLexicon();
assertEquals([], $lexicon);

echo "Snapshot tests passed\n";
