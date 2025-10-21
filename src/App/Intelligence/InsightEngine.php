<?php

declare(strict_types=1);

namespace App\Intelligence;

use App\Crawler\HiddenCrawler;
use App\KnowledgeGraph\GraphRepository;
use App\KnowledgeGraph\GraphResearcher;
use App\News\NewsSearchService;
use App\Text\TextRefiner;
use DateTimeImmutable;
use function array_filter;
use function array_slice;
use function array_unique;
use function array_values;
use function count;
use function is_array;
use function is_string;
use function max;
use function min;
use function trim;
use function dirname;
use function strtolower;

use const SORT_REGULAR;
final class InsightEngine
{
    private HiddenCrawler $crawler;

    private GraphResearcher $graphResearcher;

    private NewsSearchService $newsSearch;

    private FeatureExtractor $featureExtractor;

    private LogisticModel $model;

    private string $modelPath;

    public function __construct(
        ?HiddenCrawler $crawler = null,
        ?GraphResearcher $graphResearcher = null,
        ?NewsSearchService $newsSearch = null,
        ?FeatureExtractor $featureExtractor = null,
        ?LogisticModel $model = null,
        ?string $modelPath = null
    ) {
        $root = dirname(__DIR__, 3);
        $crawlerPath = $root . '/storage/backend/crawler-history.json';
        $graphRepository = new GraphRepository();

        $this->crawler = $crawler ?? new HiddenCrawler($crawlerPath);
        $this->graphResearcher = $graphResearcher ?? new GraphResearcher($graphRepository);
        $this->newsSearch = $newsSearch ?? new NewsSearchService(
            $this->crawler,
            $graphRepository,
            new TextRefiner()
        );
        $this->featureExtractor = $featureExtractor ?? new FeatureExtractor();
        $this->modelPath = $modelPath ?? $root . '/storage/models/intelligence-model.json';
        $this->model = $model ?? LogisticModel::loadOrDefault($this->modelPath);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function generate(string $query, array $options = []): array
    {
        $query = trim($query);
        $limit = isset($options['limit']) ? (int) $options['limit'] : 12;
        $limit = $limit <= 0 ? 12 : min(24, max(6, $limit));

        $searchPayload = $this->newsSearch->search($query, [
            'limit' => $limit,
        ]);

        $graphPayload = $this->graphResearcher->searchGraph($query, $limit);

        $featureVector = $this->featureExtractor->buildFeatureVector($searchPayload, $graphPayload, [
            'query' => $query,
        ]);
        $score = $this->model->predict($featureVector);
        $contributions = $this->model->contributions($featureVector);

        $results = $this->featureExtractor->summariseResults($searchPayload['results'] ?? [], 5);
        $entities = $this->featureExtractor->summariseEntities($graphPayload['entities'] ?? [], 5);
        $relations = $this->featureExtractor->summariseRelations($graphPayload['relations'] ?? [], 6);
        $synonyms = $this->featureExtractor->summariseSynonyms($graphPayload['synonyms'] ?? [], 6);

        $featureSummary = $this->featureExtractor->summarise($featureVector);
        $recommendations = $this->buildRecommendations($query, $featureVector, $featureSummary, $searchPayload, $graphPayload);

        return [
            'query' => $query,
            'generated_at' => (new DateTimeImmutable())->format(DateTimeImmutable::ATOM),
            'score' => [
                'cohesion' => $score,
                'label' => $this->labelScore($score),
                'feature_breakdown' => $this->featureExtractor->describeFeatures($featureVector),
                'contributions' => $contributions,
            ],
            'features' => $featureVector,
            'feature_summary' => $featureSummary,
            'search' => [
                'total' => is_array($searchPayload['results'] ?? null) ? count($searchPayload['results']) : 0,
                'top_results' => $results,
            ],
            'graph' => [
                'entities' => $entities,
                'relations' => $relations,
                'synonyms' => $synonyms,
                'summary' => isset($graphPayload['summary']) && is_array($graphPayload['summary']) ? $graphPayload['summary'] : [],
                'updated_at' => isset($graphPayload['updated_at']) && is_string($graphPayload['updated_at'])
                    ? $graphPayload['updated_at']
                    : null,
            ],
            'recommendations' => $recommendations,
            'meta' => [
                'model_version' => $this->model->version(),
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $examples
     *
     * @return array<string, mixed>
     */
    public function train(array $examples, int $iterations = 120, float $learningRate = 0.15): array
    {
        if ($examples === []) {
            return [
                'updated' => 0,
                'model_version' => $this->model->version(),
            ];
        }

        $samples = [];
        foreach ($examples as $example) {
            if (!is_array($example)) {
                continue;
            }

            $label = isset($example['label']) ? (float) $example['label'] : null;
            if ($label === null) {
                continue;
            }

            if (isset($example['features']) && is_array($example['features'])) {
                $features = $this->filterFeatures($example['features']);
            } elseif (isset($example['query']) && is_string($example['query'])) {
                $snapshot = $this->generate($example['query'], [
                    'limit' => isset($example['limit']) ? (int) $example['limit'] : 12,
                ]);
                $features = $snapshot['features'];
            } else {
                continue;
            }

            $samples[] = [
                'features' => $features,
                'label' => $label,
            ];
        }

        if ($samples === []) {
            return [
                'updated' => 0,
                'model_version' => $this->model->version(),
            ];
        }

        $this->model->train($samples, $iterations, $learningRate);
        $this->model->save($this->modelPath);

        return [
            'updated' => count($samples),
            'model_version' => $this->model->version(),
            'weights' => $this->model->weights(),
            'bias' => $this->model->bias(),
        ];
    }

    /**
     * @param array<int, string> $queries
     *
     * @return array<string, mixed>
     */
    public function overview(array $queries, int $limit = 3): array
    {
        $snapshots = [];
        $queries = array_values(array_filter(
            $queries,
            static fn($value): bool => is_string($value) && trim($value) !== ''
        ));

        foreach ($queries as $query) {
            $snapshot = $this->generate($query, [
                'limit' => 12,
            ]);

            $entityNames = $this->featureExtractor->extractEntityNames($snapshot['graph']['entities'] ?? [], 3);
            $sources = $this->featureExtractor->extractTopSources($snapshot['search']['top_results'] ?? [], 4);

            $snapshots[] = [
                'query' => $snapshot['query'],
                'score' => $snapshot['score']['cohesion'],
                'label' => $snapshot['score']['label'],
                'highlights' => $this->homeHighlights($snapshot),
                'entities' => $entityNames,
                'sources' => $sources,
                'next_actions' => $snapshot['recommendations']['actions'] ?? [],
            ];

            if (count($snapshots) >= $limit) {
                break;
            }
        }

        return [
            'snapshots' => $snapshots,
            'model_version' => $this->model->version(),
            'generated_at' => (new DateTimeImmutable())->format(DateTimeImmutable::ATOM),
        ];
    }

    /**
     * @param array<string, float> $featureVector
     * @param array{strengths: array<int, string>, risks: array<int, string>} $summary
     * @param array<string, mixed> $searchPayload
     * @param array<string, mixed> $graphPayload
     *
     * @return array<string, mixed>
     */
    private function buildRecommendations(
        string $query,
        array $featureVector,
        array $summary,
        array $searchPayload,
        array $graphPayload
    ): array {
        $actions = [];

        if (($featureVector['freshness'] ?? 0.0) < 0.35) {
            $actions[] = [
                'label' => 'Schedule a new crawl',
                'reason' => 'Freshness is low; initiate another HiddenCrawler sweep.',
                'type' => 'ingestion',
            ];
        }

        if (($featureVector['avg_quality'] ?? 0.0) < 0.45) {
            $actions[] = [
                'label' => 'Review source filters',
                'reason' => 'Average quality score falls below the recommended threshold.',
                'type' => 'quality',
            ];
        }

        if (($featureVector['graph_density'] ?? 0.0) < 0.4) {
            $actions[] = [
                'label' => 'Enrich knowledge graph',
                'reason' => 'Graph density is light; run entity extraction on new material.',
                'type' => 'graph',
            ];
        } elseif (($featureVector['graph_density'] ?? 0.0) > 0.65) {
            $actions[] = [
                'label' => 'Add graph narrative to briefing',
                'reason' => 'Dense graph coverage enables deeper storytelling in reports.',
                'type' => 'briefing',
            ];
        }

        if (($featureVector['source_diversity'] ?? 0.0) < 0.35) {
            $actions[] = [
                'label' => 'Expand discovery list',
                'reason' => 'Coverage relies on a narrow set of domains; consider new seeds.',
                'type' => 'discovery',
            ];
        }

        if (($featureVector['semantic_alignment'] ?? 0.0) < 0.4 && $query !== '') {
            $actions[] = [
                'label' => 'Refine query language',
                'reason' => 'Semantic alignment is weak; adjust the prompt or add synonyms.',
                'type' => 'query',
            ];
        }

        $actions = array_values(array_unique($actions, SORT_REGULAR));

        $nextQueries = $this->deriveNextQueries($query, $graphPayload, $searchPayload);

        return [
            'actions' => $actions,
            'next_queries' => $nextQueries,
            'key_takeaways' => $this->buildTakeaways($summary, $featureVector, $graphPayload, $searchPayload),
        ];
    }

    /**
     * @param array<string, mixed> $graphPayload
     * @param array<string, mixed> $searchPayload
     *
     * @return array<int, string>
     */
    private function deriveNextQueries(string $query, array $graphPayload, array $searchPayload): array
    {
        $suggestions = [];
        $queryLower = strtolower($query);

        $entities = $graphPayload['entities'] ?? [];
        if (is_array($entities)) {
            foreach (array_slice($entities, 0, 4) as $entity) {
                if (!is_array($entity)) {
                    continue;
                }

                $name = isset($entity['entity']) && is_string($entity['entity']) ? trim($entity['entity']) : '';
                if ($name !== '' && strtolower($name) !== $queryLower) {
                    $suggestions[] = $name;
                }

                $related = $entity['related_terms'] ?? ($entity['summary']['related_terms'] ?? []);
                if (is_array($related)) {
                    foreach (array_slice($related, 0, 2) as $term) {
                        if (!is_string($term)) {
                            continue;
                        }
                        $candidate = trim($term);
                        if ($candidate === '' || strtolower($candidate) === $queryLower) {
                            continue;
                        }
                        $suggestions[] = $candidate;
                    }
                }
            }
        }

        $synonyms = $graphPayload['synonyms'] ?? [];
        if (is_array($synonyms)) {
            foreach (array_slice($synonyms, 0, 4) as $synonym) {
                if (!is_array($synonym)) {
                    continue;
                }
                $matched = isset($synonym['matched_synonym']) && is_string($synonym['matched_synonym'])
                    ? trim($synonym['matched_synonym'])
                    : '';
                if ($matched !== '' && strtolower($matched) !== $queryLower) {
                    $suggestions[] = $matched;
                }
                $list = $synonym['synonyms'] ?? [];
                if (!is_array($list)) {
                    continue;
                }
                foreach (array_slice($list, 0, 2) as $term) {
                    if (!is_string($term)) {
                        continue;
                    }
                    $candidate = trim($term);
                    if ($candidate === '' || strtolower($candidate) === $queryLower) {
                        continue;
                    }
                    $suggestions[] = $candidate;
                }
            }
        }

        $results = $searchPayload['results'] ?? [];
        if (is_array($results)) {
            foreach (array_slice($results, 0, 6) as $result) {
                if (!is_array($result) || !isset($result['topics'])) {
                    continue;
                }
                $topics = $result['topics'];
                if (!is_array($topics)) {
                    continue;
                }
                foreach (array_slice($topics, 0, 2) as $topic) {
                    if (!is_string($topic)) {
                        continue;
                    }
                    $candidate = trim($topic);
                    if ($candidate === '' || strtolower($candidate) === $queryLower) {
                        continue;
                    }
                    $suggestions[] = $candidate;
                }
            }
        }

        $suggestions = array_values(array_unique($suggestions));

        return array_slice($suggestions, 0, 6);
    }

    /**
     * @param array{strengths: array<int, string>, risks: array<int, string>} $summary
     * @param array<string, float> $features
     * @param array<string, mixed> $graphPayload
     * @param array<string, mixed> $searchPayload
     *
     * @return array<int, string>
     */
    private function buildTakeaways(
        array $summary,
        array $features,
        array $graphPayload,
        array $searchPayload
    ): array {
        $takeaways = [];

        $dominant = array_slice($summary['strengths'], 0, 2);
        foreach ($dominant as $line) {
            $takeaways[] = $line;
        }

        if ($takeaways === []) {
            $takeaways[] = 'Pipeline is ready for enrichment but needs new signals.';
        }

        $risks = array_slice($summary['risks'], 0, 2);
        foreach ($risks as $line) {
            $takeaways[] = $line;
        }

        if (($features['volume'] ?? 0.0) < 0.35 && ($searchPayload['results'] ?? []) === []) {
            $takeaways[] = 'No indexed documents matched this request yet.';
        }

        if (($features['graph_density'] ?? 0.0) > 0.6 && is_array($graphPayload['entities'] ?? null)) {
            $takeaways[] = 'Graph entities provide enough coverage to draft a briefing.';
        }

        return array_values(array_unique($takeaways));
    }

    /**
     * @param array<string, mixed> $snapshot
     *
     * @return array<int, string>
     */
    private function homeHighlights(array $snapshot): array
    {
        $features = $snapshot['features'];
        $summary = $snapshot['feature_summary'];

        $highlights = [];

        if (($features['freshness'] ?? 0.0) >= 0.6) {
            $highlights[] = 'Latest crawl captured coverage in the past 48 hours.';
        } else {
            $highlights[] = 'Fresh crawl recommended to keep this stream current.';
        }

        if (($features['graph_density'] ?? 0.0) >= 0.55) {
            $highlights[] = 'Knowledge graph links multiple related entities for this topic.';
        } else {
            $highlights[] = 'Graph has limited context; consider running enrichment jobs.';
        }

        if (($features['avg_quality'] ?? 0.0) >= 0.65) {
            $highlights[] = 'High-quality publishers dominate the latest matches.';
        } else {
            $highlights[] = 'Quality checks flagged this stream for manual review.';
        }

        if (($features['source_diversity'] ?? 0.0) >= 0.5) {
            $highlights[] = 'Diverse source mix ensures broad coverage.';
        }

        if (($features['semantic_alignment'] ?? 0.0) < 0.4) {
            $highlights[] = 'Semantic alignment is weak; adjust prompts or synonyms.';
        }

        if ($summary['risks'] !== []) {
            $highlights[] = $summary['risks'][0];
        }

        return array_values(array_unique($highlights));
    }

    /**
     * @param array<string, float> $features
     *
     * @return array<string, float>
     */
    private function filterFeatures(array $features): array
    {
        $allowed = $this->model->weights();
        $filtered = [];
        foreach ($allowed as $name => $_) {
            $filtered[$name] = isset($features[$name]) ? (float) $features[$name] : 0.0;
        }

        return $filtered;
    }

    private function labelScore(float $score): string
    {
        if ($score >= 0.75) {
            return 'Ready for briefing';
        }
        if ($score >= 0.55) {
            return 'Needs validation';
        }
        if ($score >= 0.35) {
            return 'Needs enrichment';
        }

        return 'Needs ingestion';
    }
}
