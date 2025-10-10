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
    public function scrape(string $url): ScrapeResult
    {
        $text = 'Signal Ledger automatically gathers intelligence across markets.';
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

$history = $crawler->history();
if ($history === []) {
    throw new RuntimeException('Crawler history should persist results.');
}

unlink($storage);

echo "HiddenCrawler tests passed\n";
