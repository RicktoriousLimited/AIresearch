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
use function str_starts_with;
use function soundex;
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

    /**
     * @var array<string, mixed>|null
     */
    private ?array $snapshotCache = null;

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

        $result = [
            'query' => $query,
            'entities' => [],
            'relations' => [],
            'synonyms' => [],
            'triples' => [],
            'summary' => $snapshot['summary'],
            'sources' => $this->sanitiseSources($metadata['sources']),
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
            $summary = $this->summariseEntity($entityMatch['entity'], 6);
            $entityMatch['summary'] = $summary;
            $entityMatch['eligible'] = $summary['eligible'] ?? false;
            $entityMatch['fact_count'] = $summary['fact_count'] ?? 0;
            $entityMatch['synonyms'] = $summary['synonyms'] ?? [];
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

        $index = $this->buildEntityIndex($references);

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

        return [
            'entity' => $match,
            'score' => $this->floatValue($ranking['score'] ?? 0.0),
            'eligible' => (bool) ($ranking['eligible'] ?? false),
            'synonyms' => $synonyms,
            'signals' => $this->normaliseSignals($signals),
            'support' => $this->normaliseSupport($support),
            'facts' => $factLimit > 0 ? array_slice($facts, 0, $factLimit) : $facts,
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
     * @return array<int, array<string, mixed>>
     */
    private function buildDefaultEntitySummaries(int $limit): array
    {
        $rows = [];

        foreach ($this->listTopEntities($limit) as $entityRow) {
            $summary = $this->summariseEntity($entityRow['entity'], 6);

            $rows[] = [
                'entity' => $entityRow['entity'],
                'score' => $entityRow['score'],
                'eligible' => $entityRow['eligible'],
                'fact_count' => $entityRow['fact_count'],
                'synonym_count' => $entityRow['synonym_count'],
                'synonyms' => $summary['synonyms'] ?? [],
                'summary' => $summary,
                'signals' => ['ranking' => $entityRow['score']],
            ];
        }

        return $rows;
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

        $normalNeedle = $this->normaliseName($query);
        $normalCandidate = $this->normaliseName($candidate);

        if ($normalNeedle === '' || $normalCandidate === '') {
            return ['score' => 0.0, 'signals' => []];
        }

        $lexical = $this->lexicalSimilarity($normalNeedle, $normalCandidate);
        $overlap = $this->tokenOverlapScore($normalNeedle, $normalCandidate);
        $phonetic = $this->phoneticSimilarity($query, $candidate);
        $affinity = $this->affinityScore($normalNeedle, $normalCandidate);

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
        $value = trim($value);
        if ($value === '') {
            return [];
        }

        $parts = preg_split('/[\s-]+/u', $value);
        if (!is_array($parts)) {
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

        return array_values(array_unique($tokens));
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
        $value = strtolower(trim($value));
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/[^a-z0-9\s-]+/u', '', $value);
        if (!is_string($value)) {
            return '';
        }

        $value = preg_replace('/\s+/u', ' ', $value);
        if (!is_string($value)) {
            return '';
        }

        return trim($value);
    }
}

