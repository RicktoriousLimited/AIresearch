<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/App/bootstrap.php';

use App\Extraction\ExtractionResult;
use App\Extraction\ExtractorInterface;
use App\KnowledgeGraph\GraphRepository;
use App\KnowledgeGraph\ResearchService;
use App\Scraping\ScraperInterface;
use App\Scraping\ScrapeResult;

ini_set('assert.exception', '1');

final class FakeScraper implements ScraperInterface
{
    /** @var array<string, array{title: string, text: string, links: array<int, string>}> */
    private array $responses = [];

    /** @var array<string, string> */
    private array $failures = [];

    /**
     * @param array<int, string> $links
     */
    public function register(string $url, string $title, string $text, array $links = []): void
    {
        $this->responses[$url] = [
            'title' => $title,
            'text' => $text,
            'links' => $links,
        ];
        unset($this->failures[$url]);
    }

    public function fail(string $url, string $message = 'Source unavailable'): void
    {
        $this->failures[$url] = $message;
        unset($this->responses[$url]);
    }

    public function scrape(string $url): ScrapeResult
    {
        if (isset($this->failures[$url])) {
            throw new RuntimeException($this->failures[$url]);
        }

        if (!isset($this->responses[$url])) {
            throw new RuntimeException('No stubbed response for ' . $url);
        }

        $payload = $this->responses[$url];
        $paragraphs = [$payload['text']];

        return new ScrapeResult($url, $payload['title'], $payload['text'], $paragraphs, $payload['links']);
    }
}

final class FakeExtractor implements ExtractorInterface
{
    private int $counter = 0;

    /**
     * @var array<int, array{mode: string, documents: array<int, string>}> */
    public array $invocations = [];

    public function analyseMany(array $documents, ?array $state = null): ExtractionResult
    {
        $this->invocations[] = ['mode' => 'many', 'documents' => $documents];
        return $this->buildResult($documents);
    }

    public function analyse(string $document, ?array $state = null): ExtractionResult
    {
        $this->invocations[] = ['mode' => 'single', 'documents' => [$document]];
        return $this->buildResult([$document]);
    }

    /**
     * @param array<int, string> $documents
     */
    private function buildResult(array $documents): ExtractionResult
    {
        $triples = [];
        $entities = [];
        $relations = [];

        foreach ($documents as $index => $document) {
            $subject = 'doc-' . $this->counter . '-' . $index;
            $object = trim($document);
            $triples[] = [
                'subject' => $subject,
                'relation' => 'contains',
                'object' => $object,
            ];

            $relations['contains'] = ($relations['contains'] ?? 0) + 1;
            $entities[$subject] = 1;
            $entities[$object] = ($entities[$object] ?? 0) + 1;
        }

        $this->counter++;

        $summary = [
            'documents_received' => count($documents),
            'documents_processed' => count(array_filter($documents, static fn(string $doc): bool => trim($doc) !== '')),
            'triples' => count($triples),
            'synonym_groups' => 0,
            'unique_entities' => count($entities),
            'generated_at' => '2024-01-01T00:00:00+00:00',
        ];

        return new ExtractionResult(
            $triples,
            [],
            [],
            $relations,
            $entities,
            $summary,
            ['counter' => $this->counter],
            [],
            []
        );
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

$tempFile = tempnam(sys_get_temp_dir(), 'research');
if ($tempFile === false) {
    throw new RuntimeException('Failed to create temporary file.');
}

$repository = new GraphRepository($tempFile);
$scraper = new FakeScraper();
$extractor = new FakeExtractor();
$service = new ResearchService($repository, $scraper, $extractor);

$scraper->register('https://example.com/a', 'Example A', 'Alice runs Example A.', ['https://example.com/a/about']);
$result = $service->ingestFromUrl('https://example.com/a');

assertEquals('Example A', $result['source']['title']);
assertTrue(isset($result['graph']['summary']));
assertTrue(is_file($tempFile));
assertEquals(['https://example.com/a/about'], $result['source']['links']);

$stored = $repository->load();
assertEquals(1, count($stored['sources']));
assertTrue(isset($stored['sources'][0]['content']) && $stored['sources'][0]['content'] !== '');
assertEquals(['https://example.com/a/about'], $stored['sources'][0]['links']);

$scraper->register('https://example.com/b', 'Example B', 'Bob researches Example B.', ['https://example.com/b/team']);
$service->ingestFromUrl('https://example.com/b');

$scraper->fail('https://example.com/a');
$refresh = $service->refreshSources(0);

assertEquals(1, $refresh['summary']['removed']);
assertEquals(1, $refresh['summary']['active']);
assertEquals('https://example.com/a', $refresh['removed_sources'][0]['url']);
assertEquals('https://example.com/b', $refresh['sources'][0]['url']);
assertEquals(['https://example.com/b/team'], $refresh['sources'][0]['links']);

$latest = $repository->load();
assertEquals(1, count($latest['sources']));
assertEquals('https://example.com/b', $latest['sources'][0]['url']);
assertEquals(['https://example.com/b/team'], $latest['sources'][0]['links']);

$manualScrape = new ScrapeResult(
    'https://example.com/manual',
    'Manual Entry',
    'Manual entry text.',
    ['Manual entry text.'],
    ['https://example.com/manual/context']
);
$manual = $service->ingestScrapeResult($manualScrape);
assertEquals('https://example.com/manual', $manual['source']['url']);
assertEquals(['https://example.com/manual/context'], $manual['source']['links']);

$graphSnapshot = $service->currentGraph();
assertTrue(is_array($graphSnapshot));
assertTrue(isset($graphSnapshot['summary']));

unlink($tempFile);
