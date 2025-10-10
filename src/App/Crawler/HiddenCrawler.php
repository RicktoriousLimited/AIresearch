<?php

declare(strict_types=1);

namespace App\Crawler;

use App\Scraping\ScraperInterface;
use App\Scraping\WebScraper;
use App\Text\TextRefiner;
use RuntimeException;

use function array_map;
use function array_merge;
use function array_slice;
use function array_unique;
use function array_values;
use function dirname;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function implode;
use function is_array;
use function is_dir;
use function in_array;
use function json_decode;
use function json_encode;
use function mb_substr;
use function mb_strtolower;
use function mkdir;
use function preg_match;
use function date;
use function str_contains;
use function trim;

use const DATE_ATOM;

final class HiddenCrawler
{
    private const FINANCIAL_TERMS = [
        'stock',
        'stocks',
        'share',
        'shares',
        'equity',
        'ipo',
        'ipos',
        'market',
        'markets',
        'nasdaq',
        'dow jones',
        's&p',
        'earnings',
        'revenue',
        'profit',
        'profits',
        'loss',
        'losses',
        'dividend',
        'valuation',
        'funding',
        'venture capital',
        'investor',
        'investors',
        'hedge fund',
        'merger',
        'acquisition',
        'm&a',
        'etf',
        'bond',
        'bonds',
        'treasury',
        'interest rate',
        'federal reserve',
        'central bank',
        'crypto',
        'bitcoin',
        'token sale',
        'spac',
        'listing',
    ];

    private const FINANCIAL_ENTITY_SUFFIXES = [
        'inc',
        'corp',
        'corporation',
        'company',
        'co.',
        'ltd',
        'llc',
        'holdings',
        'group',
        'plc',
        's.a.',
        's.a',
        'ag',
    ];

    /**
     * Topic hint map used to assign additional labels to crawled entries.
     *
     * @var array<string, array<int, string>>
     */
    private const TOPIC_KEYWORDS = [
        'Technology' => ['technology', 'tech', 'software', 'ai', 'artificial intelligence', 'robot', 'cloud', 'startup', 'computing', 'internet', 'app', 'cybersecurity', 'semiconductor', 'chip', 'quantum'],
        'Finance' => ['bank', 'banking', 'interest rate', 'inflation', 'loan', 'credit', 'debt', 'mortgage', 'treasury', 'dollar', 'currency', 'exchange'],
        'Energy' => ['energy', 'oil', 'gas', 'renewable', 'solar', 'wind', 'power', 'battery', 'nuclear'],
        'Healthcare' => ['health', 'medical', 'pharma', 'drug', 'vaccine', 'hospital', 'biotech', 'clinical'],
        'Geopolitics' => ['election', 'government', 'policy', 'war', 'conflict', 'diplomatic', 'sanction', 'president', 'parliament'],
        'Climate' => ['climate', 'weather', 'warming', 'emission', 'carbon', 'wildfire', 'flood', 'storm'],
        'Consumer' => ['retail', 'consumer', 'shopping', 'e-commerce', 'sales', 'fashion', 'brand'],
        'Transportation' => ['transport', 'airline', 'flight', 'rail', 'automotive', 'car', 'vehicle', 'shipping', 'logistics'],
        'Science' => ['research', 'science', 'space', 'nasa', 'study', 'scientist', 'laboratory', 'experiment'],
        'Sports' => ['sport', 'sports', 'tournament', 'league', 'match', 'olympic'],
        'Culture' => ['culture', 'movie', 'film', 'music', 'art', 'festival', 'entertainment'],
    ];

    private ScraperInterface $scraper;

    private TextRefiner $refiner;

    private string $storagePath;

    public function __construct(string $storagePath, ?ScraperInterface $scraper = null, ?TextRefiner $refiner = null)
    {
        $this->storagePath = $storagePath;
        $this->scraper = $scraper ?? new WebScraper();
        $this->refiner = $refiner ?? new TextRefiner();

        $directory = dirname($storagePath);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        if (!file_exists($storagePath)) {
            file_put_contents($storagePath, json_encode([]));
        }
    }

    /**
     * @param array<int, string> $targets
     *
     * @return array<int, array<string, mixed>>
     */
    public function crawl(array $targets): array
    {
        $entries = [];
        foreach ($targets as $target) {
            if (!is_string($target)) {
                continue;
            }

            $url = trim($target);
            if ($url === '') {
                continue;
            }

            $entries[] = $this->crawlUrl($url);
        }

        if ($entries === []) {
            return [];
        }

        $history = $this->history();
        $history = array_values(array_merge($entries, $history));
        $history = array_slice($history, 0, 50);

        $this->store($history);

        return $entries;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function history(): array
    {
        $contents = file_get_contents($this->storagePath);
        if ($contents === false) {
            return [];
        }

        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            return [];
        }

        return $decoded;
    }

    private function store(array $entries): void
    {
        $result = file_put_contents(
            $this->storagePath,
            json_encode(array_values($entries), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        if ($result === false) {
            throw new RuntimeException('Unable to persist crawler history.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function crawlUrl(string $url): array
    {
        try {
            $scraped = $this->scraper->scrape($url);
        } catch (RuntimeException $exception) {
            return [
                'url' => $url,
                'fetched_at' => date(DATE_ATOM),
                'error' => $exception->getMessage(),
            ];
        }

        $analysis = $this->refiner->analyseDocument($scraped->text());

        $keywords = $this->formatKeywords($analysis['keywords'] ?? []);
        $entities = $this->extractEntities($analysis['analytics']['entities']['top_entities'] ?? []);

        $entry = [
            'url' => $scraped->url(),
            'title' => $scraped->title(),
            'fetched_at' => date(DATE_ATOM),
            'preview' => $scraped->preview(240),
            'keywords' => $keywords,
            'summary' => mb_substr((string) ($analysis['rewritten'] ?? ''), 0, 3200),
            'entities' => $entities,
            'links' => $scraped->toMetaArray()['links'],
        ];

        $classification = $this->classifyEntry($entry);

        return array_merge($entry, $classification);
    }

    /**
     * @param array<int, array{token: string, count: int}> $keywords
     *
     * @return array<int, array{token: string, count: int}>
     */
    private function formatKeywords(array $keywords): array
    {
        $keywords = array_values(array_map(
            static function (array $keyword): array {
                return [
                    'token' => (string) ($keyword['token'] ?? ''),
                    'count' => (int) ($keyword['count'] ?? 0),
                ];
            },
            $keywords
        ));

        return array_slice($keywords, 0, 10);
    }

    /**
     * @param array<int, array<string, mixed>> $entities
     *
     * @return array<int, array<string, mixed>>
     */
    private function extractEntities(array $entities): array
    {
        $entities = array_values(array_map(
            static function (array $entity): array {
                return [
                    'label' => (string) ($entity['label'] ?? ''),
                    'type' => (string) ($entity['type'] ?? ''),
                    'score' => (float) ($entity['score'] ?? 0.0),
                ];
            },
            $entities
        ));

        return array_slice($entities, 0, 10);
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return array{category: string, topics: array<int, string>}
     */
    private function classifyEntry(array $entry): array
    {
        $originalParts = [];
        $lowerParts = [];

        foreach (['title', 'preview', 'summary'] as $field) {
            if (!isset($entry[$field])) {
                continue;
            }

            $value = (string) $entry[$field];
            if ($value === '') {
                continue;
            }

            $originalParts[] = $value;
            $lowerParts[] = mb_strtolower($value);
        }

        foreach ($entry['keywords'] ?? [] as $keyword) {
            $token = (string) ($keyword['token'] ?? '');
            if ($token !== '') {
                $originalParts[] = $token;
                $lowerParts[] = mb_strtolower($token);
            }
        }

        foreach ($entry['entities'] ?? [] as $entity) {
            $label = (string) ($entity['label'] ?? '');
            if ($label !== '') {
                $originalParts[] = $label;
                $lowerParts[] = mb_strtolower($label);
            }
        }

        $originalText = trim(implode(' ', $originalParts));
        $lowerText = trim(implode(' ', $lowerParts));

        $isFinancial = $this->isFinancialStory($lowerText, $originalText, $entry);

        $topics = $this->extractTopics($lowerText);

        if ($isFinancial && !in_array('Markets', $topics, true)) {
            array_unshift($topics, 'Markets');
        }

        if (!$isFinancial && $topics === []) {
            $topics[] = 'World';
        }

        return [
            'category' => $isFinancial ? 'financial' : 'global',
            'topics' => array_values(array_unique($topics)),
        ];
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function isFinancialStory(string $lowerText, string $originalText, array $entry): bool
    {
        if ($lowerText !== '') {
            foreach (self::FINANCIAL_TERMS as $term) {
                if ($term !== '' && str_contains($lowerText, $term)) {
                    return true;
                }
            }
        }

        foreach ($entry['keywords'] ?? [] as $keyword) {
            $token = mb_strtolower((string) ($keyword['token'] ?? ''));
            if ($token === '') {
                continue;
            }

            if (str_contains($token, 'stock') || str_contains($token, 'market') || str_contains($token, 'ipo')) {
                return true;
            }
        }

        foreach ($entry['entities'] ?? [] as $entity) {
            $labelOriginal = (string) ($entity['label'] ?? '');
            $label = mb_strtolower($labelOriginal);
            $type = mb_strtolower((string) ($entity['type'] ?? ''));

            if ($label === '') {
                continue;
            }

            foreach (self::FINANCIAL_ENTITY_SUFFIXES as $suffix) {
                if ($suffix !== '' && str_contains($label, $suffix)) {
                    return true;
                }
            }

            if ($type !== '' && in_array($type, ['org', 'organisation', 'organization', 'company', 'corporation'], true)) {
                return true;
            }

            if ($labelOriginal !== '' && preg_match('/\b[A-Z]{2,5}(?:\.[A-Z]{1,2})?\b/u', $labelOriginal) === 1) {
                return true;
            }
        }

        if ($originalText !== '' && preg_match('/\b[A-Z]{2,5}(?:\.[A-Z]{1,2})?\b/u', $originalText) === 1) {
            return true;
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function extractTopics(string $lowerText): array
    {
        if ($lowerText === '') {
            return [];
        }

        $topics = [];

        foreach (self::TOPIC_KEYWORDS as $topic => $terms) {
            foreach ($terms as $term) {
                if ($term === '') {
                    continue;
                }

                if (str_contains($lowerText, $term)) {
                    $topics[] = $topic;
                    break;
                }
            }
        }

        return $topics;
    }
}
