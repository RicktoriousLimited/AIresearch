<?php

declare(strict_types=1);

namespace App\Intelligence;

use DateTimeImmutable;
use Throwable;

use function array_filter;
use function array_key_exists;
use function array_slice;
use function array_sum;
use function array_unique;
use function array_values;
use function count;
use function is_array;
use function is_numeric;
use function is_string;
use function max;
use function min;
use function parse_url;
use function sort;
use function strtolower;
use function trim;

use const PHP_URL_HOST;

final class FeatureExtractor
{
    /**
     * @param array<string, mixed> $searchPayload
     * @param array<string, mixed> $graphPayload
     * @param array<string, mixed> $context
     *
     * @return array<string, float>
     */
    public function buildFeatureVector(array $searchPayload, array $graphPayload, array $context = []): array
    {
        $results = $this->normaliseResults($searchPayload['results'] ?? []);
        $entities = $this->normaliseEntities($graphPayload['entities'] ?? []);
        $relations = $this->normaliseRelations($graphPayload['relations'] ?? []);
        $triples = $this->normaliseTriples($graphPayload['triples'] ?? []);

        $now = $context['now'] instanceof DateTimeImmutable ? $context['now'] : new DateTimeImmutable();

        $quality = $this->computeAverageQuality($results);
        $freshness = $this->computeFreshnessScore($results, $now);
        $graphDensity = $this->computeGraphDensity($entities, $relations, $triples);
        $entityFocus = $this->computeEntityFocus($entities);
        $sourceDiversity = $this->computeSourceDiversity($results);
        $semanticAlignment = $this->computeSemanticAlignment($results);
        $volume = $this->computeVolumeScore($results);
        $graphSupport = $this->computeGraphSupport($entities, $relations, $triples);

        return [
            'avg_quality' => $quality,
            'freshness' => $freshness,
            'graph_density' => $graphDensity,
            'entity_focus' => $entityFocus,
            'source_diversity' => $sourceDiversity,
            'semantic_alignment' => $semanticAlignment,
            'volume' => $volume,
            'graph_support' => $graphSupport,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $results
     * @return array<int, array<string, mixed>>
     */
    public function summariseResults(array $results, int $limit = 5): array
    {
        $normalised = $this->normaliseResults($results);
        $summaries = [];
        foreach (array_slice($normalised, 0, max(0, $limit)) as $result) {
            $summaries[] = [
                'title' => (string) ($result['title'] ?? ''),
                'url' => (string) ($result['url'] ?? ''),
                'source' => (string) ($result['source'] ?? ''),
                'fetched_at' => (string) ($result['fetched_at'] ?? ''),
                'quality_score' => isset($result['quality_score']) ? (float) $result['quality_score'] : null,
                'semantic_score' => isset($result['semantic_score']) ? (float) $result['semantic_score'] : null,
                'summary' => (string) ($result['summary'] ?? ''),
            ];
        }

        return $summaries;
    }

    /**
     * @param array<int, array<string, mixed>> $entities
     * @return array<int, array<string, mixed>>
     */
    public function summariseEntities(array $entities, int $limit = 5): array
    {
        $normalised = $this->normaliseEntities($entities);
        $summaries = [];

        foreach (array_slice($normalised, 0, max(0, $limit)) as $entity) {
            $summaries[] = [
                'entity' => (string) ($entity['entity'] ?? ''),
                'score' => isset($entity['score']) ? (float) $entity['score'] : 0.0,
                'fact_count' => isset($entity['fact_count']) ? (int) $entity['fact_count'] : 0,
                'synonyms' => isset($entity['synonyms']) && is_array($entity['synonyms'])
                    ? array_values(array_filter(
                        $entity['synonyms'],
                        static fn($value): bool => is_string($value) && trim($value) !== ''
                    ))
                    : [],
                'related_terms' => isset($entity['related_terms']) && is_array($entity['related_terms'])
                    ? array_values(array_filter(
                        $entity['related_terms'],
                        static fn($value): bool => is_string($value) && trim($value) !== ''
                    ))
                    : [],
            ];
        }

        return $summaries;
    }

    /**
     * @param array<int, array<string, mixed>> $relations
     * @return array<int, array<string, mixed>>
     */
    public function summariseRelations(array $relations, int $limit = 6): array
    {
        $normalised = $this->normaliseRelations($relations);
        $summaries = [];

        foreach (array_slice($normalised, 0, max(0, $limit)) as $relation) {
            $summaries[] = [
                'relation' => (string) ($relation['relation'] ?? ''),
                'score' => isset($relation['score']) ? (float) $relation['score'] : 0.0,
                'signals' => isset($relation['signals']) && is_array($relation['signals'])
                    ? $relation['signals']
                    : [],
            ];
        }

        return $summaries;
    }

    /**
     * @param array<int, array<string, mixed>> $synonyms
     * @return array<int, array<string, mixed>>
     */
    public function summariseSynonyms(array $synonyms, int $limit = 6): array
    {
        $summaries = [];
        foreach (array_slice($synonyms, 0, max(0, $limit)) as $synonym) {
            if (!is_array($synonym)) {
                continue;
            }

            $entity = isset($synonym['entity']) && is_string($synonym['entity']) ? $synonym['entity'] : '';
            $list = isset($synonym['synonyms']) && is_array($synonym['synonyms'])
                ? array_values(array_filter(
                    $synonym['synonyms'],
                    static fn($value): bool => is_string($value) && trim($value) !== ''
                ))
                : [];

            if ($entity === '' && $list === []) {
                continue;
            }

            $summaries[] = [
                'entity' => $entity,
                'synonyms' => $list,
                'score' => isset($synonym['score']) ? (float) $synonym['score'] : 0.0,
                'matched_synonym' => isset($synonym['matched_synonym']) && is_string($synonym['matched_synonym'])
                    ? $synonym['matched_synonym']
                    : null,
            ];
        }

        return $summaries;
    }

    /**
     * @param array<string, float> $features
     *
     * @return array<string, array{label: string, value: float, description: string}>
     */
    public function describeFeatures(array $features): array
    {
        $map = [
            'avg_quality' => [
                'label' => 'Source quality',
                'description' => 'Mean quality score across the top HiddenCrawler matches.',
            ],
            'freshness' => [
                'label' => 'Freshness',
                'description' => 'Recency of supporting documents relative to the last 48 hours.',
            ],
            'graph_density' => [
                'label' => 'Graph density',
                'description' => 'Availability of connected entities, relations and triples for this query.',
            ],
            'entity_focus' => [
                'label' => 'Entity focus',
                'description' => 'Depth of facts captured for the leading entities.',
            ],
            'source_diversity' => [
                'label' => 'Source diversity',
                'description' => 'Distribution of coverage across unique publishers.',
            ],
            'semantic_alignment' => [
                'label' => 'Semantic alignment',
                'description' => 'Semantic similarity between the query and enriched content.',
            ],
            'volume' => [
                'label' => 'Signal volume',
                'description' => 'Number of high-quality documents captured for this topic.',
            ],
            'graph_support' => [
                'label' => 'Graph support',
                'description' => 'Strength of supporting facts and relationships in the knowledge graph.',
            ],
        ];

        $descriptions = [];
        foreach ($map as $key => $meta) {
            $descriptions[$key] = [
                'label' => $meta['label'],
                'value' => $this->clamp(isset($features[$key]) ? (float) $features[$key] : 0.0),
                'description' => $meta['description'],
            ];
        }

        return $descriptions;
    }

    /**
     * @param array<string, float> $features
     *
     * @return array{strengths: array<int, string>, risks: array<int, string>}
     */
    public function summarise(array $features): array
    {
        $strengths = [];
        $risks = [];

        $quality = $features['avg_quality'] ?? 0.0;
        if ($quality >= 0.65) {
            $strengths[] = 'Top sources are already high quality.';
        } elseif ($quality <= 0.4) {
            $risks[] = 'Average source quality is weak; tighten publisher filters.';
        }

        $freshness = $features['freshness'] ?? 0.0;
        if ($freshness >= 0.6) {
            $strengths[] = 'Coverage is fresh with recent fetches.';
        } elseif ($freshness <= 0.35) {
            $risks[] = 'Signals are ageing out; schedule another crawl.';
        }

        $graphDensity = $features['graph_density'] ?? 0.0;
        if ($graphDensity >= 0.55) {
            $strengths[] = 'The knowledge graph contains rich supporting context.';
        } elseif ($graphDensity <= 0.35) {
            $risks[] = 'Graph coverage is thin; run enrichment to add more relations.';
        }

        $entityFocus = $features['entity_focus'] ?? 0.0;
        if ($entityFocus >= 0.55) {
            $strengths[] = 'Key entities include deep fact coverage.';
        } elseif ($entityFocus <= 0.3) {
            $risks[] = 'Entities lack depth; capture more sources or annotations.';
        }

        $diversity = $features['source_diversity'] ?? 0.0;
        if ($diversity >= 0.5) {
            $strengths[] = 'Signals originate from a diverse publisher mix.';
        } elseif ($diversity <= 0.3) {
            $risks[] = 'Coverage is concentrated in a handful of domains.';
        }

        $semantic = $features['semantic_alignment'] ?? 0.0;
        if ($semantic >= 0.6) {
            $strengths[] = 'Semantic enrichment aligns tightly with the query intent.';
        } elseif ($semantic <= 0.35) {
            $risks[] = 'Semantic alignment is low; refine the query or synonyms.';
        }

        $volume = $features['volume'] ?? 0.0;
        if ($volume >= 0.55) {
            $strengths[] = 'There is ample document volume to build a briefing.';
        } elseif ($volume <= 0.3) {
            $risks[] = 'Limited document volume may leave blind spots.';
        }

        $support = $features['graph_support'] ?? 0.0;
        if ($support >= 0.55) {
            $strengths[] = 'Relationships in the graph reinforce the storyline.';
        } elseif ($support <= 0.3) {
            $risks[] = 'Graph relationships need enrichment to support this query.';
        }

        return [
            'strengths' => array_values(array_unique($strengths)),
            'risks' => array_values(array_unique($risks)),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $results
     * @return array<int, string>
     */
    public function extractTopSources(array $results, int $limit = 5): array
    {
        $normalised = $this->normaliseResults($results);
        $domains = [];
        foreach ($normalised as $result) {
            $source = (string) ($result['source'] ?? '');
            if ($source === '') {
                continue;
            }
            $domains[] = $source;
        }

        $domains = array_values(array_unique($domains));
        sort($domains);

        return array_slice($domains, 0, max(0, $limit));
    }

    /**
     * @param array<int, array<string, mixed>> $entities
     * @return array<int, string>
     */
    public function extractEntityNames(array $entities, int $limit = 4): array
    {
        $normalised = $this->normaliseEntities($entities);
        $names = [];
        foreach ($normalised as $entity) {
            $name = trim((string) ($entity['entity'] ?? ''));
            if ($name === '') {
                continue;
            }
            $names[] = $name;
        }

        $names = array_values(array_unique($names));

        return array_slice($names, 0, max(0, $limit));
    }

    /**
     * @param array<int, array<string, mixed>> $results
     *
     * @return array<int, array<string, mixed>>
     */
    private function normaliseResults($results): array
    {
        if (!is_array($results)) {
            return [];
        }

        $normalised = [];
        foreach ($results as $result) {
            if (!is_array($result)) {
                continue;
            }

            $title = isset($result['title']) && is_string($result['title']) ? $result['title'] : '';
            $url = isset($result['url']) && is_string($result['url']) ? $result['url'] : '';
            $summary = isset($result['summary']) && is_string($result['summary']) ? $result['summary'] : '';
            $fetchedAt = $this->stringValue($result, ['fetched_at', 'source_published_at', 'last_checked_at']);
            $quality = $this->floatValue($result, 'quality_score');
            $semantic = $this->floatValue($result, 'semantic_score', $this->floatValue($result, 'match_score'));
            $source = $this->stringValue($result, ['source_site_name', 'source_domain']);

            if ($source === '' && $url !== '') {
                $domain = $this->extractDomain($url);
                $source = $domain ?? '';
            }

            $normalised[] = [
                'title' => $title,
                'url' => $url,
                'summary' => $summary,
                'fetched_at' => $fetchedAt,
                'quality_score' => $quality,
                'semantic_score' => $semantic,
                'source' => $source,
            ];
        }

        return $normalised;
    }

    /**
     * @param array<int, array<string, mixed>> $entities
     * @return array<int, array<string, mixed>>
     */
    private function normaliseEntities($entities): array
    {
        if (!is_array($entities)) {
            return [];
        }

        $normalised = [];
        foreach ($entities as $entity) {
            if (!is_array($entity)) {
                continue;
            }

            $name = isset($entity['entity']) && is_string($entity['entity']) ? trim($entity['entity']) : '';
            if ($name === '' && isset($entity['summary']['entity']) && is_string($entity['summary']['entity'])) {
                $name = trim($entity['summary']['entity']);
            }

            $factCount = 0;
            if (isset($entity['fact_count'])) {
                $factCount = (int) $entity['fact_count'];
            } elseif (isset($entity['summary']['fact_count'])) {
                $factCount = (int) $entity['summary']['fact_count'];
            }

            $synonyms = [];
            if (isset($entity['summary']['synonyms']) && is_array($entity['summary']['synonyms'])) {
                $synonyms = array_values(array_filter(
                    $entity['summary']['synonyms'],
                    static fn($value): bool => is_string($value) && trim($value) !== ''
                ));
            } elseif (isset($entity['synonyms']) && is_array($entity['synonyms'])) {
                $synonyms = array_values(array_filter(
                    $entity['synonyms'],
                    static fn($value): bool => is_string($value) && trim($value) !== ''
                ));
            }

            $related = [];
            if (isset($entity['summary']['related_terms']) && is_array($entity['summary']['related_terms'])) {
                $related = array_values(array_filter(
                    $entity['summary']['related_terms'],
                    static fn($value): bool => is_string($value) && trim($value) !== ''
                ));
            } elseif (isset($entity['related_terms']) && is_array($entity['related_terms'])) {
                $related = array_values(array_filter(
                    $entity['related_terms'],
                    static fn($value): bool => is_string($value) && trim($value) !== ''
                ));
            }

            $score = isset($entity['score']) && is_numeric($entity['score']) ? (float) $entity['score'] : 0.0;

            $normalised[] = [
                'entity' => $name,
                'score' => $score,
                'fact_count' => $factCount,
                'synonyms' => $synonyms,
                'related_terms' => $related,
            ];
        }

        return $normalised;
    }

    /**
     * @param array<int, array<string, mixed>> $relations
     * @return array<int, array<string, mixed>>
     */
    private function normaliseRelations($relations): array
    {
        if (!is_array($relations)) {
            return [];
        }

        $normalised = [];
        foreach ($relations as $relation) {
            if (!is_array($relation)) {
                continue;
            }

            $label = isset($relation['relation']) && is_string($relation['relation'])
                ? trim($relation['relation'])
                : (isset($relation['value']) && is_string($relation['value']) ? trim($relation['value']) : '');

            if ($label === '') {
                continue;
            }

            $normalised[] = [
                'relation' => $label,
                'score' => isset($relation['score']) && is_numeric($relation['score']) ? (float) $relation['score'] : 0.0,
                'signals' => isset($relation['signals']) && is_array($relation['signals']) ? $relation['signals'] : [],
            ];
        }

        return $normalised;
    }

    /**
     * @param array<int, array<string, mixed>> $triples
     * @return array<int, array<string, string>>
     */
    private function normaliseTriples($triples): array
    {
        if (!is_array($triples)) {
            return [];
        }

        $normalised = [];
        foreach ($triples as $triple) {
            if (!is_array($triple)) {
                continue;
            }

            $subject = isset($triple['subject']) && is_string($triple['subject']) ? trim($triple['subject']) : '';
            $relation = isset($triple['relation']) && is_string($triple['relation']) ? trim($triple['relation']) : '';
            $object = isset($triple['object']) && is_string($triple['object']) ? trim($triple['object']) : '';

            if ($subject === '' || $relation === '' || $object === '') {
                continue;
            }

            $normalised[] = [
                'subject' => $subject,
                'relation' => $relation,
                'object' => $object,
            ];
        }

        return $normalised;
    }

    /**
     * @param array<int, array<string, mixed>> $results
     */
    private function computeAverageQuality(array $results): float
    {
        if ($results === []) {
            return 0.0;
        }

        $scores = [];
        foreach ($results as $result) {
            $value = $result['quality_score'] ?? null;
            if (!is_numeric($value)) {
                continue;
            }

            $scores[] = $this->clamp((float) $value, 0.0, 1.0);
        }

        if ($scores === []) {
            return 0.0;
        }

        return $this->clamp(array_sum($scores) / count($scores));
    }

    /**
     * @param array<int, array<string, mixed>> $results
     */
    private function computeFreshnessScore(array $results, DateTimeImmutable $now): float
    {
        if ($results === []) {
            return 0.0;
        }

        $scores = [];
        foreach ($results as $result) {
            $timestamp = isset($result['fetched_at']) && is_string($result['fetched_at'])
                ? $this->parseDate($result['fetched_at'])
                : null;

            if ($timestamp === null) {
                continue;
            }

            $diffSeconds = max(0, $now->getTimestamp() - $timestamp->getTimestamp());
            $diffHours = $diffSeconds / 3600.0;
            $score = $this->clamp((float) exp(-$diffHours / 48.0));
            $scores[] = $score;
        }

        if ($scores === []) {
            return 0.0;
        }

        return $this->clamp(array_sum($scores) / count($scores));
    }

    /**
     * @param array<int, array<string, mixed>> $entities
     * @param array<int, array<string, mixed>> $relations
     * @param array<int, array<string, string>> $triples
     */
    private function computeGraphDensity(array $entities, array $relations, array $triples): float
    {
        $entityScore = $entities === [] ? 0.0 : min(1.0, count($entities) / 8.0);
        $relationScore = $relations === [] ? 0.0 : min(1.0, count($relations) / 12.0);
        $tripleScore = $triples === [] ? 0.0 : min(1.0, count($triples) / 40.0);

        return $this->clamp(($entityScore * 0.45) + ($relationScore * 0.3) + ($tripleScore * 0.25));
    }

    /**
     * @param array<int, array<string, mixed>> $entities
     */
    private function computeEntityFocus(array $entities): float
    {
        if ($entities === []) {
            return 0.0;
        }

        $counts = [];
        foreach ($entities as $entity) {
            $counts[] = isset($entity['fact_count']) ? (int) $entity['fact_count'] : 0;
        }

        rsort($counts);
        $top = array_slice($counts, 0, 3);
        if ($top === []) {
            return 0.0;
        }

        $average = array_sum($top) / count($top);

        return $this->clamp($average / 12.0);
    }

    /**
     * @param array<int, array<string, mixed>> $results
     */
    private function computeSourceDiversity(array $results): float
    {
        if ($results === []) {
            return 0.0;
        }

        $domains = [];
        foreach ($results as $result) {
            $source = isset($result['source']) ? (string) $result['source'] : '';
            if ($source === '') {
                continue;
            }
            $domains[] = strtolower($source);
        }

        if ($domains === []) {
            return 0.0;
        }

        $unique = array_values(array_unique($domains));
        $ratio = count($unique) / max(1, count($results));

        return $this->clamp($ratio * 1.4);
    }

    /**
     * @param array<int, array<string, mixed>> $results
     */
    private function computeSemanticAlignment(array $results): float
    {
        if ($results === []) {
            return 0.0;
        }

        $scores = [];
        foreach ($results as $result) {
            $value = $result['semantic_score'] ?? null;
            if (!is_numeric($value)) {
                continue;
            }

            $scores[] = $this->clamp((float) $value, 0.0, 1.0);
        }

        if ($scores === []) {
            return 0.0;
        }

        return $this->clamp(array_sum($scores) / count($scores));
    }

    /**
     * @param array<int, array<string, mixed>> $results
     */
    private function computeVolumeScore(array $results): float
    {
        if ($results === []) {
            return 0.0;
        }

        return $this->clamp(count($results) / 20.0);
    }

    /**
     * @param array<int, array<string, mixed>> $entities
     * @param array<int, array<string, mixed>> $relations
     * @param array<int, array<string, string>> $triples
     */
    private function computeGraphSupport(array $entities, array $relations, array $triples): float
    {
        $factCounts = [];
        foreach ($entities as $entity) {
            $factCounts[] = isset($entity['fact_count']) ? (int) $entity['fact_count'] : 0;
        }

        $avgFacts = $factCounts === [] ? 0.0 : array_sum($factCounts) / count($factCounts);
        $relationScore = $relations === [] ? 0.0 : min(1.0, count($relations) / 10.0);
        $tripleScore = $triples === [] ? 0.0 : min(1.0, count($triples) / 25.0);

        $factScore = $this->clamp($avgFacts / 10.0);

        return $this->clamp(($factScore * 0.4) + ($relationScore * 0.35) + ($tripleScore * 0.25));
    }

    private function extractDomain(string $url): ?string
    {
        if ($url === '') {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return null;
        }

        return strtolower($host);
    }

    private function parseDate(string $value): ?DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable $exception) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, string>|string $keys
     */
    private function stringValue(array $payload, $keys): string
    {
        $list = is_array($keys) ? $keys : [$keys];
        foreach ($list as $key) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }
            $value = $payload[$key];
            if (!is_string($value)) {
                continue;
            }

            $trimmed = trim($value);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, string>|string $keys
     */
    private function floatValue(array $payload, $keys, ?float $default = null): ?float
    {
        $list = is_array($keys) ? $keys : [$keys];
        foreach ($list as $key) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }
            $value = $payload[$key];
            if (is_numeric($value)) {
                return (float) $value;
            }
        }

        return $default;
    }

    private function clamp(float $value, float $min = 0.0, float $max = 1.0): float
    {
        if ($value < $min) {
            return $min;
        }
        if ($value > $max) {
            return $max;
        }

        return $value;
    }
}
