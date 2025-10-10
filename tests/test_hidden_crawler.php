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
if (!is_array($meaninglessReasons) || !in_array('Discarded meaningless text – no coherent sentences detected.', $meaninglessReasons, true)) {
    throw new RuntimeException('Meaningless samples should explain the rejection reason.');
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

echo "HiddenCrawler tests passed\n";
