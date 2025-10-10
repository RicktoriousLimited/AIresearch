<?php

declare(strict_types=1);

namespace Ricktorious\Markets\News\Sources;

use DateTimeImmutable;
use JsonException;
use Ricktorious\Markets\News\NewsArticle;
use Ricktorious\Markets\News\NewsSourceInterface;
use RuntimeException;

use const JSON_THROW_ON_ERROR;
use function array_slice;
use function file_exists;
use function file_get_contents;
use function is_array;
use function is_string;
use function json_decode;
use function strtotime;
use function trim;

final class StaticNewsSource implements NewsSourceInterface
{
    public function __construct(private string $path)
    {
    }

    /**
     * @return array<int, NewsArticle>
     */
    public function fetch(string $company, int $limit = 25): array
    {
        if (!file_exists($this->path)) {
            throw new RuntimeException('Static news source missing: ' . $this->path);
        }

        $contents = file_get_contents($this->path);
        if (!is_string($contents) || $contents === '') {
            throw new RuntimeException('Unable to read static news source.');
        }

        try {
            $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Invalid static news data: ' . $exception->getMessage(), 0, $exception);
        }

        if (!is_array($data)) {
            throw new RuntimeException('Invalid static news data.');
        }

        $articles = [];
        foreach ($data as $item) {
            if (!is_array($item)) {
                continue;
            }

            $title = trim((string) ($item['title'] ?? ''));
            $url = trim((string) ($item['url'] ?? ''));
            $source = trim((string) ($item['source'] ?? 'Static Sample'));
            $summary = trim((string) ($item['summary'] ?? ''));
            $published = trim((string) ($item['published_at'] ?? ''));

            if ($title === '' || $url === '' || $published === '') {
                continue;
            }

            $timestamp = strtotime($published);
            if ($timestamp === false) {
                continue;
            }

            $articles[] = new NewsArticle(
                $title,
                $url,
                $source,
                (new DateTimeImmutable())->setTimestamp($timestamp),
                $summary !== '' ? $summary : $title,
                ['source' => 'static']
            );
        }

        if ($limit > 0) {
            $articles = array_slice($articles, 0, $limit);
        }

        return $articles;
    }
}
