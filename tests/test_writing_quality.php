<?php
require_once __DIR__ . '/../src/App/bootstrap.php';

use App\Extraction\Extractor;

ini_set('assert.exception', 1);

function assertTrue(bool $value, string $message = ''): void {
    if (!$value) {
        throw new AssertionError($message !== '' ? $message : 'Expected condition to be true.');
    }
}

$extractor = new Extractor();

$wellStructured = <<<TEXT
In this report we explore the adoption of green energy infrastructure across mid-sized cities. First, we summarise the baseline emissions and policy commitments established in 2023. Next, we examine funding programmes that accelerate installation timelines. Finally, we outline clear recommendations for maintaining momentum over the next decade.

Overall, the evidence shows a persuasive case for continued investment supported by verifiable grant activity and published utilisation metrics.
TEXT;

$result = $extractor->analyse($wellStructured);
$documents = $result->documents();
assertTrue($documents !== [], 'Expected at least one analysed document.');
$analysis = $documents[0]['analytics'] ?? [];
assertTrue(isset($analysis['writing_quality']), 'Writing quality analytics must be present.');
$writingQuality = $analysis['writing_quality'];

$overallScore = (float) ($writingQuality['overall']['score'] ?? 0.0);
assertTrue($overallScore >= 0.55, 'Well structured analysis should earn a strong writing score.');
assertTrue(($writingQuality['structure']['paragraphs'] ?? 0) >= 2, 'Structure analysis should detect multiple paragraphs.');

$speculative = <<<TEXT
Breaking update: profits will triple next week, and the company will dominate the market without question. We believe, maybe, possibly, that new products could appear out of thin air, although no evidence exists yet. Buy immediately before facts catch up.
TEXT;

$speculativeResult = (new Extractor())->analyse($speculative);
$speculativeDocuments = $speculativeResult->documents();
assertTrue($speculativeDocuments !== [], 'Expected speculative sample to produce analytics.');
$speculativeWriting = $speculativeDocuments[0]['analytics']['writing_quality'] ?? [];

$speculativeScore = (float) ($speculativeWriting['overall']['score'] ?? 1.0);
assertTrue($speculativeScore < $overallScore, 'Speculative writing should score lower than evidence-based prose.');
assertTrue($speculativeScore < 0.6, 'Speculative writing should not appear as strong quality.');

echo "Writing quality analytics tests passed\n";
