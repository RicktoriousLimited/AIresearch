<?php

declare(strict_types=1);

namespace Ricktorious\Markets\News\Sources;

use DateTimeImmutable;
use Ricktorious\Markets\News\NewsArticle;
use Ricktorious\Markets\News\NewsSourceInterface;
use RuntimeException;
use SimpleXMLElement;

use function array_slice;
use function date_create_immutable;
use function htmlspecialchars_decode;
use function rawurlencode;
use function sprintf;
use function strip_tags;
use function strtotime;
use function trim;

final class GoogleNewsSource implements NewsSourceInterface
{
    public function __construct(private string $endpoint)
    {
    }

    /**
     * @return array<int, NewsArticle>
     */
    public function fetch(string $company, int $limit = 25): array
    {
        $query = rawurlencode($company . ' stock');
        $url = sprintf('%s?q=%s&hl=en-US&gl=US&ceid=US:en', rtrim($this->endpoint, '/'), $query);

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 10,
                'header' => [
                    'User-Agent: RicktoriousMarkets/1.0',
                    'Accept: application/rss+xml,text/xml',
                ],
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        if ($body === false || $body === '') {
            throw new RuntimeException('Unable to download Google News feed.');
        }

        $xml = @simplexml_load_string($body);
        if (!$xml instanceof SimpleXMLElement) {
            throw new RuntimeException('Unable to parse Google News feed.');
        }

        $items = [];
        foreach ($xml->channel->item as $item) {
            $title = trim((string) $item->title);
            $link = trim((string) $item->link);
            $source = trim((string) $item->source);
            $description = trim((string) $item->description);
            $pubDate = trim((string) $item->pubDate);

            if ($title === '' || $link === '') {
                continue;
            }

            $publishedAt = $this->normaliseDate($pubDate);
            if (!$publishedAt instanceof DateTimeImmutable) {
                continue;
            }

            $summary = strip_tags(htmlspecialchars_decode($description));

            $items[] = new NewsArticle(
                $title,
                $link,
                $source !== '' ? $source : 'Google News',
                $publishedAt,
                $summary !== '' ? $summary : $title,
                ['source' => 'google_news']
            );
        }

        if ($limit > 0) {
            $items = array_slice($items, 0, $limit);
        }

        return $items;
    }

    private function normaliseDate(string $date): ?DateTimeImmutable
    {
        if ($date === '') {
            return null;
        }

        $timestamp = date_create_immutable($date);
        if ($timestamp instanceof DateTimeImmutable) {
            return $timestamp;
        }

        $numeric = strtotime($date);
        if ($numeric !== false) {
            return (new DateTimeImmutable())->setTimestamp($numeric);
        }

        return null;
    }
}
