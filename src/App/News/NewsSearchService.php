<?php

declare(strict_types=1);

namespace App\News;

use App\Crawler\HiddenCrawler;
use App\KnowledgeGraph\GraphRepository;
use App\KnowledgeGraph\GraphResearcher;
use App\News\Ranking\BM25Ranker;
use App\Text\TextRefiner;
use DateTimeImmutable;
use Exception;

use function array_filter;
use function array_fill_keys;
use function array_keys;
use function array_map;
use function array_slice;
use function array_sum;
use function array_values;
use function array_unique;
use function arsort;
use function in_array;
use function count;
use function filter_var;
use function implode;
use function is_array;
use function is_string;
use function levenshtein;
use function max;
use function mb_strlen;
use function mb_strtolower;
use function mb_strtoupper;
use function mb_strpos;
use function mb_substr;
use function min;
use function parse_url;
use function preg_replace;
use function preg_split;
use function round;
use function sort;
use function strtolower;
use function str_replace;
use function str_contains;
use function str_ends_with;
use function trim;
use function ucfirst;
use function usort;

use const DATE_ATOM;
use const FILTER_VALIDATE_URL;
use const PHP_URL_HOST;

final class NewsSearchService
{
    private const LOCAL_SYNONYM_SETS = [
        'ai' => ['artificial intelligence', 'machine learning', 'automation'],
        'ml' => ['machine learning', 'artificial intelligence'],
        'earnings' => ['results', 'quarterly results', 'financial update'],
        'ipo' => ['initial public offering', 'stock listing'],
        'merger' => ['m&a', 'acquisition', 'buyout'],
        'inflation' => ['price growth', 'cost of living'],
        'crypto' => ['cryptocurrency', 'digital asset', 'token'],
        'recession' => ['economic slowdown', 'downturn'],
        'startup' => ['new venture', 'tech company'],
    ];

    private HiddenCrawler $crawler;

    private GraphRepository $graphRepository;

    private TextRefiner $refiner;

    private ?GraphResearcher $graphResearcher = null;

    private ?BM25Ranker $bm25Ranker = null;

    private TextRefiner $textRefiner;

    /**
     * @var array<string, float>
     */
    private array $termWeights = [];

    /**
     * @var array<int, string>
     */
    private array $queryTerms = [];

    /**
     * @var array<string, bool>
     */
    private array $expandedTermSet = [];

    /**
     * @var array<string, float>
     */
    private array $queryPhrases = [];

    /**
     * @var array<string, float>
     */
    private array $queryBigrams = [];

    /**
     * @var array<string, float>
     */
    private array $querySemanticTermWeights = [];

    /**
     * @var array<string, float>
     */
    private array $querySemanticPhraseWeights = [];

    private ?string $querySemanticFingerprint = null;

    private string $activeQuery = '';

    /**
     * @var array<string, float>
     */
    private array $queryFingerprint = [];

    /**
     * @var array<string, array<string, float>>
     */
    private array $fingerprintCache = [];

    private float $lastSemanticBoost = 0.0;

    /**
     * @var array{graph: array<string, mixed>|null, sources: array<int, array<string, mixed>>, updated_at: string|null}|null
     */
    private ?array $graphSnapshot = null;

    /**
     * @var array<string, float>
     */
    private array $graphPreferredUrls = [];

    /**
     * @var array<string, float>
     */
    private array $graphPreferredDomains = [];

    public function __construct(HiddenCrawler $crawler, ?GraphRepository $graphRepository = null, ?TextRefiner $refiner = null)
    {
        $this->crawler = $crawler;
        $this->graphRepository = $graphRepository ?? new GraphRepository();
        $refinerInstance = $refiner ?? new TextRefiner();
        $this->refiner = $refinerInstance;
        $this->textRefiner = $refinerInstance;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function search(string $query, array $options = []): array
    {
        $this->activeQuery = $query;
        $history = $this->crawler->history();
        $limit = (int) ($options['limit'] ?? 24);
        $limit = max(1, min(100, $limit));
        $filters = $this->normaliseFilters(isset($options['filters']) && is_array($options['filters']) ? $options['filters'] : []);

        $normalisedQuery = mb_strtolower(trim($query));
        $terms = array_values(array_filter(preg_split('/\s+/u', $normalisedQuery) ?: [], static fn(string $term): bool => $term !== ''));

        $this->graphSnapshot = null;
        $this->resetQueryState();
        $this->prepareSemanticContext($query, $terms);
        $graphSignals = $this->buildGraphQuerySignals($query, $terms);
        $this->graphPreferredUrls = $graphSignals['preferred_urls'];
        $this->graphPreferredDomains = $graphSignals['preferred_domains'];
        $this->prepareRanker($history, $terms, $graphSignals);

        $now = new DateTimeImmutable();
        $matches = [];

        foreach ($history as $row) {
            if (!is_array($row)) {
                continue;
            }

            $formatted = $this->formatRow($row);

            $quality = (float) ($formatted['quality_score'] ?? 0.0);

            $matchScore = $this->matchScore($formatted, $terms);
            $formatted['semantic_score'] = $this->lastSemanticBoost;
            if ($terms !== [] && $matchScore <= 0.0) {
                continue;
            }

            $weight = $quality + $matchScore + $this->recencyBoost((string) ($formatted['fetched_at'] ?? ''), $now);

            $contentType = isset($formatted['content_type']) ? (string) $formatted['content_type'] : '';
            if ($contentType === 'article') {
                $weight += 12.0;
            } elseif ($contentType === 'page') {
                $weight += 4.0;
            } elseif ($contentType === 'non_article') {
                $weight -= 8.0;
            } elseif ($contentType === 'error') {
                $weight -= 12.0;
            }

            $weight += $this->graphBoostForEntry($formatted);

            $this->upsertMatch($matches, $formatted, $weight);
        }

        if (count($matches) < $limit) {
            $this->augmentMatchesFromGraph($matches, $terms, $now, $limit);
        }

        $sorted = array_values($matches);
        usort($sorted, static fn(array $a, array $b): int => $b['weight'] <=> $a['weight']);

        $items = array_map(static fn(array $match): array => $match['item'], $sorted);
        $unfilteredMeta = $this->buildMeta($items);

        if ($filters !== []) {
            $items = $this->applyFilters($items, $filters);
        }

        $results = array_slice($items, 0, $limit);
        $meta = $this->buildMeta($items);
        $discovery = $this->crawler->discoveryTree();

        $meta['discovery'] = $discovery;
        $meta['facets_filtered'] = $meta['facets'] ?? [];
        $meta['facets_all'] = $unfilteredMeta['facets'] ?? [];
        $meta['total_available'] = $unfilteredMeta['total_matches'] ?? count($results);
        $meta['returned'] = count($results);
        $meta['active_filters'] = $this->presentableFilters($filters);

        return [
            'query' => $query,
            'limit' => $limit,
            'results' => $results,
            'meta' => $meta,
            'discovery' => $discovery,
            'generated_at' => $now->format(DATE_ATOM),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<string, string> $filters
     *
     * @return array<int, array<string, mixed>>
     */
    private function applyFilters(array $items, array $filters): array
    {
        if ($filters === []) {
            return $items;
        }

        $now = new DateTimeImmutable();

        return array_values(array_filter($items, function (array $item) use ($filters, $now): bool {
            if (isset($filters['recency'])) {
                $recency = $this->resolveRecencyBucket($item, $now);
                if ($recency === null || $recency !== $filters['recency']) {
                    return false;
                }
            }

            if (isset($filters['quality'])) {
                $qualityBucket = $this->resolveQualityBucket((float) ($item['quality_score'] ?? 0.0));
                if ($qualityBucket !== $filters['quality']) {
                    return false;
                }
            }

            if (isset($filters['content_type'])) {
                $type = trim((string) ($item['content_type'] ?? ''));
                if ($type === '') {
                    $type = 'page';
                }
                if ($type !== $filters['content_type']) {
                    return false;
                }
            }

            if (isset($filters['ingestion'])) {
                $ingested = !empty($item['ingest']);
                if ($filters['ingestion'] === 'ingested' && !$ingested) {
                    return false;
                }
                if ($filters['ingestion'] === 'unreviewed' && $ingested) {
                    return false;
                }
            }

            if (isset($filters['source'])) {
                $domain = trim((string) ($item['source_domain'] ?? ''));
                if ($domain === '' || $domain !== $filters['source']) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array<string, string>
     */
    private function normaliseFilters(array $filters): array
    {
        if ($filters === []) {
            return [];
        }

        $allowedRecency = ['past_hour', 'past_day', 'past_week', 'older'];
        $allowedQuality = ['90_plus', '70_89', '50_69', 'under_50'];
        $allowedIngestion = ['ingested', 'unreviewed'];

        $normalised = [];

        if (isset($filters['recency']) && is_string($filters['recency'])) {
            $candidate = trim($filters['recency']);
            if (in_array($candidate, $allowedRecency, true)) {
                $normalised['recency'] = $candidate;
            }
        }

        if (isset($filters['quality']) && is_string($filters['quality'])) {
            $candidate = trim($filters['quality']);
            if (in_array($candidate, $allowedQuality, true)) {
                $normalised['quality'] = $candidate;
            }
        }

        if (isset($filters['content_type']) && is_string($filters['content_type'])) {
            $candidate = trim($filters['content_type']);
            if ($candidate !== '') {
                $normalised['content_type'] = $candidate;
            }
        } elseif (isset($filters['type']) && is_string($filters['type'])) {
            $candidate = trim($filters['type']);
            if ($candidate !== '') {
                $normalised['content_type'] = $candidate;
            }
        }

        if (isset($filters['ingestion']) && is_string($filters['ingestion'])) {
            $candidate = trim($filters['ingestion']);
            if (in_array($candidate, $allowedIngestion, true)) {
                $normalised['ingestion'] = $candidate;
            }
        }

        if (isset($filters['source']) && is_string($filters['source'])) {
            $candidate = trim($filters['source']);
            if ($candidate !== '') {
                $normalised['source'] = $candidate;
            }
        }

        return $normalised;
    }

    /**
     * @param array<string, string> $filters
     *
     * @return array<string, string>
     */
    private function presentableFilters(array $filters): array
    {
        if ($filters === []) {
            return [];
        }

        $presentable = [];
        foreach ($filters as $key => $value) {
            if (!is_string($value) || trim($value) === '') {
                continue;
            }

            $key = (string) $key;
            if ($key === 'content_type') {
                $presentable['type'] = $value;
                continue;
            }

            $presentable[$key] = $value;
        }

        return $presentable;
    }

    private function resetQueryState(): void
    {
        $this->bm25Ranker = null;
        $this->termWeights = [];
        $this->queryTerms = [];
        $this->expandedTermSet = [];
        $this->queryPhrases = [];
        $this->queryBigrams = [];
        $this->graphPreferredUrls = [];
        $this->graphPreferredDomains = [];
        $this->querySemanticTermWeights = [];
        $this->querySemanticPhraseWeights = [];
        $this->querySemanticFingerprint = null;
    }

    /**
     * @param array<int, string> $terms
     */
    private function prepareSemanticContext(string $query, array $terms): void
    {
        $seed = trim($query);
        if ($seed === '') {
            $seed = trim(implode(' ', $terms));
        }

        if ($seed === '') {
            return;
        }

        $profile = $this->refiner->buildSemanticProfile($seed, 12);
        $this->querySemanticTermWeights = $this->normaliseWeightMap($profile['term_weights'] ?? [], 24);
        $this->querySemanticPhraseWeights = $this->normaliseWeightMap($profile['phrase_weights'] ?? [], 20);
        $fingerprint = isset($profile['fingerprint']) && is_string($profile['fingerprint'])
            ? trim($profile['fingerprint'])
            : '';
        $this->querySemanticFingerprint = $fingerprint !== '' ? $fingerprint : null;
    }

    /**
     * @param mixed $weights
     *
     * @return array<string, float>
     */
    private function normaliseWeightMap($weights, int $limit = 24): array
    {
        if (!is_array($weights)) {
            return [];
        }

        $normalised = [];
        foreach ($weights as $token => $weight) {
            if (!is_string($token)) {
                continue;
            }

            $normalized = preg_replace('/\s+/', ' ', mb_strtolower($token, 'UTF-8'));
            if (!is_string($normalized)) {
                $normalized = mb_strtolower($token, 'UTF-8');
            }

            $normalized = trim($normalized);
            if ($normalized === '') {
                continue;
            }

            if (!is_numeric($weight)) {
                $weight = 0.0;
            }

            $normalised[$normalized] = max(
                $normalised[$normalized] ?? 0.0,
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
     * @param array<int, array<string, mixed>> $history
     * @param array<int, string> $terms
     */
    private function prepareRanker(array $history, array $terms, array $graphSignals = []): void
    {
        $hasTermInput = $terms !== [];
        $hasGraphInput = !empty($graphSignals['term_weights'] ?? []) || !empty($graphSignals['phrase_weights'] ?? []);
        if (!$hasTermInput && !$hasGraphInput) {
            return;
        }

        $profile = $this->buildQueryProfile($terms, $graphSignals);
        if ($profile['terms'] === []) {
            return;
        }

        $documents = [];
        foreach ($history as $row) {
            if (is_array($row)) {
                $documents[] = $row;
            }
        }

        $snapshot = $this->loadGraphSnapshot();
        foreach ($snapshot['sources'] as $source) {
            if (!is_array($source)) {
                continue;
            }

            $documents[] = $this->normaliseGraphSource($source);
        }

        $this->bm25Ranker = BM25Ranker::fromDocuments(
            $documents,
            $profile['terms'],
            function (array $row): string {
                return $this->documentText($row);
            }
        );
        $this->termWeights = $profile['weights'];
        $this->queryPhrases = $profile['phrases'];
        $this->queryTerms = $profile['original_terms'];
        $this->expandedTermSet = array_fill_keys($profile['terms'], true);
        $this->queryBigrams = $profile['bigrams'];
        $this->queryFingerprint = $this->buildQueryFingerprint($profile);
    }

    /**
     * @return array{graph: array<string, mixed>|null, sources: array<int, array<string, mixed>>, updated_at: string|null}
     */
    private function loadGraphSnapshot(): array
    {
        if ($this->graphSnapshot !== null) {
            return $this->graphSnapshot;
        }

        $payload = $this->graphRepository->load();
        $graph = isset($payload['graph']) && is_array($payload['graph']) ? $payload['graph'] : null;

        $sources = [];
        if (isset($payload['sources']) && is_array($payload['sources'])) {
            foreach ($payload['sources'] as $source) {
                if (is_array($source)) {
                    $sources[] = $source;
                }
            }
        }

        $updatedAt = isset($payload['updated_at']) && is_string($payload['updated_at'])
            ? $payload['updated_at']
            : null;

        $this->graphSnapshot = [
            'graph' => $graph,
            'sources' => $sources,
            'updated_at' => $updatedAt,
        ];

        return $this->graphSnapshot;
    }

    /**
     * @param array<int, string> $terms
     * @return array{
     *     terms: array<int, string>,
     *     weights: array<string, float>,
     *     phrases: array<string, float>,
     *     original_terms: array<int, string>,
     *     bigrams: array<string, float>
     * }
     */
    private function buildQueryProfile(array $terms, array $graphSignals = []): array
    {
        $original = [];
        $expanded = [];
        $weights = [];
        $phrases = [];

        foreach ($terms as $term) {
            $normalised = trim(mb_strtolower($term, 'UTF-8'));
            if ($normalised === '') {
                continue;
            }

            $original[] = $normalised;
            $expanded[$normalised] = true;
            $weights[$normalised] = max($weights[$normalised] ?? 0.0, 1.0);

            foreach ($this->generateTermVariants($normalised) as $variant => $weight) {
                if ($variant === '') {
                    continue;
                }

                $expanded[$variant] = true;
                $weights[$variant] = max($weights[$variant] ?? 0.0, $weight);
            }
        }

        $synonymPhrases = $this->loadSynonymsForTerms($original);
        foreach ($synonymPhrases as $phrase) {
            if ($phrase === '') {
                continue;
            }

            $phrases[$phrase] = max($phrases[$phrase] ?? 0.0, 0.85);
            foreach (BM25Ranker::tokenise($phrase) as $token) {
                if ($token === '') {
                    continue;
                }

                $expanded[$token] = true;
                $weights[$token] = max($weights[$token] ?? 0.0, 0.7);
            }
        }

        $graphTermWeights = isset($graphSignals['term_weights']) && is_array($graphSignals['term_weights'])
            ? $graphSignals['term_weights']
            : [];
        foreach ($graphTermWeights as $term => $weight) {
            if (!is_string($term)) {
                continue;
            }

            $normalised = trim(mb_strtolower($term, 'UTF-8'));
            if ($normalised === '') {
                continue;
            }

            $expanded[$normalised] = true;
            $weights[$normalised] = max($weights[$normalised] ?? 0.0, min(1.0, max(0.0, (float) $weight)));
        }

        $graphPhraseWeights = isset($graphSignals['phrase_weights']) && is_array($graphSignals['phrase_weights'])
            ? $graphSignals['phrase_weights']
            : [];
        foreach ($graphPhraseWeights as $phrase => $weight) {
            if (!is_string($phrase)) {
                continue;
            }

            $normalisedPhrase = trim(mb_strtolower($phrase, 'UTF-8'));
            if ($normalisedPhrase === '') {
                continue;
            }

            $weightValue = min(1.0, max(0.0, (float) $weight));

            $phrases[$normalisedPhrase] = max($phrases[$normalisedPhrase] ?? 0.0, $weightValue);
            foreach (BM25Ranker::tokenise($normalisedPhrase) as $token) {
                if ($token === '') {
                    continue;
                }

                $expanded[$token] = true;
                $weights[$token] = max($weights[$token] ?? 0.0, min(1.0, $weightValue * 0.85));
            }
        }

        if ($this->querySemanticTermWeights !== []) {
            foreach ($this->querySemanticTermWeights as $term => $weight) {
                if (!is_string($term) || $term === '') {
                    continue;
                }

                $expanded[$term] = true;
                $weights[$term] = max($weights[$term] ?? 0.0, min(1.0, $weight));
            }
        }

        if ($this->querySemanticPhraseWeights !== []) {
            foreach ($this->querySemanticPhraseWeights as $phrase => $weight) {
                if (!is_string($phrase) || $phrase === '') {
                    continue;
                }

                $phrases[$phrase] = max($phrases[$phrase] ?? 0.0, min(1.0, $weight));
                foreach (BM25Ranker::tokenise($phrase) as $token) {
                    if ($token === '') {
                        continue;
                    }

                    $expanded[$token] = true;
                    $weights[$token] = max($weights[$token] ?? 0.0, min(1.0, $weight * 0.85));
                }
            }
        }

        $expandedTerms = array_keys($expanded);
        sort($expandedTerms);

        return [
            'terms' => $expandedTerms,
            'weights' => $weights,
            'phrases' => $phrases,
            'original_terms' => array_values(array_unique($original)),
            'bigrams' => $this->generateQueryBigrams($original),
        ];
    }

    /**
     * @param array{
     *     terms: array<int, string>,
     *     weights: array<string, float>,
     *     phrases: array<string, float>,
     *     original_terms: array<int, string>,
     *     bigrams: array<string, float>
     * } $profile
     *
     * @return array<string, float>
     */
    private function buildQueryFingerprint(array $profile): array
    {
        $candidates = [];

        $query = trim($this->activeQuery);
        if ($query !== '') {
            $candidates[] = $query;
        }

        if ($profile['phrases'] !== []) {
            $candidates[] = implode(' ', array_keys($profile['phrases']));
        }

        if ($profile['original_terms'] !== []) {
            $candidates[] = implode(' ', $profile['original_terms']);
        }

        $candidates = array_values(array_filter(
            $candidates,
            static fn(string $value): bool => trim($value) !== ''
        ));

        if ($candidates === []) {
            return [];
        }

        $combined = trim(implode(' ', $candidates));
        $fingerprint = $this->textRefiner->buildSemanticFingerprint($combined);
        if ($fingerprint !== []) {
            return $fingerprint;
        }

        foreach ($candidates as $candidate) {
            $candidateFingerprint = $this->textRefiner->buildSemanticFingerprint($candidate);
            if ($candidateFingerprint !== []) {
                return $candidateFingerprint;
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return array<string, float>
     */
    private function documentFingerprint(array $entry, string $text): array
    {
        $fragments = [$text];

        if (isset($entry['topics']) && is_array($entry['topics'])) {
            foreach ($entry['topics'] as $topic) {
                if (is_string($topic)) {
                    $trimmed = trim($topic);
                    if ($trimmed !== '') {
                        $fragments[] = $trimmed;
                    }
                }
            }
        }

        if (isset($entry['entities']) && is_array($entry['entities'])) {
            foreach ($entry['entities'] as $entity) {
                if (!is_array($entity)) {
                    continue;
                }
                $label = isset($entity['label']) ? trim((string) $entity['label']) : '';
                if ($label !== '') {
                    $fragments[] = $label;
                }
            }
        }

        $combined = trim(implode(' ', array_filter(
            $fragments,
            static fn($fragment): bool => is_string($fragment) && $fragment !== ''
        )));

        if ($combined === '') {
            return [];
        }

        $cacheKey = sha1($combined);
        if (!isset($this->fingerprintCache[$cacheKey])) {
            $this->fingerprintCache[$cacheKey] = $this->textRefiner->buildSemanticFingerprint($combined);

            if (count($this->fingerprintCache) > 256) {
                $this->fingerprintCache = array_slice($this->fingerprintCache, -192, null, true);
            }
        }

        return $this->fingerprintCache[$cacheKey];
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function semanticSimilarityScore(array $entry, string $text): float
    {
        if ($this->queryFingerprint === []) {
            return 0.0;
        }

        $documentFingerprint = $this->documentFingerprint($entry, $text);
        if ($documentFingerprint === []) {
            return 0.0;
        }

        $similarity = $this->textRefiner->compareFingerprints($this->queryFingerprint, $documentFingerprint);
        if ($similarity <= 0.0) {
            return 0.0;
        }

        $boost = $similarity * 9.0;

        if ($this->expandedTermSet !== [] && isset($entry['topics']) && is_array($entry['topics'])) {
            foreach ($entry['topics'] as $topic) {
                if (!is_string($topic)) {
                    continue;
                }
                foreach (BM25Ranker::tokenise($topic) as $token) {
                    if (isset($this->expandedTermSet[$token])) {
                        $boost *= 1.08;
                        break 2;
                    }
                }
            }
        }

        return $boost;
    }

    /**
     * @return array<string, float>
     */
    private function generateTermVariants(string $term): array
    {
        $variants = [];
        $register = static function (string $variant, float $weight) use (&$variants): void {
            $variant = trim($variant);
            if ($variant === '') {
                return;
            }

            $variants[$variant] = isset($variants[$variant])
                ? max($variants[$variant], $weight)
                : $weight;
        };

        $length = mb_strlen($term, 'UTF-8');

        if ($length >= 4 && str_ends_with($term, 'ies')) {
            $register(mb_substr($term, 0, -3, 'UTF-8') . 'y', 0.9);
        } elseif ($length >= 3 && str_ends_with($term, 'es')) {
            $register(mb_substr($term, 0, -2, 'UTF-8'), 0.85);
        } elseif ($length >= 3 && str_ends_with($term, 's')) {
            $register(mb_substr($term, 0, -1, 'UTF-8'), 0.82);
        }

        if ($length >= 5 && str_ends_with($term, 'ing')) {
            $register(mb_substr($term, 0, -3, 'UTF-8'), 0.8);
        }

        if ($length >= 4 && str_ends_with($term, 'ed')) {
            $register(mb_substr($term, 0, -2, 'UTF-8'), 0.78);
        }

        if ($length >= 4 && str_ends_with($term, 'er')) {
            $register(mb_substr($term, 0, -2, 'UTF-8'), 0.75);
        }

        if ($length >= 3 && !str_ends_with($term, 's')) {
            if (str_ends_with($term, 'y')) {
                $register(mb_substr($term, 0, -1, 'UTF-8') . 'ies', 0.88);
            }

            $register($term . 's', 0.8);
        }

        if (str_contains($term, '-')) {
            $register(str_replace('-', ' ', $term), 0.72);
            $register(str_replace('-', '', $term), 0.65);
        }

        return $variants;
    }

    /**
     * @param array<int, string> $terms
     *
     * @return array<string, float>
     */
    private function generateQueryBigrams(array $terms): array
    {
        if (count($terms) < 2) {
            return [];
        }

        $bigrams = [];
        $previous = null;

        foreach ($terms as $term) {
            $term = trim($term);
            if ($term === '') {
                continue;
            }

            if ($previous !== null) {
                $phrase = $previous . ' ' . $term;
                $bigrams[$phrase] = isset($bigrams[$phrase])
                    ? max($bigrams[$phrase], 0.72)
                    : 0.72;
            }

            $previous = $term;
        }

        return $bigrams;
    }

    /**
     * @param array<int, string> $tokens
     *
     * @return array<string, bool>
     */
    private function bigramTokenSet(array $tokens): array
    {
        if ($tokens === []) {
            return [];
        }

        $set = [];
        $previous = null;

        foreach ($tokens as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }

            if ($previous !== null) {
                $set[$previous . ' ' . $token] = true;
            }

            $previous = $token;
        }

        return $set;
    }

    /**
     * @param array<int, string> $terms
     * @return array<int, string>
     */
    private function loadSynonymsForTerms(array $terms): array
    {
        if ($terms === []) {
            return [];
        }

        $snapshot = $this->loadGraphSnapshot();
        $graph = $snapshot['graph'];
        if (!is_array($graph) || !isset($graph['synonyms']) || !is_array($graph['synonyms'])) {
            return [];
        }

        $termSet = array_fill_keys($terms, true);
        $results = [];

        foreach ($graph['synonyms'] as $pair) {
            if (!is_array($pair)) {
                continue;
            }

            $entity = isset($pair['entity']) ? trim(mb_strtolower((string) $pair['entity'], 'UTF-8')) : '';
            $synonyms = [];
            if (isset($pair['synonyms']) && is_array($pair['synonyms'])) {
                foreach ($pair['synonyms'] as $synonym) {
                    if (!is_string($synonym)) {
                        continue;
                    }

                    $normalised = trim(mb_strtolower($synonym, 'UTF-8'));
                    if ($normalised === '') {
                        continue;
                    }

                    $synonyms[] = $normalised;
                }
            }

            $candidates = $synonyms;
            if ($entity !== '') {
                $candidates[] = $entity;
            }

            $matched = false;
            foreach ($candidates as $candidate) {
                if (isset($termSet[$candidate])) {
                    $matched = true;
                    break;
                }
            }

            if (!$matched) {
                continue;
            }

            foreach ($candidates as $candidate) {
                if (!isset($termSet[$candidate])) {
                    $results[] = $candidate;
                }
            }
        }

        foreach ($terms as $term) {
            $normalised = trim(mb_strtolower($term, 'UTF-8'));
            if ($normalised === '' || !isset(self::LOCAL_SYNONYM_SETS[$normalised])) {
                continue;
            }

            foreach (self::LOCAL_SYNONYM_SETS[$normalised] as $synonym) {
                if (!is_string($synonym)) {
                    continue;
                }

                $candidate = trim(mb_strtolower($synonym, 'UTF-8'));
                if ($candidate === '' || isset($termSet[$candidate])) {
                    continue;
                }

                $results[] = $candidate;
            }
        }

        return array_values(array_unique($results));
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function documentText(array $entry): string
    {
        $fragments = [];

        foreach (['title', 'headline', 'summary', 'preview', 'content', 'body', 'excerpt', 'meta_description'] as $field) {
            if (!isset($entry[$field])) {
                continue;
            }

            $value = trim((string) $entry[$field]);
            if ($value !== '') {
                $fragments[] = $value;
            }
        }

        if (isset($entry['topics']) && is_array($entry['topics'])) {
            foreach ($entry['topics'] as $topic) {
                if (is_string($topic)) {
                    $topicValue = trim($topic);
                    if ($topicValue !== '') {
                        $fragments[] = $topicValue;
                    }
                }
            }
        }

        if (isset($entry['entities']) && is_array($entry['entities'])) {
            foreach ($entry['entities'] as $entity) {
                if (!is_array($entity)) {
                    continue;
                }

                $label = isset($entity['label']) ? trim((string) $entity['label']) : '';
                if ($label !== '') {
                    $fragments[] = $label;
                }

                $type = isset($entity['type']) ? trim((string) $entity['type']) : '';
                if ($type !== '') {
                    $fragments[] = $type;
                }
            }
        }

        foreach (['source_domain', 'source_site_name', 'source_language', 'source_section'] as $field) {
            if (!isset($entry[$field])) {
                continue;
            }

            $value = trim((string) $entry[$field]);
            if ($value !== '') {
                $fragments[] = $value;
            }
        }

        if (isset($entry['tags']) && is_array($entry['tags'])) {
            foreach ($entry['tags'] as $tag) {
                if (is_string($tag)) {
                    $tagValue = trim($tag);
                    if ($tagValue !== '') {
                        $fragments[] = $tagValue;
                    }
                }
            }
        }

        $filtered = array_values(array_filter(
            $fragments,
            static fn($fragment): bool => is_string($fragment) && $fragment !== ''
        ));

        return trim(implode(' ', $filtered));
    }

    /**
     * @param array<string, array{weight: float, item: array<string, mixed>}> $matches
     * @param array<string, mixed> $item
     */
    private function upsertMatch(array &$matches, array $item, float $weight): void
    {
        $key = $this->entryKey($item);
        if ($key === '') {
            return;
        }

        if (!isset($matches[$key])) {
            $matches[$key] = ['weight' => $weight, 'item' => $item];

            return;
        }

        $existing = $matches[$key];
        $merged = $this->mergeItems($existing['item'], $item);
        $matches[$key] = [
            'weight' => max($existing['weight'], $weight),
            'item' => $merged,
        ];
    }

    /**
     * @param array<string, array{weight: float, item: array<string, mixed>}> $matches
     * @param array<int, string> $terms
     */
    private function augmentMatchesFromGraph(
        array &$matches,
        array $terms,
        DateTimeImmutable $now,
        int $limit
    ): void {
        $payload = $this->loadGraphSnapshot();
        $sources = $payload['sources'];
        if ($sources === []) {
            return;
        }

        foreach ($sources as $source) {
            if (!is_array($source)) {
                continue;
            }

            $entry = $this->normaliseGraphSource($source);
            $formatted = $this->formatRow($entry);
            $quality = (float) ($formatted['quality_score'] ?? 0.0);

            $matchScore = $this->matchScore($formatted, $terms);
            $formatted['semantic_score'] = $this->lastSemanticBoost;
            if ($terms !== [] && $matchScore <= 0.0) {
                continue;
            }

            $weight = $quality + $matchScore + $this->recencyBoost((string) ($formatted['fetched_at'] ?? ''), $now) + 6.0;
            $weight += $this->graphBoostForEntry($formatted);

            $this->upsertMatch($matches, $formatted, $weight);

            if ($limit > 0 && count($matches) >= $limit * 3) {
                break;
            }
        }
    }

    private function graphResearcher(): GraphResearcher
    {
        if ($this->graphResearcher === null) {
            $this->graphResearcher = new GraphResearcher($this->graphRepository);
        }

        return $this->graphResearcher;
    }

    /**
     * @param array<int, string> $terms
     * @return array{
     *     term_weights: array<string, float>,
     *     phrase_weights: array<string, float>,
     *     preferred_urls: array<string, float>,
     *     preferred_domains: array<string, float>
     * }
     */
    private function buildGraphQuerySignals(string $query, array $terms): array
    {
        $signals = [
            'term_weights' => [],
            'phrase_weights' => [],
            'preferred_urls' => [],
            'preferred_domains' => [],
        ];

        $trimmed = trim($query);
        if ($trimmed === '') {
            return $signals;
        }

        try {
            $result = $this->graphResearcher()->searchGraph($trimmed, 10);
        } catch (Throwable $exception) {
            return $signals;
        }

        $registerTerm = function (string $term, float $weight) use (&$signals): void {
            $normalised = trim(mb_strtolower($term, 'UTF-8'));
            if ($normalised === '') {
                return;
            }

            $weight = min(1.0, max(0.0, $weight));
            $existing = $signals['term_weights'][$normalised] ?? 0.0;
            if ($weight > $existing) {
                $signals['term_weights'][$normalised] = $weight;
            }
        };

        $registerPhrase = function (string $phrase, float $weight) use (&$signals): void {
            $normalised = trim(mb_strtolower($phrase, 'UTF-8'));
            if ($normalised === '') {
                return;
            }

            $weight = min(1.0, max(0.0, $weight));
            $existing = $signals['phrase_weights'][$normalised] ?? 0.0;
            if ($weight > $existing) {
                $signals['phrase_weights'][$normalised] = $weight;
            }
        };

        $registerDomain = function (string $domain, float $weight) use (&$signals): void {
            $normalised = trim(mb_strtolower($domain, 'UTF-8'));
            if ($normalised === '') {
                return;
            }

            $weight = max(0.0, $weight);
            $signals['preferred_domains'][$normalised] = max($signals['preferred_domains'][$normalised] ?? 0.0, $weight);
        };

        $entities = isset($result['entities']) && is_array($result['entities']) ? $result['entities'] : [];
        foreach ($entities as $entity) {
            if (!is_array($entity)) {
                continue;
            }

            $score = (float) ($entity['score'] ?? 0.0);
            $entityName = isset($entity['entity']) ? trim((string) $entity['entity']) : '';
            if ($entityName !== '') {
                $registerPhrase($entityName, 0.8 + min(0.18, $score * 0.2));
            }

            $synonyms = isset($entity['synonyms']) && is_array($entity['synonyms']) ? $entity['synonyms'] : [];
            foreach ($synonyms as $synonym) {
                if (!is_string($synonym)) {
                    continue;
                }

                $registerPhrase($synonym, 0.72 + min(0.18, $score * 0.18));
            }

            $relatedTerms = isset($entity['related_terms']) && is_array($entity['related_terms'])
                ? $entity['related_terms']
                : [];
            foreach ($relatedTerms as $related) {
                if (!is_string($related)) {
                    continue;
                }

                $registerTerm($related, 0.6 + min(0.2, $score * 0.2));
            }

            $facts = isset($entity['facts']) && is_array($entity['facts']) ? $entity['facts'] : [];
            foreach ($facts as $fact) {
                if (!is_array($fact)) {
                    continue;
                }

                $relation = isset($fact['relation']) ? (string) $fact['relation'] : '';
                $counterpart = isset($fact['counterpart']) ? (string) $fact['counterpart'] : '';

                if ($relation !== '') {
                    $registerTerm($relation, 0.58 + min(0.2, $score * 0.15));
                }
                if ($counterpart !== '') {
                    $registerPhrase($counterpart, 0.68 + min(0.15, $score * 0.12));
                }
            }
        }

        $relationMatches = isset($result['relations']) && is_array($result['relations']) ? $result['relations'] : [];
        foreach ($relationMatches as $relation) {
            if (!is_array($relation)) {
                continue;
            }

            $label = isset($relation['relation']) ? (string) $relation['relation'] : '';
            if ($label === '') {
                continue;
            }

            $score = (float) ($relation['score'] ?? 0.0);
            $registerTerm($label, 0.55 + min(0.25, $score * 0.4));
        }

        $synonymMatches = isset($result['synonyms']) && is_array($result['synonyms']) ? $result['synonyms'] : [];
        foreach ($synonymMatches as $match) {
            if (!is_array($match)) {
                continue;
            }

            $synonyms = isset($match['synonyms']) && is_array($match['synonyms']) ? $match['synonyms'] : [];
            foreach ($synonyms as $synonym) {
                if (!is_string($synonym)) {
                    continue;
                }

                $registerPhrase($synonym, 0.66);
            }
        }

        $triples = isset($result['triples']) && is_array($result['triples']) ? $result['triples'] : [];
        foreach ($triples as $triple) {
            if (!is_array($triple)) {
                continue;
            }

            $subject = isset($triple['subject']) ? (string) $triple['subject'] : '';
            $object = isset($triple['object']) ? (string) $triple['object'] : '';
            $relation = isset($triple['relation']) ? (string) $triple['relation'] : '';
            $score = (float) ($triple['score'] ?? 0.0);

            if ($subject !== '') {
                $registerPhrase($subject, 0.64 + min(0.2, $score * 0.18));
            }
            if ($object !== '') {
                $registerPhrase($object, 0.64 + min(0.2, $score * 0.18));
            }
            if ($relation !== '') {
                $registerTerm($relation, 0.5 + min(0.2, $score * 0.2));
            }
        }

        $sources = isset($result['sources']) && is_array($result['sources']) ? $result['sources'] : [];
        foreach ($sources as $index => $source) {
            if (!is_array($source)) {
                continue;
            }

            $url = isset($source['url']) ? trim((string) $source['url']) : '';
            if ($url === '') {
                continue;
            }

            $rankBoost = max(0.0, 4.5 - ($index * 0.4));
            $key = $this->normaliseUrlKey($url);
            if ($key !== '') {
                $signals['preferred_urls'][$key] = max($signals['preferred_urls'][$key] ?? 0.0, $rankBoost);
            }

            $domain = isset($source['domain']) && is_string($source['domain'])
                ? $source['domain']
                : $this->domainFromUrl($url);
            if ($domain !== '') {
                $registerDomain($domain, max(0.0, 1.2 + $rankBoost * 0.25));
            }
        }

        return $signals;
    }

    private function normaliseUrlKey(string $url): string
    {
        return $this->entryKey(['normalized_url' => $url, 'url' => $url]);
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function graphBoostForEntry(array $entry): float
    {
        $boost = 0.0;

        $key = $this->entryKey($entry);
        if ($key !== '' && isset($this->graphPreferredUrls[$key])) {
            $boost += $this->graphPreferredUrls[$key];
        }

        $domain = (string) ($entry['source_domain'] ?? '');
        if ($domain === '' && isset($entry['url'])) {
            $domain = $this->domainFromUrl((string) $entry['url']);
        }
        $domain = trim(mb_strtolower($domain, 'UTF-8'));
        if ($domain !== '' && isset($this->graphPreferredDomains[$domain])) {
            $boost += $this->graphPreferredDomains[$domain];
        }

        return min(12.0, $boost);
    }

    /**
     * @param array<string, mixed> $item
     */
    private function entryKey(array $item): string
    {
        $candidates = [];
        if (isset($item['normalized_url']) && is_string($item['normalized_url'])) {
            $candidates[] = $item['normalized_url'];
        }
        if (isset($item['url']) && is_string($item['url'])) {
            $candidates[] = $item['url'];
        }

        foreach ($candidates as $candidate) {
            $value = trim(mb_strtolower((string) $candidate));
            if ($value === '') {
                continue;
            }

            $value = preg_replace('/#.*$/u', '', $value) ?? $value;
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $primary
     * @param array<string, mixed> $secondary
     * @return array<string, mixed>
     */
    private function mergeItems(array $primary, array $secondary): array
    {
        $merged = $primary;

        $stringFields = [
            'title',
            'summary',
            'preview',
            'source_site_name',
            'source_domain',
            'source_language',
            'source_published_at',
            'thumbnail',
            'image_url',
            'meta_description',
            'content_type',
            'fetched_at',
            'last_checked_at',
        ];

        foreach ($stringFields as $field) {
            $current = isset($merged[$field]) ? (string) $merged[$field] : '';
            if ($current !== '') {
                continue;
            }
            if (isset($secondary[$field]) && (string) $secondary[$field] !== '') {
                $merged[$field] = $secondary[$field];
            }
        }

        if (!isset($merged['normalized_url']) || (string) $merged['normalized_url'] === '') {
            if (isset($secondary['normalized_url']) && (string) $secondary['normalized_url'] !== '') {
                $merged['normalized_url'] = $secondary['normalized_url'];
            } elseif (isset($secondary['url']) && (string) $secondary['url'] !== '') {
                $merged['normalized_url'] = $secondary['url'];
            }
        }

        if (!isset($merged['url']) || (string) $merged['url'] === '') {
            if (isset($secondary['url']) && (string) $secondary['url'] !== '') {
                $merged['url'] = $secondary['url'];
            }
        }

        $merged['quality_score'] = max(
            (float) ($primary['quality_score'] ?? 0.0),
            (float) ($secondary['quality_score'] ?? 0.0)
        );
        if ($merged['quality_score'] > 0.0) {
            $merged['quality_label'] = $this->qualityLabelFromScore((float) $merged['quality_score']);
        }

        $merged['semantic_score'] = max(
            (float) ($primary['semantic_score'] ?? 0.0),
            (float) ($secondary['semantic_score'] ?? 0.0)
        );

        $merged['ingest'] = !empty($primary['ingest']) || !empty($secondary['ingest']);

        $merged['topics'] = $this->mergeStringLists($primary['topics'] ?? [], $secondary['topics'] ?? []);
        $merged['quality_reasons'] = $this->mergeStringLists($primary['quality_reasons'] ?? [], $secondary['quality_reasons'] ?? []);
        $merged['entities'] = $this->mergeEntities($primary['entities'] ?? [], $secondary['entities'] ?? []);
        $merged['recommended_sources'] = $this->mergeRecommendedSources($primary['recommended_sources'] ?? [], $secondary['recommended_sources'] ?? []);

        $merged['revision'] = max((int) ($primary['revision'] ?? 0), (int) ($secondary['revision'] ?? 0));
        $merged['unchanged'] = !empty($primary['unchanged']) && !empty($secondary['unchanged']);

        $primaryChanges = $primary['changes'] ?? [];
        $secondaryChanges = $secondary['changes'] ?? [];
        if ($this->isEmptyChangeSet($primaryChanges) && !$this->isEmptyChangeSet($secondaryChanges)) {
            $merged['changes'] = $secondaryChanges;
        }

        if (empty($primary['versions']) && !empty($secondary['versions'])) {
            $merged['versions'] = $secondary['versions'];
        }

        $merged['quality_label'] = (string) ($merged['quality_label'] ?? $secondary['quality_label'] ?? '');

        return $merged;
    }

    /**
     * @param mixed $changes
     */
    private function isEmptyChangeSet($changes): bool
    {
        if (!is_array($changes)) {
            return true;
        }

        $summary = (string) ($changes['summary'] ?? '');
        $keywordsAdded = $changes['keywords_added'] ?? [];
        $keywordsRemoved = $changes['keywords_removed'] ?? [];
        $entitiesAdded = $changes['entities_added'] ?? [];
        $entitiesRemoved = $changes['entities_removed'] ?? [];
        $lengthDelta = (int) ($changes['length_delta'] ?? 0);

        return $summary === ''
            && ($keywordsAdded === [] || $keywordsAdded === null)
            && ($keywordsRemoved === [] || $keywordsRemoved === null)
            && ($entitiesAdded === [] || $entitiesAdded === null)
            && ($entitiesRemoved === [] || $entitiesRemoved === null)
            && $lengthDelta === 0;
    }

    /**
     * @param mixed $left
     * @param mixed $right
     * @return array<int, string>
     */
    private function mergeStringLists($left, $right): array
    {
        $values = [];

        if (is_array($left)) {
            foreach ($left as $value) {
                if (!is_string($value)) {
                    continue;
                }
                $trimmed = trim($value);
                if ($trimmed === '') {
                    continue;
                }
                $values[mb_strtolower($trimmed)] = $trimmed;
            }
        }

        if (is_array($right)) {
            foreach ($right as $value) {
                if (!is_string($value)) {
                    continue;
                }
                $trimmed = trim($value);
                if ($trimmed === '') {
                    continue;
                }
                $values[mb_strtolower($trimmed)] = $trimmed;
            }
        }

        return array_values($values);
    }

    /**
     * @param mixed $left
     * @param mixed $right
     * @return array<int, array<string, string>>
     */
    private function mergeEntities($left, $right): array
    {
        $map = [];

        $collect = static function ($list) use (&$map): void {
            if (!is_array($list)) {
                return;
            }

            foreach ($list as $entity) {
                if (!is_array($entity)) {
                    continue;
                }

                $label = (string) ($entity['label'] ?? '');
                if ($label === '') {
                    continue;
                }

                $type = (string) ($entity['type'] ?? '');
                $key = mb_strtolower($label . '|' . $type);
                if (!isset($map[$key])) {
                    $map[$key] = [
                        'label' => $label,
                        'type' => $type,
                    ];
                }
            }
        };

        $collect($left);
        $collect($right);

        return array_values($map);
    }

    /**
     * @param mixed $left
     * @param mixed $right
     * @return array<int, array<string, mixed>>
     */
    private function mergeRecommendedSources($left, $right): array
    {
        $map = [];

        $collect = static function ($list) use (&$map): void {
            if (!is_array($list)) {
                return;
            }

            foreach ($list as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $url = (string) ($entry['url'] ?? '');
                if ($url === '') {
                    continue;
                }

                $key = mb_strtolower($url);
                if (!isset($map[$key])) {
                    $map[$key] = [
                        'url' => $url,
                        'domain' => (string) ($entry['domain'] ?? ''),
                        'trust_score' => (float) ($entry['trust_score'] ?? 0.0),
                    ];
                    continue;
                }

                if ($map[$key]['domain'] === '' && isset($entry['domain'])) {
                    $map[$key]['domain'] = (string) $entry['domain'];
                }

                $map[$key]['trust_score'] = max(
                    (float) ($map[$key]['trust_score'] ?? 0.0),
                    (float) ($entry['trust_score'] ?? 0.0)
                );
            }
        };

        $collect($left);
        $collect($right);

        return array_values($map);
    }

    private function qualityLabelFromScore(float $score): string
    {
        if ($score >= 75.0) {
            return 'High';
        }

        if ($score >= 55.0) {
            return 'Medium';
        }

        if ($score >= 35.0) {
            return 'Low';
        }

        return 'Very low';
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private function normaliseGraphSource(array $source): array
    {
        $url = (string) ($source['url'] ?? '');
        $preview = (string) ($source['preview'] ?? '');
        $title = (string) ($source['title'] ?? $url);
        $fetchedAt = (string) ($source['fetched_at'] ?? $source['verified_at'] ?? '');
        $domain = $this->domainFromUrl($url);
        $siteName = (string) ($source['site_name'] ?? '');
        if ($siteName === '' && $domain !== '') {
            $siteName = $this->humaniseDomain($domain);
        }

        $quality = $this->estimateGraphQualityScore($source);
        $imageUrl = $this->extractImageUrl($source);

        return [
            'title' => $title,
            'summary' => $preview,
            'preview' => $preview,
            'url' => $url,
            'topics' => $this->normaliseStringList($source['topics'] ?? []),
            'entities' => [],
            'fetched_at' => $fetchedAt,
            'last_checked_at' => (string) ($source['verified_at'] ?? $fetchedAt),
            'quality_score' => $quality,
            'quality_label' => $this->qualityLabelFromScore($quality),
            'quality_reasons' => ['Persisted in shared knowledge graph.'],
            'ingest' => true,
            'source_domain' => $domain,
            'source_site_name' => $siteName,
            'source_language' => (string) ($source['language'] ?? ''),
            'source_published_at' => (string) ($source['published_at'] ?? ''),
            'thumbnail' => $imageUrl,
            'image_url' => $imageUrl,
            'meta_description' => (string) ($source['meta_description'] ?? ''),
            'recommended_sources' => [],
            'content_type' => 'article',
            'revision' => 1,
            'normalized_url' => $url,
            'unchanged' => false,
            'changes' => [],
            'versions' => [],
        ];
    }

    /**
     * @param array<string, mixed> $source
     */
    private function estimateGraphQualityScore(array $source): float
    {
        $score = 55.0;

        $status = strtolower((string) ($source['status'] ?? 'active'));
        if ($status === 'active') {
            $score += 8.0;
        }

        $characters = isset($source['characters']) ? (int) $source['characters'] : 0;
        if ($characters >= 3200) {
            $score += 10.0;
        } elseif ($characters >= 1800) {
            $score += 6.0;
        } elseif ($characters >= 900) {
            $score += 3.0;
        }

        $paragraphs = isset($source['paragraphs']) ? (int) $source['paragraphs'] : 0;
        if ($paragraphs >= 12) {
            $score += 6.0;
        } elseif ($paragraphs >= 6) {
            $score += 3.0;
        }

        if (isset($source['preview']) && is_string($source['preview']) && trim($source['preview']) !== '') {
            $score += 4.0;
        }

        if (isset($source['title']) && is_string($source['title']) && trim($source['title']) !== '') {
            $score += 4.0;
        }

        return max(35.0, min(90.0, $score));
    }

    private function domainFromUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return '';
        }

        $host = strtolower($host);
        $host = preg_replace('/^www\d*\./', '', $host) ?? $host;

        return $host;
    }

    private function humaniseDomain(string $domain): string
    {
        $clean = preg_replace('/^www\d*\./', '', $domain) ?? $domain;
        $parts = preg_split('/[\.-]+/', $clean) ?: [];
        if ($parts === []) {
            return $domain;
        }

        $parts = array_map(static function (string $part): string {
            $part = trim($part);

            return $part === '' ? '' : ucfirst($part);
        }, array_slice($parts, 0, 3));

        $parts = array_values(array_filter($parts, static fn(string $part): bool => $part !== ''));

        return $parts === [] ? $domain : implode(' ', $parts);
    }

    /**
     * @param array<int, string> $terms
     * @param array<string, mixed> $entry
     */
    private function matchScore(array $entry, array $terms): float
    {
        $this->lastSemanticBoost = 0.0;

        if ($terms === []) {
            return 0.0;
        }

        $text = $this->documentText($entry);
        if ($text === '') {
            return 0.0;
        }

        $tokens = BM25Ranker::tokenise($text);
        $score = 0.0;

        if ($this->bm25Ranker !== null) {
            $score += $this->bm25Ranker->scoreTokens($tokens, $this->termWeights) * 14.0;
        }

        if ($this->queryPhrases !== []) {
            $haystack = ' ' . mb_strtolower($text, 'UTF-8') . ' ';
            foreach ($this->queryPhrases as $phrase => $weight) {
                if ($phrase === '') {
                    continue;
                }

                if (mb_strpos($haystack, ' ' . $phrase . ' ') !== false || mb_strpos($haystack, $phrase) !== false) {
                    $score += 6.0 * $weight;
                }
            }
        }

        if ($this->queryBigrams !== []) {
            $docBigrams = $this->bigramTokenSet($tokens);
            foreach ($this->queryBigrams as $phrase => $weight) {
                if (isset($docBigrams[$phrase])) {
                    $score += 4.4 * $weight;
                }
            }
        }

        if ($this->expandedTermSet !== []) {
            if (isset($entry['topics']) && is_array($entry['topics'])) {
                foreach ($entry['topics'] as $topic) {
                    if (!is_string($topic)) {
                        continue;
                    }

                    foreach (BM25Ranker::tokenise($topic) as $token) {
                        if (isset($this->expandedTermSet[$token])) {
                            $score += 3.4;
                            break;
                        }
                    }
                }
            }

            if (isset($entry['entities']) && is_array($entry['entities'])) {
                foreach ($entry['entities'] as $entity) {
                    if (!is_array($entity)) {
                        continue;
                    }

                    $label = isset($entity['label']) ? (string) $entity['label'] : '';
                    if ($label === '') {
                        continue;
                    }

                    $entityTokens = BM25Ranker::tokenise($label);
                    $matched = false;
                    foreach ($entityTokens as $token) {
                        if (isset($this->expandedTermSet[$token])) {
                            $score += 2.8;
                            $matched = true;
                            break;
                        }
                    }

                    if (!$matched && $this->queryTerms !== []) {
                        foreach ($entityTokens as $token) {
                            foreach ($this->queryTerms as $original) {
                                if ($token === $original || mb_strlen($original, 'UTF-8') < 4) {
                                    continue;
                                }

                                $distance = levenshtein($token, $original);
                                if ($distance > 0 && $distance <= 2) {
                                    $score += 1.5;
                                    $matched = true;
                                    break 2;
                                }
                            }
                        }
                    }
                }
            }
        }

        if ($this->queryTerms !== []) {
            foreach ($this->queryTerms as $original) {
                if (mb_strlen($original, 'UTF-8') < 4) {
                    continue;
                }

                foreach ($tokens as $token) {
                    if ($token === $original || isset($this->expandedTermSet[$token])) {
                        continue;
                    }

                    $distance = levenshtein($token, $original);
                    if ($distance > 0 && $distance <= 2) {
                        $score += 1.2;
                        break;
                    }
                }
            }
        }

        $entrySemanticWeights = $this->extractSemanticWeightsFromEntry($entry);
        if ($entrySemanticWeights !== [] && $this->querySemanticTermWeights !== []) {
            foreach ($entrySemanticWeights as $token => $weight) {
                if (isset($this->querySemanticTermWeights[$token])) {
                    $score += 4.6 * (($this->querySemanticTermWeights[$token] + $weight) / 2);
                    continue;
                }

                foreach ($this->querySemanticTermWeights as $queryToken => $queryWeight) {
                    if ($queryToken === $token) {
                        continue;
                    }

                    if (abs(mb_strlen($queryToken, 'UTF-8') - mb_strlen($token, 'UTF-8')) > 4) {
                        continue;
                    }

                    if (levenshtein($queryToken, $token) <= 2) {
                        $score += 2.2 * (($queryWeight + $weight) / 2);
                        break;
                    }
                }
            }
        }

        $entrySemanticPhrases = $this->extractSemanticPhrasesFromEntry($entry);
        if ($entrySemanticPhrases !== [] && $this->querySemanticPhraseWeights !== []) {
            foreach ($entrySemanticPhrases as $phrase => $weight) {
                if (isset($this->querySemanticPhraseWeights[$phrase])) {
                    $score += 5.2 * (($this->querySemanticPhraseWeights[$phrase] + $weight) / 2);
                }
            }
        }

        if ($entrySemanticPhrases !== [] && $this->querySemanticTermWeights !== []) {
            foreach ($entrySemanticPhrases as $phrase => $weight) {
                foreach ($this->querySemanticTermWeights as $token => $tokenWeight) {
                    if (str_contains($phrase, $token)) {
                        $score += 1.4 * (($tokenWeight + $weight) / 2);
                        break;
                    }
                }
            }
        }

        if ($this->queryFingerprint !== []) {
            $semanticBoost = $this->semanticSimilarityScore($entry, $text);
            if ($semanticBoost > 0.0) {
                $this->lastSemanticBoost = $semanticBoost;
                $score += $semanticBoost;
            }
        }

        return max(0.0, $score);
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return array<string, float>
     */
    private function extractSemanticWeightsFromEntry(array $entry): array
    {
        $weights = $this->normaliseWeightMap($entry['semantic_term_weights'] ?? [], 24);

        if (isset($entry['semantic_tags']) && is_array($entry['semantic_tags'])) {
            foreach ($entry['semantic_tags'] as $tag) {
                if (!is_string($tag)) {
                    continue;
                }

                $normalized = preg_replace('/\s+/', ' ', mb_strtolower($tag, 'UTF-8'));
                if (!is_string($normalized)) {
                    $normalized = mb_strtolower($tag, 'UTF-8');
                }

                $normalized = trim($normalized);
                if ($normalized === '') {
                    continue;
                }

                $weights[$normalized] = max($weights[$normalized] ?? 0.0, 0.45);
            }
        }

        if ($weights !== []) {
            arsort($weights, SORT_NUMERIC);
            $weights = array_slice($weights, 0, 24, true);
        }

        return $weights;
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return array<string, float>
     */
    private function extractSemanticPhrasesFromEntry(array $entry): array
    {
        $weights = $this->normaliseWeightMap($entry['semantic_phrase_weights'] ?? [], 20);

        if (isset($entry['key_phrases']) && is_array($entry['key_phrases'])) {
            foreach ($entry['key_phrases'] as $candidate) {
                if (is_array($candidate)) {
                    $phrase = isset($candidate['phrase']) ? (string) $candidate['phrase'] : '';
                    $score = isset($candidate['score']) ? (float) $candidate['score'] : 0.0;
                } elseif (is_string($candidate)) {
                    $phrase = $candidate;
                    $score = 0.0;
                } else {
                    continue;
                }

                $normalized = preg_replace('/\s+/', ' ', mb_strtolower($phrase, 'UTF-8'));
                if (!is_string($normalized)) {
                    $normalized = mb_strtolower($phrase, 'UTF-8');
                }

                $normalized = trim($normalized);
                if ($normalized === '') {
                    continue;
                }

                $weights[$normalized] = max($weights[$normalized] ?? 0.0, round(min(1.0, max(0.0, $score)), 3));
            }
        }

        if (isset($entry['semantic_highlights']) && is_array($entry['semantic_highlights'])) {
            foreach ($entry['semantic_highlights'] as $highlight) {
                if (!is_array($highlight)) {
                    continue;
                }

                $phrase = isset($highlight['phrase']) ? (string) $highlight['phrase'] : '';
                $normalized = preg_replace('/\s+/', ' ', mb_strtolower($phrase, 'UTF-8'));
                if (!is_string($normalized)) {
                    $normalized = mb_strtolower($phrase, 'UTF-8');
                }

                $normalized = trim($normalized);
                if ($normalized === '') {
                    continue;
                }

                $weights[$normalized] = max($weights[$normalized] ?? 0.0, 0.6);
            }
        }

        if ($weights !== []) {
            arsort($weights, SORT_NUMERIC);
            $weights = array_slice($weights, 0, 20, true);
        }

        return $weights;
    }

    private function recencyBoost(string $fetchedAt, DateTimeImmutable $now): float
    {
        if ($fetchedAt === '') {
            return 0.0;
        }

        try {
            $fetched = new DateTimeImmutable($fetchedAt);
        } catch (Exception $exception) {
            return 0.0;
        }

        $diff = max(0, $now->getTimestamp() - $fetched->getTimestamp());
        $hours = $diff / 3600;

        if ($hours <= 4) {
            return 12.0;
        }
        if ($hours <= 12) {
            return 8.0;
        }
        if ($hours <= 24) {
            return 5.0;
        }
        if ($hours <= 72) {
            return 3.0;
        }
        if ($hours <= 168) {
            return 1.5;
        }

        return 0.0;
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return array<string, mixed>
     */
    private function formatRow(array $entry): array
    {
        $topics = [];
        if (is_array($entry['topics'] ?? null)) {
            foreach ($entry['topics'] as $topic) {
                if (is_string($topic) && trim($topic) !== '') {
                    $topics[] = $topic;
                }
            }
        }

        $entities = [];
        if (is_array($entry['entities'] ?? null)) {
            foreach ($entry['entities'] as $entity) {
                if (!is_array($entity)) {
                    continue;
                }
                $label = (string) ($entity['label'] ?? '');
                $type = (string) ($entity['type'] ?? '');
                if ($label === '') {
                    continue;
                }
                $entities[] = [
                    'label' => $label,
                    'type' => $type,
                ];
            }
        }

        $qualityReasons = [];
        if (is_array($entry['quality_reasons'] ?? null)) {
            foreach ($entry['quality_reasons'] as $reason) {
                if (is_string($reason) && trim($reason) !== '') {
                    $qualityReasons[] = trim($reason);
                }
            }
        }

        $recommended = [];
        if (is_array($entry['recommended_sources'] ?? null)) {
            foreach ($entry['recommended_sources'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $url = (string) ($row['url'] ?? '');
                $domain = (string) ($row['domain'] ?? '');
                $trust = (float) ($row['trust_score'] ?? 0.0);
                if ($url === '' || $domain === '') {
                    continue;
                }
                $recommended[] = [
                    'url' => $url,
                    'domain' => $domain,
                    'trust_score' => $trust,
                ];
            }
        }

        $contentType = $this->normaliseContentType((string) ($entry['content_type'] ?? 'page'), $entry, $topics);
        $imageUrl = $this->extractImageUrl($entry);

        $highlights = [];
        if (is_array($entry['semantic_highlights'] ?? null)) {
            foreach ($entry['semantic_highlights'] as $highlight) {
                if (!is_array($highlight)) {
                    continue;
                }

                $phrase = trim((string) ($highlight['phrase'] ?? ''));
                $snippet = trim((string) ($highlight['snippet'] ?? ''));
                if ($phrase === '' || $snippet === '') {
                    continue;
                }

                $highlights[] = [
                    'phrase' => $phrase,
                    'snippet' => $snippet,
                ];
            }
        }

        return [
            'title' => (string) ($entry['title'] ?? $entry['url'] ?? ''),
            'url' => (string) ($entry['url'] ?? ''),
            'preview' => (string) ($entry['preview'] ?? ''),
            'summary' => (string) ($entry['summary'] ?? ''),
            'topics' => $topics,
            'entities' => $entities,
            'fetched_at' => (string) ($entry['fetched_at'] ?? ''),
            'last_checked_at' => (string) ($entry['last_checked_at'] ?? $entry['fetched_at'] ?? ''),
            'quality_score' => (float) ($entry['quality_score'] ?? 0.0),
            'quality_label' => (string) ($entry['quality_label'] ?? 'Low'),
            'quality_reasons' => $qualityReasons,
            'ingest' => (bool) ($entry['ingest'] ?? false),
            'source_domain' => (string) ($entry['source_domain'] ?? ''),
            'source_site_name' => (string) ($entry['site_name'] ?? $entry['source_site_name'] ?? ''),
            'source_language' => (string) ($entry['source_language'] ?? ''),
            'source_published_at' => (string) ($entry['source_published_at'] ?? $entry['published_at'] ?? ''),
            'thumbnail' => $imageUrl,
            'image_url' => $imageUrl,
            'meta_description' => (string) ($entry['meta_description'] ?? ''),
            'recommended_sources' => $recommended,
            'content_type' => $contentType,
            'revision' => (int) ($entry['revision'] ?? 1),
            'normalized_url' => (string) ($entry['normalized_url'] ?? $entry['url'] ?? ''),
            'unchanged' => (bool) ($entry['unchanged'] ?? false),
            'changes' => $this->formatChangeSummary($entry['changes'] ?? null),
            'versions' => $this->formatVersions($entry['versions'] ?? null),
            'semantic_highlights' => $highlights,
        ];
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function extractImageUrl(array $entry): string
    {
        $candidates = ['image_url', 'thumbnail', 'image', 'hero_image', 'cover_image', 'og_image', 'meta_image'];

        foreach ($candidates as $field) {
            if (!isset($entry[$field]) || !is_string($entry[$field])) {
                continue;
            }

            $normalised = $this->normaliseImageUrl($entry[$field]);
            if ($normalised !== '') {
                return $normalised;
            }
        }

        return '';
    }

    private function normaliseImageUrl(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        $candidate = trim($value);
        if ($candidate === '' || filter_var($candidate, FILTER_VALIDATE_URL) === false) {
            return '';
        }

        return $candidate;
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<int, string> $topics
     */
    private function normaliseContentType(string $value, array $entry, array $topics): string
    {
        $normalised = trim(mb_strtolower(str_replace(' ', '_', $value)));
        if ($normalised === '' || $normalised === 'unknown') {
            $normalised = 'page';
        }

        $characters = (int) ($entry['character_count'] ?? 0);
        $paragraphs = (int) ($entry['paragraph_count'] ?? 0);
        $summary = (string) ($entry['summary'] ?? $entry['preview'] ?? '');
        $summaryLength = mb_strlen($summary);
        $publishedAt = trim((string) ($entry['source_published_at'] ?? $entry['published_at'] ?? ''));
        $hasMetadata = $publishedAt !== '' || trim((string) ($entry['source_site_name'] ?? '')) !== '';
        $topicCount = count($topics);

        if ($normalised === 'non_article') {
            if ($characters >= 900 || $paragraphs >= 4 || $summaryLength >= 240 || $hasMetadata || $topicCount >= 5) {
                return 'article';
            }

            if ($characters >= 400 || $paragraphs >= 3 || $summaryLength >= 140) {
                return 'page';
            }
        }

        if ($normalised === 'page' && ($characters >= 1400 || $paragraphs >= 6 || $summaryLength >= 360)) {
            return 'article';
        }

        return $normalised === '' ? 'page' : $normalised;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     *
     * @return array<string, mixed>
     */
    private function buildMeta(array $items): array
    {
        $topicCounts = [];
        $sourceCounts = [];
        $qualityScores = [];
        $ingested = 0;
        $typeBreakdown = [];
        $semanticCounts = [];
        $semanticWeights = [];
        $recencyBuckets = [
            'past_hour' => 0,
            'past_day' => 0,
            'past_week' => 0,
            'older' => 0,
        ];
        $qualityBuckets = [
            '90_plus' => 0,
            '70_89' => 0,
            '50_69' => 0,
            'under_50' => 0,
        ];

        $now = new DateTimeImmutable();

        foreach ($items as $item) {
            $topics = is_array($item['topics'] ?? null) ? $item['topics'] : [];
            foreach ($topics as $topic) {
                if (!is_string($topic) || $topic === '') {
                    continue;
                }
                $topicCounts[$topic] = ($topicCounts[$topic] ?? 0) + 1;
            }

            $domain = (string) ($item['source_domain'] ?? '');
            if ($domain !== '') {
                $sourceCounts[$domain] = $sourceCounts[$domain] ?? ['count' => 0, 'score' => 0.0];
                $sourceCounts[$domain]['count']++;
                $sourceCounts[$domain]['score'] += (float) ($item['quality_score'] ?? 0.0);
            }

            $semanticWeightsForItem = $this->extractSemanticWeightsFromEntry($item);
            foreach ($semanticWeightsForItem as $tag => $weight) {
                $semanticCounts[$tag] = ($semanticCounts[$tag] ?? 0) + 1;
                $semanticWeights[$tag] = ($semanticWeights[$tag] ?? 0.0) + $weight;
            }

            $qualityScore = (float) ($item['quality_score'] ?? 0.0);
            $qualityScores[] = $qualityScore;
            if (!empty($item['ingest'])) {
                $ingested++;
            }

            $type = (string) ($item['content_type'] ?? '');
            if ($type !== '') {
                $typeBreakdown[$type] = ($typeBreakdown[$type] ?? 0) + 1;
            }

            $bucketKey = $this->resolveRecencyBucket($item, $now);
            if ($bucketKey !== null) {
                $recencyBuckets[$bucketKey] = ($recencyBuckets[$bucketKey] ?? 0) + 1;
            }

            $qualityBucket = $this->resolveQualityBucket($qualityScore);
            $qualityBuckets[$qualityBucket] = ($qualityBuckets[$qualityBucket] ?? 0) + 1;
        }

        arsort($topicCounts);
        $topics = [];
        foreach (array_slice(array_keys($topicCounts), 0, 8) as $topic) {
            $topics[] = [
                'topic' => $topic,
                'count' => $topicCounts[$topic],
            ];
        }

        arsort($sourceCounts);
        $sources = [];
        foreach ($sourceCounts as $domain => $stats) {
            if (!is_array($stats)) {
                continue;
            }
            $count = (int) ($stats['count'] ?? 0);
            $score = (float) ($stats['score'] ?? 0.0);
            if ($count <= 0) {
                continue;
            }
            $sources[] = [
                'domain' => $domain,
                'count' => $count,
                'average_quality' => $count > 0 ? round($score / $count, 1) : 0.0,
            ];
        }

        usort($sources, static fn(array $a, array $b): int => $b['average_quality'] <=> $a['average_quality']);
        $sources = array_slice($sources, 0, 6);

        $averageQuality = 0.0;
        if ($qualityScores !== []) {
            $averageQuality = round(array_sum($qualityScores) / max(1, count($qualityScores)), 1);
        }

        arsort($semanticCounts);
        $semanticTags = [];
        foreach (array_slice(array_keys($semanticCounts), 0, 8) as $tag) {
            $count = $semanticCounts[$tag];
            $totalWeight = $semanticWeights[$tag] ?? 0.0;
            $semanticTags[] = [
                'tag' => $tag,
                'label' => $this->presentSemanticLabel($tag),
                'count' => $count,
                'average_weight' => $count > 0 ? round($totalWeight / $count, 3) : 0.0,
            ];
        }

        $facets = [
            'recency' => $this->formatFacetList($recencyBuckets, [
                'past_hour' => 'Past hour',
                'past_day' => 'Past 24 hours',
                'past_week' => 'Past 7 days',
                'older' => 'Older',
            ]),
            'quality' => $this->formatFacetList($qualityBuckets, [
                '90_plus' => 'Score ≥ 90',
                '70_89' => 'Score 70-89',
                '50_69' => 'Score 50-69',
                'under_50' => 'Score &lt; 50',
            ]),
            'content_types' => $this->formatFacetList($typeBreakdown, null, static function (string $key): string {
                if ($key === '') {
                    return 'Unknown';
                }

                return match ($key) {
                    'article' => 'Article',
                    'page' => 'Landing page',
                    'non_article' => 'Non article',
                    'error' => 'Error',
                    default => ucfirst(str_replace('_', ' ', $key)),
                };
            }),
            'ingestion' => $this->formatFacetList([
                'ingested' => $ingested,
                'unreviewed' => max(0, count($items) - $ingested),
            ], [
                'ingested' => 'Captured & enriched',
                'unreviewed' => 'Awaiting enrichment',
            ]),
        ];

        if ($semanticCounts !== []) {
            $facets['semantic'] = $this->formatFacetList(
                $semanticCounts,
                null,
                fn(string $key): string => $this->presentSemanticLabel($key)
            );
        }

        $facetList = array_filter($facets, static fn(array $facet): bool => $facet !== []);

        return [
            'total_matches' => count($items),
            'average_quality' => $averageQuality,
            'topics' => $topics,
            'sources' => $sources,
            'high_quality' => count(array_filter($qualityScores, static fn(float $score): bool => $score >= 70.0)),
            'ingested' => $ingested,
            'semantic_tags' => $semanticTags,
            'suggested_queries' => $this->mergeSuggestedQueries($topics, $semanticTags),
            'types' => $typeBreakdown,
            'facets' => $facetList,
        ];
    }

    /**
     * @param array<string, mixed> $item
     */
    private function resolveRecencyBucket(array $item, DateTimeImmutable $now): ?string
    {
        $candidates = [
            $item['source_published_at'] ?? null,
            $item['last_checked_at'] ?? null,
            $item['fetched_at'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            try {
                $stamp = new DateTimeImmutable($candidate);
            } catch (Exception $exception) {
                continue;
            }

            $diff = $now->getTimestamp() - $stamp->getTimestamp();

            if ($diff <= 0) {
                return 'past_hour';
            }

            if ($diff <= 3600) {
                return 'past_hour';
            }

            if ($diff <= 86400) {
                return 'past_day';
            }

            if ($diff <= 604800) {
                return 'past_week';
            }

            return 'older';
        }

        return null;
    }

    private function resolveQualityBucket(float $score): string
    {
        if ($score >= 90.0) {
            return '90_plus';
        }

        if ($score >= 70.0) {
            return '70_89';
        }

        if ($score >= 50.0) {
            return '50_69';
        }

        return 'under_50';
    }

    /**
     * @param array<string, int> $buckets
     * @param array<string, string>|null $labels
     * @param callable|null $labelResolver
     *
     * @return array<int, array{key: string, label: string, count: int, share: float}>
     */
    private function formatFacetList(array $buckets, ?array $labels = null, ?callable $labelResolver = null): array
    {
        $total = 0;
        foreach ($buckets as $count) {
            if (is_numeric($count)) {
                $total += (int) $count;
            }
        }

        $formatted = [];
        foreach ($buckets as $key => $count) {
            if (!is_numeric($count) || (int) $count <= 0) {
                continue;
            }

            $label = $labels[$key] ?? null;
            if ($label === null && $labelResolver !== null) {
                $label = $labelResolver((string) $key);
            }
            if ($label === null) {
                $label = ucfirst(str_replace('_', ' ', (string) $key));
            }

            $intCount = (int) $count;
            $share = $total > 0 ? round($intCount / $total, 3) : 0.0;

            $formatted[] = [
                'key' => (string) $key,
                'label' => $label,
                'count' => $intCount,
                'share' => $share,
            ];
        }

        usort($formatted, static fn(array $a, array $b): int => $b['count'] <=> $a['count']);

        return $formatted;
    }

    /**
     * @param array<int, array{topic: string, count: int}> $topics
     * @param array<int, array{tag: string, label: string, count: int, average_weight: float}> $semanticTags
     *
     * @return array<int, string>
     */
    private function mergeSuggestedQueries(array $topics, array $semanticTags): array
    {
        $topicSuggestions = array_map(
            static fn(array $row): string => (string) ($row['topic'] ?? ''),
            array_slice($topics, 0, 5)
        );

        $semanticSuggestions = array_map(
            static fn(array $row): string => (string) ($row['label'] ?? ''),
            array_slice($semanticTags, 0, 5)
        );

        $combined = array_merge($topicSuggestions, $semanticSuggestions);
        $unique = [];
        foreach ($combined as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '') {
                continue;
            }

            if (!in_array($candidate, $unique, true)) {
                $unique[] = $candidate;
            }
        }

        return array_slice($unique, 0, 8);
    }

    private function presentSemanticLabel(string $key): string
    {
        $key = trim($key);
        if ($key === '') {
            return 'Unknown';
        }

        $upper = mb_strtoupper($key, 'UTF-8');
        if ($upper === $key && mb_strlen($key, 'UTF-8') <= 4) {
            return $upper;
        }

        $parts = preg_split('/[\s_-]+/u', $key) ?: [$key];
        $parts = array_map(static function (string $part): string {
            $part = trim($part);
            if ($part === '') {
                return '';
            }

            if (mb_strlen($part, 'UTF-8') <= 3 && strtoupper($part) === $part) {
                return strtoupper($part);
            }

            return ucfirst($part);
        }, $parts);

        $parts = array_values(array_filter($parts, static fn(string $part): bool => $part !== ''));

        return $parts === [] ? ucfirst($key) : implode(' ', $parts);
    }

    /**
     * @param mixed $changes
     *
     * @return array<string, mixed>
     */
    private function formatChangeSummary($changes): array
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
                'keywords_added' => $this->normaliseStringList($changes['keywords_added'] ?? []),
                'keywords_removed' => $this->normaliseStringList($changes['keywords_removed'] ?? []),
                'entities_added' => $this->normaliseStringList($changes['entities_added'] ?? []),
                'entities_removed' => $this->normaliseStringList($changes['entities_removed'] ?? []),
                'length_delta' => (int) ($changes['length_delta'] ?? 0),
            ];
        }

        if (is_string($changes) && $changes !== '') {
            $default['summary'] = $changes;
        }

        return $default;
    }

    /**
     * @param mixed $versions
     *
     * @return array<int, array<string, mixed>>
     */
    private function formatVersions($versions): array
    {
        if (!is_array($versions)) {
            return [];
        }

        $formatted = [];
        foreach ($versions as $version) {
            if (!is_array($version)) {
                continue;
            }

            $formatted[] = [
                'revision' => (int) ($version['revision'] ?? 0),
                'title' => (string) ($version['title'] ?? ''),
                'summary' => (string) ($version['summary'] ?? ''),
                'fetched_at' => (string) ($version['fetched_at'] ?? ''),
                'url' => (string) ($version['url'] ?? ''),
                'changes' => $this->formatChangeSummary($version['changes'] ?? null),
            ];
        }

        return array_slice($formatted, 0, 6);
    }

    /**
     * @param array<int, mixed> $values
     *
     * @return array<int, string>
     */
    private function normaliseStringList(array $values): array
    {
        $clean = [];

        foreach ($values as $value) {
            if (!is_string($value)) {
                continue;
            }

            $value = trim($value);
            if ($value === '') {
                continue;
            }

            $clean[] = $value;
        }

        return array_values(array_unique($clean));
    }
}
