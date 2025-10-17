<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/App/bootstrap.php';

use App\Extraction\ExtractionResult;
use App\Extraction\ExtractorInterface;
use App\KnowledgeGraph\AutoCrawler;
use App\KnowledgeGraph\GraphRepository;
use App\KnowledgeGraph\ResearchService;
use App\Scraping\ScraperInterface;
use App\Scraping\ScrapeResult;

ini_set('assert.exception', '1');

final class CrawlerFakeScraper implements ScraperInterface
{
    /** @var array<string, array{title: string, text: string, links: array<int, string>}> */
    private array $responses = [];

    public function register(string $url, string $title, string $text, array $links = []): void
    {
        $this->responses[$url] = [
            'title' => $title,
            'text' => $text,
            'links' => $links,
        ];
    }

    public function scrape(string $url): ScrapeResult
    {
        if (!isset($this->responses[$url])) {
            throw new RuntimeException('Missing stub for ' . $url);
        }

        $payload = $this->responses[$url];
        return new ScrapeResult($url, $payload['title'], $payload['text'], [$payload['text']], $payload['links']);
    }
}

final class CrawlerFakeExtractor implements ExtractorInterface
{
    /**
     * @var array<int, array{mode: string, documents: array<int, string>}> */
    public array $calls = [];

    public function analyseMany(array $documents, ?array $state = null): ExtractionResult
    {
        $this->calls[] = ['mode' => 'many', 'documents' => $documents];
        return $this->buildResult($documents);
    }

    public function analyse(string $document, ?array $state = null): ExtractionResult
    {
        $this->calls[] = ['mode' => 'single', 'documents' => [$document]];
        return $this->buildResult([$document]);
    }

    /**
     * @param array<int, string> $documents
     */
    private function buildResult(array $documents): ExtractionResult
    {
        $triples = [];
        $entities = [];

        foreach ($documents as $index => $document) {
            $triples[] = [
                'subject' => 'doc-' . $index,
                'relation' => 'contains',
                'object' => trim($document),
            ];
            $entities['doc-' . $index] = 1;
        }

        $summary = [
            'documents_received' => count($documents),
            'documents_processed' => count($documents),
            'triples' => count($triples),
            'synonym_groups' => 0,
            'unique_entities' => count($entities),
            'generated_at' => '2024-01-01T00:00:00+00:00',
        ];

        return new ExtractionResult(
            $triples,
            [],
            [],
            ['contains' => count($triples)],
            $entities,
            $summary,
            ['state' => 'test'],
            [],
            []
        );
    }
}

function assertArrayHasKey($key, array $array, string $message = ''): void
{
    if (!array_key_exists($key, $array)) {
        $prefix = $message !== '' ? $message . ' ' : '';
        throw new AssertionError($prefix . 'Expected key ' . var_export($key, true) . ' to exist.');
    }
}

function assertGreaterThan(int $expected, int $actual, string $message = ''): void
{
    if (!($actual > $expected)) {
        $prefix = $message !== '' ? $message . ' ' : '';
        throw new AssertionError($prefix . 'Expected ' . $actual . ' to be greater than ' . $expected);
    }
}

function assertEquals($expected, $actual, string $message = ''): void
{
    if ($expected != $actual) {
        $prefix = $message !== '' ? $message . ' ' : '';
        throw new AssertionError($prefix . 'Expected ' . var_export($expected, true) . ' but got ' . var_export($actual, true));
    }
}

function assertTrue(bool $value, string $message = ''): void
{
    if (!$value) {
        throw new AssertionError($message !== '' ? $message : 'Expected true but got false');
    }
}

$tempFile = tempnam(sys_get_temp_dir(), 'crawler');
if ($tempFile === false) {
    throw new RuntimeException('Failed to create temporary graph file.');
}

$repository = new GraphRepository($tempFile);
$scraper = new CrawlerFakeScraper();
$extractor = new CrawlerFakeExtractor();
$service = new ResearchService($repository, $scraper, $extractor);
$crawler = new AutoCrawler($service, $scraper);

$scraper->register(
    'https://example.com/start',
    'Start',
    'Start page text.',
    ['https://example.com/about', 'https://othersite.com/skip']
);
$scraper->register(
    'https://example.com/about',
    'About',
    'About page text.',
    ['https://example.com/contact']
);
$scraper->register(
    'https://example.com/contact',
    'Contact',
    'Contact page text.',
    []
);

$result = $crawler->crawl(['https://example.com/start'], 3, 2);

assertEquals(3, $result['summary']['processed']);
assertEquals(0, $result['summary']['errors']);
assertGreaterThan(0, count($result['ingested']));
assertArrayHasKey('graph', $result);
assertEquals([], array_values(array_filter($result['discovered'], static fn(string $url): bool => strpos($url, 'othersite.com') !== false)));

$sources = $service->sources();
assertEquals(3, count($sources));
assertEquals('https://example.com/contact', $sources[2]['url']);

$graph = $service->currentGraph();
assertTrue(is_array($graph));
assertTrue(isset($graph['summary']));

unlink($tempFile);
