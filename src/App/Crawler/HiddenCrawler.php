<?php

declare(strict_types=1);

namespace App\Crawler;

use App\Scraping\ScrapeResult;
use App\Scraping\ScraperInterface;
use App\Scraping\WebScraper;
use App\Text\TextRefiner;
use RuntimeException;

use function array_map;
use function array_merge;
use function array_slice;
use function array_unique;
use function array_values;
use function count;
use function dirname;
use function filter_var;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function implode;
use function is_array;
use function is_dir;
use function in_array;
use function json_decode;
use function json_encode;
use function max;
use function mb_strlen;
use function mb_substr;
use function mb_strtolower;
use function mkdir;
use function preg_match;
use function preg_replace;
use function date;
use function parse_url;
use function round;
use function str_contains;
use function str_ends_with;
use function trim;
use function usort;

use const DATE_ATOM;
use const FILTER_VALIDATE_URL;

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

    private const SOURCE_BASELINE = 0.52;

    /**
     * @var array<string, float>
     */
    private const SOURCE_QUALITY = [
        'bloomberg.com' => 0.98,
        'reuters.com' => 0.96,
        'ft.com' => 0.95,
        'bbc.co.uk' => 0.95,
        'bbc.com' => 0.95,
        'wsj.com' => 0.94,
        'nytimes.com' => 0.93,
        'cnbc.com' => 0.9,
        'apnews.com' => 0.9,
        'washingtonpost.com' => 0.9,
        'marketwatch.com' => 0.88,
        'fortune.com' => 0.86,
        'forbes.com' => 0.78,
        'seekingalpha.com' => 0.82,
        'investing.com' => 0.82,
        'axios.com' => 0.82,
        'techcrunch.com' => 0.82,
        'theverge.com' => 0.78,
        'engadget.com' => 0.74,
        'fool.com' => 0.74,
        'npr.org' => 0.85,
        'financialpost.com' => 0.8,
        'thestreet.com' => 0.76,
        'semafor.com' => 0.74,
        'yahoo.com' => 0.72,
    ];

    /**
     * @var array<int, string>
     */
    private const LOW_CONFIDENCE_PATTERNS = [
        'blogspot.',
        'wordpress',
        'medium.com',
        'substack.com',
        'tumblr.com',
        'reddit.com',
        'weebly.com',
        'notion.site',
        'github.io',
        't.me',
    ];

    private const QUALITY_THRESHOLDS = [
        85 => 'Exceptional',
        70 => 'High',
        50 => 'Medium',
        0 => 'Low',
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

        $meta = $scraped->meta();
        $thumbnail = $this->normaliseThumbnail($scraped->thumbnail());

        $entry = [
            'url' => $scraped->url(),
            'title' => $scraped->title(),
            'fetched_at' => date(DATE_ATOM),
            'preview' => $scraped->preview(240),
            'keywords' => $keywords,
            'summary' => mb_substr((string) ($analysis['rewritten'] ?? ''), 0, 3200),
            'entities' => $entities,
            'links' => array_slice($scraped->links(), 0, 20),
            'thumbnail' => $thumbnail,
            'site_name' => is_array($meta) ? (string) ($meta['site_name'] ?? '') : '',
            'meta_description' => is_array($meta) ? (string) ($meta['description'] ?? '') : '',
            'language' => is_array($meta) ? (string) ($meta['language'] ?? '') : '',
            'canonical_url' => is_array($meta) ? (string) ($meta['canonical'] ?? '') : '',
            'published_at' => is_array($meta) ? (string) ($meta['published_at'] ?? '') : '',
            'character_count' => $scraped->characterCount(),
            'paragraph_count' => $scraped->paragraphCount(),
        ];

        $classification = $this->classifyEntry($entry);
        $quality = $this->evaluateQuality($entry, $scraped);
        $recommendations = $this->recommendSources($scraped, (string) ($quality['source_domain'] ?? ''));

        return array_merge($entry, $classification, $quality, [
            'recommended_sources' => $recommendations,
        ]);
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

    /**
     * @param array<string, mixed> $entry
     *
     * @return array{
     *     quality_score: float,
     *     quality_label: string,
     *     quality_reasons: array<int, string>,
     *     ingest: bool,
     *     source_domain: string,
     *     source_site_name: string,
     *     source_language: string,
     *     source_published_at: string
     * }
     */
    private function evaluateQuality(array $entry, ScrapeResult $scraped): array
    {
        $meta = $scraped->meta();
        $domain = $this->extractDomain($scraped->url());
        $score = 0.0;
        $reasons = [];

        $base = $this->baseScoreForDomain($domain);
        $score += $base * 35.0;

        if ($domain === '') {
            $reasons[] = 'Domain not detected – relying on content only.';
        } elseif ($base >= 0.85) {
            $reasons[] = 'Highly trusted newsroom (' . $domain . ').';
            $score += 6.0;
        } elseif ($base >= 0.7) {
            $reasons[] = 'Recognised publisher (' . $domain . ').';
        } else {
            $reasons[] = 'Limited trust signals for ' . $domain . '.';
        }

        $characters = max(0, (int) ($entry['character_count'] ?? $scraped->characterCount()));
        $words = (int) round($characters / 5);
        if ($characters >= 6000) {
            $score += 24.0;
            $reasons[] = 'In-depth coverage (~' . $words . ' words).';
        } elseif ($characters >= 3200) {
            $score += 19.0;
            $reasons[] = 'Detailed article (~' . $words . ' words).';
        } elseif ($characters >= 1600) {
            $score += 12.0;
            $reasons[] = 'Standard length article (~' . $words . ' words).';
        } elseif ($characters >= 800) {
            $score += 7.0;
        } else {
            $score -= 10.0;
            $reasons[] = 'Very short copy detected (' . $words . ' words).';
        }

        $paragraphs = (int) ($entry['paragraph_count'] ?? $scraped->paragraphCount());
        if ($paragraphs >= 8) {
            $score += 6.0;
        } elseif ($paragraphs <= 2) {
            $score -= 6.0;
        }

        $entityCount = count(is_array($entry['entities'] ?? null) ? $entry['entities'] : []);
        if ($entityCount >= 8) {
            $score += 12.0;
            $reasons[] = 'Rich entity extraction (' . $entityCount . ' entities).';
        } elseif ($entityCount >= 4) {
            $score += 8.0;
        } elseif ($entityCount === 0) {
            $score -= 5.0;
            $reasons[] = 'No entities identified.';
        }

        $keywordCount = count(is_array($entry['keywords'] ?? null) ? $entry['keywords'] : []);
        if ($keywordCount >= 8) {
            $score += 8.0;
        } elseif ($keywordCount >= 4) {
            $score += 5.0;
        } elseif ($keywordCount === 0) {
            $score -= 3.0;
        }

        $topics = is_array($entry['topics'] ?? null) ? $entry['topics'] : [];
        $topicCount = count($topics);
        if ($topicCount >= 3) {
            $score += 4.0;
        } elseif ($topicCount === 0) {
            $score -= 4.0;
            $reasons[] = 'No thematic topics extracted.';
        }

        if (($entry['category'] ?? '') === 'financial') {
            $score += 4.0;
            $reasons[] = 'Financial focus detected.';
        }

        if ($this->isLowConfidenceDomain($domain)) {
            $score -= 12.0;
            $reasons[] = 'Domain flagged for manual review.';
        }

        if (is_array($meta) && is_string($meta['description'] ?? null) && trim((string) $meta['description']) !== '') {
            $score += 2.0;
        }

        $score = max(0.0, min(100.0, $score));
        $label = $this->labelForScore($score);
        $reasons = array_values(array_unique($reasons));

        return [
            'quality_score' => round($score, 1),
            'quality_label' => $label,
            'quality_reasons' => $reasons,
            'ingest' => $score >= 60.0,
            'source_domain' => $domain,
            'source_site_name' => is_array($meta) ? (string) ($meta['site_name'] ?? '') : '',
            'source_language' => is_array($meta) ? (string) ($meta['language'] ?? '') : '',
            'source_published_at' => is_array($meta) ? (string) ($meta['published_at'] ?? '') : '',
        ];
    }

    private function baseScoreForDomain(string $domain): float
    {
        if ($domain === '') {
            return self::SOURCE_BASELINE;
        }

        $lower = mb_strtolower($domain);

        foreach (self::SOURCE_QUALITY as $knownDomain => $score) {
            if ($lower === $knownDomain || str_ends_with($lower, '.' . $knownDomain)) {
                return $score;
            }
        }

        return self::SOURCE_BASELINE;
    }

    private function extractDomain(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return '';
        }

        $host = mb_strtolower($host);
        $clean = preg_replace('/^www\d*\./', '', $host);
        if (!is_string($clean)) {
            $clean = $host;
        }

        return $clean;
    }

    private function labelForScore(float $score): string
    {
        foreach (self::QUALITY_THRESHOLDS as $threshold => $label) {
            if ($score >= $threshold) {
                return $label;
            }
        }

        return 'Low';
    }

    private function isLowConfidenceDomain(string $domain): bool
    {
        if ($domain === '') {
            return false;
        }

        $lower = mb_strtolower($domain);
        foreach (self::LOW_CONFIDENCE_PATTERNS as $pattern) {
            if ($pattern !== '' && str_contains($lower, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, array{url: string, domain: string, trust_score: float}>
     */
    private function recommendSources(ScrapeResult $scraped, string $currentDomain): array
    {
        $links = $scraped->links();
        if ($links === []) {
            return [];
        }

        $scored = [];
        foreach ($links as $link) {
            if (!is_string($link) || trim($link) === '') {
                continue;
            }

            $domain = $this->extractDomain($link);
            if ($domain === '' || $domain === $currentDomain) {
                continue;
            }

            $trust = $this->baseScoreForDomain($domain);
            if ($trust < 0.6) {
                continue;
            }

            if (isset($scored[$domain]) && $scored[$domain]['trust_score'] >= $trust) {
                continue;
            }

            $scored[$domain] = [
                'url' => $link,
                'domain' => $domain,
                'trust_score' => round($trust, 2),
            ];
        }

        $ranked = array_values($scored);
        usort($ranked, static fn(array $a, array $b): int => $b['trust_score'] <=> $a['trust_score']);

        return array_slice($ranked, 0, 5);
    }

    private function normaliseThumbnail(?string $thumbnail): ?string
    {
        if ($thumbnail === null) {
            return null;
        }

        $value = trim($thumbnail);
        if ($value === '' || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return $value;
    }
}
