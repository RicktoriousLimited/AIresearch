<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/App/bootstrap.php';

use App\Web\SearchViewHelpers;

const DATA_DIR = __DIR__ . '/../data';
const INDEX_DIR = DATA_DIR . '/index';
const CACHE_DIR = DATA_DIR . '/cache';
const LOG_FILE = DATA_DIR . '/search_logs.jsonl';
const PAGE_SIZE = 20;
const CACHE_TTL = 90;
const SHARD_COUNT = 32;

$start = microtime(true);

$manifestPath = INDEX_DIR . '/manifest.json';
if (!file_exists($manifestPath)) {
    renderPage([], [
        'error' => 'Index missing. Run bin/build-index first.',
        'query' => '',
        'page' => 1,
        'since' => 'all',
        'sourceFilters' => [],
        'lang' => '',
        'dedupe' => 1,
        'facets' => ['source' => [], 'lang' => [], 'date' => ['last24h' => 0, 'last7d' => 0, 'last30d' => 0]],
        'total' => 0,
        'latency' => 0,
        'version' => 'n/a',
    ]);
    exit;
}

$manifest = json_decode(file_get_contents($manifestPath), true);
if (!is_array($manifest)) {
    renderPage([], [
        'error' => 'Invalid manifest.json',
        'query' => '',
        'page' => 1,
        'since' => 'all',
        'sourceFilters' => [],
        'lang' => '',
        'dedupe' => 1,
        'facets' => ['source' => [], 'lang' => [], 'date' => ['last24h' => 0, 'last7d' => 0, 'last30d' => 0]],
        'total' => 0,
        'latency' => 0,
        'version' => 'n/a',
    ]);
    exit;
}

$indexBase = $manifest['base_path'] ?? (INDEX_DIR . '/' . ($manifest['version'] ?? ''));
$indexBase = resolve_index_base($indexBase);
$meta = safeJsonDecode($indexBase . '/meta.json');
$recency = safeJsonDecode($indexBase . '/recency.json');
$facets = safeJsonDecode($indexBase . '/facets.json');
$facetData = is_array($facets) ? $facets : ['source' => [], 'lang' => [], 'date' => ['last24h' => 0, 'last7d' => 0, 'last30d' => 0]];
$stats = $meta['__stats'] ?? ['doc_count' => 0, 'avg_length' => 1];
unset($meta['__stats']);
$docCount = (int) ($stats['doc_count'] ?? 0);
$avgLength = max(1, (float) ($stats['avg_length'] ?? 1));

$query = trim($_GET['q'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$since = $_GET['since'] ?? 'all';
$sourceFilters = $_GET['source'] ?? [];
if (!is_array($sourceFilters)) {
    $sourceFilters = [$sourceFilters];
}
$sourceFilters = array_values(array_filter(array_map('trim', $sourceFilters), fn($s) => $s !== ''));
$lang = trim((string) ($_GET['lang'] ?? ''));
$dedupe = isset($_GET['dedupe']) ? (int) $_GET['dedupe'] : 1;
$dedupe = $dedupe ? 1 : 0;

$paramsSignature = [
    'q' => $query,
    'page' => $page,
    'since' => $since,
    'source' => $sourceFilters,
    'lang' => $lang,
    'dedupe' => $dedupe,
    'version' => $manifest['version'] ?? '',
];
$cacheKey = hash('sha1', json_encode($paramsSignature, JSON_UNESCAPED_UNICODE));
$cachePath = CACHE_DIR . '/' . $cacheKey . '.json';

if ($query !== '' && file_exists($cachePath)) {
    $cached = json_decode((string) file_get_contents($cachePath), true);
    if (is_array($cached) && ($cached['expires_at'] ?? 0) > time()) {
        $latency = (int) round((microtime(true) - $start) * 1000);
        renderPage(hydrateResults($cached['doc_ids'] ?? [], $cached['collapsed'] ?? [], $query), [
            'query' => $query,
            'page' => $page,
            'since' => $since,
            'sourceFilters' => $sourceFilters,
            'lang' => $lang,
            'dedupe' => $dedupe,
            'facets' => $facetData,
            'total' => $cached['total'] ?? 0,
            'latency' => $latency,
            'version' => $manifest['version'] ?? '',
        ]);
        logSearch($query, $latency, $cached['total'] ?? 0, $page);
        exit;
    }
}

$terms = $query !== '' ? tokenize($query) : [];
$phrases = quoted_phrases($query);
$queryEntities = extract_entities($query);

$candidates = [];
$termWeights = [];
if ($terms) {
    foreach (array_count_values($terms) as $term => $count) {
        $shardId = crc32($term) % SHARD_COUNT;
        $shard = load_term_shard($shardId, $indexBase);
        $postings = $shard[$term] ?? [];
        $df = count($postings);
        if ($df === 0) {
            continue;
        }
        $idf = log(($docCount - $df + 0.5) / ($df + 0.5) + 1);
        $termWeights[$term] = ['idf' => $idf];
        foreach ($postings as $posting) {
            [$docId, $freq] = $posting;
            if (!isset($candidates[$docId])) {
                $candidates[$docId] = ['termFreqs' => []];
            }
            $candidates[$docId]['termFreqs'][$term] = $freq;
        }
    }
}

$filtered = [];
foreach ($candidates as $docId => $data) {
    $metaEntry = $meta[(string) $docId] ?? null;
    if (!$metaEntry) {
        continue;
    }
    $doc = hydrate_doc($docId, $indexBase);
    if (!$doc) {
        continue;
    }
    if ($since !== 'all') {
        $minutesLimit = match ($since) {
            '24h' => 60 * 24,
            '7d' => 60 * 24 * 7,
            '30d' => 60 * 24 * 30,
            default => null,
        };
        if ($minutesLimit !== null) {
            $minutes = $recency[(string) $docId] ?? (int) floor((time() - $doc['ts']) / 60);
            if ($minutes > $minutesLimit) {
                continue;
            }
        }
    }
    if ($sourceFilters && !in_array($doc['source'], $sourceFilters, true)) {
        continue;
    }
    if ($lang !== '' && strtolower($doc['lang']) !== strtolower($lang)) {
        continue;
    }
    $filtered[$docId] = $data;
}

$scores = [];
foreach ($filtered as $docId => $data) {
    $metaEntry = $meta[(string) $docId];
    $doc = hydrate_doc($docId, $indexBase);
    if (!$doc) {
        continue;
    }
    $bm25 = bm25($data['termFreqs'], $metaEntry['lengthNorm'], $termWeights);
    $minutes = $recency[(string) $docId] ?? (int) floor((time() - $doc['ts']) / 60);
    $recencyBoost = 0.20 * exp(-$minutes / 4320);
    $sourceBoost = 0.15 * ($metaEntry['sourceRank'] ?? 0.6);
    $entityOverlap = array_intersect(array_map('strtolower', $queryEntities), array_map('strtolower', $doc['entities'] ?? []));
    $entityBoost = 0.05 * min(2, count($entityOverlap));
    $phraseBoost = 0.0;
    foreach ($phrases as $phrase) {
        if ($phrase !== '' && (stripos($doc['title'], $phrase) !== false || stripos($doc['lede'], $phrase) !== false)) {
            $phraseBoost = 0.10;
            break;
        }
    }
    $scores[$docId] = $bm25 + $recencyBoost + $sourceBoost + $entityBoost + $phraseBoost;
}

arsort($scores);
$totalResults = count($scores);

$collapsedCounts = [];
if ($page === 1 && $dedupe) {
    $selected = [];
    $seenSimhash = [];
    foreach ($scores as $docId => $_) {
        $doc = hydrate_doc($docId, $indexBase);
        $simhash = $doc['simhash'] ?? '';
        $isDuplicate = false;
        foreach ($seenSimhash as $primaryId => $hash) {
            if ($simhash && $hash && hammingDist($simhash, $hash) <= 4) {
                $collapsedCounts[$primaryId] = ($collapsedCounts[$primaryId] ?? 0) + 1;
                $isDuplicate = true;
                break;
            }
        }
        if (!$isDuplicate) {
            $seenSimhash[$docId] = $simhash;
            $selected[$docId] = $scores[$docId];
        }
    }
    $scores = $selected;
    arsort($scores);
    $totalResults = count($scores);
}

$offset = ($page - 1) * PAGE_SIZE;
$docIds = array_slice(array_keys($scores), $offset, PAGE_SIZE);

if ($query !== '') {
    $cachePayload = [
        'doc_ids' => $docIds,
        'collapsed' => $collapsedCounts,
        'total' => $totalResults,
        'expires_at' => time() + CACHE_TTL,
    ];
    file_put_contents($cachePath, json_encode($cachePayload, JSON_UNESCAPED_UNICODE));
}

$results = hydrateResults($docIds, $collapsedCounts, $query);
$latency = (int) round((microtime(true) - $start) * 1000);

renderPage($results, [
    'query' => $query,
    'page' => $page,
    'since' => $since,
    'sourceFilters' => $sourceFilters,
    'lang' => $lang,
    'dedupe' => $dedupe,
    'facets' => $facetData,
    'total' => $totalResults,
    'latency' => $latency,
    'version' => $manifest['version'] ?? '',
]);

logSearch($query, $latency, $totalResults, $page);

function tokenize(string $query): array
{
    $normalized = ascii_fold(mb_strtolower($query, 'UTF-8'));
    $tokens = preg_split('/[^a-z0-9]+/u', $normalized) ?: [];
    $stopwords = get_stopwords();
    $tokens = array_filter($tokens, fn($token) => $token !== '' && !isset($stopwords[$token]));
    return array_values($tokens);
}

function quoted_phrases(string $query): array
{
    if ($query === '') {
        return [];
    }
    preg_match_all('/"([^"\n]+)"/u', $query, $matches);
    return array_values(array_filter(array_map('trim', $matches[1] ?? [])));
}

function extract_entities(string $query): array
{
    preg_match_all('/\b([A-Z][\p{L}0-9]+(?:\s+[A-Z][\p{L}0-9]+)*)/u', $query, $matches);
    return array_values(array_unique(array_map('trim', $matches[1] ?? [])));
}

function ascii_fold(string $text): string
{
    $folded = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    if ($folded === false) {
        return $text;
    }
    return $folded;
}

function get_stopwords(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $words = ['the','a','an','of','and','to','in','for','on','by','with','is','are','was','were','be','as','that','from','this','at','it','or','but','if','then','into','their','they','them','he','she','his','her','over','after','before','about','than','across','has','have','had','will','can','could','should','would','just','more','most','other','also','new','its','not','into','we','you','your','i','me','our','us','so','no','yes'];
    $cache = array_fill_keys($words, true);
    return $cache;
}

function load_term_shard(int $id, string $basePath): array
{
    static $cache = [];
    if (isset($cache[$id])) {
        return $cache[$id];
    }
    $path = sprintf('%s/terms-%03d.json', $basePath, $id);
    if (!file_exists($path)) {
        $cache[$id] = [];
        return $cache[$id];
    }
    $cache[$id] = safeJsonDecode($path) ?? [];
    return $cache[$id];
}

function hydrate_doc(int $docId, string $basePath): ?array
{
    static $docCache = [];
    static $shardCache = [];
    if (isset($docCache[$docId])) {
        return $docCache[$docId];
    }
    $shardId = $docId % SHARD_COUNT;
    if (!isset($shardCache[$shardId])) {
        $path = sprintf('%s/docs-%03d.jsonl', $basePath, $shardId);
        $docs = [];
        if (file_exists($path)) {
            $handle = fopen($path, 'r');
            if ($handle) {
                while (($line = fgets($handle)) !== false) {
                    $decoded = json_decode(trim($line), true);
                    if (is_array($decoded)) {
                        $docs[(int) $decoded['id']] = $decoded;
                    }
                }
                fclose($handle);
            }
        }
        $shardCache[$shardId] = $docs;
    }
    if (isset($shardCache[$shardId][$docId])) {
        $docCache[$docId] = $shardCache[$shardId][$docId];
    }
    return $docCache[$docId] ?? null;
}

function bm25(array $termFreqs, float $lengthNorm, array $qWeights): float
{
    $score = 0.0;
    $k1 = 1.2;
    foreach ($termFreqs as $term => $freq) {
        $idf = $qWeights[$term]['idf'] ?? 0;
        $score += $idf * (($freq * ($k1 + 1)) / ($freq + $k1 * $lengthNorm));
    }
    return $score;
}

function hammingDist(string $hex1, string $hex2): int
{
    $hex1 = str_pad(strtolower($hex1), 16, '0');
    $hex2 = str_pad(strtolower($hex2), 16, '0');
    $bin1 = hex2bin($hex1);
    $bin2 = hex2bin($hex2);
    if ($bin1 === false || $bin2 === false) {
        return 64;
    }
    $len = min(strlen($bin1), strlen($bin2));
    $diff = 0;
    for ($i = 0; $i < $len; $i++) {
        $diff += count_bits(ord($bin1[$i]) ^ ord($bin2[$i]));
    }
    return $diff;
}

function count_bits(int $byte): int
{
    $count = 0;
    while ($byte) {
        $count += $byte & 1;
        $byte >>= 1;
    }
    return $count;
}

function time_ago(int $now, int $ts): string
{
    $diff = max(0, $now - $ts);
    if ($diff < 60) {
        return $diff . ' s ago';
    }
    if ($diff < 3600) {
        $mins = (int) floor($diff / 60);
        return $mins . ' min ago';
    }
    if ($diff < 86400) {
        $hours = (int) floor($diff / 3600);
        return $hours . ' h ago';
    }
    $days = (int) floor($diff / 86400);
    return $days . ' d ago';
}

function hydrateResults(array $docIds, array $collapsed, string $query): array
{
    global $manifest, $indexBase;
    $results = [];
    foreach ($docIds as $docId) {
        $doc = hydrate_doc((int) $docId, $indexBase);
        if (!$doc) {
            continue;
        }
        $rawTitle = (string) ($doc['title'] ?? '');
        $title = $rawTitle !== '' ? $rawTitle : ((string) ($doc['url'] ?? 'Untitled source'));
        $snippetSource = (string) ($doc['lede'] ?? '');
        $snippet = SearchViewHelpers::relevantSnippet($snippetSource, $query);
        if ($snippet === '' && $snippetSource !== '') {
            $snippet = SearchViewHelpers::normaliseWhitespace($snippetSource);
        }
        if ($snippet !== '' && mb_strlen($snippet) > 320) {
            $snippet = rtrim(mb_substr($snippet, 0, 317)) . '…';
        }
        $highlightedSnippet = $snippet === '' ? '' : SearchViewHelpers::highlightTerms($snippet, $query);
        $results[] = [
            'id' => $doc['id'],
            'title' => SearchViewHelpers::highlightTerms($title, $query),
            'snippet' => $highlightedSnippet,
            'lede' => $highlightedSnippet,
            'source' => $doc['source'],
            'lang' => $doc['lang'],
            'time' => time_ago(time(), (int) $doc['ts']),
            'url' => $doc['url'],
            'entities' => $doc['entities'] ?? [],
            'collapsed' => $collapsed[$docId] ?? 0,
        ];
    }
    return $results;
}

function renderPage(array $results, array $state): void
{
    $query = $state['query'] ?? '';
    $page = $state['page'] ?? 1;
    $since = $state['since'] ?? 'all';
    $sourceFilters = $state['sourceFilters'] ?? [];
    $lang = $state['lang'] ?? '';
    $dedupe = $state['dedupe'] ?? 1;
    $facets = $state['facets'] ?? ['source' => [], 'lang' => [], 'date' => ['last24h' => 0, 'last7d' => 0, 'last30d' => 0]];
    $total = $state['total'] ?? 0;
    $latency = $state['latency'] ?? 0;
    $version = $state['version'] ?? '';
    $error = $state['error'] ?? '';

    $baseUrl = strtok($_SERVER['REQUEST_URI'], '?') ?: '/search.php';
    $timeFilters = ['all' => 'Any time', '24h' => 'Last 24h', '7d' => 'Last 7d', '30d' => 'Last 30d'];
    $langOptions = array_keys($facets['lang'] ?? []);
    include __DIR__ . '/template.php';
}

function logSearch(string $query, int $latency, int $total, int $page): void
{
    if (!is_dir(dirname(LOG_FILE))) {
        mkdir(dirname(LOG_FILE), 0775, true);
    }
    $record = [
        'ts' => time(),
        'q' => $query,
        'latency_ms' => $latency,
        'results' => $total,
        'page' => $page,
    ];
    file_put_contents(LOG_FILE, json_encode($record, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
}

function safeJsonDecode(string $path)
{
    if (!file_exists($path)) {
        return null;
    }
    $data = json_decode((string) file_get_contents($path), true);
    return is_array($data) ? $data : null;
}

function resolve_index_base(string $path): string
{
    if (is_absolute_path($path)) {
        return $path;
    }
    $root = realpath(__DIR__ . '/..');
    return $root . '/' . ltrim($path, '/');
}

function is_absolute_path(string $path): bool
{
    if ($path === '') {
        return false;
    }
    if ($path[0] === '/' || $path[0] === '\\') {
        return true;
    }
    return (bool) preg_match('/^[A-Za-z]:\\\\/', $path);
}
