<?php

declare(strict_types=1);

namespace App\KnowledgeGraph;

use App\Scraping\ScraperInterface;
use App\Scraping\ScrapeResult;
use App\Scraping\WebScraper;
use Throwable;

use function array_shift;
use function array_values;
use function count;
use function is_array;
use function is_string;
use function max;
use function parse_url;
use function preg_match;
use function trim;

final class AutoCrawler
{
    private ResearchService $service;

    private ScraperInterface $scraper;

    public function __construct(ResearchService $service, ?ScraperInterface $scraper = null)
    {
        $this->service = $service;
        $this->scraper = $scraper ?? new WebScraper();
    }

    /**
     * Crawl from the supplied seed URLs and merge the discovered pages into the knowledge graph.
     *
     * @param array<int, string> $seeds
     * @return array{
     *     summary: array{processed: int, errors: int, seeds: int, remaining: int, discovered: int},
     *     ingested: array<int, array<string, mixed>>,
     *     errors: array<int, array{url: string, reason: string}>,
     *     discovered: array<int, string>,
     *     graph: array<string, mixed>,
     *     queue: array<int, string>
     * }
     */
    public function crawl(array $seeds, int $limit = 6, int $maxDepth = 2, bool $allowCrossDomain = false): array
    {
        $limit = max(1, $limit);
        $maxDepth = max(0, $maxDepth);

        $queue = [];
        $queued = [];
        $visited = [];
        $processed = [];
        $errors = [];
        $discovered = [];
        $lastGraph = null;

        foreach ($seeds as $seed) {
            $url = $this->normaliseUrl($seed);
            if ($url === null) {
                continue;
            }
            if (isset($queued[$url])) {
                continue;
            }

            $rootHost = $this->hostForUrl($url);
            $queue[] = ['url' => $url, 'depth' => 0, 'root' => $rootHost];
            $queued[$url] = true;
        }

        while ($queue !== [] && count($processed) < $limit) {
            /** @var array{url: string, depth: int, root: string|null} $item */
            $item = array_shift($queue);
            $url = $item['url'];
            $depth = $item['depth'];
            $rootHost = $item['root'];

            if (isset($visited[$url])) {
                continue;
            }

            $visited[$url] = true;

            try {
                $scrape = $this->scraper->scrape($url);
                $ingest = $this->service->ingestScrapeResult($scrape);
                $processed[] = [
                    'url' => $url,
                    'title' => $scrape->title(),
                    'characters' => $scrape->characterCount(),
                    'links_discovered' => count($scrape->links()),
                ];
                $lastGraph = $ingest['graph'];

                if ($depth < $maxDepth) {
                    $this->enqueueLinks($queue, $queued, $scrape, $rootHost, $allowCrossDomain, $depth + 1, $discovered);
                }
            } catch (Throwable $exception) {
                $errors[] = [
                    'url' => $url,
                    'reason' => $exception->getMessage(),
                ];
            }
        }

        $remainingQueue = [];
        foreach ($queue as $entry) {
            if (isset($entry['url'])) {
                $remainingQueue[] = (string) $entry['url'];
            }
        }

        $graph = $lastGraph;
        if (!is_array($graph)) {
            $graph = $this->service->currentGraph() ?? [];
        }

        return [
            'summary' => [
                'processed' => count($processed),
                'errors' => count($errors),
                'seeds' => count($seeds),
                'remaining' => count($remainingQueue),
                'discovered' => count($discovered),
            ],
            'ingested' => $processed,
            'errors' => $errors,
            'discovered' => array_values($discovered),
            'graph' => $graph,
            'queue' => $remainingQueue,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $queue
     * @param array<string, bool> $queued
     * @param array<string, bool> $discovered
     */
    private function enqueueLinks(array &$queue, array &$queued, ScrapeResult $scrape, ?string $rootHost, bool $allowCrossDomain, int $nextDepth, array &$discovered): void
    {
        foreach ($scrape->links() as $link) {
            $url = $this->normaliseUrl($link);
            if ($url === null) {
                continue;
            }

            $host = $this->hostForUrl($url);
            if (!$allowCrossDomain && $rootHost !== null && $host !== $rootHost) {
                continue;
            }

            if (isset($queued[$url])) {
                continue;
            }

            $queue[] = ['url' => $url, 'depth' => $nextDepth, 'root' => $rootHost ?? $host];
            $queued[$url] = true;
            $discovered[$url] = $url;
        }
    }

    private function normaliseUrl(string $url): ?string
    {
        $trimmed = trim($url);
        if ($trimmed === '') {
            return null;
        }

        if (!preg_match('/^https?:\/\//i', $trimmed)) {
            $trimmed = 'https://' . $trimmed;
        }

        return $trimmed;
    }

    private function hostForUrl(string $url): ?string
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return null;
        }

        $host = $parts['host'] ?? null;
        return is_string($host) ? $host : null;
    }
}
