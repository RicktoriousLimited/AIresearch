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
use function abs;
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
use function log;
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
use function strtotime;
use function floor;
use function stripos;
use function strcasecmp;


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

    private const MAX_SCHEDULED_QUEUE = 300;

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

    private string $scheduledQueuePath;

    private const MAX_TRACKED_TASKS = 120;

    private const MAX_SCHEDULE_HISTORY = 20;

    /**
     * Cached discovery ledger built from the stored history to avoid repeatedly
     * re-indexing the same data while preparing scheduled queues. The cache is
     * invalidated whenever history or queue state is persisted.
     *
     * @var array<string, array<string, mixed>>|null
     */
    private ?array $discoveryLedgerCache = null;

    /**
     * @var array<string, string>
     */
    private array $contentDigestIndex = [];

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
        $this->scheduledQueuePath = $this->deriveScheduledQueuePath($storagePath);

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

        if (!file_exists($this->scheduledQueuePath)) {
            file_put_contents($this->scheduledQueuePath, json_encode([]));
        }
    }

    /**
     * @param array<int, string> $targets
     *
     * @return array<int, array<string, mixed>>
     */
    public function crawl(
        array $targets,
        int $maxDepth = 0,
        int $autoInterval = 0,
        bool $autoStart = false,
        int $refreshAfterMinutes = 0,
        bool $deferDiscovered = false
    ): array {
        $maxDepth = max(0, $maxDepth);
        $autoInterval = max(0, $autoInterval);
        $refreshAfterMinutes = max(0, $refreshAfterMinutes);

        [$queue, $seen, $seedUrls] = $this->buildQueue($targets);

        $historyEntries = $this->loadStoredEntries();
        $history = $this->indexHistoryByUrl($historyEntries);
        $historyByQueueKey = $this->indexHistoryByQueueKey($historyEntries);
        $discoveryLedger = $this->initialiseDiscoveryLedger($historyEntries);
        $queue = $this->initialiseQueueDiscovery($queue, $discoveryLedger);
        $tasks = [];
        $queue = $this->initialiseQueueTasks($queue, $tasks);

        $scheduledQueue = $this->loadScheduledQueue();
        $scheduledIndex = $this->indexScheduledQueue($scheduledQueue);
        $scheduledChanged = false;

        if ($refreshAfterMinutes > 0) {
            $refreshQueue = $this->buildRefreshQueue($historyEntries, $refreshAfterMinutes, $seen, $tasks, $discoveryLedger);
            if ($refreshQueue !== []) {
                $queue = array_merge($queue, $refreshQueue);
                $this->sortQueueByPriority($queue);
            }
        }

        $processedKeys = [];

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
            'refresh_after' => $refreshAfterMinutes,
        ];
        $progress['auto_interval'] = $autoInterval;
        $progress['auto_start'] = $autoStart;
        $progress['refresh_after'] = $refreshAfterMinutes;
        $progress['errors'] = [];
        $progress['last_result'] = null;
        $progress['tasks'] = array_values($tasks);
        $progress['task_totals'] = $this->summariseTaskTotals($tasks);
        $progress['scheduled_total'] = count($scheduledQueue);
        $progress['scheduled_preview'] = $this->summariseScheduledQueue($scheduledQueue);
        $progress['discovery_summary'] = $this->summariseDiscoveryProgress(
            $discoveryLedger,
            $historyByQueueKey,
            array_values($tasks),
            null
        );
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
        $lastProcessedKey = null;
        $failedEntries = [];

        while ($queue !== []) {
            $current = array_shift($queue);
            if (!is_array($current) || !isset($current['url'])) {
                continue;
            }

            $processed++;
            $currentUrl = (string) ($current['url'] ?? '');
            $currentDepth = (int) ($current['depth'] ?? 0);
            $currentTaskId = (string) ($current['task_id'] ?? $this->registerTask(
                $tasks,
                $currentUrl,
                $currentDepth,
                !empty($current['seed']),
                !empty($current['refresh'])
            ));

            if (isset($tasks[$currentTaskId])) {
                $tasks[$currentTaskId]['status'] = 'running';
                $tasks[$currentTaskId]['started_at'] = date(DATE_ATOM);
                $tasks[$currentTaskId]['finished_at'] = null;
                $tasks[$currentTaskId]['error'] = null;
                $tasks[$currentTaskId]['attempts'] = (int) ($tasks[$currentTaskId]['attempts'] ?? 0) + 1;
            }
            $this->enforceTaskLimit($tasks);

            $this->registerDiscovery($discoveryLedger, $currentUrl, null, !empty($current['seed']));

            $progress['status'] = 'fetching';
            $progress['message'] = 'Fetching ' . $currentUrl;
            $progress['current_url'] = $currentUrl;
            $progress['current_depth'] = $currentDepth;
            $progress['processed'] = $processed - 1;
            $progress['queued'] = count($queue) + 1;
            $progress['last_updated_at'] = date(DATE_ATOM);
            $progress['tasks'] = array_values($tasks);
            $progress['task_totals'] = $this->summariseTaskTotals($tasks);
            $progress['scheduled_total'] = count($scheduledQueue);
            $progress['scheduled_preview'] = $this->summariseScheduledQueue($scheduledQueue);
            $progress['discovery_summary'] = $this->summariseDiscoveryProgress(
                $discoveryLedger,
                $historyByQueueKey,
                array_values($tasks),
                $currentUrl
            );
            $this->writeProgress($progress);

            try {
                [$history, $result, $scraped] = $this->crawlUrl($currentUrl, $history);
            } catch (Throwable $exception) {
                $result = $this->createFailedEntry($currentUrl, $exception->getMessage());
                $scraped = null;
            }

            if (isset($result['error'])) {
                $failedEntries[] = $result;
            }

            $normalizedAfterCrawl = (string) ($result['normalized_url'] ?? '');
            $this->reconcileDiscoveryKey(
                $discoveryLedger,
                $currentUrl,
                $normalizedAfterCrawl,
                (string) ($result['url'] ?? '')
            );

            $processedKey = $this->normaliseStoredUrl(
                $normalizedAfterCrawl !== ''
                    ? $normalizedAfterCrawl
                    : ((string) ($result['url'] ?? $currentUrl))
            );
            if ($processedKey === '') {
                $processedKey = $this->queueKey($currentUrl);
            }
            $seen[$processedKey] = true;
            $processedKeys[] = $processedKey;
            $lastProcessedKey = $processedKey;

            if (isset($tasks[$currentTaskId])) {
                $tasks[$currentTaskId]['status'] = isset($result['error']) ? 'failed' : 'completed';
                $tasks[$currentTaskId]['finished_at'] = date(DATE_ATOM);
                $tasks[$currentTaskId]['error'] = isset($result['error']) ? (string) $result['error'] : null;
                if (isset($result['title']) && is_string($result['title'])) {
                    $tasks[$currentTaskId]['title'] = (string) $result['title'];
                }
                if ($scraped instanceof ScrapeResult) {
                    $tasks[$currentTaskId]['characters'] = $scraped->characterCount();
                } elseif (isset($result['character_count'])) {
                    $tasks[$currentTaskId]['characters'] = (int) $result['character_count'];
                }
            }
            $this->enforceTaskLimit($tasks);

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

            $nextDepth = $currentDepth + 1;
            $canExploreNow = $currentDepth < $maxDepth;
            $shouldDeferDiscovery = $deferDiscovered || !$canExploreNow;

            if ($scraped !== null && ($canExploreNow || $shouldDeferDiscovery)) {
                $parentDomain = $this->extractDomain($currentUrl);
                $assignedDepth = $nextDepth;

                if ($shouldDeferDiscovery && !$deferDiscovered && !$canExploreNow) {
                    $assignedDepth = 0;
                }

                $added = $this->enqueueDiscoveredLinks(
                    $queue,
                    $seen,
                    $scraped->links(),
                    $assignedDepth,
                    $parentDomain,
                    $currentUrl,
                    $discoveryLedger,
                    $tasks,
                    $shouldDeferDiscovery,
                    $scheduledQueue,
                    $scheduledIndex,
                    $scheduledChanged
                );
                if ($added > 0) {
                    $discoveredTotal += $added;
                    $progress['discovered'] = ($progress['discovered'] ?? 0) + $added;
                    if ($shouldDeferDiscovery) {
                        $progress['scheduled_total'] = count($scheduledQueue);
                        $progress['scheduled_preview'] = $this->summariseScheduledQueue($scheduledQueue);
                        $progress['message'] = $deferDiscovered
                            ? 'Scheduled ' . $discoveredTotal . ' additional page(s).'
                            : 'Queued ' . $discoveredTotal . ' additional page(s) for later runs.';
                    } else {
                        $progress['total'] = ($progress['total'] ?? 0) + $added;
                        $progress['queued'] = count($queue);
                        $progress['message'] = 'Discovered ' . $discoveredTotal . ' additional page(s).';
                    }
                }
            }

            $this->updateDiscoveryAfterCrawl(
                $discoveryLedger,
                $result,
                $current,
                $refreshAfterMinutes
            );

            $progress['last_updated_at'] = date(DATE_ATOM);
            $progress['tasks'] = array_values($tasks);
            $historyByQueueKey = $this->indexHistoryByQueueKey(array_values($history));

            $progress['task_totals'] = $this->summariseTaskTotals($tasks);
            $progress['scheduled_total'] = count($scheduledQueue);
            $progress['scheduled_preview'] = $this->summariseScheduledQueue($scheduledQueue);
            $progress['discovery_summary'] = $this->summariseDiscoveryProgress(
                $discoveryLedger,
                $historyByQueueKey,
                array_values($tasks),
                null
            );
            $this->writeProgress($progress);
        }

        [$history, $authorityProfiles] = $this->finaliseHistoryMetadata($history, $discoveryLedger);
        $entries = $this->collectProcessedEntries($history, $processedKeys);

        if ($entries === []) {
            $progress['status'] = 'idle';
            $progress['message'] = 'No entries were processed.';
        } else {
            $this->storeHistory($history);
            $progress['status'] = 'idle';
            $progress['message'] = 'Idle';
            if ($lastProcessedKey !== null && isset($history[$lastProcessedKey])) {
                $lastEntry = $history[$lastProcessedKey];
                if (!isset($progress['last_result'])) {
                    $progress['last_result'] = [];
                }
                $progress['last_result']['authority'] = (float) ($lastEntry['ranking']['page_authority'] ?? 0.0);
                $progress['last_result']['domain_authority'] = (float) ($lastEntry['ranking']['domain_authority'] ?? 0.0);
                $progress['last_result']['inbound_links'] = (int) ($lastEntry['ranking']['inbound_links'] ?? 0);
            }
        }

        $finishedAt = date(DATE_ATOM);
        $progress['finished_at'] = $finishedAt;
        $progress['last_run_at'] = $finishedAt;
        $progress['current_url'] = null;
        $progress['current_depth'] = 0;
        $progress['queued'] = 0;
        $progress['next_run_due_at'] = $this->computeNextRunAt($autoInterval, $autoStart, $finishedAt);
        $progress['last_updated_at'] = $finishedAt;
        $progress['tasks'] = array_values($tasks);
        $historyByQueueKey = $this->indexHistoryByQueueKey(array_values($history));

        $progress['task_totals'] = $this->summariseTaskTotals($tasks);
        $progress['scheduled_total'] = count($scheduledQueue);
        $progress['scheduled_preview'] = $this->summariseScheduledQueue($scheduledQueue);
        $progress['discovery_summary'] = $this->summariseDiscoveryProgress(
            $discoveryLedger,
            $historyByQueueKey,
            array_values($tasks),
            null
        );
        $this->writeProgress($progress);

        if ($scheduledChanged) {
            $this->storeScheduledQueue($scheduledQueue);
        }

        if ($failedEntries !== []) {
            return array_merge($entries, $failedEntries);
        }

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
        $state = $this->readProgress();
        $scheduledQueue = $this->loadScheduledQueue();
        $state['scheduled_total'] = count($scheduledQueue);
        $state['scheduled_preview'] = $this->summariseScheduledQueue($scheduledQueue);

        $historyEntries = $this->loadStoredEntries();
        $ledger = $this->initialiseDiscoveryLedger($historyEntries);
        $historyByQueueKey = $this->indexHistoryByQueueKey($historyEntries);
        $tasks = isset($state['tasks']) && is_array($state['tasks']) ? array_values($state['tasks']) : [];
        $currentUrl = isset($state['current_url']) && is_string($state['current_url'])
            ? $state['current_url']
            : null;

        $state['discovery_summary'] = $this->summariseDiscoveryProgress(
            $ledger,
            $historyByQueueKey,
            $tasks,
            $currentUrl
        );

        return $state;
    }

    /**
     * @return array{
     *     generated_at: string,
     *     domains: array<int, array<string, mixed>>,
     *     pages: array<int, array<string, mixed>>
     * }
     */
    public function sourceDirectory(): array
    {
        $entries = $this->history();
        $domains = [];
        $pages = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $url = (string) ($entry['url'] ?? '');
            $normalized = (string) ($entry['normalized_url'] ?? $url);
            $domain = $this->extractDomain($normalized !== '' ? $normalized : $url);
            if ($domain === '') {
                continue;
            }

            $ranking = is_array($entry['ranking'] ?? null) ? $entry['ranking'] : [];
            $pageAuthority = (float) ($ranking['page_authority'] ?? 0.0);
            $domainAuthority = (float) ($ranking['domain_authority'] ?? $this->baseScoreForDomain($domain));
            $inboundLinks = (int) ($ranking['inbound_links'] ?? 0);
            $uniqueSources = (int) ($ranking['unique_sources'] ?? 0);

            $pages[] = [
                'url' => $url,
                'title' => (string) ($entry['title'] ?? $url),
                'domain' => $domain,
                'page_authority' => $pageAuthority,
                'domain_authority' => $domainAuthority,
                'inbound_links' => $inboundLinks,
                'unique_sources' => $uniqueSources,
                'fetched_at' => (string) ($entry['fetched_at'] ?? ''),
                'preview' => (string) ($entry['preview'] ?? ''),
            ];

            if (!isset($domains[$domain])) {
                $domains[$domain] = [
                    'domain' => $domain,
                    'page_count' => 0,
                    'authority_sum' => 0.0,
                    'baseline' => $this->baseScoreForDomain($domain),
                    'inbound_links' => 0,
                    'unique_sources' => [],
                    'top_page' => null,
                    'last_seen_at' => (string) ($entry['last_checked_at'] ?? $entry['fetched_at'] ?? ''),
                ];
            }

            $domains[$domain]['page_count']++;
            $domains[$domain]['authority_sum'] += $pageAuthority;
            $domains[$domain]['inbound_links'] += $inboundLinks;

            $entryLastSeen = (string) ($entry['last_checked_at'] ?? $entry['fetched_at'] ?? '');
            if ($domains[$domain]['last_seen_at'] === '' || $domains[$domain]['last_seen_at'] < $entryLastSeen) {
                $domains[$domain]['last_seen_at'] = $entryLastSeen;
            }

            if (
                $domains[$domain]['top_page'] === null
                || $pageAuthority > (float) ($domains[$domain]['top_page']['authority'] ?? 0.0)
            ) {
                $domains[$domain]['top_page'] = [
                    'url' => $url,
                    'title' => (string) ($entry['title'] ?? $url),
                    'authority' => $pageAuthority,
                ];
            }

            $discoverySources = is_array($entry['discovery']['sources'] ?? null) ? $entry['discovery']['sources'] : [];
            foreach ($discoverySources as $source) {
                if (!is_array($source)) {
                    continue;
                }

                $sourceDomain = (string) ($source['domain'] ?? $this->extractDomain((string) ($source['url'] ?? '')));
                if ($sourceDomain === '') {
                    continue;
                }

                $domains[$domain]['unique_sources'][$sourceDomain] = true;
            }
        }

        $domainList = [];
        foreach ($domains as $domain => $data) {
            $average = $data['page_count'] > 0 ? $data['authority_sum'] / $data['page_count'] : 0.0;
            $diversityBoost = min(0.25, count($data['unique_sources']) * 0.05);
            $volumeBoost = $data['page_count'] > 1 ? min(0.15, log(1 + $data['page_count']) / 3) : 0.0;
            $domainAuthority = round(
                min(1.0, max(0.0, $data['baseline'] + ($average * 0.4) + $diversityBoost + $volumeBoost)),
                3
            );

            $domainList[] = [
                'domain' => $domain,
                'domain_authority' => $domainAuthority,
                'average_page_authority' => round($average, 3),
                'page_count' => $data['page_count'],
                'inbound_links' => $data['inbound_links'],
                'unique_sources' => count($data['unique_sources']),
                'top_page' => $data['top_page'],
                'last_seen_at' => $data['last_seen_at'],
                'baseline' => $data['baseline'],
            ];
        }

        usort($domainList, static function (array $left, array $right): int {
            return (float) ($right['domain_authority'] ?? 0.0) <=> (float) ($left['domain_authority'] ?? 0.0);
        });

        usort($pages, static function (array $left, array $right): int {
            return (float) ($right['page_authority'] ?? 0.0) <=> (float) ($left['page_authority'] ?? 0.0);
        });

        return [
            'generated_at' => date(DATE_ATOM),
            'domains' => $domainList,
            'pages' => array_slice($pages, 0, 200),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function discoveryTree(
        int $maxSeeds = 6,
        int $maxChildren = 5,
        int $maxDepth = 3,
        int $maxRecommended = 12
    ): array {
        $historyEntries = $this->loadStoredEntries();
        if ($historyEntries === []) {
            return [
                'generated_at' => date(DATE_ATOM),
                'seeds' => [],
                'total_nodes' => 0,
                'pending' => 0,
                'recommended' => [],
            ];
        }

        $ledger = $this->initialiseDiscoveryLedger($historyEntries);
        $historyByKey = $this->indexHistoryByQueueKey($historyEntries);
        $childrenMap = $this->buildDiscoveryChildren($ledger);
        $seedKeys = $this->selectDiscoverySeeds($ledger);

        $visited = [];
        $nodes = [];
        $totalNodes = 0;
        $pendingCandidates = [];

        $seedSlice = array_slice($seedKeys, 0, max(1, $maxSeeds));
        foreach ($seedSlice as $seedKey) {
            $node = $this->formatDiscoveryNode(
                $seedKey,
                $ledger,
                $historyByKey,
                $childrenMap,
                $maxChildren,
                $maxDepth,
                0,
                $visited,
                $totalNodes,
                $pendingCandidates
            );
            if ($node !== null) {
                $nodes[] = $node;
            }
        }

        usort($pendingCandidates, static fn(array $left, array $right): int => ($right['score'] ?? 0.0) <=> ($left['score'] ?? 0.0));

        $recommended = [];
        foreach (array_slice($pendingCandidates, 0, max(0, $maxRecommended)) as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $url = isset($candidate['url']) ? (string) $candidate['url'] : '';
            if ($url === '') {
                continue;
            }

            $recommended[] = [
                'url' => $url,
                'domain' => isset($candidate['domain']) ? (string) $candidate['domain'] : $this->extractDomain($url),
                'score' => round((float) ($candidate['score'] ?? 0.0), 2),
                'last_seen_at' => isset($candidate['last_seen_at']) ? (string) $candidate['last_seen_at'] : '',
            ];
        }

        return [
            'generated_at' => date(DATE_ATOM),
            'seeds' => $nodes,
            'total_nodes' => $totalNodes,
            'pending' => count($pendingCandidates),
            'recommended' => $recommended,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function continueDiscovery(int $limit = 5, int $maxDepth = 1): array
    {
        $limit = max(1, $limit);
        $maxDepth = max(0, $maxDepth);

        $snapshot = $this->discoveryTree(8, 6, 3, $limit * 3);
        $targets = [];
        $recommended = isset($snapshot['recommended']) && is_array($snapshot['recommended']) ? $snapshot['recommended'] : [];
        foreach ($recommended as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $url = isset($candidate['url']) ? (string) $candidate['url'] : '';
            if ($url === '') {
                continue;
            }

            $targets[] = $url;
            if (count($targets) >= $limit) {
                break;
            }
        }

        if ($targets === []) {
            return [
                'processed' => 0,
                'targets' => [],
                'errors' => [],
                'discovery' => $snapshot,
            ];
        }

        $entries = $this->crawl($targets, $maxDepth);
        $errors = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || !isset($entry['error'])) {
                continue;
            }

            $errors[] = [
                'url' => (string) ($entry['url'] ?? ''),
                'error' => (string) $entry['error'],
            ];
        }

        return [
            'processed' => count($entries),
            'targets' => $targets,
            'errors' => $errors,
            'discovery' => $this->discoveryTree(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function knownUrls(int $limit = 50, bool $onlyDue = true): array
    {
        $limit = max(1, $limit);

        $entries = $this->loadStoredEntries();
        $ledger = $this->initialiseDiscoveryLedger($entries);
        $historyByKey = $this->indexHistoryByQueueKey($entries);

        try {
            $now = new DateTimeImmutable();
        } catch (Throwable $exception) {
            $now = null;
        }

        $candidates = [];

        foreach ($ledger as $key => $meta) {
            if (!is_array($meta)) {
                continue;
            }

            $url = (string) ($meta['url'] ?? $key);
            if ($url === '') {
                continue;
            }

            $domain = $this->extractDomain($url);
            $schedule = $this->normaliseSchedule($meta['schedule'] ?? []);
            $ledgerEntry = $meta;
            $ledgerEntry['schedule'] = $schedule;

            $historyEntry = $historyByKey[$key] ?? null;
            $interval = $this->determineRefreshInterval($historyEntry, $ledgerEntry, 0);
            $dueAt = $this->resolveNextDueDate($schedule, (string) ($meta['last_seen_at'] ?? ''), $interval);

            if ($onlyDue && $now !== null && $dueAt !== null && $dueAt > $now) {
                continue;
            }

            $sourceCount = is_array($meta['sources'] ?? null) ? count($meta['sources']) : 0;

            $candidates[] = [
                'url' => $url,
                'domain' => $domain,
                'seed' => !empty($meta['seed']),
                'priority' => $this->baseScoreForDomain($domain),
                'interval_minutes' => $interval,
                'last_seen_at' => (string) ($meta['last_seen_at'] ?? ''),
                'next_due_at' => $dueAt !== null ? $dueAt->format(DATE_ATOM) : '',
                'source_count' => $sourceCount,
            ];
        }

        if ($candidates !== []) {
            usort($candidates, static function (array $left, array $right): int {
                $leftDue = (string) ($left['next_due_at'] ?? '');
                $rightDue = (string) ($right['next_due_at'] ?? '');

                if ($leftDue === '' && $rightDue !== '') {
                    return 1;
                }

                if ($rightDue === '' && $leftDue !== '') {
                    return -1;
                }

                if ($leftDue !== $rightDue) {
                    return $leftDue <=> $rightDue;
                }

                return (float) ($right['priority'] ?? 0.0) <=> (float) ($left['priority'] ?? 0.0);
            });
        }

        return array_slice($candidates, 0, $limit);
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     *
     * @return array<string, array<string, mixed>>
     */
    private function indexHistoryByQueueKey(array $entries): array
    {
        $indexed = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $candidates = [];
            if (isset($entry['normalized_url']) && is_string($entry['normalized_url'])) {
                $candidates[] = $entry['normalized_url'];
            }
            if (isset($entry['canonical_url']) && is_string($entry['canonical_url'])) {
                $candidates[] = $entry['canonical_url'];
            }
            if (isset($entry['url']) && is_string($entry['url'])) {
                $candidates[] = $entry['url'];
            }

            foreach ($candidates as $candidate) {
                $key = $this->queueKey($candidate);
                if ($key === '') {
                    continue;
                }

                $indexed[$key] = $entry;
                break;
            }
        }

        return $indexed;
    }

    /**
     * @param array<string, array<string, mixed>> $ledger
     *
     * @return array<string, array<int, string>>
     */
    private function buildDiscoveryChildren(array $ledger): array
    {
        $children = [];

        foreach ($ledger as $key => $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $sources = isset($entry['sources']) && is_array($entry['sources']) ? $entry['sources'] : [];
            if ($sources === []) {
                continue;
            }

            $bestKey = null;
            $bestCount = -1;
            foreach ($sources as $sourceKey => $meta) {
                if (!is_array($meta)) {
                    continue;
                }

                $count = (int) ($meta['count'] ?? 0);
                if ($count > $bestCount) {
                    $bestCount = $count;
                    $bestKey = $sourceKey;
                }
            }

            if ($bestKey === null) {
                $keys = array_keys($sources);
                $bestKey = $keys[0] ?? null;
            }

            if ($bestKey === null) {
                continue;
            }

            if (!isset($children[$bestKey])) {
                $children[$bestKey] = [];
            }

            $children[$bestKey][] = $key;
        }

        return $children;
    }

    /**
     * @param array<string, array<string, mixed>> $ledger
     *
     * @return array<int, string>
     */
    private function selectDiscoverySeeds(array $ledger): array
    {
        $candidates = [];

        foreach ($ledger as $key => $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $sources = isset($entry['sources']) && is_array($entry['sources']) ? $entry['sources'] : [];
            if (!empty($entry['seed']) || $sources === []) {
                $candidates[] = $key;
            }
        }

        if ($candidates === []) {
            $candidates = array_keys($ledger);
        }

        usort($candidates, static function (string $left, string $right) use ($ledger): int {
            $leftSeen = isset($ledger[$left]['last_seen_at']) ? (string) $ledger[$left]['last_seen_at'] : '';
            $rightSeen = isset($ledger[$right]['last_seen_at']) ? (string) $ledger[$right]['last_seen_at'] : '';

            return $rightSeen <=> $leftSeen;
        });

        return $candidates;
    }

    /**
     * @param array<string, array<string, mixed>> $ledger
     * @param array<string, array<string, mixed>> $historyByKey
     * @param array<string, array<int, string>> $childrenMap
     * @param array<string, bool> $visited
     * @param array<int, array<string, mixed>> $pendingCandidates
     *
     * @return array<string, mixed>|null
     */
    private function formatDiscoveryNode(
        string $key,
        array $ledger,
        array $historyByKey,
        array $childrenMap,
        int $maxChildren,
        int $maxDepth,
        int $depth,
        array &$visited,
        int &$totalNodes,
        array &$pendingCandidates
    ): ?array {
        if (isset($visited[$key])) {
            return null;
        }
        $visited[$key] = true;

        if (!isset($ledger[$key]) || !is_array($ledger[$key])) {
            return null;
        }

        $entry = $ledger[$key];
        $url = isset($entry['url']) ? (string) $entry['url'] : '';
        if ($url === '') {
            return null;
        }

        $historyEntry = $historyByKey[$key] ?? null;
        $status = $historyEntry !== null ? 'indexed' : 'pending';
        $totalNodes++;

        if ($status === 'pending') {
            $pendingCandidates[] = [
                'url' => $url,
                'domain' => $this->extractDomain($url),
                'score' => $this->scoreDiscoveryCandidate($entry),
                'last_seen_at' => isset($entry['last_seen_at']) ? (string) $entry['last_seen_at'] : '',
            ];
        }

        $childrenKeys = $childrenMap[$key] ?? [];
        $childrenKeys = array_values(array_unique($childrenKeys));
        usort($childrenKeys, static function (string $left, string $right) use ($ledger): int {
            $leftSeen = isset($ledger[$left]['last_seen_at']) ? (string) $ledger[$left]['last_seen_at'] : '';
            $rightSeen = isset($ledger[$right]['last_seen_at']) ? (string) $ledger[$right]['last_seen_at'] : '';

            return $rightSeen <=> $leftSeen;
        });
        $childCount = count($childrenKeys);
        if ($maxChildren > 0) {
            $childrenKeys = array_slice($childrenKeys, 0, $maxChildren);
        }

        $children = [];
        if ($depth < $maxDepth) {
            foreach ($childrenKeys as $childKey) {
                $child = $this->formatDiscoveryNode(
                    $childKey,
                    $ledger,
                    $historyByKey,
                    $childrenMap,
                    $maxChildren,
                    $maxDepth,
                    $depth + 1,
                    $visited,
                    $totalNodes,
                    $pendingCandidates
                );

                if ($child !== null) {
                    $children[] = $child;
                }
            }
        }

        $title = '';
        if ($historyEntry !== null && isset($historyEntry['title'])) {
            $title = (string) $historyEntry['title'];
        }
        if ($title === '') {
            $title = $url;
        }

        return [
            'url' => $url,
            'title' => $title,
            'domain' => $this->extractDomain($url),
            'first_seen_at' => isset($entry['first_seen_at']) ? (string) $entry['first_seen_at'] : '',
            'last_seen_at' => isset($entry['last_seen_at']) ? (string) $entry['last_seen_at'] : '',
            'seed' => !empty($entry['seed']),
            'status' => $status,
            'quality' => isset($historyEntry['quality_score']) ? (float) $historyEntry['quality_score'] : 0.0,
            'child_count' => $childCount,
            'children' => $children,
        ];
    }

    /**
     * @param array<string, mixed> $ledgerEntry
     */
    private function scoreDiscoveryCandidate(array $ledgerEntry): float
    {
        $url = isset($ledgerEntry['url']) ? (string) $ledgerEntry['url'] : '';
        $domain = $this->extractDomain($url);
        $base = $this->baseScoreForDomain($domain) * 100.0;

        $sources = isset($ledgerEntry['sources']) && is_array($ledgerEntry['sources']) ? $ledgerEntry['sources'] : [];
        $sourceBoost = min(25.0, count($sources) * 4.5);

        $recencyBoost = 0.0;
        $lastSeen = isset($ledgerEntry['last_seen_at']) ? (string) $ledgerEntry['last_seen_at'] : '';
        if ($lastSeen !== '') {
            $timestamp = strtotime($lastSeen);
            if (is_int($timestamp)) {
                $ageHours = max(0.0, (time() - $timestamp) / 3600.0);
                $recencyBoost = max(0.0, 12.0 - min(12.0, $ageHours));
            }
        }

        return $base + $sourceBoost + $recencyBoost;
    }

    private function deriveProgressPath(string $storagePath): string
    {
        $suffixPosition = strrpos($storagePath, '.json');
        if ($suffixPosition !== false) {
            return substr($storagePath, 0, $suffixPosition) . self::PROGRESS_FILE_SUFFIX;
        }

        return $storagePath . self::PROGRESS_FILE_SUFFIX;
    }

    private function deriveScheduledQueuePath(string $storagePath): string
    {
        $suffixPosition = strrpos($storagePath, '.json');
        if ($suffixPosition !== false) {
            return substr($storagePath, 0, $suffixPosition) . '.scheduled-queue.json';
        }

        return $storagePath . '.scheduled-queue.json';
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
            'refresh_after' => 0,
            'options' => [
                'depth' => 0,
                'auto_interval' => 0,
                'auto_start' => false,
                'refresh_after' => 0,
            ],
            'next_run_due_at' => null,
            'tasks' => [],
            'task_totals' => $this->defaultTaskTotals(),
            'scheduled_total' => 0,
            'scheduled_preview' => [],
            'discovery_summary' => [
                'generated_at' => $now,
                'totals' => [
                    'links' => 0,
                    'domains' => 0,
                    'fresh' => 0,
                    'recent' => 0,
                    'stale' => 0,
                    'overdue' => 0,
                    'queued' => 0,
                    'running' => 0,
                    'new' => 0,
                    'failed' => 0,
                    'unknown' => 0,
                ],
                'domains' => [],
                'links' => [],
            ],
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

        if (!isset($decoded['tasks']) || !is_array($decoded['tasks'])) {
            $decoded['tasks'] = [];
        }

        if (!isset($decoded['task_totals']) || !is_array($decoded['task_totals'])) {
            $decoded['task_totals'] = $this->defaultTaskTotals();
        } else {
            $decoded['task_totals'] = array_merge($this->defaultTaskTotals(), $decoded['task_totals']);
        }

        if (!isset($decoded['scheduled_total'])) {
            $decoded['scheduled_total'] = 0;
        }

        if (!isset($decoded['scheduled_preview']) || !is_array($decoded['scheduled_preview'])) {
            $decoded['scheduled_preview'] = [];
        }

        if (!isset($decoded['refresh_after'])) {
            $decoded['refresh_after'] = (int) ($decoded['options']['refresh_after'] ?? 0);
        }

        if (!isset($decoded['options']) || !is_array($decoded['options'])) {
            $decoded['options'] = [
                'depth' => 0,
                'auto_interval' => 0,
                'auto_start' => false,
                'refresh_after' => (int) $decoded['refresh_after'],
            ];
        } elseif (!isset($decoded['options']['refresh_after'])) {
            $decoded['options']['refresh_after'] = (int) $decoded['refresh_after'];
        }

        if (!isset($decoded['discovery_summary']) || !is_array($decoded['discovery_summary'])) {
            $default = $this->defaultProgressState();
            $decoded['discovery_summary'] = $default['discovery_summary'];
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
            $candidateUrl = '';
            $seed = true;
            $depth = 0;
            $priority = null;
            $refresh = false;

            if (is_string($target)) {
                $candidateUrl = $target;
            } elseif (is_array($target)) {
                $candidateUrl = isset($target['url']) ? (string) $target['url'] : '';
                $seed = isset($target['seed']) ? (bool) $target['seed'] : true;
                $depth = isset($target['depth']) ? max(0, (int) $target['depth']) : 0;
                if (isset($target['priority'])) {
                    $priority = (float) $target['priority'];
                }
                $refresh = !empty($target['refresh']);
            } else {
                continue;
            }

            $normalised = $this->normaliseSeedTarget($candidateUrl);
            if ($normalised === null) {
                continue;
            }

            $key = $this->queueKey($normalised);
            if (isset($seen[$key])) {
                continue;
            }

            $domain = $this->extractDomain($normalised);
            if ($priority === null) {
                $priority = $this->baseScoreForDomain($domain);
                if ($seed) {
                    $priority += 0.25;
                }
            }

            $queue[] = [
                'url' => $normalised,
                'depth' => $depth,
                'priority' => $priority,
                'seed' => $seed,
                'refresh' => $refresh,
            ];
            $seen[$key] = true;
            if ($seed) {
                $seeds[] = $normalised;
            }
        }

        if ($queue === []) {
            $this->buildQueueFromExistingKnowledge($queue, $seen, $seeds);
        }

        $this->sortQueueByPriority($queue);

        if (count($queue) > self::MAX_QUEUE_SIZE) {
            $queue = array_slice($queue, 0, self::MAX_QUEUE_SIZE);
        }

        return [$queue, $seen, $seeds];
    }

    /**
     * @param array<int, array<string, mixed>> $queue
     * @param array<string, bool> $seen
     * @param array<int, string> $seeds
     */
    private function buildQueueFromExistingKnowledge(array &$queue, array &$seen, array &$seeds): void
    {
        $availableSlots = self::MAX_QUEUE_SIZE - count($queue);
        if ($availableSlots <= 0) {
            return;
        }

        $candidates = $this->deriveStoredQueueCandidates($seen, $availableSlots);
        if ($candidates === []) {
            return;
        }

        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $url = isset($candidate['url']) ? (string) $candidate['url'] : '';
            if ($url === '') {
                continue;
            }

            $key = $this->queueKey($url);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }

            $queue[] = [
                'url' => $url,
                'depth' => isset($candidate['depth']) ? max(0, (int) $candidate['depth']) : 0,
                'priority' => isset($candidate['priority'])
                    ? (float) $candidate['priority']
                    : $this->baseScoreForDomain($this->extractDomain($url)),
                'seed' => !empty($candidate['seed']),
                'refresh' => !empty($candidate['refresh']),
            ];

            $seen[$key] = true;
            if (!empty($candidate['seed'])) {
                $seeds[] = $url;
            }

            if (count($queue) >= self::MAX_QUEUE_SIZE) {
                break;
            }
        }
    }

    /**
     * @param array<string, bool> $seen
     *
     * @return array<int, array<string, mixed>>
     */
    private function deriveStoredQueueCandidates(array $seen, int $limit): array
    {
        $limit = max(0, $limit);
        if ($limit === 0) {
            return [];
        }

        $historyEntries = $this->loadStoredEntries();
        if ($historyEntries === []) {
            return [];
        }

        $ledger = $this->initialiseDiscoveryLedger($historyEntries);
        if ($ledger === []) {
            return [];
        }

        try {
            $reference = new DateTimeImmutable();
        } catch (Throwable $exception) {
            $reference = null;
        }

        $candidates = [];
        $existingKeys = [];

        foreach ($ledger as $key => $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $url = isset($entry['url']) ? (string) $entry['url'] : '';
            if ($url === '') {
                continue;
            }

            if (isset($seen[$key]) || isset($existingKeys[$key])) {
                continue;
            }

            $schedule = $this->normaliseSchedule($entry['schedule'] ?? []);
            $lastSeenRaw = isset($entry['last_seen_at']) ? (string) $entry['last_seen_at'] : '';
            $lastSeen = $lastSeenRaw !== '' ? $this->parseDateTime($lastSeenRaw) : null;
            $nextDueRaw = isset($schedule['next_due_at']) ? (string) $schedule['next_due_at'] : '';
            $nextDue = $nextDueRaw !== '' ? $this->parseDateTime($nextDueRaw) : null;

            $score = $this->scoreDiscoveryCandidate($entry);
            $priority = max(0.0, $score / 100.0);

            if (!empty($entry['seed'])) {
                $priority += 0.25;
            }

            $priority += $this->computeStoredTargetDueBoost($reference, $nextDue);
            $priority += $this->computeStoredTargetRecencyBoost($reference, $lastSeen);

            $sources = isset($entry['sources']) && is_array($entry['sources']) ? $entry['sources'] : [];
            $sourceCount = count($sources);
            if ($sourceCount >= 4) {
                $priority += 0.12;
            } elseif ($sourceCount >= 2) {
                $priority += 0.05;
            }

            if ((int) ($schedule['total_runs'] ?? 0) === 0) {
                $priority += 0.08;
            }

            $candidates[] = [
                'key' => $key,
                'url' => $url,
                'seed' => !empty($entry['seed']),
                'priority' => $priority,
                'last_seen' => $lastSeen instanceof DateTimeImmutable ? $lastSeen->getTimestamp() : null,
                'due' => $nextDue instanceof DateTimeImmutable ? $nextDue->getTimestamp() : null,
                'depth' => 0,
                'refresh' => ($nextDue !== null) || ((int) ($schedule['total_runs'] ?? 0) > 0),
                'score' => $score,
            ];
            $existingKeys[$key] = true;
        }

        if ($candidates !== []) {
            usort($candidates, static function (array $left, array $right): int {
                $priorityCompare = ($right['priority'] ?? 0.0) <=> ($left['priority'] ?? 0.0);
                if ($priorityCompare !== 0) {
                    return $priorityCompare;
                }

                $leftDue = $left['due'] ?? null;
                $rightDue = $right['due'] ?? null;
                if ($leftDue !== $rightDue) {
                    if ($leftDue === null) {
                        return 1;
                    }
                    if ($rightDue === null) {
                        return -1;
                    }

                    return $leftDue <=> $rightDue;
                }

                $leftSeen = $left['last_seen'] ?? null;
                $rightSeen = $right['last_seen'] ?? null;
                if ($leftSeen !== $rightSeen) {
                    if ($leftSeen === null) {
                        return -1;
                    }
                    if ($rightSeen === null) {
                        return 1;
                    }

                    return $leftSeen <=> $rightSeen;
                }

                return ($right['score'] ?? 0.0) <=> ($left['score'] ?? 0.0);
            });
        }

        $results = [];
        $resultKeys = [];

        foreach (array_slice($candidates, 0, $limit) as $candidate) {
            $url = (string) ($candidate['url'] ?? '');
            if ($url === '') {
                continue;
            }

            $results[] = [
                'url' => $url,
                'depth' => (int) ($candidate['depth'] ?? 0),
                'priority' => (float) ($candidate['priority'] ?? 0.0),
                'seed' => !empty($candidate['seed']),
                'refresh' => !empty($candidate['refresh']),
            ];

            $key = $this->queueKey($url);
            if ($key !== '') {
                $resultKeys[$key] = true;
            }
        }

        if (count($results) < $limit) {
            $scheduledQueue = $this->loadScheduledQueue();
            foreach ($scheduledQueue as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $url = isset($entry['url']) ? (string) $entry['url'] : '';
                if ($url === '') {
                    continue;
                }

                $key = $this->queueKey($url);
                if ($key === '' || isset($seen[$key]) || isset($resultKeys[$key])) {
                    continue;
                }

                $results[] = [
                    'url' => $url,
                    'depth' => isset($entry['depth']) ? max(0, (int) $entry['depth']) : 0,
                    'priority' => isset($entry['priority'])
                        ? (float) $entry['priority']
                        : $this->baseScoreForDomain($this->extractDomain($url)),
                    'seed' => !empty($entry['seed']),
                    'refresh' => !empty($entry['seed']) ? false : !empty($entry['refresh']),
                ];

                if ($key !== '') {
                    $resultKeys[$key] = true;
                }

                if (count($results) >= $limit) {
                    break;
                }
            }
        }

        return $results;
    }

    private function computeStoredTargetDueBoost(?DateTimeImmutable $reference, ?DateTimeImmutable $nextDue): float
    {
        if ($reference === null || $nextDue === null) {
            return 0.0;
        }

        $difference = ($nextDue->getTimestamp() - $reference->getTimestamp()) / 60.0;

        if ($difference <= -240.0) {
            return 0.4;
        }

        if ($difference <= -60.0) {
            return 0.32;
        }

        if ($difference <= 0.0) {
            return 0.28;
        }

        if ($difference <= 120.0) {
            return 0.22;
        }

        if ($difference <= 360.0) {
            return 0.14;
        }

        if ($difference <= 720.0) {
            return 0.07;
        }

        if ($difference <= 1440.0) {
            return 0.03;
        }

        return 0.0;
    }

    private function computeStoredTargetRecencyBoost(?DateTimeImmutable $reference, ?DateTimeImmutable $lastSeen): float
    {
        if ($reference === null) {
            return 0.0;
        }

        if ($lastSeen === null) {
            return 0.18;
        }

        $difference = ($reference->getTimestamp() - $lastSeen->getTimestamp()) / 60.0;
        if ($difference <= 0.0) {
            return 0.0;
        }

        if ($difference >= 10080.0) {
            return 0.24;
        }

        if ($difference >= 4320.0) {
            return 0.18;
        }

        if ($difference >= 1440.0) {
            return 0.12;
        }

        if ($difference >= 360.0) {
            return 0.06;
        }

        if ($difference >= 120.0) {
            return 0.03;
        }

        return 0.0;
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
        string $parentDomain,
        string $parentUrl,
        array &$ledger,
        array &$tasks,
        bool $deferDiscovered,
        array &$scheduledQueue,
        array &$scheduledIndex,
        bool &$scheduledChanged
    ): int {
        $depth = max(0, $depth);

        $filtered = $this->filterLinks($links);
        if ($filtered === []) {
            return 0;
        }

        $added = 0;

        foreach ($filtered as $normalised) {
            if (!$deferDiscovered && count($queue) >= self::MAX_QUEUE_SIZE) {
                break;
            }

            $this->registerDiscovery($ledger, $normalised, $parentUrl, false);
            $this->registerSchedule($ledger, $normalised, 'discovery', null, null);

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

            if ($deferDiscovered) {
                if ($this->scheduleDiscovery($scheduledQueue, $scheduledIndex, $normalised, $depth, $priority, false, $parentUrl)) {
                    $scheduledChanged = true;
                    $seen[$key] = true;
                    $added++;
                }
            } else {
                $queue[] = $this->attachTaskMetadata([
                    'url' => $normalised,
                    'depth' => $depth,
                    'priority' => $priority,
                    'seed' => false,
                ], $tasks, false, false);
                $seen[$key] = true;
                $added++;
            }

            if ($added >= self::MAX_DISCOVERED_PER_PAGE) {
                break;
            }
        }

        if ($added > 0 && !$deferDiscovered) {
            $this->sortQueueByPriority($queue);
            $this->enforceTaskLimit($tasks);
        }

        return $added;
    }

    /**
     * @param array<int, array<string, mixed>> $queue
     * @param array<string, array<string, mixed>> $ledger
     *
     * @return array<int, array<string, mixed>>
     */
    private function initialiseQueueDiscovery(array $queue, array &$ledger): array
    {
        foreach ($queue as $index => $item) {
            if (!is_array($item) || !isset($item['url'])) {
                continue;
            }

            $queue[$index]['seed'] = !empty($item['seed']);
            $queue[$index]['depth'] = (int) ($item['depth'] ?? 0);

            if ($queue[$index]['seed']) {
                $this->registerDiscovery($ledger, (string) $item['url'], null, true);
                $this->registerSchedule($ledger, (string) $item['url'], 'seed', null, null);
            } else {
                $this->registerSchedule($ledger, (string) $item['url'], 'queued', null, null);
            }
        }

        return $queue;
    }

    /**
     * @param array<int, array<string, mixed>> $queue
     * @param array<string, array<string, mixed>> $tasks
     *
     * @return array<int, array<string, mixed>>
     */
    private function initialiseQueueTasks(array $queue, array &$tasks): array
    {
        foreach ($queue as $index => $item) {
            if (!is_array($item) || !isset($item['url'])) {
                continue;
            }

            $queue[$index] = $this->attachTaskMetadata(
                $item,
                $tasks,
                !empty($item['seed']),
                !empty($item['refresh'])
            );
        }

        $this->enforceTaskLimit($tasks);

        return $queue;
    }

    /**
     * @param array<string, array<string, mixed>> $tasks
     *
     * @return array<string, mixed>
     */
    private function attachTaskMetadata(array $item, array &$tasks, bool $seed, bool $refresh): array
    {
        $url = isset($item['url']) ? (string) $item['url'] : '';
        if ($url === '') {
            return $item;
        }

        $depth = (int) ($item['depth'] ?? 0);
        $taskId = $this->registerTask($tasks, $url, $depth, $seed, $refresh);
        $item['task_id'] = $taskId;

        if ($refresh) {
            $item['refresh'] = true;
        }

        return $item;
    }

    /**
     * @param array<string, array<string, mixed>> $tasks
     */
    private function enforceTaskLimit(array &$tasks): void
    {
        if (count($tasks) <= self::MAX_TRACKED_TASKS) {
            return;
        }

        $items = array_values($tasks);
        usort($items, static function (array $left, array $right): int {
            $order = ['running' => 0, 'queued' => 1, 'failed' => 2, 'completed' => 3];
            $leftStatus = (string) ($left['status'] ?? 'queued');
            $rightStatus = (string) ($right['status'] ?? 'queued');
            $leftOrder = $order[$leftStatus] ?? 4;
            $rightOrder = $order[$rightStatus] ?? 4;

            if ($leftOrder === $rightOrder) {
                $leftTime = (string) ($left['queued_at'] ?? '');
                $rightTime = (string) ($right['queued_at'] ?? '');

                return $rightTime <=> $leftTime;
            }

            return $leftOrder <=> $rightOrder;
        });

        $items = array_slice($items, 0, self::MAX_TRACKED_TASKS);
        $trimmed = [];
        foreach ($items as $item) {
            if (!isset($item['id'])) {
                continue;
            }

            $trimmed[(string) $item['id']] = $item;
        }

        $tasks = $trimmed;
    }

    /**
     * @param array<string, array<string, mixed>> $tasks
     */
    private function registerTask(array &$tasks, string $url, int $depth, bool $seed, bool $refresh): string
    {
        $taskId = hash('sha1', mb_strtolower(trim($url)) . '|' . $depth);

        if (!isset($tasks[$taskId])) {
            $tasks[$taskId] = [
                'id' => $taskId,
                'url' => $url,
                'depth' => $depth,
                'seed' => $seed,
                'refresh' => $refresh,
                'status' => 'queued',
                'queued_at' => date(DATE_ATOM),
                'started_at' => null,
                'finished_at' => null,
                'error' => null,
                'attempts' => 0,
            ];
        } else {
            if ($seed && empty($tasks[$taskId]['seed'])) {
                $tasks[$taskId]['seed'] = true;
            }
            if ($refresh && empty($tasks[$taskId]['refresh'])) {
                $tasks[$taskId]['refresh'] = true;
            }
        }

        $this->enforceTaskLimit($tasks);

        return $taskId;
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @param array<string, bool> $seen
     * @param array<string, array<string, mixed>> $tasks
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildRefreshQueue(
        array $entries,
        int $refreshAfterMinutes,
        array &$seen,
        array &$tasks,
        array &$ledger
    ): array {
        try {
            $now = new DateTimeImmutable();
        } catch (Throwable $exception) {
            return [];
        }

        $queue = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $url = (string) ($entry['normalized_url'] ?? $entry['canonical_url'] ?? $entry['url'] ?? '');
            if ($url === '') {
                continue;
            }

            $key = $this->queueKey($url);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }

            if (!isset($ledger[$key])) {
                $ledger[$key] = [
                    'url' => $url,
                    'seed' => false,
                    'first_seen_at' => (string) ($entry['fetched_at'] ?? date(DATE_ATOM)),
                    'last_seen_at' => (string) ($entry['last_checked_at'] ?? $entry['fetched_at'] ?? date(DATE_ATOM)),
                    'sources' => [],
                    'schedule' => $this->defaultScheduleState(),
                ];
            }

            $lastSeenRaw = (string) ($entry['last_checked_at'] ?? $entry['fetched_at'] ?? '');
            $intervalMinutes = $this->determineRefreshInterval($entry, $ledger[$key], $refreshAfterMinutes);
            $dueAt = $this->resolveNextDueDate(
                $ledger[$key]['schedule'] ?? [],
                $lastSeenRaw,
                $intervalMinutes
            );

            if ($dueAt === null || $dueAt > $now) {
                continue;
            }

            $domain = $this->extractDomain($url);
            $priority = $this->baseScoreForDomain($domain) + 0.15;
            if ($intervalMinutes < 90) {
                $priority += 0.05;
            }

            $queue[] = $this->attachTaskMetadata([
                'url' => $url,
                'depth' => 0,
                'priority' => $priority,
                'seed' => false,
                'refresh' => true,
            ], $tasks, false, true);

            $seen[$key] = true;

            $this->registerSchedule($ledger, $url, 'refresh', $intervalMinutes, null);
        }

        if ($queue !== []) {
            $this->sortQueueByPriority($queue);
        }

        return $queue;
    }

    /**
     * @param mixed $schedule
     */
    private function resolveNextDueDate($schedule, string $lastSeen, int $intervalMinutes): ?DateTimeImmutable
    {
        $intervalMinutes = max(0, $intervalMinutes);
        if ($intervalMinutes === 0) {
            return null;
        }

        $state = $this->normaliseSchedule($schedule);
        $nextDueRaw = (string) ($state['next_due_at'] ?? '');
        $nextDue = $nextDueRaw !== '' ? $this->parseDateTime($nextDueRaw) : null;
        if ($nextDue !== null) {
            return $nextDue;
        }

        $origin = $this->parseDateTime($lastSeen);
        if ($origin === null) {
            try {
                $origin = new DateTimeImmutable();
            } catch (Throwable $exception) {
                return null;
            }
        }

        try {
            return $origin->add(new DateInterval('PT' . $intervalMinutes . 'M'));
        } catch (Throwable $exception) {
            return null;
        }
    }

    private function determineRefreshInterval(?array $entry, array $ledgerEntry, int $defaultInterval): int
    {
        $defaultInterval = max(0, $defaultInterval);

        $schedule = $this->normaliseSchedule($ledgerEntry['schedule'] ?? []);
        $history = is_array($schedule['history'] ?? null) ? $schedule['history'] : [];

        $intervals = [];
        $previous = null;

        foreach ($history as $event) {
            if (!is_array($event)) {
                continue;
            }

            $reason = (string) ($event['reason'] ?? '');
            if ($reason === '') {
                continue;
            }

            if (!in_array($reason, ['refresh', 'crawl', 'discovery', 'seed'], true)) {
                continue;
            }

            $queuedAt = (string) ($event['queued_at'] ?? '');
            $timestamp = $this->parseDateTime($queuedAt);
            if ($timestamp === null) {
                continue;
            }

            if ($previous !== null) {
                $difference = (int) round(($timestamp->getTimestamp() - $previous->getTimestamp()) / 60);
                if ($difference > 0) {
                    $intervals[] = $difference;
                }
            }

            $previous = $timestamp;
        }

        if ($intervals !== []) {
            sort($intervals);
            $count = count($intervals);
            $middle = (int) floor($count / 2);
            if ($count % 2 === 0 && $count > 1) {
                $median = (int) round(($intervals[$middle - 1] + $intervals[$middle]) / 2);
            } else {
                $median = $intervals[$middle];
            }
            $interval = max(5, $median);
        } else {
            $interval = $defaultInterval > 0 ? $defaultInterval : 360;
        }

        $summary = '';
        $lengthDelta = 0;
        $revision = 1;

        if (is_array($entry)) {
            $revision = max(1, (int) ($entry['revision'] ?? 1));
            $changes = $entry['changes'] ?? null;
            if (is_array($changes)) {
                $summary = (string) ($changes['summary'] ?? '');
                $lengthDelta = (int) ($changes['length_delta'] ?? 0);
            } elseif (is_string($changes)) {
                $summary = $changes;
            }
        }

        $unchanged = stripos($summary, 'no content changes') !== false;

        if ($unchanged) {
            $interval = (int) round($interval * 1.5);
            if ($defaultInterval > 0) {
                $interval = max($interval, (int) round($defaultInterval * 1.5));
            }
        } else {
            if ($lengthDelta > 500) {
                $interval = (int) round($interval * 0.6);
            } else {
                $interval = (int) round($interval * 0.8);
            }

            if ($defaultInterval > 0) {
                $interval = min($interval, max(15, $defaultInterval));
            }
        }

        if ($revision <= 2) {
            $cap = $defaultInterval > 0 ? max(30, $defaultInterval) : 240;
            $interval = min($interval, $cap);
        }

        return max(15, min($interval, 1440));
    }

    private function updateDiscoveryAfterCrawl(
        array &$ledger,
        array $result,
        array $context,
        int $defaultInterval
    ): void {
        if (isset($result['error'])) {
            return;
        }

        $candidates = [
            (string) ($result['normalized_url'] ?? ''),
            (string) ($result['url'] ?? ''),
            (string) ($context['url'] ?? ''),
        ];

        $url = '';
        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '') {
                $url = $candidate;
                break;
            }
        }

        if ($url === '') {
            return;
        }

        $key = $this->queueKey($url);
        if ($key === '') {
            return;
        }

        if (!isset($ledger[$key])) {
            $ledger[$key] = [
                'url' => $url,
                'seed' => !empty($context['seed']),
                'first_seen_at' => (string) ($result['fetched_at'] ?? date(DATE_ATOM)),
                'last_seen_at' => (string) ($result['last_checked_at'] ?? $result['fetched_at'] ?? date(DATE_ATOM)),
                'sources' => [],
                'schedule' => $this->defaultScheduleState(),
            ];
        }

        if (!isset($ledger[$key]['schedule']) || !is_array($ledger[$key]['schedule'])) {
            $ledger[$key]['schedule'] = $this->defaultScheduleState();
        } else {
            $ledger[$key]['schedule'] = $this->normaliseSchedule($ledger[$key]['schedule']);
        }

        if (!empty($context['seed'])) {
            $ledger[$key]['seed'] = true;
        }

        $timestamp = (string) ($result['last_checked_at'] ?? $result['fetched_at'] ?? date(DATE_ATOM));
        if (!isset($ledger[$key]['first_seen_at']) || $ledger[$key]['first_seen_at'] === '') {
            $ledger[$key]['first_seen_at'] = $timestamp;
        }

        $ledger[$key]['last_seen_at'] = $this->latestTimestamp(
            (string) ($ledger[$key]['last_seen_at'] ?? ''),
            $timestamp
        );

        $interval = $this->determineRefreshInterval($result, $ledger[$key], $defaultInterval);
        $this->registerSchedule($ledger, $url, 'crawl', $interval, null);
    }

    private function parseDateTime(string $value): ?DateTimeImmutable
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($trimmed);
        } catch (Throwable $exception) {
            return null;
        }
    }

    /**
     * @param array<string, array<string, mixed>> $tasks
     *
     * @return array<string, int>
     */
    private function summariseTaskTotals(array $tasks): array
    {
        $totals = $this->defaultTaskTotals();

        foreach ($tasks as $task) {
            if (!is_array($task)) {
                continue;
            }

            $status = (string) ($task['status'] ?? 'queued');
            if (!isset($totals[$status])) {
                continue;
            }

            $totals[$status]++;
        }

        return $totals;
    }

    /**
     * @return array<string, int>
     */
    private function defaultTaskTotals(): array
    {
        return [
            'queued' => 0,
            'running' => 0,
            'completed' => 0,
            'failed' => 0,
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $ledger
     * @param array<string, array<string, mixed>> $historyByQueueKey
     * @param array<int, array<string, mixed>> $tasks
     *
     * @return array<string, mixed>
     */
    private function summariseDiscoveryProgress(
        array $ledger,
        array $historyByQueueKey,
        array $tasks,
        ?string $currentUrl
    ): array {
        $taskIndex = $this->indexTasksByQueueKey($tasks);
        $currentKey = $this->queueKey((string) $currentUrl);
        if ($currentKey !== '' && isset($taskIndex[$currentKey])) {
            $taskIndex[$currentKey]['status'] = 'running';
        }

        $now = $this->safeNow();

        $domainStats = [];
        $links = [];
        $totals = [
            'links' => 0,
            'domains' => 0,
            'fresh' => 0,
            'recent' => 0,
            'stale' => 0,
            'overdue' => 0,
            'queued' => 0,
            'running' => 0,
            'new' => 0,
            'failed' => 0,
            'unknown' => 0,
        ];

        foreach ($ledger as $key => $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $url = (string) ($entry['url'] ?? '');
            if ($url === '') {
                continue;
            }

            $domain = $this->extractDomain($url);
            if ($domain === '') {
                $domain = 'unknown';
            }

            if (!isset($domainStats[$domain])) {
                $domainStats[$domain] = [
                    'domain' => $domain,
                    'total' => 0,
                    'fresh' => 0,
                    'recent' => 0,
                    'stale' => 0,
                    'overdue' => 0,
                    'queued' => 0,
                    'running' => 0,
                    'new' => 0,
                    'failed' => 0,
                    'unknown' => 0,
                    'last_crawled_at' => '',
                    'next_due_at' => null,
                ];
            }

            $historyEntry = $historyByQueueKey[$key] ?? null;
            $schedule = $this->normaliseSchedule($entry['schedule'] ?? []);
            $nextDueAt = isset($schedule['next_due_at']) && (string) $schedule['next_due_at'] !== ''
                ? (string) $schedule['next_due_at']
                : null;

            $taskMeta = $taskIndex[$key] ?? null;
            $taskStatus = is_array($taskMeta) ? (string) ($taskMeta['status'] ?? 'queued') : null;

            $lastCrawledAt = '';
            if (is_array($historyEntry)) {
                $lastCrawledAt = (string) ($historyEntry['last_checked_at'] ?? $historyEntry['fetched_at'] ?? '');
            }
            if ($lastCrawledAt === '') {
                $lastCrawledAt = (string) ($entry['last_seen_at'] ?? '');
            }

            $lastCrawledTime = $lastCrawledAt !== '' ? $this->parseDateTime($lastCrawledAt) : null;
            $nextDueTime = $nextDueAt !== null ? $this->parseDateTime($nextDueAt) : null;

            $freshnessMinutes = null;
            if ($now !== null && $lastCrawledTime !== null) {
                $diff = $now->getTimestamp() - $lastCrawledTime->getTimestamp();
                $freshnessMinutes = $diff <= 0 ? 0 : (int) floor($diff / 60);
            }

            $dueInMinutes = null;
            if ($now !== null && $nextDueTime !== null) {
                $diff = $nextDueTime->getTimestamp() - $now->getTimestamp();
                $dueInMinutes = (int) floor($diff / 60);
            }

            $status = 'unknown';
            if ($historyEntry === null && $lastCrawledAt === '') {
                $status = 'new';
            } elseif ($taskStatus === 'running') {
                $status = 'running';
            } elseif ($taskStatus === 'queued') {
                $status = 'queued';
            } elseif ($taskStatus === 'failed') {
                $status = 'failed';
            } elseif ($nextDueTime !== null && $now !== null && $nextDueTime <= $now) {
                $status = 'overdue';
            } elseif ($freshnessMinutes !== null) {
                if ($freshnessMinutes <= 60) {
                    $status = 'fresh';
                } elseif ($freshnessMinutes <= 360) {
                    $status = 'recent';
                } else {
                    $status = 'stale';
                }
            }

            if (!isset($totals[$status])) {
                $totals[$status] = 0;
            }
            $totals[$status]++;
            $totals['links']++;

            $domainStats[$domain]['total']++;
            if (!isset($domainStats[$domain][$status])) {
                $domainStats[$domain][$status] = 0;
            }
            $domainStats[$domain][$status]++;

            if ($lastCrawledAt !== '') {
                $currentDomainLast = (string) $domainStats[$domain]['last_crawled_at'];
                if ($currentDomainLast === '' || $currentDomainLast < $lastCrawledAt) {
                    $domainStats[$domain]['last_crawled_at'] = $lastCrawledAt;
                }
            }

            if ($nextDueAt !== null) {
                $currentDomainDue = $domainStats[$domain]['next_due_at'];
                if ($currentDomainDue === null || ($currentDomainDue !== null && $nextDueAt < $currentDomainDue)) {
                    $domainStats[$domain]['next_due_at'] = $nextDueAt;
                }
            }

            $links[] = [
                'url' => $url,
                'domain' => $domain,
                'status' => $status,
                'seed' => !empty($entry['seed']),
                'last_crawled_at' => $lastCrawledAt,
                'last_seen_at' => (string) ($entry['last_seen_at'] ?? ''),
                'next_due_at' => $nextDueAt,
                'freshness_minutes' => $freshnessMinutes,
                'due_in_minutes' => $dueInMinutes,
                'queued_at' => is_array($taskMeta) ? (string) ($taskMeta['queued_at'] ?? '') : null,
                'depth' => is_array($taskMeta) ? (int) ($taskMeta['depth'] ?? 0) : 0,
                'attempts' => is_array($taskMeta) ? (int) ($taskMeta['attempts'] ?? 0) : 0,
            ];
        }

        $statusOrder = [
            'running' => 0,
            'queued' => 1,
            'overdue' => 2,
            'failed' => 3,
            'fresh' => 4,
            'recent' => 5,
            'stale' => 6,
            'new' => 7,
            'unknown' => 8,
        ];

        usort($links, static function (array $left, array $right) use ($statusOrder): int {
            $leftStatus = (string) ($left['status'] ?? 'unknown');
            $rightStatus = (string) ($right['status'] ?? 'unknown');
            $leftOrder = $statusOrder[$leftStatus] ?? 9;
            $rightOrder = $statusOrder[$rightStatus] ?? 9;

            if ($leftOrder === $rightOrder) {
                $leftDue = (string) ($left['next_due_at'] ?? '');
                $rightDue = (string) ($right['next_due_at'] ?? '');
                if ($leftDue !== '' || $rightDue !== '') {
                    return $leftDue <=> $rightDue;
                }

                $leftSeen = (string) ($left['last_crawled_at'] ?? '');
                $rightSeen = (string) ($right['last_crawled_at'] ?? '');

                return $leftSeen <=> $rightSeen;
            }

            return $leftOrder <=> $rightOrder;
        });

        $domainList = array_values($domainStats);
        usort($domainList, static function (array $left, array $right): int {
            $priority = ['overdue', 'stale', 'queued', 'running', 'recent', 'fresh', 'new', 'failed'];
            foreach ($priority as $field) {
                $difference = (int) ($right[$field] ?? 0) <=> (int) ($left[$field] ?? 0);
                if ($difference !== 0) {
                    return $difference;
                }
            }

            return strcmp((string) ($left['domain'] ?? ''), (string) ($right['domain'] ?? ''));
        });

        $totals['domains'] = count($domainList);

        return [
            'generated_at' => date(DATE_ATOM),
            'totals' => [
                'links' => $totals['links'],
                'domains' => $totals['domains'],
                'fresh' => $totals['fresh'],
                'recent' => $totals['recent'],
                'stale' => $totals['stale'],
                'overdue' => $totals['overdue'],
                'queued' => $totals['queued'],
                'running' => $totals['running'],
                'new' => $totals['new'],
                'failed' => $totals['failed'],
                'unknown' => $totals['unknown'],
            ],
            'domains' => $domainList,
            'links' => $links,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $tasks
     *
     * @return array<string, array<string, mixed>>
     */
    private function indexTasksByQueueKey(array $tasks): array
    {
        $indexed = [];

        foreach ($tasks as $task) {
            if (!is_array($task)) {
                continue;
            }

            $url = isset($task['url']) ? (string) $task['url'] : '';
            if ($url === '') {
                continue;
            }

            $key = $this->queueKey($url);
            if ($key === '') {
                continue;
            }

            $indexed[$key] = $task;
        }

        return $indexed;
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultScheduleState(): array
    {
        return [
            'history' => [],
            'last_scheduled_at' => null,
            'next_due_at' => null,
            'total_runs' => 0,
        ];
    }

    private function registerSchedule(
        array &$ledger,
        string $url,
        string $reason,
        ?int $intervalMinutes,
        ?string $dueAt
    ): void {
        $url = trim($url);
        if ($url === '') {
            return;
        }

        $key = $this->queueKey($url);
        if ($key === '') {
            return;
        }

        if (!isset($ledger[$key])) {
            $ledger[$key] = [
                'url' => $url,
                'seed' => false,
                'first_seen_at' => date(DATE_ATOM),
                'last_seen_at' => date(DATE_ATOM),
                'sources' => [],
                'schedule' => $this->defaultScheduleState(),
            ];
        }

        if (!isset($ledger[$key]['schedule']) || !is_array($ledger[$key]['schedule'])) {
            $ledger[$key]['schedule'] = $this->defaultScheduleState();
        } else {
            $ledger[$key]['schedule'] = $this->normaliseSchedule($ledger[$key]['schedule']);
        }

        $intervalMinutes = $intervalMinutes !== null ? max(0, $intervalMinutes) : null;
        $timestamp = date(DATE_ATOM);
        $computedDueAt = $dueAt;

        if ($computedDueAt === null && $intervalMinutes !== null && $intervalMinutes > 0) {
            try {
                $computedDueAt = (new DateTimeImmutable($timestamp))
                    ->add(new DateInterval('PT' . $intervalMinutes . 'M'))
                    ->format(DATE_ATOM);
            } catch (Throwable $exception) {
                $computedDueAt = null;
            }
        }

        $history = is_array($ledger[$key]['schedule']['history'] ?? null)
            ? $ledger[$key]['schedule']['history']
            : [];

        $history[] = [
            'queued_at' => $timestamp,
            'due_at' => $computedDueAt,
            'reason' => $reason,
            'interval_minutes' => $intervalMinutes,
        ];

        if (count($history) > self::MAX_SCHEDULE_HISTORY) {
            $history = array_slice($history, -self::MAX_SCHEDULE_HISTORY);
        }

        $ledger[$key]['schedule']['history'] = $history;
        $ledger[$key]['schedule']['last_scheduled_at'] = $timestamp;
        if ($computedDueAt !== null) {
            $ledger[$key]['schedule']['next_due_at'] = $computedDueAt;
        } elseif (!isset($ledger[$key]['schedule']['next_due_at'])) {
            $ledger[$key]['schedule']['next_due_at'] = null;
        }

        $ledger[$key]['schedule']['total_runs'] = (int) ($ledger[$key]['schedule']['total_runs'] ?? 0) + 1;
    }

    /**
     * @param mixed $schedule
     *
     * @return array<string, mixed>
     */
    private function normaliseSchedule($schedule): array
    {
        $state = $this->defaultScheduleState();

        if (!is_array($schedule)) {
            return $state;
        }

        $state['last_scheduled_at'] = isset($schedule['last_scheduled_at']) && (string) $schedule['last_scheduled_at'] !== ''
            ? (string) $schedule['last_scheduled_at']
            : null;
        $state['next_due_at'] = isset($schedule['next_due_at']) && (string) $schedule['next_due_at'] !== ''
            ? (string) $schedule['next_due_at']
            : null;
        $state['total_runs'] = isset($schedule['total_runs'])
            ? max(0, (int) $schedule['total_runs'])
            : 0;

        $history = [];
        $rawHistory = is_array($schedule['history'] ?? null) ? $schedule['history'] : [];
        foreach ($rawHistory as $event) {
            if (!is_array($event)) {
                continue;
            }

            $queuedAt = (string) ($event['queued_at'] ?? '');
            if ($queuedAt === '') {
                continue;
            }

            $intervalRaw = $event['interval_minutes'] ?? null;
            $interval = $intervalRaw === null ? null : (int) $intervalRaw;
            $dueRaw = $event['due_at'] ?? null;
            $dueAt = is_string($dueRaw) && $dueRaw !== '' ? $dueRaw : null;

            $history[] = [
                'queued_at' => $queuedAt,
                'due_at' => $dueAt,
                'reason' => (string) ($event['reason'] ?? ''),
                'interval_minutes' => $interval,
            ];
        }

        if ($history !== []) {
            usort($history, static function (array $left, array $right): int {
                return (string) ($left['queued_at'] ?? '') <=> (string) ($right['queued_at'] ?? '');
            });
        }

        if (count($history) > self::MAX_SCHEDULE_HISTORY) {
            $history = array_slice($history, -self::MAX_SCHEDULE_HISTORY);
        }

        $state['history'] = $history;
        if ($state['total_runs'] === 0) {
            $state['total_runs'] = count($history);
        }

        return $state;
    }

    /**
     * @param array<string, mixed> $schedule
     *
     * @return array<string, mixed>
     */
    private function summariseSchedule(array $schedule): array
    {
        $normalised = $this->normaliseSchedule($schedule);

        $history = [];
        $events = array_reverse($normalised['history']);
        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }

            $history[] = [
                'queued_at' => (string) ($event['queued_at'] ?? ''),
                'due_at' => isset($event['due_at']) && $event['due_at'] !== null ? (string) $event['due_at'] : '',
                'reason' => (string) ($event['reason'] ?? ''),
                'interval_minutes' => isset($event['interval_minutes']) && $event['interval_minutes'] !== null
                    ? (int) $event['interval_minutes']
                    : null,
            ];
        }

        return [
            'total_runs' => (int) ($normalised['total_runs'] ?? count($history)),
            'last_scheduled_at' => (string) ($normalised['last_scheduled_at'] ?? ''),
            'next_due_at' => (string) ($normalised['next_due_at'] ?? ''),
            'history' => $history,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function scheduledQueue(int $limit = 25): array
    {
        $limit = max(1, $limit);

        return array_slice($this->summariseScheduledQueue($this->loadScheduledQueue(), $limit), 0, $limit);
    }

    /**
     * @return array<string, mixed>
     */
    public function runScheduledQueue(
        int $limit = 5,
        int $maxDepth = 1,
        int $autoInterval = 0,
        bool $autoStart = false,
        int $refreshAfterMinutes = 0
    ): array {
        $limit = max(1, $limit);
        $maxDepth = max(0, $maxDepth);
        $autoInterval = max(0, $autoInterval);
        $refreshAfterMinutes = max(0, $refreshAfterMinutes);

        $queue = $this->loadScheduledQueue();
        if ($queue === []) {
            return [
                'processed' => 0,
                'targets' => [],
                'scheduled_remaining' => 0,
                'results' => [],
            ];
        }

        $targets = array_slice($queue, 0, $limit);
        $remaining = array_slice($queue, count($targets));
        $this->storeScheduledQueue($remaining);

        $targetList = [];
        foreach ($targets as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $url = isset($entry['url']) ? (string) $entry['url'] : '';
            if ($url === '') {
                continue;
            }

            $targetList[] = [
                'url' => $url,
                'depth' => isset($entry['depth']) ? max(0, (int) $entry['depth']) : 0,
                'priority' => isset($entry['priority']) ? (float) $entry['priority'] : null,
                'seed' => !empty($entry['seed']),
            ];
        }

        if ($targetList === []) {
            return [
                'processed' => 0,
                'targets' => [],
                'scheduled_remaining' => count($remaining),
                'results' => [],
            ];
        }

        $results = $this->crawl(
            $targetList,
            $maxDepth,
            $autoInterval,
            $autoStart,
            $refreshAfterMinutes,
            true
        );

        return [
            'processed' => count($results),
            'targets' => array_map(static fn(array $target): string => (string) $target['url'], $targetList),
            'scheduled_remaining' => count($remaining),
            'results' => $results,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function scheduleRecommended(int $limit = 10, int $depth = 1): array
    {
        $limit = max(1, $limit);
        $depth = max(0, $depth);

        $snapshot = $this->discoveryTree(8, 6, 3, $limit * 3);
        $recommended = isset($snapshot['recommended']) && is_array($snapshot['recommended'])
            ? $snapshot['recommended']
            : [];

        $queue = $this->loadScheduledQueue();
        $index = $this->indexScheduledQueue($queue);
        $scheduled = 0;

        foreach ($recommended as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $url = isset($candidate['url']) ? (string) $candidate['url'] : '';
            if ($url === '') {
                continue;
            }

            $priorityScore = isset($candidate['score'])
                ? max(0.0, (float) $candidate['score'] / 100.0)
                : $this->baseScoreForDomain($this->extractDomain($url));

            if ($this->scheduleDiscovery($queue, $index, $url, $depth, $priorityScore, false, null)) {
                $scheduled++;
            }

            if ($scheduled >= $limit) {
                break;
            }
        }

        if ($scheduled > 0) {
            $this->storeScheduledQueue($queue);
        }

        return [
            'scheduled' => $scheduled,
            'total' => count($queue),
            'snapshot' => $snapshot,
            'preview' => $this->summariseScheduledQueue($queue),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadScheduledQueue(): array
    {
        if (!file_exists($this->scheduledQueuePath)) {
            return [];
        }

        $contents = file_get_contents($this->scheduledQueuePath);
        if (!is_string($contents) || trim($contents) === '') {
            return [];
        }

        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            return [];
        }

        $queue = [];
        $index = [];

        foreach ($decoded as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $url = isset($entry['url']) ? (string) $entry['url'] : '';
            if ($url === '') {
                continue;
            }

            $key = $this->queueKey($url);
            if ($key === '' || isset($index[$key])) {
                continue;
            }

            $queue[] = [
                'url' => $url,
                'depth' => isset($entry['depth']) ? max(0, (int) $entry['depth']) : 0,
                'priority' => isset($entry['priority']) ? (float) $entry['priority'] : $this->baseScoreForDomain($this->extractDomain($url)),
                'seed' => !empty($entry['seed']),
                'queued_at' => isset($entry['queued_at']) && (string) $entry['queued_at'] !== ''
                    ? (string) $entry['queued_at']
                    : date(DATE_ATOM),
                'parent_url' => isset($entry['parent_url']) ? (string) $entry['parent_url'] : null,
            ];
            $index[$key] = true;
        }

        $this->sortScheduledQueue($queue);

        if (count($queue) > self::MAX_SCHEDULED_QUEUE) {
            $queue = array_slice($queue, 0, self::MAX_SCHEDULED_QUEUE);
        }

        return $queue;
    }

    /**
     * @param array<int, array<string, mixed>> $queue
     */
    private function storeScheduledQueue(array $queue): void
    {
        $this->sortScheduledQueue($queue);

        if (count($queue) > self::MAX_SCHEDULED_QUEUE) {
            $queue = array_slice($queue, 0, self::MAX_SCHEDULED_QUEUE);
        }

        $serialisable = [];
        foreach ($queue as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $url = isset($entry['url']) ? (string) $entry['url'] : '';
            if ($url === '') {
                continue;
            }

            $serialisable[] = [
                'url' => $url,
                'depth' => isset($entry['depth']) ? max(0, (int) $entry['depth']) : 0,
                'priority' => isset($entry['priority']) ? (float) $entry['priority'] : $this->baseScoreForDomain($this->extractDomain($url)),
                'seed' => !empty($entry['seed']),
                'queued_at' => isset($entry['queued_at']) && (string) $entry['queued_at'] !== ''
                    ? (string) $entry['queued_at']
                    : date(DATE_ATOM),
                'parent_url' => isset($entry['parent_url']) && (string) $entry['parent_url'] !== ''
                    ? (string) $entry['parent_url']
                    : null,
            ];
        }

        file_put_contents(
            $this->scheduledQueuePath,
            json_encode($serialisable, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
    }

    /**
     * @param array<int, array<string, mixed>> $queue
     */
    private function sortScheduledQueue(array &$queue): void
    {
        if ($queue === []) {
            return;
        }

        $ledger = $this->discoveryLedger();
        $now = $this->safeNow();

        usort($queue, function (array $left, array $right) use ($ledger, $now): int {
            return $this->compareScheduledEntries($left, $right, $ledger, $now);
        });
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     * @param array<string, array<string, mixed>> $ledger
     */
    private function compareScheduledEntries(
        array $left,
        array $right,
        array $ledger,
        ?DateTimeImmutable $now
    ): int {
        $leftMeta = $this->scheduledEntryMeta($left, $ledger, $now);
        $rightMeta = $this->scheduledEntryMeta($right, $ledger, $now);

        if ($leftMeta['due_sort'] !== $rightMeta['due_sort']) {
            return $leftMeta['due_sort'] <=> $rightMeta['due_sort'];
        }

        $leftPriority = (float) $leftMeta['priority'];
        $rightPriority = (float) $rightMeta['priority'];
        if (abs($leftPriority - $rightPriority) > 0.0001) {
            return $rightPriority <=> $leftPriority;
        }

        return $leftMeta['queued_sort'] <=> $rightMeta['queued_sort'];
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, array<string, mixed>> $ledger
     *
     * @return array{
     *     due_sort: int,
     *     queued_sort: int,
     *     priority: float,
     *     due_at: string,
     *     freshness_state: string,
     *     freshness_label: string,
     *     queued_label: string,
     *     last_seen_at: string,
     *     staleness_minutes: int|null
     * }
     */
    private function scheduledEntryMeta(
        array $entry,
        array $ledger,
        ?DateTimeImmutable $now
    ): array {
        $priority = (float) ($entry['priority'] ?? 0.0);
        $queuedAtRaw = (string) ($entry['queued_at'] ?? '');
        $queuedAt = $this->parseDateTime($queuedAtRaw);
        $queuedSort = $queuedAt !== null ? $queuedAt->getTimestamp() : PHP_INT_MAX;
        $queuedLabel = $this->formatQueuedDescriptor($queuedAt, $now);

        $url = isset($entry['url']) ? (string) $entry['url'] : '';
        $ledgerEntry = null;
        if ($url !== '') {
            $key = $this->queueKey($url);
            if ($key !== '' && isset($ledger[$key]) && is_array($ledger[$key])) {
                $ledgerEntry = $ledger[$key];
            }
        }

        $dueTimestamp = null;
        $dueAtString = '';
        $state = 'queued';
        $label = $queuedLabel;
        $lastSeen = '';
        $stalenessMinutes = null;

        if (is_array($ledgerEntry)) {
            $lastSeen = (string) ($ledgerEntry['last_seen_at'] ?? '');
            $schedule = $this->normaliseSchedule($ledgerEntry['schedule'] ?? []);

            $due = null;
            $dueRaw = (string) ($schedule['next_due_at'] ?? '');
            if ($dueRaw !== '') {
                $due = $this->parseDateTime($dueRaw);
            }

            $intervalMinutes = $this->extractScheduleInterval($schedule);
            if ($due === null && $intervalMinutes !== null && $intervalMinutes > 0) {
                $origin = null;
                $lastScheduledRaw = (string) ($schedule['last_scheduled_at'] ?? '');
                if ($lastScheduledRaw !== '') {
                    $origin = $this->parseDateTime($lastScheduledRaw);
                }
                if ($origin === null && $lastSeen !== '') {
                    $origin = $this->parseDateTime($lastSeen);
                }
                if ($origin === null) {
                    $origin = $queuedAt;
                }

                if ($origin !== null) {
                    $due = $this->addMinutes($origin, $intervalMinutes);
                }
            }

            if ($due !== null) {
                $dueTimestamp = $due->getTimestamp();
                $dueAtString = $due->format(DATE_ATOM);
                $descriptor = $this->formatDueDescriptor($due, $now);
                $label = $descriptor['label'];
                $state = $descriptor['state'];
            } else {
                $lastSeenTime = $lastSeen !== '' ? $this->parseDateTime($lastSeen) : null;
                if ($lastSeenTime !== null && $now !== null) {
                    $diffSeconds = max(0, $now->getTimestamp() - $lastSeenTime->getTimestamp());
                    $stalenessMinutes = (int) floor($diffSeconds / 60);
                    if ($stalenessMinutes >= 1440) {
                        $label = 'Stale for ' . $this->describeInterval($diffSeconds);
                        $state = 'stale';
                    } elseif ($stalenessMinutes >= 360) {
                        $label = 'Ready for refresh';
                        $state = 'due_soon';
                    }
                }
            }
        }

        return [
            'due_sort' => $dueTimestamp ?? $queuedSort,
            'queued_sort' => $queuedSort,
            'priority' => $priority,
            'due_at' => $dueAtString,
            'freshness_state' => $state,
            'freshness_label' => $label,
            'queued_label' => $queuedLabel,
            'last_seen_at' => $lastSeen,
            'staleness_minutes' => $stalenessMinutes,
        ];
    }

    /**
     * @param array<string, mixed> $schedule
     */
    private function extractScheduleInterval(array $schedule): ?int
    {
        $history = is_array($schedule['history'] ?? null) ? $schedule['history'] : [];
        if ($history === []) {
            return null;
        }

        $events = array_reverse($history);
        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }

            if (isset($event['interval_minutes']) && $event['interval_minutes'] !== null) {
                $candidate = (int) $event['interval_minutes'];
                if ($candidate > 0) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    private function addMinutes(DateTimeImmutable $timestamp, int $minutes): DateTimeImmutable
    {
        $minutes = max(0, $minutes);
        if ($minutes === 0) {
            return $timestamp;
        }

        try {
            return $timestamp->add(new DateInterval('PT' . $minutes . 'M'));
        } catch (Throwable $exception) {
            return $timestamp;
        }
    }

    private function describeInterval(int $seconds): string
    {
        $seconds = max(0, $seconds);
        if ($seconds < 60) {
            return 'under a minute';
        }

        $minutes = (int) floor($seconds / 60);
        if ($minutes < 60) {
            return $minutes === 1 ? '1 minute' : $minutes . ' minutes';
        }

        $hours = (int) floor($minutes / 60);
        if ($hours < 24) {
            return $hours === 1 ? '1 hour' : $hours . ' hours';
        }

        $days = (int) floor($hours / 24);
        if ($days < 7) {
            return $days === 1 ? '1 day' : $days . ' days';
        }

        $weeks = (int) floor($days / 7);
        if ($weeks < 5) {
            return $weeks === 1 ? '1 week' : $weeks . ' weeks';
        }

        $months = (int) floor($days / 30);
        if ($months < 18) {
            return $months === 1 ? '1 month' : $months . ' months';
        }

        $years = (int) floor($days / 365);

        return $years <= 1 ? '1 year' : $years . ' years';
    }

    private function formatDueDescriptor(DateTimeImmutable $due, ?DateTimeImmutable $now): array
    {
        if ($now === null) {
            return [
                'label' => 'Due ' . $due->format('M j, H:i'),
                'state' => 'scheduled',
            ];
        }

        $diff = $due->getTimestamp() - $now->getTimestamp();
        if ($diff <= 0) {
            return [
                'label' => 'Overdue by ' . $this->describeInterval(abs($diff)),
                'state' => 'overdue',
            ];
        }

        $label = 'Due in ' . $this->describeInterval($diff);
        $state = $diff <= 3600 ? 'due_soon' : ($diff <= 21600 ? 'due_next' : 'scheduled');

        return [
            'label' => $label,
            'state' => $state,
        ];
    }

    private function formatQueuedDescriptor(?DateTimeImmutable $queuedAt, ?DateTimeImmutable $now): string
    {
        if ($queuedAt === null) {
            return 'Queued';
        }

        if ($now === null) {
            return 'Queued ' . $queuedAt->format(DATE_ATOM);
        }

        $diff = $now->getTimestamp() - $queuedAt->getTimestamp();
        if ($diff <= 0) {
            return 'Queued moments ago';
        }

        if ($diff < 60) {
            return 'Queued moments ago';
        }

        return 'Queued ' . $this->describeInterval($diff) . ' ago';
    }

    /**
     * @param array<int, array<string, mixed>> $queue
     *
     * @return array<string, bool>
     */
    private function indexScheduledQueue(array $queue): array
    {
        $index = [];

        foreach ($queue as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $url = isset($entry['url']) ? (string) $entry['url'] : '';
            if ($url === '') {
                continue;
            }

            $key = $this->queueKey($url);
            if ($key === '') {
                continue;
            }

            $index[$key] = true;
        }

        return $index;
    }

    /**
     * @param array<int, array<string, mixed>> $queue
     *
     * @return array<int, array<string, mixed>>
     */
    private function summariseScheduledQueue(array $queue, int $limit = 12): array
    {
        $this->sortScheduledQueue($queue);

        $preview = [];
        $ledger = $this->discoveryLedger();
        $now = $this->safeNow();

        foreach (array_slice($queue, 0, max(0, $limit)) as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $url = isset($entry['url']) ? (string) $entry['url'] : '';
            if ($url === '') {
                continue;
            }

            $meta = $this->scheduledEntryMeta($entry, $ledger, $now);
            $preview[] = [
                'url' => $url,
                'domain' => $this->extractDomain($url),
                'depth' => isset($entry['depth']) ? max(0, (int) $entry['depth']) : 0,
                'priority' => round((float) ($entry['priority'] ?? 0.0), 3),
                'queued_at' => isset($entry['queued_at']) ? (string) $entry['queued_at'] : '',
                'seed' => !empty($entry['seed']),
                'due_at' => (string) $meta['due_at'],
                'freshness_state' => (string) $meta['freshness_state'],
                'freshness_label' => (string) $meta['freshness_label'],
                'queued_label' => (string) $meta['queued_label'],
                'last_seen_at' => (string) $meta['last_seen_at'],
                'staleness_minutes' => $meta['staleness_minutes'],
            ];
        }

        return $preview;
    }

    private function scheduleDiscovery(
        array &$queue,
        array &$index,
        string $url,
        int $depth,
        float $priority,
        bool $seed,
        ?string $parentUrl
    ): bool {
        $url = trim($url);
        if ($url === '') {
            return false;
        }

        $key = $this->queueKey($url);
        if ($key === '' || isset($index[$key])) {
            return false;
        }

        $entry = [
            'url' => $url,
            'depth' => max(0, $depth),
            'priority' => max(0.0, $priority),
            'seed' => $seed,
            'queued_at' => date(DATE_ATOM),
        ];

        if ($parentUrl !== null && $parentUrl !== '') {
            $entry['parent_url'] = $parentUrl;
        }

        $queue[] = $entry;
        $this->sortScheduledQueue($queue);

        if (count($queue) > self::MAX_SCHEDULED_QUEUE) {
            $queue = array_slice($queue, 0, self::MAX_SCHEDULED_QUEUE);
        }

        $index = $this->indexScheduledQueue($queue);

        return true;
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     *
     * @return array<string, array<string, mixed>>
     */
    private function initialiseDiscoveryLedger(array $entries): array
    {
        $ledger = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $normalizedUrl = (string) ($entry['normalized_url'] ?? '');
            $url = (string) ($entry['url'] ?? $normalizedUrl);
            $candidate = $normalizedUrl !== '' ? $normalizedUrl : $url;
            $key = $candidate !== '' ? $this->queueKey($candidate) : $this->queueKey($url);
            if ($key === '') {
                continue;
            }

            $discovery = is_array($entry['discovery'] ?? null) ? $entry['discovery'] : [];
            $firstSeen = (string) ($discovery['first_seen_at'] ?? ($entry['fetched_at'] ?? ''));
            $lastSeen = (string) ($discovery['last_seen_at'] ?? ($entry['last_checked_at'] ?? ($entry['fetched_at'] ?? '')));

            $ledger[$key] = [
                'url' => $candidate !== '' ? $candidate : $url,
                'seed' => !empty($discovery['seed']),
                'first_seen_at' => $firstSeen,
                'last_seen_at' => $lastSeen,
                'sources' => [],
                'schedule' => $this->normaliseSchedule($discovery['schedule'] ?? []),
            ];

            $sources = is_array($discovery['sources'] ?? null) ? $discovery['sources'] : [];
            foreach ($sources as $source) {
                if (!is_array($source)) {
                    continue;
                }

                $sourceUrl = (string) ($source['url'] ?? '');
                if ($sourceUrl === '') {
                    continue;
                }

                $sourceKey = $this->queueKey($sourceUrl);
                if ($sourceKey === '') {
                    continue;
                }

                $ledger[$key]['sources'][$sourceKey] = [
                    'url' => $sourceUrl,
                    'domain' => (string) ($source['domain'] ?? $this->extractDomain($sourceUrl)),
                    'count' => (int) ($source['count'] ?? 1),
                    'last_seen_at' => (string) ($source['last_seen_at'] ?? $lastSeen),
                ];
            }

            if (!isset($ledger[$key]['schedule'])) {
                $ledger[$key]['schedule'] = $this->defaultScheduleState();
            }
        }

        return $ledger;
    }

    /**
     * Lazily load the discovery ledger derived from stored crawl history.
     *
     * @return array<string, array<string, mixed>>
     */
    private function discoveryLedger(): array
    {
        if ($this->discoveryLedgerCache !== null) {
            return $this->discoveryLedgerCache;
        }

        $entries = $this->loadStoredEntries();
        $this->discoveryLedgerCache = $this->initialiseDiscoveryLedger($entries);

        return $this->discoveryLedgerCache;
    }

    private function resetDiscoveryLedgerCache(): void
    {
        $this->discoveryLedgerCache = null;
    }

    private function safeNow(): ?DateTimeImmutable
    {
        try {
            return new DateTimeImmutable();
        } catch (Throwable $exception) {
            return null;
        }
    }

    private function registerDiscovery(array &$ledger, string $url, ?string $source, bool $seed): void
    {
        $url = trim($url);
        if ($url === '') {
            return;
        }

        $key = $this->queueKey($url);
        if ($key === '') {
            return;
        }

        $timestamp = date(DATE_ATOM);

        if (!isset($ledger[$key])) {
            $ledger[$key] = [
                'url' => $url,
                'seed' => $seed,
                'first_seen_at' => $timestamp,
                'last_seen_at' => $timestamp,
                'sources' => [],
                'schedule' => $this->defaultScheduleState(),
            ];
        } else {
            if (!isset($ledger[$key]['url']) || $ledger[$key]['url'] === '') {
                $ledger[$key]['url'] = $url;
            }
            if (!isset($ledger[$key]['first_seen_at']) || $ledger[$key]['first_seen_at'] === '') {
                $ledger[$key]['first_seen_at'] = $timestamp;
            }
            $ledger[$key]['last_seen_at'] = $this->latestTimestamp(
                (string) ($ledger[$key]['last_seen_at'] ?? ''),
                $timestamp
            );
            if ($seed) {
                $ledger[$key]['seed'] = true;
            }

            if (!isset($ledger[$key]['schedule']) || !is_array($ledger[$key]['schedule'])) {
                $ledger[$key]['schedule'] = $this->defaultScheduleState();
            } else {
                $ledger[$key]['schedule'] = $this->normaliseSchedule($ledger[$key]['schedule']);
            }
        }

        if ($source === null) {
            return;
        }

        $source = trim($source);
        if ($source === '' || $source === $url) {
            return;
        }

        $sourceKey = $this->queueKey($source);
        if ($sourceKey === '') {
            return;
        }

        if (!isset($ledger[$key]['sources'][$sourceKey])) {
            $ledger[$key]['sources'][$sourceKey] = [
                'url' => $source,
                'domain' => $this->extractDomain($source),
                'count' => 0,
                'last_seen_at' => $timestamp,
            ];
        }

        $ledger[$key]['sources'][$sourceKey]['count'] = (int) ($ledger[$key]['sources'][$sourceKey]['count'] ?? 0) + 1;
        $ledger[$key]['sources'][$sourceKey]['last_seen_at'] = $timestamp;
    }

    private function reconcileDiscoveryKey(array &$ledger, string $originalUrl, string $normalizedUrl, string $resultUrl): void
    {
        $originalKey = $this->queueKey($originalUrl);
        if ($originalKey === '') {
            $originalKey = $this->queueKey($resultUrl);
        }

        $targetCandidate = $normalizedUrl !== '' ? $normalizedUrl : ($resultUrl !== '' ? $resultUrl : $originalUrl);
        $targetKey = $this->queueKey($targetCandidate);

        if ($originalKey === '' || $targetKey === '' || $originalKey === $targetKey) {
            if ($originalKey !== '' && isset($ledger[$originalKey])) {
                if ($normalizedUrl !== '') {
                    $ledger[$originalKey]['url'] = $normalizedUrl;
                } elseif ($resultUrl !== '') {
                    $ledger[$originalKey]['url'] = $resultUrl;
                }
            }

            return;
        }

        if (!isset($ledger[$originalKey])) {
            return;
        }

        $entry = $ledger[$originalKey];
        $entry['url'] = $targetCandidate;

        if (isset($ledger[$targetKey])) {
            $ledger[$targetKey]['seed'] = !empty($ledger[$targetKey]['seed']) || !empty($entry['seed']);
            $ledger[$targetKey]['first_seen_at'] = $this->earliestTimestamp(
                (string) ($ledger[$targetKey]['first_seen_at'] ?? ''),
                (string) ($entry['first_seen_at'] ?? '')
            );
            $ledger[$targetKey]['last_seen_at'] = $this->latestTimestamp(
                (string) ($ledger[$targetKey]['last_seen_at'] ?? ''),
                (string) ($entry['last_seen_at'] ?? '')
            );

            foreach ($entry['sources'] ?? [] as $sourceKey => $sourceMeta) {
                if (!is_array($sourceMeta)) {
                    continue;
                }

                if (!isset($ledger[$targetKey]['sources'][$sourceKey])) {
                    $ledger[$targetKey]['sources'][$sourceKey] = $sourceMeta;
                    continue;
                }

                $ledger[$targetKey]['sources'][$sourceKey]['count'] = (int) ($ledger[$targetKey]['sources'][$sourceKey]['count'] ?? 0)
                    + (int) ($sourceMeta['count'] ?? 0);
                $ledger[$targetKey]['sources'][$sourceKey]['last_seen_at'] = $this->latestTimestamp(
                    (string) ($ledger[$targetKey]['sources'][$sourceKey]['last_seen_at'] ?? ''),
                    (string) ($sourceMeta['last_seen_at'] ?? '')
                );
            }
        } else {
            $ledger[$targetKey] = $entry;
        }

        unset($ledger[$originalKey]);
    }

    private function earliestTimestamp(string $left, string $right): string
    {
        if ($left === '') {
            return $right;
        }

        if ($right === '') {
            return $left;
        }

        return $left <= $right ? $left : $right;
    }

    private function latestTimestamp(string $left, string $right): string
    {
        if ($left === '') {
            return $right;
        }

        if ($right === '') {
            return $left;
        }

        return $left >= $right ? $left : $right;
    }

    /**
     * @param array<string, array<string, mixed>> $history
     * @param array<string, array<string, mixed>> $ledger
     *
     * @return array{0: array<string, array<string, mixed>>, 1: array<string, array<string, mixed>>}
     */
    private function finaliseHistoryMetadata(array $history, array $ledger): array
    {
        $history = $this->applyDiscoveryMetadata($history, $ledger);

        return $this->applyAuthorityProfiles($history, $ledger);
    }

    /**
     * @param array<string, array<string, mixed>> $history
     * @param array<string, array<string, mixed>> $ledger
     *
     * @return array<string, array<string, mixed>>
     */
    private function applyDiscoveryMetadata(array $history, array $ledger): array
    {
        foreach ($history as $key => $entry) {
            $lookupKey = $this->queueKey((string) ($entry['normalized_url'] ?? $entry['url'] ?? ''));
            if ($lookupKey === '' || !isset($ledger[$lookupKey])) {
                continue;
            }

            $history[$key]['discovery'] = $this->formatDiscoveryForEntry($ledger[$lookupKey]);
        }

        return $history;
    }

    /**
     * @param array<string, mixed> $ledgerEntry
     *
     * @return array<string, mixed>
     */
    private function formatDiscoveryForEntry(array $ledgerEntry): array
    {
        $sources = [];
        $totalReferences = 0;
        $uniqueDomains = [];

        $ledgerSources = is_array($ledgerEntry['sources'] ?? null) ? $ledgerEntry['sources'] : [];
        foreach ($ledgerSources as $source) {
            if (!is_array($source)) {
                continue;
            }

            $url = (string) ($source['url'] ?? '');
            if ($url === '') {
                continue;
            }

            $domain = (string) ($source['domain'] ?? $this->extractDomain($url));
            $count = max(1, (int) ($source['count'] ?? 1));
            $totalReferences += $count;

            if ($domain !== '') {
                $uniqueDomains[$domain] = true;
            }

            $sources[] = [
                'url' => $url,
                'domain' => $domain,
                'count' => $count,
                'last_seen_at' => (string) ($source['last_seen_at'] ?? ''),
            ];
        }

        usort($sources, static function (array $left, array $right): int {
            return (int) ($right['count'] ?? 0) <=> (int) ($left['count'] ?? 0);
        });

        return [
            'seed' => !empty($ledgerEntry['seed']),
            'first_seen_at' => (string) ($ledgerEntry['first_seen_at'] ?? ''),
            'last_seen_at' => (string) ($ledgerEntry['last_seen_at'] ?? ''),
            'sources' => array_slice($sources, 0, 20),
            'total_sources' => $totalReferences,
            'unique_source_domains' => count($uniqueDomains),
            'schedule' => $this->summariseSchedule($ledgerEntry['schedule'] ?? []),
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $history
     * @param array<string, array<string, mixed>> $ledger
     *
     * @return array{0: array<string, array<string, mixed>>, 1: array<string, array<string, mixed>>}
     */
    private function applyAuthorityProfiles(array $history, array $ledger): array
    {
        $pageMetrics = [];
        $domainProfiles = [];

        foreach ($ledger as $key => $meta) {
            if (!is_array($meta)) {
                continue;
            }

            $url = (string) ($meta['url'] ?? $key);
            $domain = $this->extractDomain($url);
            $sources = is_array($meta['sources'] ?? null) ? $meta['sources'] : [];

            $weightedInbound = 0.0;
            $inboundCount = 0;
            $uniqueDomains = [];

            foreach ($sources as $sourceMeta) {
                if (!is_array($sourceMeta)) {
                    continue;
                }

                $count = max(1, (int) ($sourceMeta['count'] ?? 0));
                $sourceDomain = (string) ($sourceMeta['domain'] ?? $this->extractDomain((string) ($sourceMeta['url'] ?? '')));
                if ($sourceDomain === '') {
                    continue;
                }

                $domainScore = $this->baseScoreForDomain($sourceDomain);
                $weightedInbound += $domainScore * log(1 + $count);
                $inboundCount += $count;
                $uniqueDomains[$sourceDomain] = true;
            }

            $baseline = $this->baseScoreForDomain($domain);
            $diversityBoost = $uniqueDomains === [] ? 0.0 : min(0.25, count($uniqueDomains) * 0.05);
            $weightedBoost = $weightedInbound > 0 ? min(0.4, log(1 + $weightedInbound) / 3) : 0.0;
            $pageAuthority = round(min(1.0, max(0.0, $baseline + $diversityBoost + $weightedBoost)), 3);

            $pageMetrics[$key] = [
                'page_authority' => $pageAuthority,
                'baseline' => $baseline,
                'domain' => $domain,
                'inbound_links' => $inboundCount,
                'unique_sources' => count($uniqueDomains),
            ];

            if ($domain === '') {
                continue;
            }

            if (!isset($domainProfiles[$domain])) {
                $domainProfiles[$domain] = [
                    'domain' => $domain,
                    'page_count' => 0,
                    'authority_sum' => 0.0,
                    'baseline' => $this->baseScoreForDomain($domain),
                    'inbound_links' => 0,
                    'unique_source_domains' => [],
                    'top_page' => ['key' => $key, 'authority' => $pageAuthority],
                ];
            }

            $domainProfiles[$domain]['page_count']++;
            $domainProfiles[$domain]['authority_sum'] += $pageAuthority;
            $domainProfiles[$domain]['inbound_links'] += $inboundCount;
            if ($pageAuthority > $domainProfiles[$domain]['top_page']['authority']) {
                $domainProfiles[$domain]['top_page'] = ['key' => $key, 'authority' => $pageAuthority];
            }

            foreach (array_keys($uniqueDomains) as $refDomain) {
                $domainProfiles[$domain]['unique_source_domains'][$refDomain] = true;
            }
        }

        foreach ($domainProfiles as $domain => &$profile) {
            $average = $profile['page_count'] > 0 ? $profile['authority_sum'] / $profile['page_count'] : 0.0;
            $diversityBoost = min(0.25, count($profile['unique_source_domains']) * 0.05);
            $volumeBoost = $profile['page_count'] > 1 ? min(0.15, log(1 + $profile['page_count']) / 3) : 0.0;
            $profile['domain_authority'] = round(
                min(1.0, max(0.0, $profile['baseline'] + ($average * 0.4) + $diversityBoost + $volumeBoost)),
                3
            );
            $profile['average_page_authority'] = round($average, 3);
            $profile['unique_source_domains'] = count($profile['unique_source_domains']);
        }
        unset($profile);

        foreach ($history as $key => $entry) {
            $lookupKey = $this->queueKey((string) ($entry['normalized_url'] ?? $entry['url'] ?? ''));
            if ($lookupKey === '') {
                continue;
            }

            if (isset($pageMetrics[$lookupKey])) {
                $metrics = $pageMetrics[$lookupKey];
                $domainAuthority = $metrics['domain'] !== '' && isset($domainProfiles[$metrics['domain']])
                    ? (float) $domainProfiles[$metrics['domain']]['domain_authority']
                    : $metrics['baseline'];

                $history[$key]['ranking'] = [
                    'page_authority' => $metrics['page_authority'],
                    'domain_authority' => $domainAuthority,
                    'inbound_links' => $metrics['inbound_links'],
                    'unique_sources' => $metrics['unique_sources'],
                ];
            } elseif (!isset($history[$key]['ranking'])) {
                $history[$key]['ranking'] = [
                    'page_authority' => 0.0,
                    'domain_authority' => 0.0,
                    'inbound_links' => 0,
                    'unique_sources' => 0,
                ];
            }
        }

        return [$history, $domainProfiles];
    }

    /**
     * @param array<string, array<string, mixed>> $history
     * @param array<int, string> $processedKeys
     *
     * @return array<int, array<string, mixed>>
     */
    private function collectProcessedEntries(array $history, array $processedKeys): array
    {
        if ($processedKeys === []) {
            return [];
        }

        $entries = [];
        foreach ($processedKeys as $key) {
            if (!isset($history[$key])) {
                continue;
            }

            $entries[] = $history[$key];
        }

        return $entries;
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
            return [$history, $this->createFailedEntry($url, $exception->getMessage()), null];
        }

        $rawText = $scraped->text();
        $contentDigest = $this->digestContent($rawText);

        $analysis = $this->refiner->analyseDocument($rawText);
        $meaningful = (bool) ($analysis['is_meaningful'] ?? false);

        $semanticProfile = $this->refiner->buildSemanticProfile($scraped->text(), 14);
        $semanticTermWeights = $this->normaliseSemanticWeights($semanticProfile['term_weights'] ?? [], 18);
        $semanticPhraseWeights = $this->normaliseSemanticPhraseWeights($semanticProfile['phrase_weights'] ?? [], 18);
        $semanticTags = [];
        if (isset($semanticProfile['terms']) && is_array($semanticProfile['terms'])) {
            foreach ($semanticProfile['terms'] as $term) {
                if (!is_string($term)) {
                    continue;
                }

                $normalized = trim(mb_strtolower($term, 'UTF-8'));
                if ($normalized === '') {
                    continue;
                }

                $semanticTags[] = $normalized;
            }
        }
        if ($semanticTags === []) {
            $semanticTags = array_keys($semanticTermWeights);
        }
        $semanticTags = array_slice(array_values(array_unique($semanticTags)), 0, 18);

        $keyPhrases = $this->normaliseKeyPhrases($semanticProfile['key_phrases'] ?? [], 12);
        $semanticFingerprint = isset($semanticProfile['fingerprint']) && is_string($semanticProfile['fingerprint'])
            ? trim($semanticProfile['fingerprint'])
            : '';
        $semanticHighlights = $this->refiner->semanticHighlights($scraped->text(), 4);

        $keywords = $this->formatKeywords($analysis['keywords'] ?? []);
        $entities = $this->extractEntities($analysis['analytics']['entities']['top_entities'] ?? []);
        if (!$meaningful) {
            $keywords = [];
            $entities = [];
            $semanticTags = [];
            $semanticTermWeights = [];
            $semanticPhraseWeights = [];
            $keyPhrases = [];
            $semanticFingerprint = '';
            $semanticHighlights = [];
        }
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
        if ($contentDigest === '' && $summaryClean !== '') {
            $contentDigest = $this->digestContent($summaryClean);
        }
        if (!$meaningful) {
            $preview = '';
            $summaryClean = '';
        }
        $narrativeAnalytics = is_array($analysis['analytics'] ?? null) ? $analysis['analytics'] : [];
        if (!$meaningful) {
            $narrativeAnalytics = [];
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
            'narrative' => $narrativeAnalytics,
            'meaningful' => $meaningful,
            'key_phrases' => $keyPhrases,
            'semantic_tags' => $semanticTags,
            'semantic_term_weights' => $semanticTermWeights,
            'semantic_phrase_weights' => $semanticPhraseWeights,
            'semantic_fingerprint' => $semanticFingerprint,
            'semantic_highlights' => $semanticHighlights,
        ];

        $classification = $this->classifyEntry($entry);
        $quality = $this->evaluateQuality($entry, $scraped);
        $recommendations = $this->recommendSources($filteredLinks, (string) ($quality['source_domain'] ?? ''));

        $graphContext = [
            'ingested' => false,
            'quality_gate_passed' => !empty($quality['ingest']),
        ];
        if ($this->graphService !== null) {
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
        $fingerprint = $this->fingerprint($scraped, $semanticProfile);

        $fullEntry = array_merge($entry, $classification, $quality, [
            'recommended_sources' => $recommendations,
            'graph' => $graphContext,
            'normalized_url' => $normalizedUrl,
            'content_type' => $contentType,
            'fingerprint' => $fingerprint,
            'content_digest' => $contentDigest,
        ]);

        [$history, $merged] = $this->mergeEntry($fullEntry, $history);

        return [$history, $merged, $scraped];
    }

    /**
     * @return array<string, mixed>
     */
    private function createFailedEntry(string $url, string $message): array
    {
        $timestamp = date(DATE_ATOM);

        return [
            'url' => $url,
            'title' => $url,
            'fetched_at' => $timestamp,
            'last_checked_at' => $timestamp,
            'error' => $message,
            'content_type' => 'error',
            'revision' => null,
            'versions' => [],
            'changes' => $this->buildNoChangeSummary(),
            'unchanged' => false,
            'normalized_url' => $this->normaliseStoredUrl($url),
            'graph' => [
                'ingested' => false,
            ],
            'keywords' => [],
            'entities' => [],
            'links' => [],
            'recommended_sources' => [],
            'character_count' => 0,
            'paragraph_count' => 0,
            'meaningful' => false,
            'key_phrases' => [],
            'semantic_tags' => [],
            'semantic_term_weights' => [],
            'semantic_phrase_weights' => [],
            'semantic_fingerprint' => '',
            'semantic_highlights' => [],
        ];
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

        $this->registerExistingDigests($entries);

        return $entries;
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     */
    private function registerExistingDigests(array $entries): void
    {
        $index = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $digest = '';
            if (isset($entry['content_digest']) && is_string($entry['content_digest'])) {
                $digest = trim($entry['content_digest']);
            }

            if ($digest === '') {
                $digest = $this->deriveEntryDigest($entry);
            }

            if ($digest === '') {
                continue;
            }

            $key = (string) ($entry['normalized_url'] ?? $entry['url'] ?? '');
            if ($key === '') {
                $key = $this->normaliseStoredUrl((string) ($entry['url'] ?? ''));
            }

            if ($key === '') {
                continue;
            }

            $index[$digest] = $key;

            if (isset($entry['versions']) && is_array($entry['versions'])) {
                foreach ($entry['versions'] as $version) {
                    if (!is_array($version)) {
                        continue;
                    }

                    $versionDigest = '';
                    if (isset($version['content_digest']) && is_string($version['content_digest'])) {
                        $versionDigest = trim($version['content_digest']);
                    }

                    if ($versionDigest === '') {
                        $versionDigest = $this->deriveEntryDigest($version);
                    }

                    if ($versionDigest === '') {
                        continue;
                    }

                    if (!isset($index[$versionDigest])) {
                        $index[$versionDigest] = $key;
                    }
                }
            }
        }

        $this->contentDigestIndex = $index;
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

        $this->resetDiscoveryLedgerCache();
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

        $digest = isset($entry['content_digest']) && is_string($entry['content_digest'])
            ? trim($entry['content_digest'])
            : '';
        $isDuplicate = false;
        $duplicateKey = null;

        if ($digest !== '') {
            if (isset($this->contentDigestIndex[$digest]) && $this->contentDigestIndex[$digest] !== $key) {
                $duplicateKey = $this->contentDigestIndex[$digest];
                $isDuplicate = true;
                $entry['duplicate_of'] = $duplicateKey;
                $entry['ingest'] = false;
                $entry['quality_score'] = max(0.0, (float) ($entry['quality_score'] ?? 0.0) - 25.0);
                $entry['quality_label'] = $this->labelForScore((float) $entry['quality_score']);
                $reasons = is_array($entry['quality_reasons'] ?? null) ? $entry['quality_reasons'] : [];
                $reasons[] = 'Detected duplicate content from ' . $duplicateKey . '.';
                $entry['quality_reasons'] = array_values(array_unique($reasons));
            } else {
                unset($entry['duplicate_of']);
            }
        } else {
            unset($entry['duplicate_of']);
        }

        if (isset($history[$key])) {
            $existing = $history[$key];
            $existing['changes'] = $this->normaliseChanges($existing['changes'] ?? null);
            $existing['versions'] = $this->normaliseVersions($existing['versions'] ?? []);

            $previousFingerprint = (string) ($existing['fingerprint'] ?? '');
            if ($previousFingerprint === (string) ($entry['fingerprint'] ?? '')) {
                $existing['last_checked_at'] = $entry['last_checked_at'] ?? $entry['fetched_at'] ?? date(DATE_ATOM);
                $existing['unchanged'] = true;
                $existing['changes'] = $this->buildNoChangeSummary();
                $history[$key] = $existing;

                return [$history, $existing];
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

        if ($digest !== '') {
            if (!$isDuplicate || ($this->contentDigestIndex[$digest] ?? '') === $key) {
                $this->contentDigestIndex[$digest] = $key;
            }
        }

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
     */
    private function deriveEntryDigest(array $entry): string
    {
        $fragments = [];

        foreach (['summary', 'preview', 'content', 'body', 'meta_description'] as $field) {
            if (!isset($entry[$field])) {
                continue;
            }

            $raw = is_string($entry[$field]) ? $entry[$field] : '';
            if ($raw === '') {
                continue;
            }

            $clean = $this->refiner->cleanDocument($raw);
            if ($clean === '') {
                $clean = $raw;
            }

            $clean = trim($clean);
            if ($clean !== '') {
                $fragments[] = $clean;
            }
        }

        if ($fragments === []) {
            return '';
        }

        $normalised = preg_replace('/\s+/u', ' ', implode(' ', $fragments));
        $normalised = is_string($normalised) ? mb_strtolower(trim($normalised)) : '';
        if ($normalised === '') {
            return '';
        }

        return hash('sha256', $normalised);
    }

    private function digestContent(string $text): string
    {
        $clean = $this->refiner->cleanDocument($text);
        if ($clean === '') {
            $clean = trim($text);
        }

        if ($clean === '') {
            return '';
        }

        $normalised = preg_replace('/\s+/u', ' ', $clean);
        $normalised = is_string($normalised) ? mb_strtolower(trim($normalised)) : '';

        return $normalised === '' ? '' : hash('sha256', $normalised);
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

    private function fingerprint(ScrapeResult $scraped, ?array $semanticProfile = null): string
    {
        $text = mb_strtolower(trim($scraped->text()));
        $title = mb_strtolower(trim($scraped->title()));

        $signatureParts = [];
        if (is_array($semanticProfile)) {
            $fingerprint = isset($semanticProfile['fingerprint']) && is_string($semanticProfile['fingerprint'])
                ? trim($semanticProfile['fingerprint'])
                : '';
            if ($fingerprint !== '') {
                $signatureParts[] = $fingerprint;
            }

            if (isset($semanticProfile['terms']) && is_array($semanticProfile['terms'])) {
                foreach ($semanticProfile['terms'] as $term) {
                    if (!is_string($term)) {
                        continue;
                    }

                    $normalized = trim(mb_strtolower($term, 'UTF-8'));
                    if ($normalized === '') {
                        continue;
                    }

                    $signatureParts[] = $normalized;
                }
            }

            if (isset($semanticProfile['key_phrases']) && is_array($semanticProfile['key_phrases'])) {
                foreach ($semanticProfile['key_phrases'] as $phrase) {
                    if (is_array($phrase)) {
                        $phraseText = (string) ($phrase['phrase'] ?? '');
                    } elseif (is_string($phrase)) {
                        $phraseText = $phrase;
                    } else {
                        continue;
                    }

                    $normalizedPhrase = trim(mb_strtolower($phraseText, 'UTF-8'));
                    if ($normalizedPhrase === '') {
                        continue;
                    }

                    $signatureParts[] = $normalizedPhrase;
                }
            }
        }

        if ($signatureParts !== []) {
            $signatureParts = array_values(array_unique(array_slice($signatureParts, 0, 20)));
        }

        return hash('sha256', $title . '|' . $text . '|' . implode('|', $signatureParts));
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

        if (!isset($entry['key_phrases']) || !is_array($entry['key_phrases'])) {
            $entry['key_phrases'] = [];
        }
        if (!isset($entry['semantic_tags']) || !is_array($entry['semantic_tags'])) {
            $entry['semantic_tags'] = [];
        }
        if (!isset($entry['semantic_term_weights']) || !is_array($entry['semantic_term_weights'])) {
            $entry['semantic_term_weights'] = [];
        }
        if (!isset($entry['semantic_phrase_weights']) || !is_array($entry['semantic_phrase_weights'])) {
            $entry['semantic_phrase_weights'] = [];
        }
        if (!isset($entry['semantic_fingerprint']) || !is_string($entry['semantic_fingerprint'])) {
            $entry['semantic_fingerprint'] = '';
        }
        if (!isset($entry['semantic_highlights']) || !is_array($entry['semantic_highlights'])) {
            $entry['semantic_highlights'] = [];
        }

        if (!isset($entry['discovery']) || !is_array($entry['discovery'])) {
            $entry['discovery'] = [
                'seed' => false,
                'first_seen_at' => (string) ($entry['fetched_at'] ?? ''),
                'last_seen_at' => (string) ($entry['last_checked_at'] ?? ($entry['fetched_at'] ?? '')),
                'sources' => [],
                'total_sources' => 0,
                'unique_source_domains' => 0,
                'schedule' => [
                    'total_runs' => 0,
                    'last_scheduled_at' => '',
                    'next_due_at' => '',
                    'history' => [],
                ],
            ];
        } elseif (!isset($entry['discovery']['schedule']) || !is_array($entry['discovery']['schedule'])) {
            $entry['discovery']['schedule'] = [
                'total_runs' => 0,
                'last_scheduled_at' => '',
                'next_due_at' => '',
                'history' => [],
            ];
        }

        if (!isset($entry['ranking']) || !is_array($entry['ranking'])) {
            $entry['ranking'] = [
                'page_authority' => 0.0,
                'domain_authority' => 0.0,
                'inbound_links' => 0,
                'unique_sources' => 0,
            ];
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
     * @param mixed $weights
     *
     * @return array<string, float>
     */
    private function normaliseSemanticWeights($weights, int $limit = 18): array
    {
        if (!is_array($weights)) {
            return [];
        }

        $normalised = [];
        foreach ($weights as $token => $weight) {
            if (!is_string($token)) {
                continue;
            }

            $normalizedToken = trim(mb_strtolower($token, 'UTF-8'));
            if ($normalizedToken === '') {
                continue;
            }

            if (!is_numeric($weight)) {
                $weight = 0.0;
            }

            $normalised[$normalizedToken] = max(
                $normalised[$normalizedToken] ?? 0.0,
                round(min(1.0, max(0.0, (float) $weight)), 3)
            );
        }

        if ($normalised === []) {
            return [];
        }

        arsort($normalised, SORT_NUMERIC);

        return array_slice($normalised, 0, $limit, true);
    }

    /**
     * @param mixed $weights
     *
     * @return array<string, float>
     */
    private function normaliseSemanticPhraseWeights($weights, int $limit = 16): array
    {
        if (!is_array($weights)) {
            return [];
        }

        $normalised = [];
        foreach ($weights as $phrase => $weight) {
            if (!is_string($phrase)) {
                continue;
            }

            $normalizedPhrase = preg_replace('/\s+/', ' ', mb_strtolower($phrase, 'UTF-8'));
            if (!is_string($normalizedPhrase)) {
                $normalizedPhrase = mb_strtolower($phrase, 'UTF-8');
            }

            $normalizedPhrase = trim($normalizedPhrase);
            if ($normalizedPhrase === '') {
                continue;
            }

            if (!is_numeric($weight)) {
                $weight = 0.0;
            }

            $normalised[$normalizedPhrase] = max(
                $normalised[$normalizedPhrase] ?? 0.0,
                round(min(1.0, max(0.0, (float) $weight)), 3)
            );
        }

        if ($normalised === []) {
            return [];
        }

        arsort($normalised, SORT_NUMERIC);

        return array_slice($normalised, 0, $limit, true);
    }

    /**
     * @param mixed $phrases
     *
     * @return array<int, array{phrase: string, score: float}>
     */
    private function normaliseKeyPhrases($phrases, int $limit = 12): array
    {
        if (!is_array($phrases)) {
            return [];
        }

        $bucket = [];
        foreach ($phrases as $phrase) {
            if (is_array($phrase)) {
                $text = isset($phrase['phrase']) ? (string) $phrase['phrase'] : '';
                $score = isset($phrase['score']) ? (float) $phrase['score'] : 0.0;
            } elseif (is_string($phrase)) {
                $text = $phrase;
                $score = 0.0;
            } else {
                continue;
            }

            $text = trim($text);
            if ($text === '') {
                continue;
            }

            $normalizedKey = preg_replace('/\s+/', ' ', mb_strtolower($text, 'UTF-8'));
            if (!is_string($normalizedKey)) {
                $normalizedKey = mb_strtolower($text, 'UTF-8');
            }
            $normalizedKey = trim($normalizedKey);
            if ($normalizedKey === '') {
                continue;
            }

            $rounded = round(min(1.0, max(0.0, $score)), 3);
            if (!isset($bucket[$normalizedKey]) || $rounded > $bucket[$normalizedKey]['score']) {
                $bucket[$normalizedKey] = [
                    'phrase' => $text,
                    'score' => $rounded,
                ];
            }
        }

        if ($bucket === []) {
            return [];
        }

        usort($bucket, static function (array $left, array $right): int {
            if ($left['score'] === $right['score']) {
                return strcasecmp($left['phrase'], $right['phrase']);
            }

            return $right['score'] <=> $left['score'];
        });

        return array_slice(array_values($bucket), 0, $limit);
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
        $score += $this->baseTrustContribution($base);

        if ($domain === '') {
            $reasons[] = 'Domain not detected – relying on content only.';
        } elseif ($base >= 0.85) {
            $reasons[] = 'Highly trusted newsroom (' . $domain . ').';
            $score += 3.0;
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
            $score -= 4.0;
            $reasons[] = 'Very short copy detected (' . $words . ' words).';
        }

        $paragraphs = (int) ($entry['paragraph_count'] ?? $scraped->paragraphCount());
        if ($paragraphs >= 8) {
            $score += 6.0;
        } elseif ($paragraphs <= 2) {
            $score -= 3.0;
        }

        $entityCount = count(is_array($entry['entities'] ?? null) ? $entry['entities'] : []);
        if ($entityCount >= 8) {
            $score += 12.0;
            $reasons[] = 'Rich entity extraction (' . $entityCount . ' entities).';
        } elseif ($entityCount >= 4) {
            $score += 8.0;
        } elseif ($entityCount === 0) {
            $score -= 2.0;
            $reasons[] = 'No entities identified.';
        }

        $keywordCount = count(is_array($entry['keywords'] ?? null) ? $entry['keywords'] : []);
        if ($keywordCount >= 8) {
            $score += 8.0;
        } elseif ($keywordCount >= 4) {
            $score += 5.0;
        } elseif ($keywordCount === 0) {
            $score -= 1.0;
        }

        $topics = is_array($entry['topics'] ?? null) ? $entry['topics'] : [];
        $topicCount = count($topics);
        if ($topicCount >= 3) {
            $score += 4.0;
        } elseif ($topicCount === 0) {
            $score -= 2.0;
            $reasons[] = 'No thematic topics extracted.';
        }

        if (($entry['category'] ?? '') === 'financial') {
            $score += 4.0;
            $reasons[] = 'Financial focus detected.';
        }

        if (empty($entry['meaningful'])) {
            $score -= 18.0;
            $reasons[] = 'Flagged meaningless text – no coherent sentences detected.';
        }

        if ($this->isLowConfidenceDomain($domain)) {
            $score -= 6.0;
            $reasons[] = 'Domain flagged for manual review.';
        }

        if (is_array($meta) && is_string($meta['description'] ?? null) && trim((string) $meta['description']) !== '') {
            $score += 2.0;
        }

        if ($score > 97.5) {
            $score = 97.5 + ($score - 97.5) * 0.25;
        }

        $score = max(0.0, min(100.0, $score));
        $label = $this->labelForScore($score);
        $meetsQualityGate = $score >= 60.0;

        if (!$meetsQualityGate) {
            $reasons[] = 'Quality below recommended threshold – included for comprehensive coverage.';
        }

        $reasons = array_values(array_unique($reasons));

        return [
            'quality_score' => round($score, 1),
            'quality_label' => $label,
            'quality_reasons' => $reasons,
            'ingest' => $meetsQualityGate,
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

    private function baseTrustContribution(float $baseScore): float
    {
        $baseline = max(0.0, min(1.0, self::SOURCE_BASELINE));
        $clamped = max($baseline, min(1.0, $baseScore));
        $range = max(0.0001, 1.0 - $baseline);
        $normalized = ($clamped - $baseline) / $range;

        return 18.0 + ($normalized * 14.0);
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
