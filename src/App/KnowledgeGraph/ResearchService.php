<?php

declare(strict_types=1);

namespace App\KnowledgeGraph;

use App\Extraction\ExtractionResult;
use App\Extraction\ExtractorInterface;
use App\Scraping\ScraperInterface;
use App\Scraping\ScrapeResult;
use App\Scraping\WebScraper;
use App\Extraction\Extractor;
use DateInterval;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

use function array_filter;
use function array_map;
use function array_slice;
use function array_unique;
use function array_values;
use function count;
use function implode;
use function is_array;
use function is_numeric;
use function is_string;
use function max;
use function mb_strlen;
use function mb_substr;
use function preg_replace;
use function sprintf;
use function strtolower;
use function trim;
use function usort;

final class ResearchService
{
    private GraphRepository $repository;

    private ScraperInterface $scraper;

    private ExtractorInterface $extractor;

    private ?ReportBuilder $reportBuilder = null;

    private ?GraphResearcher $graphResearcher = null;

    public function __construct(
        ?GraphRepository $repository = null,
        ?ScraperInterface $scraper = null,
        ?ExtractorInterface $extractor = null
    ) {
        $this->repository = $repository ?? new GraphRepository();
        $this->scraper = $scraper ?? new WebScraper();
        $this->extractor = $extractor ?? new Extractor();
    }

    /**
     * Persist a new source in the shared knowledge graph by scraping and analysing the page.
     *
     * @return array{graph: array<string, mixed>, source: array<string, mixed>, sources: array<int, array<string, mixed>>}
     */
    public function ingestFromUrl(string $url): array
    {
        $scrapeResult = $this->scraper->scrape($url);

        return $this->ingestScrapeResult($scrapeResult);
    }

    /**
     * Persist a pre-scraped page into the knowledge graph.
     *
     * @return array{graph: array<string, mixed>, source: array<string, mixed>, sources: array<int, array<string, mixed>>}
     */
    public function ingestScrapeResult(ScrapeResult $scrapeResult): array
    {
        $payload = $this->repository->load();
        $state = null;
        if (isset($payload['graph']['state']) && is_array($payload['graph']['state'])) {
            $state = $payload['graph']['state'];
        }

        $result = $this->extractor->analyse($scrapeResult->text(), $state);

        $sources = is_array($payload['sources']) ? array_values($payload['sources']) : [];
        $now = new DateTimeImmutable();

        $sourceRecord = array_merge(
            $scrapeResult->toMetaArray(),
            [
                'content' => $scrapeResult->text(),
                'fetched_at' => $now->format(DATE_ATOM),
                'verified_at' => $now->format(DATE_ATOM),
                'status' => 'active',
            ]
        );

        if (!isset($sourceRecord['links']) || !is_array($sourceRecord['links'])) {
            $sourceRecord['links'] = [];
        }

        $sources = $this->repository->upsertSource($sources, $sourceRecord);
        $this->repository->save($result, $sources);

        return [
            'graph' => $result->toArray(),
            'source' => $sourceRecord,
            'sources' => $sources,
        ];
    }

    /**
     * Re-scrape existing sources and rebuild the knowledge graph, removing any pages that no longer resolve.
     *
     * @return array{
     *     summary: array{refreshed: int, removed: int, skipped: int, active: int},
     *     sources: array<int, array<string, mixed>>,
     *     removed_sources: array<int, array<string, string>>,
     *     graph: array<string, mixed>
     * }
     */
    public function refreshSources(int $maxAgeHours = 168): array
    {
        $payload = $this->repository->load();
        $sources = is_array($payload['sources']) ? array_values($payload['sources']) : [];

        if ($sources === []) {
            $emptyResult = $this->extractor->analyseMany([]);
            $this->repository->save($emptyResult, []);

            return [
                'summary' => ['refreshed' => 0, 'removed' => 0, 'skipped' => 0, 'active' => 0],
                'sources' => [],
                'removed_sources' => [],
                'graph' => $emptyResult->toArray(),
            ];
        }

        $now = new DateTimeImmutable();
        $maxAgeHours = max(0, $maxAgeHours);
        $threshold = $maxAgeHours === 0 ? null : $now->sub(new DateInterval('PT' . $maxAgeHours . 'H'));

        $activeSources = [];
        $documents = [];
        $removed = [];
        $refreshed = 0;
        $skipped = 0;

        foreach ($sources as $source) {
            if (!is_array($source)) {
                continue;
            }

            $url = (string) ($source['url'] ?? '');
            if ($url === '') {
                continue;
            }

            $needsRefresh = true;
            $storedContent = '';
            if (isset($source['content']) && is_string($source['content']) && $source['content'] !== '') {
                $storedContent = $source['content'];
                $needsRefresh = false;

                if ($threshold !== null) {
                    $fetchedAt = $this->parseDate($source['fetched_at'] ?? null);
                    if ($fetchedAt === null || $fetchedAt < $threshold) {
                        $needsRefresh = true;
                    }
                }
            }

            if ($maxAgeHours === 0) {
                $needsRefresh = true;
            }

            if ($needsRefresh) {
                try {
                    $scrape = $this->scraper->scrape($url);
                } catch (Throwable $exception) {
                    $removed[] = [
                        'url' => $url,
                        'reason' => $exception->getMessage(),
                    ];
                    continue;
                }

                $source = array_merge(
                    $source,
                    $scrape->toMetaArray(),
                    [
                        'content' => $scrape->text(),
                        'fetched_at' => $now->format(DATE_ATOM),
                    ]
                );
                $refreshed++;
                $storedContent = $scrape->text();
            } else {
                $skipped++;
            }

            if ($storedContent === '') {
                continue;
            }

            $source['content'] = $storedContent;
            $source['verified_at'] = $now->format(DATE_ATOM);
            $source['status'] = 'active';

            $documents[] = $storedContent;
            $activeSources[] = $source;
        }

        usort(
            $activeSources,
            static function (array $left, array $right): int {
                $leftTime = $left['fetched_at'] ?? '';
                $rightTime = $right['fetched_at'] ?? '';
                if (is_string($leftTime) && is_string($rightTime)) {
                    return strcmp($leftTime, $rightTime);
                }

                return 0;
            }
        );

        $documents = array_values(array_filter($documents, static fn(string $value): bool => trim($value) !== ''));

        $result = $this->rebuildGraph($documents);
        $this->repository->save($result, $activeSources);

        return [
            'summary' => [
                'refreshed' => $refreshed,
                'removed' => count($removed),
                'skipped' => $skipped,
                'active' => count($activeSources),
            ],
            'sources' => $activeSources,
            'removed_sources' => $removed,
            'graph' => $result->toArray(),
        ];
    }

    /**
     * Convenience wrapper exposing the GraphResearcher entity listing.
     *
     * @return array<int, array{entity: string, score: float, eligible: bool, fact_count: int, synonym_count: int}>
     */
    public function listTopEntities(int $limit = 10): array
    {
        return $this->researcher()->listTopEntities($limit);
    }

    /**
     * Proxy entity summary lookups to the GraphResearcher utility.
     */
    public function summariseEntity(string $query, int $factLimit = 12): ?array
    {
        return $this->researcher()->summariseEntity($query, $factLimit);
    }

    /**
     * Expose the stored sources for API responses.
     *
     * @return array<int, array<string, mixed>>
     */
    public function sources(): array
    {
        $payload = $this->repository->load();
        return is_array($payload['sources']) ? array_values($payload['sources']) : [];
    }

    /**
     * Compare stored sources and surface uniqueness metrics.
     *
     * @param array<int, string> $selectors
     */
    public function compareSources(array $selectors = [], int $limit = 12): array
    {
        return $this->reportBuilder()->compareSources($selectors, $limit);
    }

    /**
     * Build a multi-source research brief for the provided query.
     *
     * @param array<int, string> $selectors
     */
    public function buildResearchBrief(string $query, int $limit = 5, array $selectors = []): array
    {
        return $this->reportBuilder()->buildReport($query, $limit, $selectors);
    }

    /**
     * Blend a research brief with knowledge graph signals to produce a consolidated insight packet.
     *
     * @param array<int, string> $selectors
     */
    public function buildInsightDocument(string $query, int $limit = 5, array $selectors = []): array
    {
        $limit = max(1, $limit);

        $report = $this->buildResearchBrief($query, $limit, $selectors);
        $search = $this->researcher()->searchGraph($query, max(12, $limit * 2));

        $entities = $this->composeEntitySummaries($search['entities'] ?? [], max(6, $limit));
        $sections = $this->composeDocumentSections($report, $entities);

        $citations = isset($report['citations']) && is_array($report['citations'])
            ? array_values($report['citations'])
            : [];
        $sources = isset($search['sources']) && is_array($search['sources'])
            ? array_values($search['sources'])
            : [];

        return [
            'query' => isset($search['query']) && is_string($search['query']) ? $search['query'] : $query,
            'generated_at' => (new DateTimeImmutable())->format(DATE_ATOM),
            'document' => [
                'title' => $query !== '' ? 'Insight briefing for “' . $query . '”' : 'Latest insight briefing',
                'sections' => $sections,
            ],
            'entities' => $entities,
            'references' => [
                'citations' => $citations,
                'sources' => $sources,
            ],
            'report' => $report,
            'search' => $search,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function currentGraph(): ?array
    {
        $payload = $this->repository->load();
        return isset($payload['graph']) && is_array($payload['graph']) ? $payload['graph'] : null;
    }

    private function rebuildGraph(array $documents): ExtractionResult
    {
        try {
            return $this->extractor->analyseMany($documents);
        } catch (Throwable $exception) {
            throw new RuntimeException('Unable to rebuild knowledge graph: ' . $exception->getMessage(), 0, $exception);
        }
    }

    /**
     * @param mixed $value
     */
    private function parseDate($value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable $exception) {
            return null;
        }
    }

    private function reportBuilder(): ReportBuilder
    {
        if ($this->reportBuilder === null) {
            $this->reportBuilder = new ReportBuilder($this->repository);
        }

        return $this->reportBuilder;
    }

    private function researcher(): GraphResearcher
    {
        if ($this->graphResearcher === null) {
            $this->graphResearcher = new GraphResearcher($this->repository);
        }

        return $this->graphResearcher;
    }

    /**
     * @param array<int, array<string, mixed>> $entities
     * @return array<int, array<string, mixed>>
     */
    private function composeEntitySummaries(array $entities, int $limit): array
    {
        $rows = [];
        $limit = max(1, $limit);

        foreach (array_slice($entities, 0, $limit) as $entity) {
            if (!is_array($entity)) {
                continue;
            }

            $name = isset($entity['entity']) && is_string($entity['entity']) ? trim($entity['entity']) : '';
            if ($name === '') {
                continue;
            }

            $score = $this->normaliseScore($entity['score'] ?? 0.0);
            $matchedSynonym = isset($entity['matched_synonym']) && is_string($entity['matched_synonym'])
                ? trim($entity['matched_synonym'])
                : '';
            $matchedFact = isset($entity['matched_fact']) && is_string($entity['matched_fact'])
                ? $this->truncateText(trim($entity['matched_fact']), 160)
                : '';

            $summary = isset($entity['summary']) && is_array($entity['summary'])
                ? $entity['summary']
                : $this->researcher()->summariseEntity($name, 8);

            $synonyms = [];
            $factCount = 0;
            $relationCounts = [];
            $counterpartCounts = [];
            $factDescriptions = [];
            $relatedTerms = [];

            if (is_array($summary)) {
                if (isset($summary['synonyms']) && is_array($summary['synonyms'])) {
                    $synonyms = array_values(array_filter(
                        array_map('strval', $summary['synonyms']),
                        static fn(string $value): bool => trim($value) !== ''
                    ));
                }

                if (isset($summary['fact_count'])) {
                    $factCount = (int) $summary['fact_count'];
                }

                if (isset($summary['relation_counts']) && is_array($summary['relation_counts'])) {
                    foreach ($summary['relation_counts'] as $relation => $count) {
                        if (!is_string($relation)) {
                            continue;
                        }

                        $relationCounts[] = [
                            'label' => $relation,
                            'count' => (int) $count,
                        ];
                    }
                }

                if (isset($summary['counterpart_counts']) && is_array($summary['counterpart_counts'])) {
                    foreach ($summary['counterpart_counts'] as $counterpart => $count) {
                        if (!is_string($counterpart)) {
                            continue;
                        }

                        $counterpartCounts[] = [
                            'label' => $counterpart,
                            'count' => (int) $count,
                        ];
                    }
                }

                if (isset($summary['fact_descriptions']) && is_array($summary['fact_descriptions'])) {
                    foreach (array_slice($summary['fact_descriptions'], 0, 6) as $description) {
                        if (!is_string($description)) {
                            continue;
                        }

                        $trimmed = trim($description);
                        if ($trimmed !== '') {
                            $factDescriptions[] = $trimmed;
                        }
                    }
                } elseif (isset($summary['facts']) && is_array($summary['facts'])) {
                    foreach (array_slice($summary['facts'], 0, 6) as $fact) {
                        if (!is_array($fact)) {
                            continue;
                        }

                        $description = $this->formatFactDescription($name, $fact);
                        if ($description !== '') {
                            $factDescriptions[] = $description;
                        }
                    }
                }

                if (isset($summary['related_terms']) && is_array($summary['related_terms'])) {
                    foreach ($summary['related_terms'] as $related) {
                        if (!is_array($related)) {
                            continue;
                        }

                        $relatedName = isset($related['entity']) ? trim((string) $related['entity']) : '';
                        if ($relatedName === '') {
                            continue;
                        }

                        $relatedScore = isset($related['score']) ? $this->normaliseScore($related['score']) : 0.0;
                        $relatedTerms[] = [
                            'entity' => $relatedName,
                            'score' => $relatedScore,
                        ];
                    }
                }
            }

            $factDescriptions = array_values(array_unique($factDescriptions));
            $relatedTerms = $this->normaliseRelatedTermList($relatedTerms);

            $rows[] = [
                'entity' => $name,
                'score' => $score,
                'summary' => $this->summariseEntityDetails(
                    $name,
                    $factCount,
                    $relationCounts,
                    $counterpartCounts,
                    $matchedFact,
                    $matchedSynonym,
                    $relatedTerms
                ),
                'matched_synonym' => $matchedSynonym,
                'matched_fact' => $matchedFact,
                'fact_count' => $factCount,
                'synonyms' => array_slice($synonyms, 0, 6),
                'facts' => array_slice($factDescriptions, 0, 6),
                'top_relations' => array_slice($relationCounts, 0, 3),
                'top_counterparts' => array_slice($counterpartCounts, 0, 3),
                'related_terms' => array_slice($relatedTerms, 0, 6),
                'signals' => $this->normaliseSignals($entity['signals'] ?? []),
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $report
     * @param array<int, array<string, mixed>> $entities
     * @return array<int, array<string, mixed>>
     */
    private function composeDocumentSections(array $report, array $entities): array
    {
        $sections = [];

        $combined = isset($report['combined_summary']) && is_array($report['combined_summary'])
            ? $report['combined_summary']
            : [];
        $summaryItems = [];
        foreach (array_slice($combined, 0, 6) as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $answer = isset($entry['answer']) && is_string($entry['answer']) ? trim($entry['answer']) : '';
            if ($answer === '') {
                continue;
            }

            $summaryItems[] = [
                'text' => $this->truncateText($answer, 260),
                'question' => isset($entry['question']) && is_string($entry['question']) ? trim($entry['question']) : '',
                'citation' => isset($entry['citation']) && is_string($entry['citation']) ? trim($entry['citation']) : '',
            ];
        }

        if ($summaryItems !== []) {
            $sections[] = [
                'heading' => 'Key takeaways',
                'type' => 'bullets',
                'items' => $summaryItems,
            ];
        }

        $highlightItems = [];
        foreach (array_slice($entities, 0, 5) as $entity) {
            if (!is_array($entity)) {
                continue;
            }

            $name = isset($entity['entity']) && is_string($entity['entity']) ? trim($entity['entity']) : '';
            if ($name === '') {
                continue;
            }

            $highlightItems[] = [
                'text' => $this->summariseEntityHighlight($entity),
                'entity' => $name,
            ];
        }

        if ($highlightItems !== []) {
            $sections[] = [
                'heading' => 'Knowledge graph highlights',
                'type' => 'bullets',
                'items' => $highlightItems,
            ];
        }

        $topics = isset($report['topics']) && is_array($report['topics']) ? $report['topics'] : [];
        $topicItems = [];
        foreach (array_slice($topics, 0, 8) as $topic) {
            if (!is_array($topic)) {
                continue;
            }

            $label = isset($topic['label']) && is_string($topic['label']) ? trim($topic['label']) : '';
            if ($label === '') {
                continue;
            }

            $citations = [];
            if (isset($topic['citations']) && is_array($topic['citations'])) {
                $citations = array_slice(
                    array_values(array_filter(
                        array_map('strval', $topic['citations']),
                        static fn(string $value): bool => trim($value) !== ''
                    )),
                    0,
                    6
                );
            }

            $topicItems[] = [
                'label' => $label,
                'count' => isset($topic['count']) ? (int) $topic['count'] : 0,
                'citations' => $citations,
            ];
        }

        if ($topicItems !== []) {
            $sections[] = [
                'heading' => 'Topics to monitor',
                'type' => 'topics',
                'items' => $topicItems,
            ];
        }

        return $sections;
    }

    /**
     * @param array<string, mixed> $entity
     */
    private function summariseEntityHighlight(array $entity): string
    {
        $name = isset($entity['entity']) && is_string($entity['entity']) ? trim($entity['entity']) : '';
        $facts = isset($entity['facts']) && is_array($entity['facts']) ? $entity['facts'] : [];
        $summary = isset($entity['summary']) && is_string($entity['summary']) ? trim($entity['summary']) : '';

        $primaryFact = '';
        foreach ($facts as $fact) {
            if (!is_string($fact)) {
                continue;
            }

            $candidate = trim($fact);
            if ($candidate === '') {
                continue;
            }

            $primaryFact = $candidate;
            break;
        }

        if ($primaryFact === '') {
            $primaryFact = $summary;
        }

        if ($primaryFact === '') {
            $primaryFact = 'Referenced in the knowledge graph.';
        }

        $highlight = $name !== '' ? $name . ': ' . $primaryFact : $primaryFact;

        return $this->truncateText($highlight, 220);
    }

    /**
     * @param array<int, array{label: string, count: int}> $relations
     * @param array<int, array{label: string, count: int}> $counterparts
     */
    private function summariseEntityDetails(
        string $entity,
        int $factCount,
        array $relations,
        array $counterparts,
        string $matchedFact,
        string $matchedSynonym,
        array $relatedTerms
    ): string {
        $parts = [];

        if ($factCount > 0) {
            $parts[] = sprintf('%d fact%s indexed', $factCount, $factCount === 1 ? '' : 's');
        }

        if ($relations !== []) {
            $parts[] = 'Top relation: ' . $relations[0]['label'];
        }

        if ($counterparts !== []) {
            $parts[] = 'Key counterpart: ' . $counterparts[0]['label'];
        }

        if ($matchedFact !== '') {
            $parts[] = 'Context match: ' . $matchedFact;
        } elseif ($matchedSynonym !== '') {
            $parts[] = 'Synonym match: ' . $matchedSynonym;
        }

        if ($relatedTerms !== []) {
            $primaryRelated = $relatedTerms[0]['entity'] ?? '';
            if (is_string($primaryRelated) && $primaryRelated !== '') {
                $parts[] = 'Related to ' . $primaryRelated;
            }
        }

        if ($parts === []) {
            return $entity . ' appears in the knowledge graph.';
        }

        return $this->truncateText($entity . ' — ' . implode(' · ', $parts), 220);
    }

    /**
     * @param array<int, array{entity: string, score: float}> $terms
     * @return array<int, array{entity: string, score: float}>
     */
    private function normaliseRelatedTermList(array $terms): array
    {
        if ($terms === []) {
            return [];
        }

        $seen = [];
        $normalised = [];
        foreach ($terms as $term) {
            if (!is_array($term)) {
                continue;
            }

            $entity = isset($term['entity']) ? trim((string) $term['entity']) : '';
            if ($entity === '' || isset($seen[$entity])) {
                continue;
            }

            $seen[$entity] = true;
            $normalised[] = [
                'entity' => $entity,
                'score' => $this->normaliseScore($term['score'] ?? 0.0),
            ];
        }

        if ($normalised === []) {
            return [];
        }

        usort(
            $normalised,
            static function (array $left, array $right): int {
                $comparison = $right['score'] <=> $left['score'];
                if ($comparison !== 0) {
                    return $comparison;
                }

                return $left['entity'] <=> $right['entity'];
            }
        );

        return $normalised;
    }

    /**
     * @param array<string, mixed> $fact
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

        if ($text === '') {
            return '';
        }

        return $this->truncateText($text, 180);
    }

    /**
     * @param mixed $value
     */
    private function normaliseScore($value): float
    {
        $numeric = is_numeric($value) ? (float) $value : 0.0;

        if ($numeric < 0.0) {
            $numeric = 0.0;
        }

        if ($numeric > 1.0) {
            $numeric = 1.0;
        }

        return round($numeric, 4);
    }

    /**
     * @param mixed $signals
     * @return array<string, float>
     */
    private function normaliseSignals($signals): array
    {
        if (!is_array($signals)) {
            return [];
        }

        $normalised = [];
        foreach ($signals as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $normalised[$key] = $this->normaliseScore($value);
        }

        return $normalised;
    }

    private function truncateText(string $text, int $limit = 200): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        $snippet = mb_substr($text, 0, $limit);
        $snippet = preg_replace('/\s+\S*$/u', '', $snippet) ?: $snippet;

        return rtrim($snippet, " \t\n\r\0\x0B.,;:") . '…';
    }
}

