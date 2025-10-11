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
    public string $text = 'Signal Ledger automatically gathers intelligence across markets.';

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

$firstScheduleEvent = $firstScheduleHistory[0];
if (($firstScheduleEvent['reason'] ?? '') !== 'seed') {
    throw new RuntimeException('Seed schedule should track the seed scheduling reason.');
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

$latestLinkedEvent = $linkedEvents[0];
if (($latestLinkedEvent['reason'] ?? '') !== 'discovery') {
    throw new RuntimeException('Discovered schedule should flag discovery events.');
}

if (($latestLinkedEvent['queued_at'] ?? '') === '') {
    throw new RuntimeException('Schedule events should capture the queued timestamp.');
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

echo "HiddenCrawler tests passed\n";
