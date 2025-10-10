<?php

declare(strict_types=1);

namespace App\Crawler;

use App\Scraping\ScrapeResult;
use App\Scraping\ScraperInterface;
use App\Scraping\WebScraper;
use App\KnowledgeGraph\ResearchService;
use App\Text\TextRefiner;
use DateInterval;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

use function array_map;
use function array_merge;
use function array_slice;
use function array_unique;
use function array_values;
use function array_diff;
use function array_keys;
use function array_filter;
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
use function http_build_query;
use function json_decode;
use function json_encode;
use function max;
use function mb_strlen;
use function mb_substr;
use function mb_strtolower;
use function explode;
use function mkdir;
use function preg_match;
use function preg_replace;
use function date;
use function parse_url;
use function parse_str;
use function round;
use function rtrim;
use function str_contains;
use function str_ends_with;
use function strrpos;
use function substr;
use function strtolower;
use function trim;
use function usort;
use function hash;
use function ksort;
use function sprintf;


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

    private const TRACKING_QUERY_PARAMETERS = [
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_term',
        'utm_content',
        'utm_id',
        'gclid',
        'fbclid',
        'mc_cid',
        'mc_eid',
        'mkt_tok',
        'icid',
        'ref',
        'ref_',
        'cmpid',
        'clid',
    ];

    private const MAX_HISTORY_ENTRIES = 50;

    private const MAX_VERSION_HISTORY = 8;

    private const PROGRESS_FILE_SUFFIX = '.progress.json';

    private const MAX_QUEUE_SIZE = 60;

    private const MAX_DISCOVERED_PER_PAGE = 6;

    private const USELESS_LINK_SEGMENTS = [
        'about' => true,
        'account' => true,
        'advert' => true,
        'advertisement' => true,
        'advertising' => true,
        'careers' => true,
        'company' => true,
        'contact' => true,
        'cookies' => true,
        'copyright' => true,
        'faq' => true,
        'feedback' => true,
        'finance' => true,
        'help' => true,
        'home' => true,
        'investors' => true,
        'legal' => true,
        'login' => true,
        'logout' => true,
        'menu' => true,
        'newsletter' => true,
        'privacy' => true,
        'register' => true,
        'settings' => true,
        'signin' => true,
        'signout' => true,
        'signup' => true,
        'subscribe' => true,
        'support' => true,
        'terms' => true,
    ];

    private const DISCARDED_KEYWORDS = [
        'home' => true,
        'menu' => true,
        'privacy' => true,
        'terms' => true,
        'subscribe' => true,
        'account' => true,
        'contact' => true,
        'login' => true,
        'signup' => true,
        'copyright' => true,
        'navigation' => true,
        'newsletter' => true,
        'cookie' => true,
    ];

    private ScraperInterface $scraper;

    private TextRefiner $refiner;

    private ?ResearchService $graphService;

    private string $storagePath;

    private string $progressPath;

    public function __construct(
        string $storagePath,
        ?ScraperInterface $scraper = null,
        ?TextRefiner $refiner = null,
        ?ResearchService $graphService = null
    )
    {
        $this->storagePath = $storagePath;
        $this->scraper = $scraper ?? new WebScraper();
        $this->refiner = $refiner ?? new TextRefiner();
        $this->graphService = $graphService;
        $this->progressPath = $this->deriveProgressPath($storagePath);

        $directory = dirname($storagePath);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        if (!file_exists($storagePath)) {
            file_put_contents($storagePath, json_encode([]));
        }

        if (!file_exists($this->progressPath)) {
            file_put_contents(
                $this->progressPath,
                json_encode($this->defaultProgressState(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            );
        }
    }

    /**
     * @param array<int, string> $targets
     *
     * @return array<int, array<string, mixed>>
     */
    public function crawl(array $targets, int $maxDepth = 0, int $autoInterval = 0, bool $autoStart = false): array
    {
        $maxDepth = max(0, $maxDepth);
        $autoInterval = max(0, $autoInterval);

        [$queue, $seen, $seedUrls] = $this->buildQueue($targets);

        $historyEntries = $this->loadStoredEntries();
        $history = $this->indexHistoryByUrl($historyEntries);
        $entries = [];

        $startedAt = date(DATE_ATOM);
        $progress = $this->defaultProgressState();
        $progress['status'] = 'initialising';
        $progress['message'] = 'Preparing crawl run.';
        $progress['started_at'] = $startedAt;
        $progress['last_updated_at'] = $startedAt;
        $progress['seed_urls'] = $seedUrls;
        $progress['total'] = count($queue);
        $progress['queued'] = count($queue);
        $progress['processed'] = 0;
        $progress['options'] = [
            'depth' => $maxDepth,
            'auto_interval' => $autoInterval,
            'auto_start' => $autoStart,
        ];
        $progress['auto_interval'] = $autoInterval;
        $progress['auto_start'] = $autoStart;
        $progress['errors'] = [];
        $progress['last_result'] = null;
        $this->writeProgress($progress);

        if ($queue === []) {
            $progress['status'] = 'idle';
            $progress['message'] = 'No valid targets were provided.';
            $progress['finished_at'] = $startedAt;
            $progress['last_run_at'] = $startedAt;
            $progress['next_run_due_at'] = $this->computeNextRunAt($autoInterval, $autoStart, $startedAt);
            $progress['last_updated_at'] = date(DATE_ATOM);
            $this->writeProgress($progress);

            return [];
        }

        $processed = 0;
        $discoveredTotal = 0;

        while ($queue !== []) {
            $current = array_shift($queue);
            if (!is_array($current) || !isset($current['url'])) {
                continue;
            }

            $processed++;
            $currentUrl = (string) ($current['url'] ?? '');
            $currentDepth = (int) ($current['depth'] ?? 0);
            $progress['status'] = 'fetching';
            $progress['message'] = 'Fetching ' . $currentUrl;
            $progress['current_url'] = $currentUrl;
            $progress['current_depth'] = $currentDepth;
            $progress['processed'] = $processed - 1;
            $progress['queued'] = count($queue) + 1;
            $progress['last_updated_at'] = date(DATE_ATOM);
            $this->writeProgress($progress);

            [$history, $result, $scraped] = $this->crawlUrl($currentUrl, $history);
            $entries[] = $result;

            $progress['processed'] = $processed;
            $progress['queued'] = count($queue);
            $progress['message'] = 'Processed ' . $processed . ' of ' . max(1, (int) $progress['total']) . ' page(s).';
            $progress['last_result'] = [
                'url' => (string) ($result['url'] ?? $currentUrl),
                'title' => (string) ($result['title'] ?? ''),
                'quality' => isset($result['quality_score']) ? (float) $result['quality_score'] : 0.0,
                'ingested' => !empty($result['graph']['ingested'] ?? false),
                'error' => isset($result['error']) ? (string) $result['error'] : null,
                'content_type' => (string) ($result['content_type'] ?? ''),
                'revision' => (int) ($result['revision'] ?? 0),
            ];

            if (isset($result['error'])) {
                if (!isset($progress['errors']) || !is_array($progress['errors'])) {
                    $progress['errors'] = [];
                }
                $progress['errors'][] = [
                    'url' => $currentUrl,
                    'message' => (string) $result['error'],
                    'occurred_at' => date(DATE_ATOM),
                ];
                if (count($progress['errors']) > 20) {
                    $progress['errors'] = array_slice($progress['errors'], -20);
                }
            }

            if ($scraped !== null && $currentDepth < $maxDepth) {
                $parentDomain = $this->extractDomain($currentUrl);
                $added = $this->enqueueDiscoveredLinks(
                    $queue,
                    $seen,
                    $scraped->links(),
                    $currentDepth + 1,
                    $parentDomain
                );
                if ($added > 0) {
                    $discoveredTotal += $added;
                    $progress['discovered'] = ($progress['discovered'] ?? 0) + $added;
                    $progress['total'] = ($progress['total'] ?? 0) + $added;
                    $progress['queued'] = count($queue);
                    $progress['message'] = 'Discovered ' . $discoveredTotal . ' additional page(s).';
                }
            }

            $progress['last_updated_at'] = date(DATE_ATOM);
            $this->writeProgress($progress);
        }

        if ($entries === []) {
            $progress['status'] = 'idle';
            $progress['message'] = 'No entries were processed.';
        } else {
            $this->storeHistory($history);
            $progress['status'] = 'idle';
            $progress['message'] = 'Idle';
        }

        $finishedAt = date(DATE_ATOM);
        $progress['finished_at'] = $finishedAt;
        $progress['last_run_at'] = $finishedAt;
        $progress['current_url'] = null;
        $progress['current_depth'] = 0;
        $progress['queued'] = 0;
        $progress['next_run_due_at'] = $this->computeNextRunAt($autoInterval, $autoStart, $finishedAt);
        $progress['last_updated_at'] = $finishedAt;
        $this->writeProgress($progress);

        return $entries;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function history(): array
    {
        $entries = $this->loadStoredEntries();
        foreach ($entries as $index => $entry) {
            $entries[$index]['unchanged'] = false;
        }

        return $entries;
    }

    /**
     * @return array<string, mixed>
     */
    public function progress(): array
    {
        return $this->readProgress();
    }

    private function deriveProgressPath(string $storagePath): string
    {
        $suffixPosition = strrpos($storagePath, '.json');
        if ($suffixPosition !== false) {
            return substr($storagePath, 0, $suffixPosition) . self::PROGRESS_FILE_SUFFIX;
        }

        return $storagePath . self::PROGRESS_FILE_SUFFIX;
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultProgressState(): array
    {
        $now = date(DATE_ATOM);

        return [
            'status' => 'idle',
            'message' => 'Idle',
            'started_at' => null,
            'finished_at' => null,
            'last_run_at' => null,
            'last_updated_at' => $now,
            'processed' => 0,
            'total' => 0,
            'queued' => 0,
            'current_url' => null,
            'current_depth' => 0,
            'seed_urls' => [],
            'discovered' => 0,
            'last_result' => null,
            'errors' => [],
            'auto_interval' => 0,
            'auto_start' => false,
            'options' => [
                'depth' => 0,
                'auto_interval' => 0,
                'auto_start' => false,
            ],
            'next_run_due_at' => null,
        ];
    }

    /**
     * @param array<string, mixed> $state
     */
    private function writeProgress(array $state): void
    {
        file_put_contents(
            $this->progressPath,
            json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function readProgress(): array
    {
        if (!file_exists($this->progressPath)) {
            return $this->defaultProgressState();
        }

        $contents = file_get_contents($this->progressPath);
        if (!is_string($contents) || trim($contents) === '') {
            return $this->defaultProgressState();
        }

        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            return $this->defaultProgressState();
        }

        return $decoded;
    }

    /**
     * @param array<int, string> $targets
     *
     * @return array{0: array<int, array<string, mixed>>, 1: array<string, bool>, 2: array<int, string>}
     */
    private function buildQueue(array $targets): array
    {
        $queue = [];
        $seen = [];
        $seeds = [];

        foreach ($targets as $target) {
            if (!is_string($target)) {
                continue;
            }

            $normalised = $this->normaliseSeedTarget($target);
            if ($normalised === null) {
                continue;
            }

            $key = $this->queueKey($normalised);
            if (isset($seen[$key])) {
                continue;
            }

            $domain = $this->extractDomain($normalised);
            $priority = $this->baseScoreForDomain($domain) + 0.25;

            $queue[] = [
                'url' => $normalised,
                'depth' => 0,
                'priority' => $priority,
                'seed' => true,
            ];
            $seen[$key] = true;
            $seeds[] = $normalised;
        }

        $this->sortQueueByPriority($queue);

        if (count($queue) > self::MAX_QUEUE_SIZE) {
            $queue = array_slice($queue, 0, self::MAX_QUEUE_SIZE);
        }

        return [$queue, $seen, $seeds];
    }

    private function normaliseSeedTarget(string $target): ?string
    {
        $candidate = trim($target);
        if ($candidate === '') {
            return null;
        }

        if (!preg_match('/^https?:\/\//i', $candidate)) {
            $candidate = 'https://' . $candidate;
        }

        if (!filter_var($candidate, FILTER_VALIDATE_URL)) {
            return null;
        }

        return $candidate;
    }

    private function queueKey(string $url): string
    {
        $normalised = $this->normaliseStoredUrl($url);
        if ($normalised === '') {
            $normalised = mb_strtolower(trim($url));
        }

        return $normalised;
    }

    /**
     * @param array<int, array<string, mixed>> $queue
     */
    private function sortQueueByPriority(array &$queue): void
    {
        usort($queue, static function (array $left, array $right): int {
            $leftPriority = (float) ($left['priority'] ?? 0.0);
            $rightPriority = (float) ($right['priority'] ?? 0.0);

            return $rightPriority <=> $leftPriority;
        });
    }

    /**
     * @param array<int, array<string, mixed>> $queue
     * @param array<string, bool> $seen
     * @param array<int, string> $links
     */
    private function enqueueDiscoveredLinks(
        array &$queue,
        array &$seen,
        array $links,
        int $depth,
        string $parentDomain
    ): int {
        $depth = max(0, $depth);

        $filtered = $this->filterLinks($links);
        if ($filtered === []) {
            return 0;
        }

        $added = 0;

        foreach ($filtered as $normalised) {
            if (count($queue) >= self::MAX_QUEUE_SIZE) {
                break;
            }

            $key = $this->queueKey($normalised);
            if (isset($seen[$key])) {
                continue;
            }

            $domain = $this->extractDomain($normalised);
            if ($this->isLowConfidenceDomain($domain)) {
                continue;
            }

            $priority = $this->baseScoreForDomain($domain);
            if ($domain === $parentDomain) {
                $priority += 0.1;
            }

            if ($priority < self::SOURCE_BASELINE) {
                continue;
            }

            $queue[] = [
                'url' => $normalised,
                'depth' => $depth,
                'priority' => $priority,
                'seed' => false,
            ];
            $seen[$key] = true;
            $added++;

            if ($added >= self::MAX_DISCOVERED_PER_PAGE) {
                break;
            }
        }

        if ($added > 0) {
            $this->sortQueueByPriority($queue);
        }

        return $added;
    }

    /**
     * @param array<int, string> $links
     *
     * @return array<int, string>
     */
    private function filterLinks(array $links): array
    {
        $filtered = [];

        foreach ($links as $link) {
            if (!is_string($link)) {
                continue;
            }

            $candidate = trim($link);
            if ($candidate === '') {
                continue;
            }

            $normalised = $this->normaliseSeedTarget($candidate);
            if ($normalised === null) {
                continue;
            }

            if (!$this->isUsefulLink($normalised)) {
                continue;
            }

            $filtered[] = $normalised;
        }

        return array_values(array_slice(array_unique($filtered), 0, 40));
    }

    private function isUsefulLink(string $url): bool
    {
        $parts = parse_url($url);
        if ($parts === false) {
            return false;
        }

        $path = isset($parts['path']) ? mb_strtolower((string) $parts['path']) : '';
        if ($path !== '') {
            $segments = array_filter(explode('/', $path));
            foreach ($segments as $segment) {
                $segment = trim($segment);
                if ($segment === '') {
                    continue;
                }

                if (isset(self::USELESS_LINK_SEGMENTS[$segment])) {
                    return false;
                }
            }
        }

        $query = isset($parts['query']) ? mb_strtolower((string) $parts['query']) : '';
        if ($query !== '') {
            foreach (self::USELESS_LINK_SEGMENTS as $segment => $flag) {
                if ($flag && str_contains($query, $segment)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function computeNextRunAt(int $autoInterval, bool $autoStart, string $from): ?string
    {
        if (!$autoStart || $autoInterval <= 0) {
            return null;
        }

        try {
            $origin = new DateTimeImmutable($from);
        } catch (Throwable $exception) {
            return null;
        }

        try {
            $interval = new DateInterval('PT' . $autoInterval . 'M');
            return $origin->add($interval)->format(DATE_ATOM);
        } catch (Throwable $exception) {
            return null;
        }
    }

    /**
     * @param array<string, array<string, mixed>> $history
     *
     * @return array{0: array<string, array<string, mixed>>, 1: array<string, mixed>}
     */
    /**
     * @param array<string, array<string, mixed>> $history
     *
     * @return array{0: array<string, array<string, mixed>>, 1: array<string, mixed>, 2: ?ScrapeResult}
     */
    private function crawlUrl(string $url, array $history): array
    {
        try {
            $scraped = $this->scraper->scrape($url);
        } catch (RuntimeException $exception) {
            $entry = [
                'url' => $url,
                'fetched_at' => date(DATE_ATOM),
                'last_checked_at' => date(DATE_ATOM),
                'error' => $exception->getMessage(),
                'content_type' => 'error',
                'revision' => null,
                'versions' => [],
                'changes' => $this->buildNoChangeSummary(),
                'unchanged' => false,
            ];

            return [$history, $entry, null];
        }

        $analysis = $this->refiner->analyseDocument($scraped->text());

        $keywords = $this->formatKeywords($analysis['keywords'] ?? []);
        $entities = $this->extractEntities($analysis['analytics']['entities']['top_entities'] ?? []);
        $filteredLinks = $this->filterLinks($scraped->links());

        $meta = $scraped->meta();
        $thumbnail = $this->normaliseThumbnail($scraped->thumbnail());
        $fetchedAt = date(DATE_ATOM);
        $previewRaw = $scraped->preview(240);
        $preview = $this->refiner->cleanDocument($previewRaw);
        if ($preview === '') {
            $preview = $previewRaw;
        }
        $summaryRaw = (string) ($analysis['rewritten'] ?? '');
        $summaryClean = $this->refiner->cleanDocument($summaryRaw);
        if ($summaryClean === '') {
            $summaryClean = $summaryRaw;
        }

        $entry = [
            'url' => $scraped->url(),
            'title' => $scraped->title(),
            'fetched_at' => $fetchedAt,
            'last_checked_at' => $fetchedAt,
            'preview' => $preview,
            'keywords' => $keywords,
            'summary' => mb_substr($summaryClean, 0, 3200),
            'entities' => $entities,
            'links' => array_slice($filteredLinks, 0, 20),
            'thumbnail' => $thumbnail,
            'site_name' => is_array($meta) ? (string) ($meta['site_name'] ?? '') : '',
            'meta_description' => is_array($meta) ? (string) ($meta['description'] ?? '') : '',
            'language' => is_array($meta) ? (string) ($meta['language'] ?? '') : '',
            'canonical_url' => is_array($meta) ? (string) ($meta['canonical'] ?? '') : '',
            'published_at' => is_array($meta) ? (string) ($meta['published_at'] ?? '') : '',
            'character_count' => $scraped->characterCount(),
            'paragraph_count' => $scraped->paragraphCount(),
            'narrative' => is_array($analysis['analytics'] ?? null) ? $analysis['analytics'] : [],
        ];

        $classification = $this->classifyEntry($entry);
        $quality = $this->evaluateQuality($entry, $scraped);
        $recommendations = $this->recommendSources($filteredLinks, (string) ($quality['source_domain'] ?? ''));

        $graphContext = ['ingested' => false];
        if ($this->graphService !== null && ($quality['ingest'] ?? false)) {
            try {
                $graphResult = $this->graphService->ingestScrapeResult($scraped);
                $graphContext['ingested'] = true;
                $graphContext['source'] = [
                    'url' => (string) ($graphResult['source']['url'] ?? $scraped->url()),
                    'title' => (string) ($graphResult['source']['title'] ?? $scraped->title()),
                ];
                if (isset($graphResult['graph']['summary']['generated_at'])) {
                    $graphContext['graph_updated_at'] = (string) $graphResult['graph']['summary']['generated_at'];
                }
            } catch (Throwable $exception) {
                $graphContext['error'] = $exception->getMessage();
            }
        }

        $normalizedUrl = $this->normalisePageUrl($entry['url'], (string) $entry['canonical_url']);
        $contentType = $this->detectContentType($scraped, $entry);
        $fingerprint = $this->fingerprint($scraped);

        $fullEntry = array_merge($entry, $classification, $quality, [
            'recommended_sources' => $recommendations,
            'graph' => $graphContext,
            'normalized_url' => $normalizedUrl,
            'content_type' => $contentType,
            'fingerprint' => $fingerprint,
        ]);

        [$history, $merged] = $this->mergeEntry($fullEntry, $history);

        return [$history, $merged, $scraped];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadStoredEntries(): array
    {
        $contents = file_get_contents($this->storagePath);
        if (!is_string($contents) || trim($contents) === '') {
            return [];
        }

        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            return [];
        }

        $entries = [];
        foreach ($decoded as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $entries[] = $this->upgradeLegacyEntry($entry);
        }

        usort($entries, static function (array $a, array $b): int {
            $left = (string) ($a['fetched_at'] ?? '');
            $right = (string) ($b['fetched_at'] ?? '');

            return $right <=> $left;
        });

        return $entries;
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @return array<string, array<string, mixed>>
     */
    private function indexHistoryByUrl(array $entries): array
    {
        $indexed = [];

        foreach ($entries as $entry) {
            $key = (string) ($entry['normalized_url'] ?? '');
            if ($key === '') {
                $key = $this->normaliseStoredUrl((string) ($entry['canonical_url'] ?? $entry['url'] ?? ''));
            }

            if ($key === '') {
                $key = (string) ($entry['url'] ?? '');
            }

            if ($key === '') {
                continue;
            }

            $indexed[$key] = $entry;
        }

        return $indexed;
    }

    /**
     * @param array<string, array<string, mixed>> $history
     */
    private function storeHistory(array $history): void
    {
        $entries = array_values($history);

        usort($entries, static function (array $a, array $b): int {
            $left = (string) ($a['fetched_at'] ?? '');
            $right = (string) ($b['fetched_at'] ?? '');

            return $right <=> $left;
        });

        $entries = array_slice($entries, 0, self::MAX_HISTORY_ENTRIES);
        $entries = array_map([$this, 'sanitiseEntryForStorage'], $entries);

        $result = file_put_contents(
            $this->storagePath,
            json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        if ($result === false) {
            throw new RuntimeException('Unable to persist crawler history.');
        }
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, array<string, mixed>> $history
     *
     * @return array{0: array<string, array<string, mixed>>, 1: array<string, mixed>}
     */
    private function mergeEntry(array $entry, array $history): array
    {
        $key = (string) ($entry['normalized_url'] ?? $entry['url'] ?? '');
        if ($key === '') {
            $key = $this->normaliseStoredUrl((string) ($entry['url'] ?? ''));
        }

        $entry['versions'] = is_array($entry['versions'] ?? null)
            ? $this->normaliseVersions($entry['versions'])
            : [];
        $entry['changes'] = $this->normaliseChanges($entry['changes'] ?? null);
        $entry['unchanged'] = false;

        if (isset($history[$key])) {
            $existing = $history[$key];
            $existing['changes'] = $this->normaliseChanges($existing['changes'] ?? null);
            $existing['versions'] = $this->normaliseVersions($existing['versions'] ?? []);

            $previousFingerprint = (string) ($existing['fingerprint'] ?? '');
            if ($previousFingerprint === (string) ($entry['fingerprint'] ?? '')) {
                $existing['last_checked_at'] = $entry['last_checked_at'] ?? $entry['fetched_at'] ?? date(DATE_ATOM);
                $history[$key] = $existing;

                $result = $existing;
                $result['unchanged'] = true;
                $result['changes'] = $this->buildNoChangeSummary();

                return [$history, $result];
            }

            $entry['revision'] = (int) ($existing['revision'] ?? 1) + 1;
            $entry['versions'] = $this->prependVersion($existing);
            $entry['changes'] = $this->summariseChanges($existing, $entry);
        } else {
            $entry['revision'] = 1;
            $entry['versions'] = [];
            $entry['changes'] = $this->buildInitialChangeSummary($entry);
        }

        $history[$key] = $entry;

        return [$history, $entry];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildNoChangeSummary(): array
    {
        return [
            'summary' => 'No content changes detected.',
            'keywords_added' => [],
            'keywords_removed' => [],
            'entities_added' => [],
            'entities_removed' => [],
            'length_delta' => 0,
        ];
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return array<string, mixed>
     */
    private function buildInitialChangeSummary(array $entry): array
    {
        $keywords = $this->indexKeywords($entry['keywords'] ?? []);
        $entities = $this->indexEntities($entry['entities'] ?? []);

        return [
            'summary' => 'Initial capture',
            'keywords_added' => array_values($keywords),
            'keywords_removed' => [],
            'entities_added' => array_values($entities),
            'entities_removed' => [],
            'length_delta' => (int) ($entry['character_count'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $previous
     * @param array<string, mixed> $current
     *
     * @return array<string, mixed>
     */
    private function summariseChanges(array $previous, array $current): array
    {
        $previousKeywords = $this->indexKeywords($previous['keywords'] ?? []);
        $currentKeywords = $this->indexKeywords($current['keywords'] ?? []);
        $previousEntities = $this->indexEntities($previous['entities'] ?? []);
        $currentEntities = $this->indexEntities($current['entities'] ?? []);

        $addedKeywordKeys = array_values(array_diff(array_keys($currentKeywords), array_keys($previousKeywords)));
        $removedKeywordKeys = array_values(array_diff(array_keys($previousKeywords), array_keys($currentKeywords)));
        $keywordsAdded = array_map(static fn(string $key) => $currentKeywords[$key], $addedKeywordKeys);
        $keywordsRemoved = array_map(static fn(string $key) => $previousKeywords[$key], $removedKeywordKeys);

        $addedEntityKeys = array_values(array_diff(array_keys($currentEntities), array_keys($previousEntities)));
        $removedEntityKeys = array_values(array_diff(array_keys($previousEntities), array_keys($currentEntities)));
        $entitiesAdded = array_map(static fn(string $key) => $currentEntities[$key], $addedEntityKeys);
        $entitiesRemoved = array_map(static fn(string $key) => $previousEntities[$key], $removedEntityKeys);

        $lengthDelta = (int) ($current['character_count'] ?? 0) - (int) ($previous['character_count'] ?? 0);

        $parts = [];

        if (trim((string) ($previous['title'] ?? '')) !== trim((string) ($current['title'] ?? ''))) {
            $parts[] = 'title updated';
        }

        if (trim((string) ($previous['summary'] ?? '')) !== trim((string) ($current['summary'] ?? ''))) {
            $parts[] = 'summary refreshed';
        }

        if ($lengthDelta !== 0) {
            $parts[] = sprintf(
                'length %s by %d characters',
                $lengthDelta > 0 ? 'increased' : 'decreased',
                abs($lengthDelta)
            );
        }

        if ($keywordsAdded !== [] || $keywordsRemoved !== []) {
            $parts[] = 'keywords updated';
        }

        if ($entitiesAdded !== [] || $entitiesRemoved !== []) {
            $parts[] = 'entities updated';
        }

        if (($previous['content_type'] ?? '') !== ($current['content_type'] ?? '')) {
            $parts[] = sprintf('reclassified as %s', (string) ($current['content_type'] ?? 'page'));
        }

        $summary = $parts === [] ? 'Content refreshed.' : ucfirst(implode('; ', $parts)) . '.';

        return [
            'summary' => $summary,
            'keywords_added' => $keywordsAdded,
            'keywords_removed' => $keywordsRemoved,
            'entities_added' => $entitiesAdded,
            'entities_removed' => $entitiesRemoved,
            'length_delta' => $lengthDelta,
        ];
    }

    /**
     * @param array<string, mixed> $existing
     *
     * @return array<int, array<string, mixed>>
     */
    private function prependVersion(array $existing): array
    {
        $versions = $this->normaliseVersions($existing['versions'] ?? []);
        $archive = $this->prepareArchivedVersion($existing);
        array_unshift($versions, $archive);

        return array_slice($versions, 0, self::MAX_VERSION_HISTORY);
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return array<string, mixed>
     */
    private function prepareArchivedVersion(array $entry): array
    {
        $version = $entry;
        unset($version['versions'], $version['normalized_url'], $version['fingerprint']);
        $version['unchanged'] = false;
        $version['changes'] = $this->normaliseChanges($version['changes'] ?? null);

        if (!isset($version['last_checked_at']) && isset($version['fetched_at'])) {
            $version['last_checked_at'] = (string) $version['fetched_at'];
        }

        return $version;
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return array<string, mixed>
     */
    private function sanitiseEntryForStorage(array $entry): array
    {
        $entry['versions'] = $this->normaliseVersions($entry['versions'] ?? []);
        $entry['changes'] = $this->normaliseChanges($entry['changes'] ?? null);
        unset($entry['unchanged']);

        return $entry;
    }

    /**
     * @param array<int, mixed> $versions
     *
     * @return array<int, array<string, mixed>>
     */
    private function normaliseVersions(array $versions): array
    {
        $normalised = [];

        foreach ($versions as $version) {
            if (!is_array($version)) {
                continue;
            }

            $item = $version;
            unset($item['versions']);
            if (isset($item['normalized_url'])) {
                unset($item['normalized_url']);
            }
            $item['changes'] = $this->normaliseChanges($item['changes'] ?? null);
            $item['unchanged'] = false;

            $normalised[] = $item;
        }

        return array_slice($normalised, 0, self::MAX_VERSION_HISTORY);
    }

    /**
     * @param mixed $changes
     *
     * @return array<string, mixed>
     */
    private function normaliseChanges($changes): array
    {
        $default = [
            'summary' => '',
            'keywords_added' => [],
            'keywords_removed' => [],
            'entities_added' => [],
            'entities_removed' => [],
            'length_delta' => 0,
        ];

        if (is_array($changes)) {
            return [
                'summary' => (string) ($changes['summary'] ?? ''),
                'keywords_added' => $this->normaliseStrings($changes['keywords_added'] ?? []),
                'keywords_removed' => $this->normaliseStrings($changes['keywords_removed'] ?? []),
                'entities_added' => $this->normaliseStrings($changes['entities_added'] ?? []),
                'entities_removed' => $this->normaliseStrings($changes['entities_removed'] ?? []),
                'length_delta' => (int) ($changes['length_delta'] ?? 0),
            ];
        }

        if (is_string($changes) && $changes !== '') {
            $default['summary'] = $changes;
        }

        return $default;
    }

    /**
     * @param array<int, mixed> $values
     *
     * @return array<int, string>
     */
    private function normaliseStrings(array $values): array
    {
        $normalised = [];

        foreach ($values as $value) {
            if (!is_string($value)) {
                continue;
            }

            $value = trim($value);
            if ($value === '') {
                continue;
            }

            $normalised[] = $value;
        }

        return array_values(array_unique($normalised));
    }

    /**
     * @param array<int, mixed> $keywords
     *
     * @return array<string, string>
     */
    private function indexKeywords(array $keywords): array
    {
        $indexed = [];

        foreach ($keywords as $keyword) {
            if (!is_array($keyword)) {
                continue;
            }

            $token = trim((string) ($keyword['token'] ?? ''));
            if ($token === '') {
                continue;
            }

            $indexed[mb_strtolower($token)] = $token;
        }

        return $indexed;
    }

    /**
     * @param array<int, mixed> $entities
     *
     * @return array<string, string>
     */
    private function indexEntities(array $entities): array
    {
        $indexed = [];

        foreach ($entities as $entity) {
            if (!is_array($entity)) {
                continue;
            }

            $label = trim((string) ($entity['label'] ?? ''));
            if ($label === '') {
                continue;
            }

            $indexed[mb_strtolower($label)] = $label;
        }

        return $indexed;
    }

    private function normalisePageUrl(string $url, string $canonical): string
    {
        $candidate = trim($canonical) !== '' ? $canonical : $url;
        $candidate = trim($candidate);
        if ($candidate === '') {
            return $url;
        }

        $parts = parse_url($candidate);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return $candidate;
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = (string) ($parts['path'] ?? '/');
        if ($path === '') {
            $path = '/';
        }
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        $query = '';
        if (isset($parts['query']) && $parts['query'] !== '') {
            $params = [];
            parse_str((string) $parts['query'], $params);
            if (is_array($params)) {
                foreach ($params as $key => $value) {
                    if (!is_string($key)) {
                        unset($params[$key]);
                        continue;
                    }

                    $keyLower = strtolower($key);
                    if (in_array($keyLower, self::TRACKING_QUERY_PARAMETERS, true)) {
                        unset($params[$key]);
                        continue;
                    }

                    if (is_array($value) && $value === []) {
                        unset($params[$key]);
                    }
                }

                if ($params !== []) {
                    ksort($params);
                    $query = http_build_query($params);
                }
            }
        }

        $normalized = $scheme . '://' . $host . $port . $path;
        if ($query !== '') {
            $normalized .= '?' . $query;
        }

        return $normalized;
    }

    private function normaliseStoredUrl(string $url): string
    {
        return $this->normalisePageUrl($url, '');
    }

    private function fingerprint(ScrapeResult $scraped): string
    {
        $text = mb_strtolower(trim($scraped->text()));
        $title = mb_strtolower(trim($scraped->title()));

        return hash('sha256', $title . '|' . $text);
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function detectContentType(ScrapeResult $scraped, array $entry): string
    {
        $characterCount = (int) ($entry['character_count'] ?? $scraped->characterCount());
        $paragraphCount = (int) ($entry['paragraph_count'] ?? $scraped->paragraphCount());
        $publishedAt = trim((string) ($entry['published_at'] ?? ''));
        $textLower = mb_strtolower($scraped->text());
        $titleLower = mb_strtolower($scraped->title());

        $nonArticleIndicators = [
            '404',
            'page not found',
            'sign in',
            'login',
            'cookies',
            'javascript required',
        ];

        foreach ($nonArticleIndicators as $indicator) {
            if ($indicator !== '' && str_contains($textLower, $indicator)) {
                return 'non_article';
            }
        }

        if ($publishedAt !== '' || $characterCount >= 1200 || $paragraphCount >= 5) {
            return 'article';
        }

        if ($characterCount < 320 || $paragraphCount <= 2) {
            return 'non_article';
        }

        if (str_contains($titleLower, 'blog') || str_contains($titleLower, 'news')) {
            return 'article';
        }

        return 'page';
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return array<string, mixed>
     */
    private function upgradeLegacyEntry(array $entry): array
    {
        $entry['versions'] = $this->normaliseVersions($entry['versions'] ?? []);
        $entry['changes'] = $this->normaliseChanges($entry['changes'] ?? null);
        $entry['revision'] = (int) ($entry['revision'] ?? 1);
        $entry['normalized_url'] = (string) ($entry['normalized_url'] ?? $this->normaliseStoredUrl((string) ($entry['canonical_url'] ?? $entry['url'] ?? '')));
        if (!isset($entry['last_checked_at'])) {
            $entry['last_checked_at'] = (string) ($entry['fetched_at'] ?? '');
        }
        if (!isset($entry['content_type'])) {
            $entry['content_type'] = $this->inferLegacyContentType($entry);
        }
        if (!isset($entry['fingerprint'])) {
            $entry['fingerprint'] = hash(
                'sha256',
                mb_strtolower((string) ($entry['summary'] ?? '')) . '|' . mb_strtolower((string) ($entry['title'] ?? ''))
            );
        }

        return $entry;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function inferLegacyContentType(array $entry): string
    {
        $characterCount = (int) ($entry['character_count'] ?? 0);
        $paragraphCount = (int) ($entry['paragraph_count'] ?? 0);
        $publishedAt = trim((string) ($entry['published_at'] ?? ''));

        if ($publishedAt !== '' || $characterCount >= 1200 || $paragraphCount >= 5) {
            return 'article';
        }

        if ($characterCount < 320 || $paragraphCount <= 2) {
            return 'non_article';
        }

        return 'page';
    }

    /**
     * @param array<int, array{token: string, count: int}> $keywords
     *
     * @return array<int, array{token: string, count: int}>
     */
    private function formatKeywords(array $keywords): array
    {
        $normalised = [];

        foreach ($keywords as $keyword) {
            if (!is_array($keyword)) {
                continue;
            }

            $token = trim((string) ($keyword['token'] ?? ''));
            if ($token === '') {
                continue;
            }

            $tokenLower = mb_strtolower($token);
            if (isset(self::DISCARDED_KEYWORDS[$tokenLower])) {
                continue;
            }

            if (mb_strlen($tokenLower) <= 2) {
                continue;
            }

            $count = (int) ($keyword['count'] ?? 0);
            if (!isset($normalised[$tokenLower]) || $count > $normalised[$tokenLower]['count']) {
                $normalised[$tokenLower] = [
                    'token' => $token,
                    'count' => $count,
                ];
            }
        }

        usort($normalised, static function (array $left, array $right): int {
            return (int) ($right['count'] ?? 0) <=> (int) ($left['count'] ?? 0);
        });

        return array_slice(array_values($normalised), 0, 10);
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
    /**
     * @param array<int, string> $links
     *
     * @return array<int, array{url: string, domain: string, trust_score: float}>
     */
    private function recommendSources(array $links, string $currentDomain): array
    {
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
