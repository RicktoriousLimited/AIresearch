<?php
require_once __DIR__ . '/../src/App/bootstrap.php';
require_once __DIR__ . '/../src/App/News/NewsSearchService.php';
require_once __DIR__ . '/../src/App/Crawler/HiddenCrawler.php';
require_once __DIR__ . '/../src/App/Scraping/ScrapeResult.php';
require_once __DIR__ . '/../src/App/Scraping/ScraperInterface.php';
require_once __DIR__ . '/../src/App/Text/TextRefiner.php';

use App\Crawler\HiddenCrawler;
use App\News\NewsSearchService;
use App\Scraping\ScrapeResult;
use App\Scraping\ScraperInterface;
use App\Text\TextRefiner;

class NullScraper implements ScraperInterface
{
    public function scrape(string $url): ScrapeResult
    {
        throw new RuntimeException('Scraper should not be invoked in search service test.');
    }
}

function computeDigest(TextRefiner $refiner, string $text): string
{
    $clean = $refiner->cleanDocument($text);
    if ($clean === '') {
        $clean = $text;
    }

    $normalised = preg_replace('/\s+/u', ' ', $clean);
    $normalised = is_string($normalised) ? mb_strtolower(trim($normalised)) : '';

    return $normalised === '' ? hash('sha256', uniqid('', true)) : hash('sha256', $normalised);
}

function buildStoredEntry(array $data, TextRefiner $refiner, string $timestamp): array
{
    $digest = computeDigest($refiner, $data['content']);
    $fingerprint = hash('sha256', mb_strtolower($data['summary']) . '|' . mb_strtolower($data['title']));

    return [
        'url' => $data['url'],
        'normalized_url' => $data['normalized_url'],
        'title' => $data['title'],
        'summary' => $data['summary'],
        'content' => $data['content'],
        'topics' => $data['topics'],
        'entities' => $data['entities'],
        'quality_score' => $data['quality_score'],
        'quality_label' => $data['quality_label'],
        'quality_reasons' => $data['quality_reasons'],
        'ingest' => $data['ingest'],
        'fetched_at' => $timestamp,
        'last_checked_at' => $timestamp,
        'content_type' => $data['content_type'],
        'meta_description' => $data['meta_description'],
        'keywords' => [],
        'preview' => $data['summary'],
        'links' => [],
        'thumbnail' => null,
        'site_name' => '',
        'language' => '',
        'canonical_url' => '',
        'published_at' => '',
        'character_count' => strlen($data['content']),
        'paragraph_count' => 3,
        'narrative' => [],
        'meaningful' => true,
        'recommended_sources' => [],
        'graph' => [
            'ingested' => false,
            'quality_gate_passed' => true,
        ],
        'fingerprint' => $fingerprint,
        'content_digest' => $digest,
        'versions' => [],
        'changes' => [
            'summary' => 'Initial capture',
            'keywords_added' => [],
            'keywords_removed' => [],
            'entities_added' => [],
            'entities_removed' => [],
            'length_delta' => strlen($data['content']),
        ],
        'revision' => 1,
        'discovery' => [
            'seed' => true,
            'first_seen_at' => $timestamp,
            'last_seen_at' => $timestamp,
            'sources' => [],
            'total_sources' => 0,
            'unique_source_domains' => 0,
            'schedule' => [
                'total_runs' => 1,
                'last_scheduled_at' => $timestamp,
                'next_due_at' => $timestamp,
                'history' => [],
            ],
        ],
        'ranking' => [
            'page_authority' => 0.5,
            'domain_authority' => 0.5,
            'inbound_links' => 0,
            'unique_sources' => 0,
        ],
    ];
}

$refiner = new TextRefiner();
$now = date(DATE_ATOM);

$rawEntries = [
    [
        'url' => 'https://example.com/ai-healthcare',
        'normalized_url' => 'https://example.com/ai-healthcare',
        'title' => 'Quantum AI boosts medical imaging',
        'summary' => 'Medical imaging improved by quantum AI systems.',
        'content' => 'Researchers at MedTech Labs built quantum AI for radiology diagnostics.',
        'topics' => ['healthcare', 'ai'],
        'entities' => [
            ['label' => 'MedTech Labs', 'type' => 'company'],
            ['label' => 'radiology', 'type' => 'discipline'],
        ],
        'quality_score' => 82.5,
        'quality_label' => 'High',
        'quality_reasons' => ['In-depth technical coverage.'],
        'ingest' => true,
        'content_type' => 'article',
        'meta_description' => 'Quantum AI diagnostics enter hospitals.',
    ],
    [
        'url' => 'https://example.com/sports-update',
        'normalized_url' => 'https://example.com/sports-update',
        'title' => 'Local team wins championship',
        'summary' => 'Sports update about local team performance.',
        'content' => 'Fans celebrate after the local football club wins the regional championship.',
        'topics' => ['sports'],
        'entities' => [
            ['label' => 'Springfield FC', 'type' => 'team'],
        ],
        'quality_score' => 91.0,
        'quality_label' => 'Exceptional',
        'quality_reasons' => ['Comprehensive event recap.'],
        'ingest' => true,
        'content_type' => 'article',
        'meta_description' => 'Celebrations for Springfield FC supporters.',
    ],
];

$storedEntries = array_map(static fn(array $entry) => buildStoredEntry($entry, $refiner, $now), $rawEntries);

$storage = sys_get_temp_dir() . '/search_history_' . bin2hex(random_bytes(4)) . '.json';
$crawler = new HiddenCrawler($storage, new NullScraper(), $refiner);
file_put_contents($storage, json_encode($storedEntries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

$service = new NewsSearchService($crawler, null, $refiner);

$crawler->history();
$result = $service->search('quantum healthcare ai', ['limit' => 2]);
$results = $result['results'] ?? [];

if (count($results) < 1) {
    throw new RuntimeException('Expected at least one result from stub search.');
}

$first = $results[0];
if (($first['url'] ?? '') !== 'https://example.com/ai-healthcare') {
    throw new RuntimeException('Semantic boost should prioritise the healthcare article for the AI query.');
}

if (!isset($first['semantic_score']) || (float) $first['semantic_score'] <= 0.0) {
    throw new RuntimeException('Search results should surface a positive semantic score for relevant entries.');
}

if (count($results) > 1) {
    $second = $results[1];
    if ((float) ($first['semantic_score'] ?? 0.0) <= (float) ($second['semantic_score'] ?? 0.0)) {
        throw new RuntimeException('Semantic score should be higher for the query-aligned document.');
    }
}
