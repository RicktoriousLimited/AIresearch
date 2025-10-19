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

function assertFalse(bool $value, string $message = ''): void {
    if ($value) {
        throw new AssertionError($message !== '' ? $message : 'Expected condition to be false.');
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
assertTrue(strpos($cleaned, 'alice smyth wrks on data minng.') === false, 'Expected incorrectly capitalised sentence to be removed.');
assertTrue(strpos($cleaned, 'she leads the lab.') === false, 'Expected sentence lacking initial capitalisation to be removed.');

$rewrite = $refiner->rewriteDocument($raw);
assertTrue(strpos($rewrite, '- Builds pipeline') !== false, 'Rewrite should capitalise bullet entries.');
assertTrue(strpos($rewrite, '- Cleans datasets') !== false, 'Rewrite should capitalise each bullet item.');
assertTrue(strpos($rewrite, 'Alice smyth wrks on data minng.') === false, 'Rewrite should not resurrect filtered sentences.');

$numberedList = "1. first milestone\n2) second milestone\nIII. third stage\n4 - final step";
$numberedCleaned = $refiner->cleanDocument($numberedList);
assertTrue(strpos($numberedCleaned, '- first milestone') !== false, 'Expected numbered list to be normalised to bullets.');
assertTrue(strpos($numberedCleaned, '- second milestone') !== false, 'Expected parenthesised numbering to be normalised to bullets.');
assertTrue(strpos($numberedCleaned, '- third stage') !== false, 'Expected roman numerals to be normalised to bullets.');
assertTrue(strpos($numberedCleaned, '- final step') !== false, 'Expected hyphenated numbering to be normalised to bullets.');

$numberedRewrite = $refiner->rewriteDocument($numberedList);
assertTrue(strpos($numberedRewrite, '- First milestone') !== false, 'Rewrite should capitalise normalised bullet entries.');
assertTrue(strpos($numberedRewrite, '- Second milestone') !== false, 'Rewrite should capitalise numbered list entries.');
assertTrue(strpos($numberedRewrite, '- Third stage') !== false, 'Rewrite should capitalise roman numeral list entries.');
assertTrue(strpos($numberedRewrite, '- Final step') !== false, 'Rewrite should capitalise hyphenated numbering entries.');

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
assertTrue(
    isset($analysis['cleaned'], $analysis['rewritten'], $analysis['keywords'], $analysis['spelling'], $analysis['qa'], $analysis['is_meaningful']),
    'Analysis payload should expose all fields.'
);
assertTrue($analysis['rewritten'] !== '', 'Rewritten text should not be empty.');
assertTrue(is_array($analysis['qa']) && $analysis['qa'] !== [], 'QA analysis should provide at least one entry.');
assertTrue(is_bool($analysis['is_meaningful']), 'Meaningfulness flag should be boolean.');
assertTrue($analysis['is_meaningful'], 'Expected sample document to be marked as meaningful.');
assertTrue(isset($analysis['analytics']['grammar']), 'Analytics payload should include grammar insights.');
$grammarInsights = $analysis['analytics']['grammar'];
assertTrue(is_array($grammarInsights['nouns'] ?? null), 'Grammar nouns listing should be an array.');
assertTrue(is_array($grammarInsights['verbs'] ?? null), 'Grammar verbs listing should be an array.');
assertTrue(is_array($grammarInsights['adjectives'] ?? null), 'Grammar adjectives listing should be an array.');
assertTrue(is_array($grammarInsights['entity_associations'] ?? null), 'Grammar entity associations should be an array.');

$qaPairs = $refiner->generateQuestionAnswerPairs($raw);
assertTrue($qaPairs !== [], 'Expected QA pairs to be generated.');
$firstPair = $qaPairs[0];
assertTrue(isset($firstPair['question'], $firstPair['answer'], $firstPair['response']), 'QA pair should expose question, answer and response keys.');
assertTrue($firstPair['question'] !== '', 'QA question should not be empty.');
assertTrue($firstPair['answer'] !== '', 'QA answer should not be empty.');
assertTrue($firstPair['response'] === $firstPair['answer'], 'QA response should mirror the answer payload.');

$fingerprintA = $refiner->buildSemanticFingerprint('Quantum computing advances are transforming healthcare diagnostics.');
$fingerprintB = $refiner->buildSemanticFingerprint('Healthcare leaders adopt quantum computing tools for diagnostics.');
$fingerprintC = $refiner->buildSemanticFingerprint('Local football clubs celebrate regional tournament victories.');

assertTrue($fingerprintA !== [] && $fingerprintB !== [], 'Semantic fingerprints should capture meaningful text.');
$similarityAB = $refiner->compareFingerprints($fingerprintA, $fingerprintB);
$similarityAC = $refiner->compareFingerprints($fingerprintA, $fingerprintC);
assertTrue($similarityAB > 0.0, 'Related passages should yield a positive similarity score.');
assertTrue($similarityAB > $similarityAC, 'Unrelated passages should produce a lower similarity score.');

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

$footerHtml = <<<HTML
<article>
    <h1>Breakthrough in battery storage</h1>
    <p>Researchers developed a solid-state battery with double the capacity.</p>
</article>
<footer>
    <div>Share this article</div>
    <nav>
        <a href="/privacy">Privacy policy</a>
        <a href="/terms">Terms of use</a>
    </nav>
    <p>© 2024 Example Media Group. All rights reserved.</p>
</footer>
HTML;

$footerCleaned = $refiner->cleanDocument($footerHtml);
assertTrue(strpos($footerCleaned, 'Breakthrough in battery storage') !== false, 'Expected main article content to remain.');
assertTrue(strpos($footerCleaned, 'solid-state battery') !== false, 'Expected article paragraph to remain.');
assertTrue(strpos($footerCleaned, 'Example Media Group') === false, 'Expected footer attribution to be removed.');
assertTrue(strpos($footerCleaned, 'Privacy policy') === false, 'Expected footer navigation links to be removed.');

$accentedHtml = <<<HTML
<header>
    <nav>
        <a href="#">Inicio</a>
        <a href="#">Noticias</a>
    </nav>
</header>
<article>
    <p>El Niño trae façade de naïveté &amp; déjà vu — análisis por Zoë Müller.</p>
</article>
<footer>
    <p>© 2025 Compañía Ejemplo</p>
</footer>
HTML;

$accentedCleaned = $refiner->cleanDocument($accentedHtml);
assertTrue(
    strpos($accentedCleaned, 'El Niño trae façade de naïveté & déjà vu — análisis por Zoë Müller.') !== false,
    'Expected accented and special characters to be preserved.'
);
assertTrue(
    strpos($accentedCleaned, 'Compañía Ejemplo') === false,
    'Expected footer attribution with special characters to be removed.'
);

$gibberish = <<<TEXT
asdf qwer zxcv

This is a meaningful sentence that should survive.
TEXT;

$gibberishCleaned = $refiner->cleanDocument($gibberish);
assertTrue(strpos($gibberishCleaned, 'asdf qwer zxcv') === false, 'Expected meaningless text to be removed.');
assertTrue(strpos($gibberishCleaned, 'This is a meaningful sentence') !== false, 'Expected meaningful text to remain.');
$gibberishAnalysis = $refiner->analyseDocument($gibberish);
assertTrue($gibberishAnalysis['is_meaningful'] ?? false, 'Mixed samples with meaningful sentences should still pass meaningfulness checks.');

$wordSalad = 'orange table river cloud stone horizon';
$wordSaladAnalysis = $refiner->analyseDocument($wordSalad);
assertFalse($wordSaladAnalysis['is_meaningful'] ?? true, 'Expected random word list to fail meaningfulness checks.');

$unknownVocabulary = <<<TEXT
Xyzzor is qliph.

asdf qwer zxcv
TEXT;

$unknownCleaned = $refiner->cleanDocument($unknownVocabulary);
assertTrue(strpos($unknownCleaned, 'Xyzzor is qliph.') !== false, 'Expected structured sentence to remain.');
assertTrue(strpos($unknownCleaned, 'asdf qwer zxcv') === false, 'Expected gibberish to still be filtered.');
$unknownAnalysis = $refiner->analyseDocument($unknownVocabulary);
assertTrue($unknownAnalysis['is_meaningful'] ?? false, 'Structured sentences with unknown vocabulary should still pass meaningfulness checks.');

$entitySample = 'Acme Robotics develops innovative and reliable automation platforms for global clients.';
$entityAnalysis = $refiner->analyseDocument($entitySample);
$entityGrammar = $entityAnalysis['analytics']['grammar'] ?? [];
$entityAssociations = $entityGrammar['entity_associations'] ?? [];
$foundAcme = false;
foreach ($entityAssociations as $association) {
    if (!is_array($association)) {
        continue;
    }
    $entityName = (string) ($association['entity'] ?? '');
    if (stripos($entityName, 'Acme Robotics') === false) {
        continue;
    }

    $foundAcme = true;
    $verbs = array_map(static fn($value) => strtolower((string) $value), $association['verbs'] ?? []);
    $adjectives = array_map(static fn($value) => strtolower((string) $value), $association['adjectives'] ?? []);

    assertTrue(in_array('develops', $verbs, true), 'Expected action verb linked to Acme Robotics.');
    assertTrue(
        in_array('innovative', $adjectives, true) || in_array('reliable', $adjectives, true),
        'Expected descriptive adjectives linked to Acme Robotics.'
    );
}
assertTrue($foundAcme, 'Expected entity association for Acme Robotics.');

$grammarSample = <<<TEXT
Running down the street.
Because the dog was barking.
The boy sang.
I love to read I read every day.
I love to read, and I read every day.
TEXT;

$grammarCleaned = $refiner->cleanDocument($grammarSample);
assertTrue(strpos($grammarCleaned, 'Running down the street.') === false, 'Expected sentence fragment to be removed.');
assertTrue(strpos($grammarCleaned, 'Because the dog was barking.') === false, 'Expected dependent clause fragment to be removed.');
assertTrue(strpos($grammarCleaned, 'I love to read I read every day.') === false, 'Expected run-on sentence to be removed.');
assertTrue(strpos($grammarCleaned, 'The boy sang.') !== false, 'Expected valid simple sentence to remain.');
assertTrue(strpos($grammarCleaned, 'I love to read, and I read every day.') !== false, 'Expected properly punctuated compound sentence to remain.');

echo "TextRefiner tests passed\n";
