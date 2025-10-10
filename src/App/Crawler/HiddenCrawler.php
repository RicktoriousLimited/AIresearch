<?php

declare(strict_types=1);

namespace App\Crawler;

use App\Scraping\ScraperInterface;
use App\Scraping\WebScraper;
use App\Text\TextRefiner;
use RuntimeException;

use function array_map;
use function array_merge;
use function array_slice;
use function array_values;
use function dirname;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function is_array;
use function is_dir;
use function json_decode;
use function json_encode;
use function mb_substr;
use function mkdir;
use function date;
use function trim;

use const DATE_ATOM;

final class HiddenCrawler
{
    private ScraperInterface $scraper;

    private TextRefiner $refiner;

    private string $storagePath;

    public function __construct(string $storagePath, ?ScraperInterface $scraper = null, ?TextRefiner $refiner = null)
    {
        $this->storagePath = $storagePath;
        $this->scraper = $scraper ?? new WebScraper();
        $this->refiner = $refiner ?? new TextRefiner();

        $directory = dirname($storagePath);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        if (!file_exists($storagePath)) {
            file_put_contents($storagePath, json_encode([]));
        }
    }

    /**
     * @param array<int, string> $targets
     *
     * @return array<int, array<string, mixed>>
     */
    public function crawl(array $targets): array
    {
        $entries = [];
        foreach ($targets as $target) {
            if (!is_string($target)) {
                continue;
            }

            $url = trim($target);
            if ($url === '') {
                continue;
            }

            $entries[] = $this->crawlUrl($url);
        }

        if ($entries === []) {
            return [];
        }

        $history = $this->history();
        $history = array_values(array_merge($entries, $history));
        $history = array_slice($history, 0, 50);

        $this->store($history);

        return $entries;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function history(): array
    {
        $contents = file_get_contents($this->storagePath);
        if ($contents === false) {
            return [];
        }

        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            return [];
        }

        return $decoded;
    }

    private function store(array $entries): void
    {
        $result = file_put_contents(
            $this->storagePath,
            json_encode(array_values($entries), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        if ($result === false) {
            throw new RuntimeException('Unable to persist crawler history.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function crawlUrl(string $url): array
    {
        try {
            $scraped = $this->scraper->scrape($url);
        } catch (RuntimeException $exception) {
            return [
                'url' => $url,
                'fetched_at' => date(DATE_ATOM),
                'error' => $exception->getMessage(),
            ];
        }

        $analysis = $this->refiner->analyseDocument($scraped->text());

        return [
            'url' => $scraped->url(),
            'title' => $scraped->title(),
            'fetched_at' => date(DATE_ATOM),
            'preview' => $scraped->preview(240),
            'keywords' => $this->formatKeywords($analysis['keywords'] ?? []),
            'summary' => mb_substr((string) ($analysis['rewritten'] ?? ''), 0, 3200),
            'entities' => $this->extractEntities($analysis['analytics']['entities']['top_entities'] ?? []),
            'links' => $scraped->toMetaArray()['links'],
        ];
    }

    /**
     * @param array<int, array{token: string, count: int}> $keywords
     *
     * @return array<int, array{token: string, count: int}>
     */
    private function formatKeywords(array $keywords): array
    {
        $keywords = array_values(array_map(
            static function (array $keyword): array {
                return [
                    'token' => (string) ($keyword['token'] ?? ''),
                    'count' => (int) ($keyword['count'] ?? 0),
                ];
            },
            $keywords
        ));

        return array_slice($keywords, 0, 10);
    }

    /**
     * @param array<int, array<string, mixed>> $entities
     *
     * @return array<int, array<string, mixed>>
     */
    private function extractEntities(array $entities): array
    {
        $entities = array_values(array_map(
            static function (array $entity): array {
                return [
                    'label' => (string) ($entity['label'] ?? ''),
                    'type' => (string) ($entity['type'] ?? ''),
                    'score' => (float) ($entity['score'] ?? 0.0),
                ];
            },
            $entities
        ));

        return array_slice($entities, 0, 10);
    }
}
