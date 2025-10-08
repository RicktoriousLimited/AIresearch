<?php
require_once __DIR__ . '/../src/App/bootstrap.php';
require_once __DIR__ . '/../src/App/Text/TextRefiner.php';

use App\Text\TextRefiner;

ini_set('assert.exception', '1');

function assertTrue(bool $value, string $message = ''): void {
    if (!$value) {
        throw new AssertionError($message !== '' ? $message : 'Expected condition to be true.');
    }
}

function assertNotEmpty($value, string $message = ''): void {
    if (empty($value)) {
        throw new AssertionError($message !== '' ? $message : 'Expected value to be non-empty.');
    }
}

$refiner = new TextRefiner();

$raw = "alice smyth wrks on data minng. she leads the lab.\n\n- builds pipeline\n- cleans datasets";

$cleaned = $refiner->cleanDocument($raw);
assertTrue(strpos($cleaned, '- builds pipeline') !== false, 'Expected bullet formatting to be preserved.');
assertTrue(strpos($cleaned, 'alice smyth wrks on data minng.') !== false, 'Expected sentences to remain present.');

$rewrite = $refiner->rewriteDocument($raw);
assertTrue(strpos($rewrite, 'Alice smyth wrks on data minng.') !== false, 'Rewrite should capitalise sentences.');
assertTrue(strpos($rewrite, 'She leads the lab.') !== false, 'Rewrite should segment sentences.');

$keywords = $refiner->extractKeywords($raw, 5);
assertNotEmpty($keywords, 'Expected keywords to be detected.');
$keywordTokens = array_map(static fn(array $entry): string => $entry['token'], $keywords);
assertTrue(in_array('data', $keywordTokens, true) || in_array('pipeline', $keywordTokens, true), 'Expected domain keywords in results.');

$spelling = $refiner->spellCheck($raw);
assertNotEmpty($spelling, 'Expected spelling suggestions for misspelled tokens.');

$wrksEntry = null;
foreach ($spelling as $entry) {
    if (($entry['token'] ?? '') === 'wrks') {
        $wrksEntry = $entry;
        break;
    }
}

assertTrue($wrksEntry !== null, 'Expected wrks token to be flagged.');
assertTrue(in_array('works', $wrksEntry['suggestions'] ?? [], true), 'Expected "works" suggestion for wrks token.');

$analysis = $refiner->analyseDocument($raw);
assertTrue(isset($analysis['cleaned'], $analysis['rewritten'], $analysis['keywords'], $analysis['spelling']), 'Analysis payload should expose all fields.');
assertTrue($analysis['rewritten'] !== '', 'Rewritten text should not be empty.');

$bbcNav = <<<TEXT
BBC Homepage
Skip to content
Accessibility Help
Sign in
Notifications
Home
News
Sport
Weather
iPlayer
Sounds
Bitesize
More menu
Search BBC

Conservatives would scrap stamp duty, Badenoch announces
Kemi Badenoch said the Conservatives would scrap stamp duty on primary residences to boost home ownership.

Published 8 October 2025, 14:11 BST
Updated 1 hour ago
Media caption, Standing ovation as Badenoch says Tories would scrap stamp duty

She said scrapping stamp duty will unlock a fairer society and help people of all ages.
TEXT;

$navCleaned = $refiner->cleanDocument($bbcNav);
assertTrue(strpos($navCleaned, 'BBC Homepage') === false, 'Expected boilerplate headers to be removed.');
assertTrue(strpos($navCleaned, 'Skip to content') === false, 'Expected navigation prompts to be removed.');
assertTrue(strpos($navCleaned, 'Conservatives would scrap stamp duty') !== false, 'Expected article headline to remain.');
assertTrue(strpos($navCleaned, 'She said scrapping stamp duty') !== false, 'Expected article body to remain.');

$gibberish = <<<TEXT
asdf qwer zxcv

This is a meaningful sentence that should survive.
TEXT;

$gibberishCleaned = $refiner->cleanDocument($gibberish);
assertTrue(strpos($gibberishCleaned, 'asdf qwer zxcv') === false, 'Expected meaningless text to be removed.');
assertTrue(strpos($gibberishCleaned, 'This is a meaningful sentence') !== false, 'Expected meaningful text to remain.');

$unknownVocabulary = <<<TEXT
Xyzzor is qliph.

asdf qwer zxcv
TEXT;

$unknownCleaned = $refiner->cleanDocument($unknownVocabulary);
assertTrue(strpos($unknownCleaned, 'Xyzzor is qliph.') !== false, 'Expected structured sentence to remain.');
assertTrue(strpos($unknownCleaned, 'asdf qwer zxcv') === false, 'Expected gibberish to still be filtered.');

echo "TextRefiner tests passed\n";
