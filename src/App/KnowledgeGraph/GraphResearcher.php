<?php

declare(strict_types=1);

namespace App\KnowledgeGraph;

use function array_slice;
use function array_values;
use function in_array;
use function is_array;
use function is_numeric;
use function is_string;
use function preg_replace;
use function str_contains;
use function strtolower;
use function trim;
use function usort;

/**
 * Utility wrapper around the shared knowledge graph snapshot that exposes
 * research-friendly summaries and lookups.
 */
final class GraphResearcher
{
    private GraphRepository $repository;

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
        $payload = $this->repository->load();
        $graph = $payload['graph'];

        if (!is_array($graph)) {
            return [
                'triples' => [],
                'synonyms' => [],
                'relations' => [],
                'entities' => [],
                'summary' => [],
                'cross_references' => [],
            ];
        }

        return [
            'triples' => $this->normaliseTriples($graph['triples'] ?? []),
            'synonyms' => $this->normaliseSynonyms($graph['synonyms'] ?? []),
            'relations' => $this->normaliseHistogram($graph['relations'] ?? []),
            'entities' => $this->normaliseHistogram($graph['entities'] ?? []),
            'summary' => is_array($graph['summary'] ?? null) ? $graph['summary'] : [],
            'cross_references' => $this->normaliseCrossReferences($graph['cross_references'] ?? []),
        ];
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

