<?php

declare(strict_types=1);

namespace App\KnowledgeGraph;

use function array_intersect;
use function array_map;
use function array_merge;
use function array_slice;
use function array_unique;
use function array_values;
use function count;
use function in_array;
use function is_array;
use function is_numeric;
use function is_string;
use function levenshtein;
use function max;
use function metaphone;
use function preg_replace;
use function preg_split;
use function similar_text;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function soundex;
use function sprintf;
use function strtolower;
use function strlen;
use function trim;
use function usort;

/**
 * Utility wrapper around the shared knowledge graph snapshot that exposes
 * research-friendly summaries and lookups.
 */
final class GraphResearcher
{
    private GraphRepository $repository;

    private const STOP_WORDS = [
        'a',
        'about',
        'above',
        'after',
        'again',
        'against',
        'all',
        'almost',
        'along',
        'also',
        'although',
        'am',
        'among',
        'an',
        'and',
        'any',
        'around',
        'as',
        'at',
        'be',
        'because',
        'been',
        'before',
        'being',
        'below',
        'between',
        'both',
        'but',
        'by',
        'can',
        'could',
        'did',
        'do',
        'does',
        'doing',
        'done',
        'during',
        'each',
        'either',
        'else',
        'ever',
        'for',
        'from',
        'further',
        'give',
        'given',
        'had',
        'has',
        'have',
        'having',
        'how',
        'if',
        'in',
        'into',
        'is',
        'its',
        'just',
        'latest',
        'like',
        'made',
        'make',
        'makes',
        'many',
        'may',
        'might',
        'more',
        'most',
        'much',
        'must',
        'near',
        'need',
        'needed',
        'needs',
        'new',
        'news',
        'no',
        'nor',
        'not',
        'now',
        'of',
        'off',
        'on',
        'once',
        'one',
        'only',
        'onto',
        'or',
        'other',
        'our',
        'out',
        'over',
        'please',
        'provide',
        'recent',
        'report',
        'reports',
        'said',
        'say',
        'says',
        'see',
        'should',
        'show',
        'since',
        'so',
        'some',
        'such',
        'tell',
        'than',
        'that',
        'the',
        'their',
        'them',
        'then',
        'there',
        'these',
        'they',
        'this',
        'those',
        'though',
        'through',
        'to',
        'today',
        'told',
        'toward',
        'towards',
        'under',
        'until',
        'up',
        'upon',
        'use',
        'used',
        'using',
        'very',
        'via',
        'want',
        'wanted',
        'wants',
        'was',
        'were',
        'what',
        'when',
        'where',
        'which',
        'while',
        'who',
        'whom',
        'why',
        'will',
        'with',
        'within',
        'without',
        'would',
    ];

    /**
     * @var array<string, bool>|null
     */
    private static ?array $stopWordLookup = null;

    /**
     * @var array<string, mixed>|null
     */
    private ?array $snapshotCache = null;

    /**
     * @var array{entities: array<string, string>, synonyms: array<string, array<int, string>>}|null
     */
    private ?array $entityIndexCache = null;

    /**
     * @var array<string, string>
     */
    private array $normalisedNameCache = [];

    /**
     * @var array<string, array<int, string>>
     */
    private array $tokenCache = [];

    public function __construct(?GraphRepository $repository = null)
    {
        $this->repository = $repository ?? new GraphRepository();
    }

    /**
     * Load the persisted knowledge graph snapshot.
     *
     * @return array{
     *     triples: array<int, array{subject: string, relation: string, object: string}>,
     *     synonyms: array<int, array{entity: string, synonyms: array<int, string>}>,
     *     relations: array<string, int>,
     *     entities: array<string, int>,
     *     summary: array<string, mixed>,
     *     cross_references: array<string, array<string, mixed>>
     * }
     */
    public function snapshot(): array
    {
        if ($this->snapshotCache !== null) {
            return $this->snapshotCache;
        }

        $payload = $this->repository->load();
        $graph = $payload['graph'];

        if (!is_array($graph)) {
            $this->snapshotCache = [
                'triples' => [],
                'synonyms' => [],
                'relations' => [],
                'entities' => [],
                'summary' => [],
                'cross_references' => [],
            ];

            return $this->snapshotCache;
        }

        $this->snapshotCache = [
            'triples' => $this->normaliseTriples($graph['triples'] ?? []),
            'synonyms' => $this->normaliseSynonyms($graph['synonyms'] ?? []),
            'relations' => $this->normaliseHistogram($graph['relations'] ?? []),
            'entities' => $this->normaliseHistogram($graph['entities'] ?? []),
            'summary' => is_array($graph['summary'] ?? null) ? $graph['summary'] : [],
            'cross_references' => $this->normaliseCrossReferences($graph['cross_references'] ?? []),
        ];

        $this->entityIndexCache = null;
        $this->normalisedNameCache = [];
        $this->tokenCache = [];

        return $this->snapshotCache;
    }

    /**
     * @return array{sources: array<int, array<string, mixed>>, updated_at: string|null}
     */
    public function metadata(): array
    {
        $payload = $this->repository->load();
        $sources = $payload['sources'];

        return [
            'sources' => is_array($sources) ? array_values($sources) : [],
            'updated_at' => is_string($payload['updated_at']) ? $payload['updated_at'] : null,
        ];
    }

    /**
     * Return the highest-ranked entities for quick discovery.
     *
     * @return array<int, array{entity: string, score: float, eligible: bool, fact_count: int, synonym_count: int}>
     */
    public function listTopEntities(int $limit = 10): array
    {
        $snapshot = $this->snapshot();
        $references = $snapshot['cross_references'];

        $rows = [];
        foreach ($references as $entity => $payload) {
            if (!is_string($entity) || $entity === '' || !is_array($payload)) {
                continue;
            }

            $ranking = is_array($payload['ranking'] ?? null) ? $payload['ranking'] : [];
            $score = $this->floatValue($ranking['score'] ?? 0.0);
            $eligible = (bool) ($ranking['eligible'] ?? false);

            $facts = is_array($payload['facts'] ?? null) ? $payload['facts'] : [];
            $synonyms = is_array($payload['synonyms'] ?? null) ? $payload['synonyms'] : [];

            $rows[] = [
                'entity' => $entity,
                'score' => $score,
                'eligible' => $eligible,
                'fact_count' => count($facts),
                'synonym_count' => count($synonyms),
            ];
        }

        usort(
            $rows,
            static function (array $left, array $right): int {
                if ($left['score'] === $right['score']) {
                    if ($left['fact_count'] === $right['fact_count']) {
                        return $left['entity'] <=> $right['entity'];
                    }

                    return $right['fact_count'] <=> $left['fact_count'];
                }

                return $right['score'] <=> $left['score'];
            }
        );

        if ($limit < 1) {
            return $rows;
        }

        return array_slice($rows, 0, $limit);
    }

    /**
     * Execute a linguistically-aware search across the knowledge graph.
     *
     * @return array{
     *     query: string,
     *     entities: array<int, array<string, mixed>>,
     *     relations: array<int, array<string, mixed>>,
     *     synonyms: array<int, array<string, mixed>>,
     *     triples: array<int, array<string, mixed>>,
     *     summary: array<string, mixed>,
     *     sources: array<int, array<string, mixed>>,
     *     updated_at: string|null
     * }
     */
    public function searchGraph(string $query, int $limit = 12): array
    {
        $query = trim($query);

        $snapshot = $this->snapshot();
        $metadata = $this->metadata();

        $sources = $this->filterSourcesForQuery($metadata['sources'], $query, max(6, $limit));

        $result = [
            'query' => $query,
            'entities' => [],
            'relations' => [],
            'synonyms' => [],
            'triples' => [],
            'summary' => $snapshot['summary'],
            'sources' => $this->sanitiseSources($sources),
            'updated_at' => $metadata['updated_at'],
        ];

        if ($snapshot['triples'] === [] && $snapshot['synonyms'] === [] && $snapshot['entities'] === []) {
            return $result;
        }

        $limit = max(1, $limit);

        if ($query === '') {
            $result['entities'] = $this->buildDefaultEntitySummaries($limit);
            $result['relations'] = $this->buildRelationMatches('', $snapshot['relations'], $limit);
            $result['synonyms'] = $this->buildSynonymMatches('', $snapshot['synonyms'], $limit);
            $result['triples'] = $this->buildTripleMatches('', $snapshot['triples'], min(50, $limit * 4));

            return $result;
        }

        $references = $snapshot['cross_references'];
        $entities = [];

        foreach ($references as $entity => $payload) {
            if (!is_string($entity) || $entity === '' || !is_array($payload)) {
                continue;
            }

            $labelMatch = $this->evaluateMatch($query, $entity);

            $synonymMatch = ['score' => 0.0, 'matched' => null, 'signals' => []];
            if (isset($payload['synonyms']) && is_array($payload['synonyms'])) {
                $synonymMatch = $this->evaluateSynonymSet($query, $payload['synonyms']);
            }

            $factMatch = ['score' => 0.0, 'matched' => null, 'signals' => []];
            if (isset($payload['facts']) && is_array($payload['facts'])) {
                $factMatch = $this->evaluateFacts($query, $payload['facts']);
            }

            $score = max($labelMatch['score'], $synonymMatch['score'], $factMatch['score']);
            if ($score <= 0.0) {
                continue;
            }

            $signals = $this->filterSignals([
                'label' => $labelMatch['score'],
                'synonym' => $synonymMatch['score'],
                'context' => $factMatch['score'],
            ]);

            $entities[] = [
                'entity' => $entity,
                'score' => $score,
                'signals' => $signals,
                'matched_synonym' => $synonymMatch['matched'],
                'matched_fact' => $factMatch['matched'],
            ];
        }

        usort(
            $entities,
            static function (array $left, array $right): int {
                if ($left['score'] === $right['score']) {
                    return $left['entity'] <=> $right['entity'];
                }

                return $right['score'] <=> $left['score'];
            }
        );

        $entities = array_slice($entities, 0, $limit);

        foreach ($entities as &$entityMatch) {
            $entityName = $entityMatch['entity'];
            $summary = null;

            if (isset($references[$entityName]) && is_array($references[$entityName])) {
                $summary = $this->summariseKnownEntity($entityName, $references[$entityName], 6);
            }

            if ($summary === null) {
                $summary = $this->summariseEntity($entityName, 6);
            }

            $summary = is_array($summary) ? $summary : [];

            $entityMatch['summary'] = $summary;
            $entityMatch['eligible'] = isset($summary['eligible']) ? (bool) $summary['eligible'] : false;
            $entityMatch['fact_count'] = isset($summary['fact_count']) ? (int) $summary['fact_count'] : 0;
            $entityMatch['synonyms'] = isset($summary['synonyms']) && is_array($summary['synonyms'])
                ? array_values($summary['synonyms'])
                : [];
            $entityMatch['facts'] = isset($summary['fact_descriptions']) && is_array($summary['fact_descriptions'])
                ? array_slice($summary['fact_descriptions'], 0, 6)
                : [];
        }
        unset($entityMatch);

        $result['entities'] = $entities;
        $result['relations'] = $this->buildRelationMatches($query, $snapshot['relations'], $limit);
        $result['synonyms'] = $this->buildSynonymMatches($query, $snapshot['synonyms'], $limit);
        $result['triples'] = $this->buildTripleMatches($query, $snapshot['triples'], min(50, $limit * 4));

        return $result;
    }

    /**
     * Summarise the graph facts for an entity or synonym query.
     *
     * @return array{
     *     entity: string,
     *     score: float,
     *     eligible: bool,
     *     synonyms: array<int, string>,
     *     signals: array<string, float>,
     *     support: array<string, int>,
     *     facts: array<int, array{direction: string, relation: string, counterpart: string}>,
     *     fact_count: int,
     *     relation_counts: array<string, int>,
     *     counterpart_counts: array<string, int>,
     *     context: array{as_subject: array<string, int>, as_object: array<string, int>}
     * }|null
     */
    public function summariseEntity(string $query, int $factLimit = 12): ?array
    {
        $needle = $this->normaliseName($query);
        if ($needle === '') {
            return null;
        }

        $snapshot = $this->snapshot();
        $references = $snapshot['cross_references'];
        if ($references === []) {
            return null;
        }

        $index = $this->entityIndex($references);

        $match = $index['entities'][$needle] ?? null;
        if ($match === null && isset($index['synonyms'][$needle])) {
            $candidates = $index['synonyms'][$needle];
            $match = $this->selectBestEntity($candidates, $references);
        }

        if ($match === null) {
            $match = $this->fuzzyMatch($needle, $index, $references);
        }

        if ($match === null || !isset($references[$match])) {
            return null;
        }

        $payload = $references[$match];
        if (!is_array($payload)) {
            return null;
        }

        return $this->summariseKnownEntity($match, $payload, $factLimit);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private function summariseKnownEntity(string $entity, array $payload, int $factLimit = 12): ?array
    {
        $entity = trim($entity);
        if ($entity === '') {
            return null;
        }

        $facts = [];
        $rawFacts = is_array($payload['facts'] ?? null) ? $payload['facts'] : [];
        foreach ($rawFacts as $fact) {
            if (!is_array($fact)) {
                continue;
            }

            $facts[] = [
                'direction' => (string) ($fact['direction'] ?? ''),
                'relation' => (string) ($fact['relation'] ?? ''),
                'counterpart' => (string) ($fact['counterpart'] ?? ''),
            ];
        }

        $relationCounts = [];
        $counterpartCounts = [];
        foreach ($facts as $fact) {
            $relation = $fact['relation'];
            if ($relation !== '') {
                $relationCounts[$relation] = ($relationCounts[$relation] ?? 0) + 1;
            }

            $counterpart = $fact['counterpart'];
            if ($counterpart !== '') {
                $counterpartCounts[$counterpart] = ($counterpartCounts[$counterpart] ?? 0) + 1;
            }
        }

        arsort($relationCounts);
        arsort($counterpartCounts);

        $ranking = is_array($payload['ranking'] ?? null) ? $payload['ranking'] : [];
        $signals = is_array($ranking['signals'] ?? null) ? $ranking['signals'] : [];
        $support = is_array($ranking['support'] ?? null) ? $ranking['support'] : [];

        $synonyms = is_array($payload['synonyms'] ?? null) ? array_values($payload['synonyms']) : [];
        $context = is_array($payload['context'] ?? null) ? $payload['context'] : [];

        $limitedFacts = $factLimit > 0 ? array_slice($facts, 0, $factLimit) : $facts;
        $factDescriptions = $this->buildFactDescriptions($entity, $limitedFacts);

        return [
            'entity' => $entity,
            'score' => $this->floatValue($ranking['score'] ?? 0.0),
            'eligible' => (bool) ($ranking['eligible'] ?? false),
            'synonyms' => $synonyms,
            'signals' => $this->normaliseSignals($signals),
            'support' => $this->normaliseSupport($support),
            'facts' => $limitedFacts,
            'fact_descriptions' => $factDescriptions,
            'fact_count' => count($facts),
            'relation_counts' => $relationCounts,
            'counterpart_counts' => $counterpartCounts,
            'context' => [
                'as_subject' => $this->normaliseHistogram($context['as_subject'] ?? []),
                'as_object' => $this->normaliseHistogram($context['as_object'] ?? []),
            ],
        ];
    }

    /**
     * @param array<int, array{direction: string, relation: string, counterpart: string}> $facts
     * @return array<int, string>
     */
    private function buildFactDescriptions(string $entity, array $facts): array
    {
        $entity = trim($entity);
        if ($entity === '') {
            return [];
        }

        $descriptions = [];
        $seen = [];

        foreach ($facts as $fact) {
            if (!is_array($fact)) {
                continue;
            }

            $description = $this->formatFactDescription($entity, $fact);
            if ($description === '') {
                continue;
            }

            if (isset($seen[$description])) {
                continue;
            }

            $seen[$description] = true;
            $descriptions[] = $description;
        }

        return $descriptions;
    }

    /**
     * @param array<string, string> $fact
     */
    private function formatFactDescription(string $entity, array $fact): string
    {
        $direction = strtolower(trim((string) ($fact['direction'] ?? '')));
        $relation = trim((string) ($fact['relation'] ?? ''));
        $counterpart = trim((string) ($fact['counterpart'] ?? ''));

        if ($relation === '' && $counterpart === '') {
            return '';
        }

        $text = '';

        if ($direction === 'incoming') {
            if ($counterpart !== '' && $relation !== '') {
                $text = sprintf('Receives “%s” from %s.', $relation, $counterpart);
            } elseif ($counterpart !== '') {
                $text = sprintf('Connected to %s.', $counterpart);
            } elseif ($relation !== '') {
                $text = sprintf('Receives relation “%s”.', $relation);
            }
        } else {
            if ($counterpart !== '' && $relation !== '') {
                $text = sprintf('Links to %s via “%s”.', $counterpart, $relation);
            } elseif ($relation !== '') {
                $text = sprintf('Links via “%s”.', $relation);
            } elseif ($counterpart !== '') {
                $text = sprintf('Connected to %s.', $counterpart);
            }
        }

        return trim($text);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildDefaultEntitySummaries(int $limit): array
    {
        $rows = [];

        foreach ($this->listTopEntities($limit) as $entityRow) {
            $summary = $this->summariseEntity($entityRow['entity'], 6);
            $summary = is_array($summary) ? $summary : [];

            $rows[] = [
                'entity' => $entityRow['entity'],
                'score' => $entityRow['score'],
                'eligible' => $entityRow['eligible'],
                'fact_count' => $entityRow['fact_count'],
                'synonym_count' => $entityRow['synonym_count'],
                'synonyms' => isset($summary['synonyms']) && is_array($summary['synonyms'])
                    ? array_values($summary['synonyms'])
                    : [],
                'facts' => isset($summary['fact_descriptions']) && is_array($summary['fact_descriptions'])
                    ? array_slice($summary['fact_descriptions'], 0, 6)
                    : [],
                'summary' => $summary,
                'signals' => ['ranking' => $entityRow['score']],
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, array<string, mixed>> $references
     * @return array{entities: array<string, string>, synonyms: array<string, array<int, string>>}
     */
    private function entityIndex(array $references): array
    {
        if ($this->entityIndexCache !== null) {
            return $this->entityIndexCache;
        }

        $this->entityIndexCache = $this->buildEntityIndex($references);

        return $this->entityIndexCache;
    }

    /**
     * @param array<string, int> $histogram
     * @return array<int, array<string, mixed>>
     */
    private function buildRelationMatches(string $query, array $histogram, int $limit): array
    {
        $matches = [];
        $maxFrequency = 0;
        foreach ($histogram as $count) {
            if (is_numeric($count)) {
                $maxFrequency = max($maxFrequency, (int) $count);
            }
        }

        foreach ($histogram as $relation => $count) {
            if (!is_string($relation)) {
                continue;
            }

            $match = $query === '' ? ['score' => 0.0, 'signals' => []] : $this->evaluateMatch($query, $relation);
            $score = $query === ''
                ? $this->normaliseFrequencyScore((int) $count, $maxFrequency)
                : $match['score'];

            if ($query !== '' && $score < 0.2) {
                continue;
            }

            $signals = $match['signals'] ?? [];
            $signals['frequency'] = (float) $count;

            $matches[] = [
                'relation' => $relation,
                'count' => (int) $count,
                'score' => $score,
                'signals' => $this->filterSignals($signals),
            ];
        }

        usort(
            $matches,
            static function (array $left, array $right) use ($query): int {
                if ($left['score'] === $right['score']) {
                    if ($query === '') {
                        return $right['count'] <=> $left['count'];
                    }

                    return $left['relation'] <=> $right['relation'];
                }

                return $right['score'] <=> $left['score'];
            }
        );

        return array_slice($matches, 0, $limit);
    }

    /**
     * @param array<int, array{entity: string, synonyms: array<int, string>}> $synonyms
     * @return array<int, array<string, mixed>>
     */
    private function buildSynonymMatches(string $query, array $synonyms, int $limit): array
    {
        $matches = [];
        $maxCount = 0;

        foreach ($synonyms as $pair) {
            if (!is_array($pair)) {
                continue;
            }

            $entity = (string) ($pair['entity'] ?? '');
            $list = isset($pair['synonyms']) && is_array($pair['synonyms']) ? array_values($pair['synonyms']) : [];

            if ($entity === '' || $list === []) {
                continue;
            }

            if ($query === '') {
                $count = count($list);
                $maxCount = max($maxCount, $count);
                $matches[] = [
                    'entity' => $entity,
                    'synonyms' => $list,
                    'score' => (float) $count,
                    'matched_synonym' => null,
                    'signals' => ['synonym_count' => (float) $count],
                ];
                continue;
            }

            $synonymMatch = $this->evaluateSynonymSet($query, $list);
            if ($synonymMatch['score'] < 0.2) {
                continue;
            }

            $matches[] = [
                'entity' => $entity,
                'synonyms' => $list,
                'score' => $synonymMatch['score'],
                'matched_synonym' => $synonymMatch['matched'],
                'signals' => $this->filterSignals($synonymMatch['signals']),
            ];
        }

        usort(
            $matches,
            static function (array $left, array $right) use ($query): int {
                if ($left['score'] === $right['score']) {
                    return $left['entity'] <=> $right['entity'];
                }

                if ($query === '') {
                    return $right['score'] <=> $left['score'];
                }

                return $right['score'] <=> $left['score'];
            }
        );

        if ($query === '') {
            foreach ($matches as &$match) {
                $match['score'] = $this->normaliseFrequencyScore((int) ($match['signals']['synonym_count'] ?? 0), $maxCount);
                $match['signals'] = $this->filterSignals($match['signals']);
            }
            unset($match);
        }

        return array_slice($matches, 0, $limit);
    }

    /**
     * @param array<int, array{subject: string, relation: string, object: string}> $triples
     * @return array<int, array<string, mixed>>
     */
    private function buildTripleMatches(string $query, array $triples, int $limit): array
    {
        $matches = [];

        foreach ($triples as $triple) {
            if (!is_array($triple)) {
                continue;
            }

            $subject = (string) ($triple['subject'] ?? $triple[0] ?? '');
            $relation = (string) ($triple['relation'] ?? $triple[1] ?? '');
            $object = (string) ($triple['object'] ?? $triple[2] ?? '');

            if ($query === '') {
                $matches[] = [
                    'subject' => $subject,
                    'relation' => $relation,
                    'object' => $object,
                    'score' => 1.0,
                    'signals' => ['baseline' => 1.0],
                ];
                continue;
            }

            $subjectMatch = $this->evaluateMatch($query, $subject);
            $relationMatch = $this->evaluateMatch($query, $relation);
            $objectMatch = $this->evaluateMatch($query, $object);

            $score = max($subjectMatch['score'], $relationMatch['score'], $objectMatch['score']);
            if ($score < 0.25) {
                continue;
            }

            $matches[] = [
                'subject' => $subject,
                'relation' => $relation,
                'object' => $object,
                'score' => $score,
                'signals' => $this->filterSignals([
                    'subject' => $subjectMatch['score'],
                    'relation' => $relationMatch['score'],
                    'object' => $objectMatch['score'],
                ]),
            ];
        }

        if ($query === '') {
            return array_slice($matches, 0, $limit);
        }

        usort(
            $matches,
            static function (array $left, array $right): int {
                if ($left['score'] === $right['score']) {
                    $leftLabel = $left['subject'] . $left['relation'] . $left['object'];
                    $rightLabel = $right['subject'] . $right['relation'] . $right['object'];

                    return $leftLabel <=> $rightLabel;
                }

                return $right['score'] <=> $left['score'];
            }
        );

        return array_slice($matches, 0, $limit);
    }

    /**
     * @param array<int, string> $synonyms
     * @return array{score: float, matched: string|null, signals: array<string, float>}
     */
    private function evaluateSynonymSet(string $query, array $synonyms): array
    {
        $bestScore = 0.0;
        $bestSynonym = null;
        $bestSignals = [];

        foreach ($synonyms as $synonym) {
            if (!is_string($synonym) || $synonym === '') {
                continue;
            }

            $match = $this->evaluateMatch($query, $synonym);
            if ($match['score'] <= $bestScore) {
                continue;
            }

            $bestScore = $match['score'];
            $bestSynonym = $synonym;
            $bestSignals = $match['signals'];
        }

        return [
            'score' => $bestScore,
            'matched' => $bestSynonym,
            'signals' => $this->filterSignals($bestSignals),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $facts
     * @return array{score: float, matched: string|null, signals: array<string, float>}
     */
    private function evaluateFacts(string $query, array $facts): array
    {
        $bestScore = 0.0;
        $bestDescription = null;
        $bestSignals = [];

        foreach ($facts as $fact) {
            if (!is_array($fact)) {
                continue;
            }

            $relation = (string) ($fact['relation'] ?? '');
            $counterpart = (string) ($fact['counterpart'] ?? '');
            $direction = (string) ($fact['direction'] ?? '');

            $relationMatch = $this->evaluateMatch($query, $relation);
            $counterpartMatch = $this->evaluateMatch($query, $counterpart);

            $score = max($relationMatch['score'], $counterpartMatch['score']);
            if ($score <= $bestScore) {
                continue;
            }

            $bestScore = $score;
            $bestSignals = $this->filterSignals([
                'relation' => $relationMatch['score'],
                'counterpart' => $counterpartMatch['score'],
            ]);
            $bestDescription = trim($direction . ' ' . trim($relation . ' ' . $counterpart));
        }

        return [
            'score' => $bestScore,
            'matched' => $bestDescription !== '' ? $bestDescription : null,
            'signals' => $bestSignals,
        ];
    }

    /**
     * @param array<string, float> $signals
     * @return array<string, float>
     */
    private function filterSignals(array $signals): array
    {
        $filtered = [];
        foreach ($signals as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $numeric = (float) $value;
            if ($numeric <= 0.0) {
                continue;
            }

            $filtered[$key] = round($numeric, 4);
        }

        return $filtered;
    }

    /**
     * @param array<int, array<string, mixed>> $sources
     * @return array<int, array<string, mixed>>
     */
    private function filterSourcesForQuery(array $sources, string $query, int $limit): array
    {
        $limit = max(1, $limit);
        if ($sources === []) {
            return [];
        }

        $query = trim($query);
        if ($query === '') {
            return array_slice($sources, 0, $limit);
        }

        $normalisedQuery = $this->normaliseName($query);
        if ($normalisedQuery === '') {
            return array_slice($sources, 0, $limit);
        }

        $tokens = $this->tokenize($normalisedQuery);
        $focusedTokens = $this->focusTokens($tokens);
        if ($focusedTokens !== []) {
            $tokens = $focusedTokens;
        }

        if ($tokens === []) {
            return array_slice($sources, 0, $limit);
        }

        $scored = [];
        foreach ($sources as $source) {
            if (!is_array($source)) {
                continue;
            }

            $score = $this->scoreSourceAgainstTokens($source, $tokens);
            if ($score <= 0.0) {
                continue;
            }

            $scored[] = ['score' => $score, 'source' => $source];
        }

        if ($scored === []) {
            return array_slice($sources, 0, $limit);
        }

        usort(
            $scored,
            static function (array $left, array $right): int {
                if ($left['score'] === $right['score']) {
                    $leftTitle = isset($left['source']['title']) && is_string($left['source']['title'])
                        ? $left['source']['title']
                        : '';
                    $rightTitle = isset($right['source']['title']) && is_string($right['source']['title'])
                        ? $right['source']['title']
                        : '';

                    return $leftTitle <=> $rightTitle;
                }

                return ($left['score'] < $right['score']) ? 1 : -1;
            }
        );

        $filtered = array_slice($scored, 0, $limit);

        return array_map(
            static function (array $entry): array {
                /** @var array<string, mixed> $source */
                $source = $entry['source'];
                return $source;
            },
            $filtered
        );
    }

    /**
     * @param array<string, mixed> $source
     * @param array<int, string> $tokens
     */
    private function scoreSourceAgainstTokens(array $source, array $tokens): float
    {
        $title = isset($source['title']) && is_string($source['title']) ? $source['title'] : '';
        $preview = isset($source['preview']) && is_string($source['preview']) ? $source['preview'] : '';
        $content = isset($source['content']) && is_string($source['content']) ? $source['content'] : '';
        $url = isset($source['url']) && is_string($source['url']) ? $source['url'] : '';

        $titleScore = $this->tokenCoverage($title, $tokens);
        $previewScore = $this->tokenCoverage($preview, $tokens);
        $contentScore = $this->tokenCoverage($content, $tokens);
        $urlScore = $this->tokenCoverage($url, $tokens);

        $boost = 0.0;
        if ($titleScore > 0.0) {
            $boost += 0.2;
        }
        if ($previewScore > 0.0 && $titleScore === 0.0) {
            $boost += 0.1;
        }

        $score = ($titleScore * 0.5)
            + ($previewScore * 0.25)
            + ($contentScore * 0.2)
            + ($urlScore * 0.05)
            + $boost;

        return max(0.0, min(1.0, $score));
    }

    /**
     * @param array<int, string> $tokens
     */
    private function tokenCoverage(string $text, array $tokens): float
    {
        if ($text === '' || $tokens === []) {
            return 0.0;
        }

        $normalised = $this->normaliseName($text);
        if ($normalised === '') {
            return 0.0;
        }

        $haystack = $this->tokenize($normalised);
        if ($haystack === []) {
            return 0.0;
        }

        $matches = array_intersect($tokens, $haystack);
        if ($matches === []) {
            return 0.0;
        }

        return count($matches) / count($tokens);
    }

    /**
     * @param array<int, array<string, mixed>> $sources
     * @return array<int, array<string, mixed>>
     */
    private function sanitiseSources(array $sources): array
    {
        return array_map(
            static function (array $source): array {
                unset($source['content']);
                return $source;
            },
            $sources
        );
    }

    private function normaliseFrequencyScore(int $value, int $maxValue): float
    {
        if ($maxValue <= 0) {
            return 0.0;
        }

        return max(0.0, min(1.0, $value / $maxValue));
    }

    /**
     * @return array{score: float, signals: array<string, float>}
     */
    private function evaluateMatch(string $query, string $candidate): array
    {
        $query = trim($query);
        $candidate = trim($candidate);

        if ($query === '' || $candidate === '') {
            return ['score' => 0.0, 'signals' => []];
        }

        $normalCandidate = $this->normaliseName($candidate);
        if ($normalCandidate === '') {
            return ['score' => 0.0, 'signals' => []];
        }

        $normalNeedle = $this->normaliseName($query);
        $focusedNeedle = $this->focusQuery($query, $normalNeedle);

        if ($normalNeedle === '') {
            $normalNeedle = $focusedNeedle;
        }

        if ($normalNeedle === '') {
            return ['score' => 0.0, 'signals' => []];
        }

        $best = $this->buildMatchScore($normalNeedle, $normalCandidate, trim($query), $candidate);

        if ($focusedNeedle !== '' && $focusedNeedle !== $normalNeedle) {
            $focused = $this->buildMatchScore($focusedNeedle, $normalCandidate, $focusedNeedle, $candidate);

            if ($focused['score'] > $best['score']) {
                $best = $focused;
            } elseif ($focused['score'] === $best['score'] && $focused['signals'] !== []) {
                $best['signals'] = $this->mergeSignals($best['signals'], $focused['signals']);
            }
        }

        return $best;
    }

    private function lexicalSimilarity(string $left, string $right): float
    {
        if ($left === '' || $right === '') {
            return 0.0;
        }

        $percent = 0.0;
        similar_text($left, $right, $percent);

        $maxLength = max(strlen($left), strlen($right));
        $levScore = 0.0;
        if ($maxLength > 0) {
            $levenshtein = levenshtein($left, $right);
            $levScore = 1.0 - ($levenshtein / $maxLength);
        }

        return max($percent / 100.0, max(0.0, $levScore));
    }

    private function tokenOverlapScore(string $left, string $right): float
    {
        $leftTokens = $this->tokenize($left);
        $rightTokens = $this->tokenize($right);

        if ($leftTokens === [] || $rightTokens === []) {
            return 0.0;
        }

        $intersection = array_intersect($leftTokens, $rightTokens);
        $union = array_unique(array_merge($leftTokens, $rightTokens));

        if ($union === []) {
            return 0.0;
        }

        return count($intersection) / count($union);
    }

    private function phoneticSimilarity(string $left, string $right): float
    {
        $leftMeta = metaphone($left);
        $rightMeta = metaphone($right);

        if ($leftMeta !== '' && $leftMeta === $rightMeta) {
            return 1.0;
        }

        $leftSoundex = soundex($left);
        $rightSoundex = soundex($right);

        if ($leftSoundex !== '' && $leftSoundex === $rightSoundex) {
            return 0.7;
        }

        return 0.0;
    }

    private function affinityScore(string $needle, string $candidate): float
    {
        if ($needle === '' || $candidate === '') {
            return 0.0;
        }

        if (str_starts_with($candidate, $needle)) {
            return 1.0;
        }

        if (str_contains($candidate, $needle)) {
            return 0.6;
        }

        return 0.0;
    }

    /**
     * @return array<int, string>
     */
    private function tokenize(string $value): array
    {
        if (isset($this->tokenCache[$value])) {
            return $this->tokenCache[$value];
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            $this->tokenCache[$value] = [];

            return [];
        }

        $parts = preg_split('/[\s-]+/u', $trimmed);
        if (!is_array($parts)) {
            $this->tokenCache[$value] = [];

            return [];
        }

        $tokens = [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part === '') {
                continue;
            }

            $tokens[] = $part;
        }

        $unique = array_values(array_unique($tokens));
        $this->tokenCache[$value] = $unique;

        return $unique;
    }

    /**
     * @return array{score: float, signals: array<string, float>}
     */
    private function buildMatchScore(string $needle, string $candidate, string $phoneticNeedle, string $phoneticCandidate): array
    {
        $lexical = $this->lexicalSimilarity($needle, $candidate);
        $overlap = $this->tokenOverlapScore($needle, $candidate);
        $phonetic = $this->phoneticSimilarity($phoneticNeedle, $phoneticCandidate);
        $affinity = $this->affinityScore($needle, $candidate);

        $score = ($lexical * 0.45) + ($overlap * 0.25) + ($phonetic * 0.2) + ($affinity * 0.1);

        return [
            'score' => max(0.0, min(1.0, $score)),
            'signals' => $this->filterSignals([
                'lexical' => $lexical,
                'overlap' => $overlap,
                'phonetic' => $phonetic,
                'affinity' => $affinity,
            ]),
        ];
    }

    /**
     * @param array<string, float> $primary
     * @param array<string, float> $secondary
     * @return array<string, float>
     */
    private function mergeSignals(array $primary, array $secondary): array
    {
        $merged = $primary;

        foreach ($secondary as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            if (!isset($merged[$key]) || $value > $merged[$key]) {
                $merged[$key] = $value;
            }
        }

        return $this->filterSignals($merged);
    }

    private function focusQuery(string $query, ?string $normalised = null): string
    {
        $normalised = $normalised ?? $this->normaliseName($query);
        if ($normalised === '') {
            return '';
        }

        $tokens = $this->tokenize($normalised);
        if ($tokens === []) {
            return $normalised;
        }

        $focused = $this->focusTokens($tokens);
        if ($focused === []) {
            return $normalised;
        }

        return implode(' ', $focused);
    }

    /**
     * @param array<int, string> $tokens
     * @return array<int, string>
     */
    private function focusTokens(array $tokens): array
    {
        if ($tokens === []) {
            return [];
        }

        $lookup = $this->stopWordLookup();
        $focused = [];

        foreach ($tokens as $token) {
            $token = trim((string) $token);
            if ($token === '') {
                continue;
            }

            if (isset($lookup[$token])) {
                continue;
            }

            $stem = $this->stemToken($token);
            if ($stem === '' || isset($lookup[$stem])) {
                continue;
            }

            if (in_array($stem, $focused, true)) {
                continue;
            }

            $focused[] = $stem;
        }

        return $focused;
    }

    private function stemToken(string $token): string
    {
        $token = trim($token);
        if ($token === '') {
            return '';
        }

        if (str_ends_with($token, "'s")) {
            $token = substr($token, 0, -2);
        }

        $length = strlen($token);
        if ($length <= 3) {
            return $token;
        }

        if ($length > 4 && str_ends_with($token, 'ies')) {
            return substr($token, 0, -3) . 'y';
        }

        foreach (['ing', 'ers', 'ied'] as $suffix) {
            if ($length > 4 && str_ends_with($token, $suffix)) {
                return substr($token, 0, -strlen($suffix));
            }
        }

        foreach (['ed', 'es'] as $suffix) {
            if ($length > 3 && str_ends_with($token, $suffix)) {
                return substr($token, 0, -strlen($suffix));
            }
        }

        if ($length > 3 && str_ends_with($token, 's')) {
            return substr($token, 0, -1);
        }

        return $token;
    }

    /**
     * @return array<string, bool>
     */
    private function stopWordLookup(): array
    {
        if (self::$stopWordLookup !== null) {
            return self::$stopWordLookup;
        }

        $lookup = [];
        foreach (self::STOP_WORDS as $word) {
            $lookup[$word] = true;
        }

        self::$stopWordLookup = $lookup;

        return $lookup;
    }

    /**
     * @param array<string, array<string, mixed>> $references
     * @return array{entities: array<string, string>, synonyms: array<string, array<int, string>>}
     */
    private function buildEntityIndex(array $references): array
    {
        $entities = [];
        $synonyms = [];

        foreach ($references as $entity => $payload) {
            if (!is_string($entity) || $entity === '' || !is_array($payload)) {
                continue;
            }

            $normalised = $this->normaliseName($entity);
            if ($normalised !== '') {
                $entities[$normalised] = $entity;
            }

            if (!isset($payload['synonyms']) || !is_array($payload['synonyms'])) {
                continue;
            }

            foreach ($payload['synonyms'] as $synonym) {
                if (!is_string($synonym) || $synonym === '') {
                    continue;
                }
                $normalizedSynonym = $this->normaliseName($synonym);
                if ($normalizedSynonym === '') {
                    continue;
                }
                $synonyms[$normalizedSynonym] = $synonyms[$normalizedSynonym] ?? [];
                if (!in_array($entity, $synonyms[$normalizedSynonym], true)) {
                    $synonyms[$normalizedSynonym][] = $entity;
                }
            }
        }

        return ['entities' => $entities, 'synonyms' => $synonyms];
    }

    /**
     * @param array<int, string> $candidates
     * @param array<string, array<string, mixed>> $references
     */
    private function selectBestEntity(array $candidates, array $references): ?string
    {
        $bestEntity = null;
        $bestScore = -1.0;

        foreach ($candidates as $entity) {
            if (!isset($references[$entity]) || !is_array($references[$entity])) {
                continue;
            }
            $score = $this->floatValue($references[$entity]['ranking']['score'] ?? 0.0);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestEntity = $entity;
            }
        }

        return $bestEntity;
    }

    /**
     * @param array{entities: array<string, string>, synonyms: array<string, array<int, string>>} $index
     * @param array<string, array<string, mixed>> $references
     */
    private function fuzzyMatch(string $needle, array $index, array $references): ?string
    {
        $candidates = [];

        foreach ($index['entities'] as $normalised => $entity) {
            if ($needle === $normalised) {
                return $entity;
            }

            if ($needle !== '' && str_contains($normalised, $needle)) {
                $candidates[] = $entity;
            }
        }

        foreach ($index['synonyms'] as $normalised => $entities) {
            if ($needle !== '' && str_contains($normalised, $needle)) {
                foreach ($entities as $entity) {
                    $candidates[] = $entity;
                }
            }
        }

        if ($candidates === []) {
            return null;
        }

        return $this->selectBestEntity($candidates, $references);
    }

    /**
     * @param mixed $value
     * @return array<int, array{subject: string, relation: string, object: string}>
     */
    private function normaliseTriples($value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $triples = [];
        foreach ($value as $triple) {
            if (!is_array($triple)) {
                continue;
            }

            $triples[] = [
                'subject' => (string) ($triple['subject'] ?? $triple[0] ?? ''),
                'relation' => (string) ($triple['relation'] ?? $triple[1] ?? ''),
                'object' => (string) ($triple['object'] ?? $triple[2] ?? ''),
            ];
        }

        return $triples;
    }

    /**
     * @param mixed $value
     * @return array<int, array{entity: string, synonyms: array<int, string>}>
     */
    private function normaliseSynonyms($value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $pairs = [];
        foreach ($value as $pair) {
            if (!is_array($pair)) {
                continue;
            }

            $entity = (string) ($pair['entity'] ?? '');
            $synonyms = [];

            if (isset($pair['synonyms']) && is_array($pair['synonyms'])) {
                foreach ($pair['synonyms'] as $synonym) {
                    if (!is_string($synonym) || $synonym === '') {
                        continue;
                    }
                    $synonyms[] = $synonym;
                }
            }

            $pairs[] = [
                'entity' => $entity,
                'synonyms' => $synonyms,
            ];
        }

        return $pairs;
    }

    /**
     * @param mixed $value
     * @return array<string, int>
     */
    private function normaliseHistogram($value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $histogram = [];
        foreach ($value as $key => $count) {
            if (!is_string($key)) {
                continue;
            }
            $histogram[$key] = (int) (is_numeric($count) ? $count : 0);
        }

        return $histogram;
    }

    /**
     * @param mixed $value
     * @return array<string, array<string, mixed>>
     */
    private function normaliseCrossReferences($value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $references = [];
        foreach ($value as $entity => $payload) {
            if (!is_string($entity) || !is_array($payload)) {
                continue;
            }

            $references[$entity] = $payload;
        }

        return $references;
    }

    /**
     * @param array<string, mixed> $signals
     * @return array<string, float>
     */
    private function normaliseSignals(array $signals): array
    {
        $normalised = [];
        foreach ($signals as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            $normalised[$key] = $this->floatValue($value);
        }

        return $normalised;
    }

    /**
     * @param array<string, mixed> $support
     * @return array<string, int>
     */
    private function normaliseSupport(array $support): array
    {
        $normalised = [];
        foreach ($support as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            $normalised[$key] = (int) (is_numeric($value) ? $value : 0);
        }

        return $normalised;
    }

    /**
     * @param mixed $value
     */
    private function floatValue($value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        return 0.0;
    }

    private function normaliseName(string $value): string
    {
        if (isset($this->normalisedNameCache[$value])) {
            return $this->normalisedNameCache[$value];
        }

        $normalised = strtolower(trim($value));
        if ($normalised === '') {
            $this->normalisedNameCache[$value] = '';

            return '';
        }

        $normalised = preg_replace('/[^a-z0-9\s-]+/u', '', $normalised);
        if (!is_string($normalised)) {
            $this->normalisedNameCache[$value] = '';

            return '';
        }

        $normalised = preg_replace('/\s+/u', ' ', $normalised);
        if (!is_string($normalised)) {
            $this->normalisedNameCache[$value] = '';

            return '';
        }

        $normalised = trim($normalised);
        $this->normalisedNameCache[$value] = $normalised;

        return $normalised;
    }
}

