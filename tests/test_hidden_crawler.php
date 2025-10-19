<?php
require_once __DIR__ . '/../src/App/bootstrap.php';
require_once __DIR__ . '/../src/App/Crawler/HiddenCrawler.php';
require_once __DIR__ . '/../src/App/Scraping/ScrapeResult.php';
require_once __DIR__ . '/../src/App/Scraping/ScraperInterface.php';
require_once __DIR__ . '/../src/App/Text/TextRefiner.php';

use App\Crawler\HiddenCrawler;
use App\Scraping\ScrapeResult;
use App\Scraping\ScraperInterface;
use App\Text\TextRefiner;

class StubScraper implements ScraperInterface
{
    public string $text = 'Signal Ledger automatically gathers intelligence across markets, summarising investor moves for analysts.';

    public function scrape(string $url): ScrapeResult
    {
        $text = $this->text;

        return new ScrapeResult($url, 'Stub page', $text, [$text], []);
    }
}

class FlakyScraper implements ScraperInterface
{
    public function scrape(string $url): ScrapeResult
    {
        if (str_contains($url, 'fail')) {
            throw new RuntimeException('Simulated fetch failure');
        }

        $text = 'Signal Ledger tracks resilient tasks.';

        return new ScrapeResult($url, 'Resilient page', $text, [$text], []);
    }
}

class LinkedScraper implements ScraperInterface
{
    public function scrape(string $url): ScrapeResult
    {
        $links = str_contains($url, 'start-linked') ? ['https://example.com/child-page'] : [];
        $text = 'Signal Ledger follows discovery links.';

        return new ScrapeResult($url, 'Linked page', $text, [$text], $links);
    }
}

$storage = sys_get_temp_dir() . '/crawler_history_' . bin2hex(random_bytes(4)) . '.json';
$crawler = new HiddenCrawler($storage, new StubScraper(), new TextRefiner());

$result = $crawler->crawl(['https://example.com']);
if (count($result) !== 1) {
    throw new RuntimeException('Expected a single crawl result.');
}

$first = $result[0];
if (!isset($first['category']) || !in_array($first['category'], ['financial', 'global'], true)) {
    throw new RuntimeException('Crawler results should include a valid category label.');
}

if (!is_string($first['content_digest'] ?? null) || trim((string) $first['content_digest']) === '') {
    throw new RuntimeException('Crawler entries should expose a semantic content digest.');
}

if (!isset($first['topics']) || !is_array($first['topics']) || $first['topics'] === []) {
    throw new RuntimeException('Crawler results should include at least one topic.');
}

$contentType = isset($first['content_type']) ? (string) $first['content_type'] : '';
if (!in_array($contentType, ['article', 'page', 'non_article', 'error'], true)) {
    throw new RuntimeException('Crawler should classify entries by content type.');
}

$revision = isset($first['revision']) ? (int) $first['revision'] : 0;
if ($revision !== 1) {
    throw new RuntimeException('Initial crawl should start at revision 1.');
}

$versions = $first['versions'] ?? null;
if (!is_array($versions) || $versions !== []) {
    throw new RuntimeException('Initial crawl should not include archived versions.');
}

$narrative = $first['narrative'] ?? [];
if (!is_array($narrative)) {
    throw new RuntimeException('Crawler results should expose narrative analytics.');
}

$keyPhrases = $first['key_phrases'] ?? null;
if (!is_array($keyPhrases)) {
    throw new RuntimeException('Crawler results should expose key phrase signals.');
}

$semanticTags = $first['semantic_tags'] ?? null;
if (!is_array($semanticTags)) {
    throw new RuntimeException('Crawler results should include semantic tags.');
}

$semanticFingerprint = $first['semantic_fingerprint'] ?? null;
if (!is_string($semanticFingerprint)) {
    throw new RuntimeException('Crawler results should compute a semantic fingerprint.');
}

$semanticHighlights = $first['semantic_highlights'] ?? null;
if (!is_array($semanticHighlights) || $semanticHighlights === []) {
    throw new RuntimeException('Crawler results should include semantic highlights.');
}

$firstHighlight = $semanticHighlights[0];
if (!is_string($firstHighlight['phrase'] ?? '') || $firstHighlight['phrase'] === '') {
    throw new RuntimeException('Semantic highlight entries should expose a phrase.');
}

if (!is_string($firstHighlight['snippet'] ?? '') || $firstHighlight['snippet'] === '') {
    throw new RuntimeException('Semantic highlight entries should expose a snippet.');
}

$graph = $first['graph'] ?? [];
if (!is_array($graph) || !array_key_exists('ingested', $graph)) {
    throw new RuntimeException('Crawler results should include graph integration metadata.');
}

$history = $crawler->history();
if ($history === []) {
    throw new RuntimeException('Crawler history should persist results.');
}

$firstHistory = $history[0];
if ((int) ($firstHistory['revision'] ?? 0) !== 1) {
    throw new RuntimeException('History should reflect the latest revision.');
}

$firstSchedule = $firstHistory['discovery']['schedule'] ?? null;
if (!is_array($firstSchedule) || (int) ($firstSchedule['total_runs'] ?? 0) < 1) {
    throw new RuntimeException('Seed entries should record at least one scheduled run.');
}

$firstScheduleHistory = $firstSchedule['history'] ?? [];
if (!is_array($firstScheduleHistory) || $firstScheduleHistory === []) {
    throw new RuntimeException('Seed schedule history should include the initial run.');
}

$seedReasons = array_map(static fn(array $event): string => (string) ($event['reason'] ?? ''), $firstScheduleHistory);
if (!in_array('seed', $seedReasons, true)) {
    throw new RuntimeException('Seed schedule should track the seed scheduling reason.');
}

$knownSeeds = $crawler->knownUrls(10, false);
if (!is_array($knownSeeds) || $knownSeeds === []) {
    throw new RuntimeException('Known URLs list should include seeded targets.');
}

$knownSeed = $knownSeeds[0];
if (!is_string($knownSeed['url'] ?? '') || $knownSeed['url'] === '') {
    throw new RuntimeException('Known URL entries should expose the page URL.');
}

if (!array_key_exists('interval_minutes', $knownSeed) || (int) $knownSeed['interval_minutes'] < 15) {
    throw new RuntimeException('Known URL entries should include a positive refresh interval.');
}

if (!array_key_exists('next_due_at', $knownSeed)) {
    throw new RuntimeException('Known URL entries should expose the next due timestamp.');
}

$progressState = $crawler->progress();
$discoverySummary = $progressState['discovery_summary'] ?? null;
if (!is_array($discoverySummary)) {
    throw new RuntimeException('Crawler progress should include a discovery summary.');
}

$discoveryTotals = $discoverySummary['totals'] ?? null;
if (!is_array($discoveryTotals) || (int) ($discoveryTotals['links'] ?? 0) < 1) {
    throw new RuntimeException('Discovery summary should record at least one tracked link.');
}

$discoveryDomains = $discoverySummary['domains'] ?? null;
if (!is_array($discoveryDomains) || $discoveryDomains === []) {
    throw new RuntimeException('Discovery summary should group links by domain.');
}

$secondResult = $crawler->crawl(['https://example.com?utm_source=twitter']);
if (count($secondResult) !== 1) {
    throw new RuntimeException('Expected a single crawl result on second run.');
}

$second = $secondResult[0];
if (empty($second['unchanged'])) {
    throw new RuntimeException('Second crawl should detect unchanged content.');
}

if (($second['revision'] ?? 0) !== 1) {
    throw new RuntimeException('Revision should remain at 1 when no changes are detected.');
}

$changes = $second['changes']['summary'] ?? '';
if (!is_string($changes) || stripos($changes, 'no content changes') === false) {
    throw new RuntimeException('Unchanged entries should surface a descriptive change summary.');
}

$historyAfterSecond = $crawler->history();
if (count($historyAfterSecond) !== 1) {
    throw new RuntimeException('History should remain grouped by URL.');
}

$historyEntry = $historyAfterSecond[0];
if (!empty($historyEntry['versions'])) {
    throw new RuntimeException('No archived versions should be stored when nothing changed.');
}


$crawlerScraper = new StubScraper();
$crawlerScraper->text = 'Signal Ledger now tracks AI and venture funding sentiment across markets.';
$crawlerChanged = new HiddenCrawler($storage, $crawlerScraper, new TextRefiner());
$thirdResult = $crawlerChanged->crawl(['https://example.com?utm_medium=email']);
if (count($thirdResult) !== 1) {
    throw new RuntimeException('Expected a single crawl result on third run.');
}

$third = $thirdResult[0];
if (!empty($third['unchanged'])) {
    throw new RuntimeException('Content updates should be detected.');
}

if ((int) ($third['revision'] ?? 0) !== 2) {
    throw new RuntimeException('Revision should increment when content changes.');
}

if (empty($third['versions']) || !is_array($third['versions'])) {
    throw new RuntimeException('Updated entries should retain prior versions.');
}

$duplicateScraper = new StubScraper();
$duplicateScraper->text = 'Signal Ledger automatically gathers intelligence across markets.';
$crawlerDuplicate = new HiddenCrawler($storage, $duplicateScraper, new TextRefiner());
$duplicateResult = $crawlerDuplicate->crawl(['https://mirror.example.com']);
if (count($duplicateResult) !== 1) {
    throw new RuntimeException('Expected a single crawl result for duplicate run.');
}

$duplicateEntry = $duplicateResult[0];
if (!is_string($duplicateEntry['duplicate_of'] ?? null) || trim((string) $duplicateEntry['duplicate_of']) === '') {
    throw new RuntimeException('Duplicate entries should reference the canonical URL.');
}

if (($duplicateEntry['duplicate_of'] ?? '') === ($duplicateEntry['normalized_url'] ?? '')) {
    throw new RuntimeException('Duplicate pointer should not reference the entry itself.');
}

if (!empty($duplicateEntry['ingest'])) {
    throw new RuntimeException('Duplicate entries should not be marked for ingestion.');
}

$duplicateReasons = $duplicateEntry['quality_reasons'] ?? [];
if (!is_array($duplicateReasons) || $duplicateReasons === []) {
    throw new RuntimeException('Duplicate entries should surface quality reasons.');
}

$duplicateReasonText = implode(' ', $duplicateReasons);
if (stripos($duplicateReasonText, 'duplicate') === false) {
    throw new RuntimeException('Duplicate entries should note the duplicate detection in quality reasons.');
}


$latestHistory = $crawlerChanged->history();
if ((int) ($latestHistory[0]['revision'] ?? 0) !== 2) {
    throw new RuntimeException('History should reflect the newest revision.');
}

$archived = $latestHistory[0]['versions'] ?? [];
if (count($archived) !== 1) {
    throw new RuntimeException('History should archive the previous revision once.');
}

if ((int) ($archived[0]['revision'] ?? 0) !== 1) {
    throw new RuntimeException('Archived revision should retain its original revision number.');
}

$meaninglessStorage = sys_get_temp_dir() . '/crawler_history_' . bin2hex(random_bytes(4)) . '.json';
$meaninglessScraper = new StubScraper();
$meaninglessScraper->text = 'orange table river cloud stone horizon';
$meaninglessCrawler = new HiddenCrawler($meaninglessStorage, $meaninglessScraper, new TextRefiner());
$meaninglessResult = $meaninglessCrawler->crawl(['https://example.com/meaningless']);
if (count($meaninglessResult) !== 1) {
    throw new RuntimeException('Expected a single crawl result for meaningless sample.');
}

$meaninglessEntry = $meaninglessResult[0];
if (!empty($meaninglessEntry['meaningful'])) {
    throw new RuntimeException('Meaningless samples should be flagged as not meaningful.');
}

$meaninglessReasons = $meaninglessEntry['quality_reasons'] ?? [];
if (!is_array($meaninglessReasons) || !in_array('Flagged meaningless text – no coherent sentences detected.', $meaninglessReasons, true)) {
    throw new RuntimeException('Meaningless samples should explain the rejection reason.');
}

if (!in_array('Quality below recommended threshold – included for comprehensive coverage.', $meaninglessReasons, true)) {
    throw new RuntimeException('Low quality inclusions should note comprehensive coverage.');
}

$meaninglessDuplicateResult = $meaninglessCrawler->crawl(['https://mirror.example.com/meaningless']);
if (count($meaninglessDuplicateResult) !== 1) {
    throw new RuntimeException('Expected a single crawl result for meaningless duplicate run.');
}

$meaninglessDuplicateEntry = $meaninglessDuplicateResult[0];
if (!is_string($meaninglessDuplicateEntry['duplicate_of'] ?? null) || trim((string) $meaninglessDuplicateEntry['duplicate_of']) === '') {
    throw new RuntimeException('Meaningless duplicate entries should reference their canonical URL.');
}

if (($meaninglessDuplicateEntry['duplicate_of'] ?? '') === ($meaninglessDuplicateEntry['normalized_url'] ?? '')) {
    throw new RuntimeException('Meaningless duplicate detection should not point to the entry itself.');
}

$flakyStorage = sys_get_temp_dir() . '/crawler_history_' . bin2hex(random_bytes(4)) . '.json';
$flakyCrawler = new HiddenCrawler($flakyStorage, new FlakyScraper(), new TextRefiner());
$flakyResult = $flakyCrawler->crawl([
    'https://example.com/fail',
    'https://example.com/success',
]);

if (count($flakyResult) !== 2) {
    throw new RuntimeException('Flaky crawler run should return entries for every attempted URL.');
}

$flakyFailures = array_values(array_filter($flakyResult, static fn(array $entry): bool => !empty($entry['error'])));
if (count($flakyFailures) !== 1) {
    throw new RuntimeException('Exactly one flaky crawl should report a failure.');
}

$flakySuccesses = array_values(array_filter($flakyResult, static fn(array $entry): bool => empty($entry['error'])));
if (count($flakySuccesses) !== 1) {
    throw new RuntimeException('Successful crawls should be returned alongside failures.');
}

$linkedStorage = sys_get_temp_dir() . '/crawler_history_' . bin2hex(random_bytes(4)) . '.json';
$linkedCrawler = new HiddenCrawler($linkedStorage, new LinkedScraper(), new TextRefiner());
$linkedCrawler->crawl(['https://example.com/start-linked'], 2, 1);
$linkedHistory = $linkedCrawler->history();
$linkedChild = null;
foreach ($linkedHistory as $entry) {
    if (!is_array($entry)) {
        continue;
    }

    $url = (string) ($entry['url'] ?? '');
    if (str_contains($url, 'child-page')) {
        $linkedChild = $entry;
        break;
    }
}

if (!is_array($linkedChild)) {
    throw new RuntimeException('Discovered pages should be recorded in crawler history.');
}

$linkedSchedule = $linkedChild['discovery']['schedule'] ?? null;
if (!is_array($linkedSchedule) || (int) ($linkedSchedule['total_runs'] ?? 0) < 1) {
    throw new RuntimeException('Discovered pages should include schedule metadata.');
}

$linkedEvents = $linkedSchedule['history'] ?? [];
if (!is_array($linkedEvents) || $linkedEvents === []) {
    throw new RuntimeException('Discovered schedule history should retain recent events.');
}

$linkedReasons = array_map(static fn(array $event): string => (string) ($event['reason'] ?? ''), $linkedEvents);
if (!in_array('discovery', $linkedReasons, true)) {
    throw new RuntimeException('Discovered schedule should flag discovery events.');
}

$queuedTimestamps = array_values(array_filter(array_map(static fn(array $event): string => (string) ($event['queued_at'] ?? ''), $linkedEvents), static fn(string $value): bool => $value !== ''));
if ($queuedTimestamps === []) {
    throw new RuntimeException('Schedule events should capture the queued timestamp.');
}

$linkedKnown = $linkedCrawler->knownUrls(10, false);
if (!is_array($linkedKnown) || $linkedKnown === []) {
    throw new RuntimeException('Known URL list should include linked discoveries.');
}

$linkedChildKnown = null;
foreach ($linkedKnown as $knownEntry) {
    if (!is_array($knownEntry)) {
        continue;
    }

    if (str_contains((string) ($knownEntry['url'] ?? ''), 'child-page')) {
        $linkedChildKnown = $knownEntry;
        break;
    }
}

if (!is_array($linkedChildKnown)) {
    throw new RuntimeException('Discovered links should surface in known URL listings.');
}

if (!array_key_exists('interval_minutes', $linkedChildKnown) || (int) $linkedChildKnown['interval_minutes'] < 15) {
    throw new RuntimeException('Discovered entries should include a refresh interval.');
}

if (!is_string($linkedChildKnown['next_due_at'] ?? '') || $linkedChildKnown['next_due_at'] === '') {
    throw new RuntimeException('Discovered entries should expose the next due timestamp.');
}

$manualStorage = sys_get_temp_dir() . '/crawler_history_' . bin2hex(random_bytes(4)) . '.json';
$manualCrawler = new HiddenCrawler($manualStorage, new StubScraper(), new TextRefiner());
$manualQueued = $manualCrawler->queueManualRun(['https://example.com/manual-queue'], 1, 5, false, 15);
if ((int) ($manualQueued['scheduled'] ?? 0) !== 1) {
    throw new RuntimeException('Queueing a manual run should schedule at least one target.');
}

$manualProgress = $manualCrawler->progress();
if ($manualProgress['status'] !== 'queued') {
    throw new RuntimeException('Manual queueing should mark the crawler status as queued.');
}

$manualPending = $manualProgress['pending_run'] ?? null;
if (!is_array($manualPending) || (int) ($manualPending['scheduled_remaining'] ?? 0) < 1) {
    throw new RuntimeException('Manual queueing should track remaining scheduled pages.');
}

$manualCrawler->updatePendingRunProgress(1, ['depth' => 1], ['scheduled_total' => 1]);
$updatedProgress = $manualCrawler->progress();
if ((int) ($updatedProgress['queued'] ?? 0) !== 1) {
    throw new RuntimeException('Pending run progress should reflect remaining queued tasks.');
}

$manualRunResult = $manualCrawler->runScheduledQueue(1, 1, 0, false, 0);
$manualCrawler->updatePendingRunProgress(
    (int) ($manualRunResult['scheduled_remaining'] ?? 0),
    ['depth' => 1],
    [
        'scheduled_total' => (int) ($manualRunResult['scheduled_total'] ?? 0),
        'scheduled_preview' => $manualRunResult['scheduled_preview'] ?? [],
    ]
);
$finalManualProgress = $manualCrawler->progress();
if ((int) ($finalManualProgress['queued'] ?? -1) !== 0) {
    throw new RuntimeException('Completing a pending run should clear queued counters.');
}

$manualScheduled = $manualCrawler->scheduledQueue();
if (!is_array($manualScheduled) || $manualScheduled !== []) {
    throw new RuntimeException('Scheduled queue should be empty after manual run completion.');
}

unlink($flakyStorage);
$flakyProgress = preg_replace('/\.json$/', '.progress.json', $flakyStorage);
if (!is_string($flakyProgress)) {
    $flakyProgress = $flakyStorage . '.progress.json';
}
if (file_exists($flakyProgress)) {
    unlink($flakyProgress);
}

unlink($storage);
$progressFile = preg_replace('/\.json$/', '.progress.json', $storage);
if (!is_string($progressFile)) {
    $progressFile = $storage . '.progress.json';
}
if (file_exists($progressFile)) {
    unlink($progressFile);
}

unlink($meaninglessStorage);
$meaninglessProgress = preg_replace('/\.json$/', '.progress.json', $meaninglessStorage);
if (!is_string($meaninglessProgress)) {
    $meaninglessProgress = $meaninglessStorage . '.progress.json';
}
if (file_exists($meaninglessProgress)) {
    unlink($meaninglessProgress);
}

unlink($linkedStorage);
$linkedProgress = preg_replace('/\.json$/', '.progress.json', $linkedStorage);
if (!is_string($linkedProgress)) {
    $linkedProgress = $linkedStorage . '.progress.json';
}
if (file_exists($linkedProgress)) {
    unlink($linkedProgress);
}

unlink($manualStorage);
$manualProgressFile = preg_replace('/\.json$/', '.progress.json', $manualStorage);
if (!is_string($manualProgressFile)) {
    $manualProgressFile = $manualStorage . '.progress.json';
}
if (file_exists($manualProgressFile)) {
    unlink($manualProgressFile);
}

echo "HiddenCrawler tests passed\n";
