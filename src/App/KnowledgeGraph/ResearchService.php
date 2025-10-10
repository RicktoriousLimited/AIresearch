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
use function array_values;
use function count;
use function is_array;
use function is_string;
use function max;
use function trim;
use function usort;

final class ResearchService
{
    private GraphRepository $repository;

    private ScraperInterface $scraper;

    private ExtractorInterface $extractor;

    private ?ReportBuilder $reportBuilder = null;

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
        $researcher = new GraphResearcher($this->repository);
        return $researcher->listTopEntities($limit);
    }

    /**
     * Proxy entity summary lookups to the GraphResearcher utility.
     */
    public function summariseEntity(string $query, int $factLimit = 12): ?array
    {
        $researcher = new GraphResearcher($this->repository);
        return $researcher->summariseEntity($query, $factLimit);
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
}

