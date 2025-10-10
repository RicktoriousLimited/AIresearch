<?php

declare(strict_types=1);

namespace App\News;

use App\Crawler\HiddenCrawler;
use DateTimeImmutable;
use Exception;

use function array_filter;
use function array_keys;
use function array_map;
use function array_slice;
use function array_sum;
use function array_values;
use function array_unique;
use function arsort;
use function count;
use function implode;
use function is_array;
use function is_string;
use function max;
use function mb_strtolower;
use function min;
use function preg_split;
use function round;
use function str_contains;
use function trim;
use function usort;

use const DATE_ATOM;

final class NewsSearchService
{
    private HiddenCrawler $crawler;

    public function __construct(HiddenCrawler $crawler)
    {
        $this->crawler = $crawler;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function search(string $query, array $options = []): array
    {
        $history = $this->crawler->history();
        $limit = (int) ($options['limit'] ?? 24);
        $limit = max(1, min(100, $limit));
        $minQuality = (float) ($options['min_quality'] ?? 0.0);
        $minQuality = max(0.0, min(100.0, $minQuality));

        $normalisedQuery = mb_strtolower(trim($query));
        $terms = array_values(array_filter(preg_split('/\s+/u', $normalisedQuery) ?: [], static fn(string $term): bool => $term !== ''));

        $now = new DateTimeImmutable();
        $matches = [];

        foreach ($history as $row) {
            if (!is_array($row)) {
                continue;
            }

            $quality = (float) ($row['quality_score'] ?? 0.0);
            if ($quality < $minQuality) {
                continue;
            }

            $matchScore = $this->matchScore($row, $terms);
            if ($terms !== [] && $matchScore <= 0.0) {
                continue;
            }

            $weight = $quality + $matchScore + $this->recencyBoost((string) ($row['fetched_at'] ?? ''), $now);

            $contentType = isset($row['content_type']) ? (string) $row['content_type'] : '';
            if ($contentType === 'article') {
                $weight += 12.0;
            } elseif ($contentType === 'page') {
                $weight += 4.0;
            } elseif ($contentType === 'non_article') {
                $weight -= 8.0;
            } elseif ($contentType === 'error') {
                $weight -= 12.0;
            }

            $matches[] = [
                'weight' => $weight,
                'item' => $this->formatRow($row),
            ];
        }

        usort($matches, static fn(array $a, array $b): int => $b['weight'] <=> $a['weight']);

        $items = array_map(static fn(array $match): array => $match['item'], $matches);
        $results = array_slice($items, 0, $limit);
        $meta = $this->buildMeta($items);

        return [
            'query' => $query,
            'min_quality' => $minQuality,
            'limit' => $limit,
            'results' => $results,
            'meta' => $meta,
            'generated_at' => $now->format(DATE_ATOM),
        ];
    }

    /**
     * @param array<int, string> $terms
     * @param array<string, mixed> $entry
     */
    private function matchScore(array $entry, array $terms): float
    {
        if ($terms === []) {
            return 0.0;
        }

        $score = 0.0;
        $haystacks = [];
        foreach (['title', 'summary', 'preview', 'meta_description', 'source_domain', 'source_site_name'] as $field) {
            if (!isset($entry[$field])) {
                continue;
            }

            $value = mb_strtolower((string) $entry[$field]);
            if ($value !== '') {
                $haystacks[] = $value;
            }
        }

        if ($haystacks === []) {
            return 0.0;
        }

        $haystack = ' ' . implode(' ', $haystacks) . ' ';

        foreach ($terms as $term) {
            if ($term === '') {
                continue;
            }

            if (str_contains($haystack, ' ' . $term . ' ')) {
                $score += 18.0;
            } elseif (str_contains($haystack, $term)) {
                $score += 10.0;
            }
        }

        $topics = is_array($entry['topics'] ?? null) ? $entry['topics'] : [];
        foreach ($topics as $topic) {
            $topicLower = mb_strtolower((string) $topic);
            foreach ($terms as $term) {
                if ($term !== '' && str_contains($topicLower, $term)) {
                    $score += 6.0;
                    break;
                }
            }
        }

        $entities = is_array($entry['entities'] ?? null) ? $entry['entities'] : [];
        foreach ($entities as $entity) {
            if (!is_array($entity)) {
                continue;
            }

            $label = mb_strtolower((string) ($entity['label'] ?? ''));
            foreach ($terms as $term) {
                if ($term !== '' && $label !== '' && str_contains($label, $term)) {
                    $score += 4.0;
                    break;
                }
            }
        }

        return $score;
    }

    private function recencyBoost(string $fetchedAt, DateTimeImmutable $now): float
    {
        if ($fetchedAt === '') {
            return 0.0;
        }

        try {
            $fetched = new DateTimeImmutable($fetchedAt);
        } catch (Exception $exception) {
            return 0.0;
        }

        $diff = max(0, $now->getTimestamp() - $fetched->getTimestamp());
        $hours = $diff / 3600;

        if ($hours <= 4) {
            return 12.0;
        }
        if ($hours <= 12) {
            return 8.0;
        }
        if ($hours <= 24) {
            return 5.0;
        }
        if ($hours <= 72) {
            return 3.0;
        }
        if ($hours <= 168) {
            return 1.5;
        }

        return 0.0;
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return array<string, mixed>
     */
    private function formatRow(array $entry): array
    {
        $topics = [];
        if (is_array($entry['topics'] ?? null)) {
            foreach ($entry['topics'] as $topic) {
                if (is_string($topic) && trim($topic) !== '') {
                    $topics[] = $topic;
                }
            }
        }

        $entities = [];
        if (is_array($entry['entities'] ?? null)) {
            foreach ($entry['entities'] as $entity) {
                if (!is_array($entity)) {
                    continue;
                }
                $label = (string) ($entity['label'] ?? '');
                $type = (string) ($entity['type'] ?? '');
                if ($label === '') {
                    continue;
                }
                $entities[] = [
                    'label' => $label,
                    'type' => $type,
                ];
            }
        }

        $qualityReasons = [];
        if (is_array($entry['quality_reasons'] ?? null)) {
            foreach ($entry['quality_reasons'] as $reason) {
                if (is_string($reason) && trim($reason) !== '') {
                    $qualityReasons[] = trim($reason);
                }
            }
        }

        $recommended = [];
        if (is_array($entry['recommended_sources'] ?? null)) {
            foreach ($entry['recommended_sources'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $url = (string) ($row['url'] ?? '');
                $domain = (string) ($row['domain'] ?? '');
                $trust = (float) ($row['trust_score'] ?? 0.0);
                if ($url === '' || $domain === '') {
                    continue;
                }
                $recommended[] = [
                    'url' => $url,
                    'domain' => $domain,
                    'trust_score' => $trust,
                ];
            }
        }

        return [
            'title' => (string) ($entry['title'] ?? $entry['url'] ?? ''),
            'url' => (string) ($entry['url'] ?? ''),
            'preview' => (string) ($entry['preview'] ?? ''),
            'summary' => (string) ($entry['summary'] ?? ''),
            'topics' => $topics,
            'entities' => $entities,
            'fetched_at' => (string) ($entry['fetched_at'] ?? ''),
            'last_checked_at' => (string) ($entry['last_checked_at'] ?? $entry['fetched_at'] ?? ''),
            'quality_score' => (float) ($entry['quality_score'] ?? 0.0),
            'quality_label' => (string) ($entry['quality_label'] ?? 'Low'),
            'quality_reasons' => $qualityReasons,
            'ingest' => (bool) ($entry['ingest'] ?? false),
            'source_domain' => (string) ($entry['source_domain'] ?? ''),
            'source_site_name' => (string) ($entry['site_name'] ?? $entry['source_site_name'] ?? ''),
            'source_language' => (string) ($entry['source_language'] ?? ''),
            'source_published_at' => (string) ($entry['source_published_at'] ?? $entry['published_at'] ?? ''),
            'thumbnail' => (string) ($entry['thumbnail'] ?? ''),
            'meta_description' => (string) ($entry['meta_description'] ?? ''),
            'recommended_sources' => $recommended,
            'content_type' => (string) ($entry['content_type'] ?? 'page'),
            'revision' => (int) ($entry['revision'] ?? 1),
            'normalized_url' => (string) ($entry['normalized_url'] ?? $entry['url'] ?? ''),
            'unchanged' => (bool) ($entry['unchanged'] ?? false),
            'changes' => $this->formatChangeSummary($entry['changes'] ?? null),
            'versions' => $this->formatVersions($entry['versions'] ?? null),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $items
     *
     * @return array<string, mixed>
     */
    private function buildMeta(array $items): array
    {
        $topicCounts = [];
        $sourceCounts = [];
        $qualityScores = [];
        $ingested = 0;
        $typeBreakdown = [];

        foreach ($items as $item) {
            $topics = is_array($item['topics'] ?? null) ? $item['topics'] : [];
            foreach ($topics as $topic) {
                if (!is_string($topic) || $topic === '') {
                    continue;
                }
                $topicCounts[$topic] = ($topicCounts[$topic] ?? 0) + 1;
            }

            $domain = (string) ($item['source_domain'] ?? '');
            if ($domain !== '') {
                $sourceCounts[$domain] = $sourceCounts[$domain] ?? ['count' => 0, 'score' => 0.0];
                $sourceCounts[$domain]['count']++;
                $sourceCounts[$domain]['score'] += (float) ($item['quality_score'] ?? 0.0);
            }

            $qualityScores[] = (float) ($item['quality_score'] ?? 0.0);
            if (!empty($item['ingest'])) {
                $ingested++;
            }

            $type = (string) ($item['content_type'] ?? '');
            if ($type !== '') {
                $typeBreakdown[$type] = ($typeBreakdown[$type] ?? 0) + 1;
            }
        }

        arsort($topicCounts);
        $topics = [];
        foreach (array_slice(array_keys($topicCounts), 0, 8) as $topic) {
            $topics[] = [
                'topic' => $topic,
                'count' => $topicCounts[$topic],
            ];
        }

        arsort($sourceCounts);
        $sources = [];
        foreach ($sourceCounts as $domain => $stats) {
            if (!is_array($stats)) {
                continue;
            }
            $count = (int) ($stats['count'] ?? 0);
            $score = (float) ($stats['score'] ?? 0.0);
            if ($count <= 0) {
                continue;
            }
            $sources[] = [
                'domain' => $domain,
                'count' => $count,
                'average_quality' => $count > 0 ? round($score / $count, 1) : 0.0,
            ];
        }

        usort($sources, static fn(array $a, array $b): int => $b['average_quality'] <=> $a['average_quality']);
        $sources = array_slice($sources, 0, 6);

        $averageQuality = 0.0;
        if ($qualityScores !== []) {
            $averageQuality = round(array_sum($qualityScores) / max(1, count($qualityScores)), 1);
        }

        return [
            'total_matches' => count($items),
            'average_quality' => $averageQuality,
            'topics' => $topics,
            'sources' => $sources,
            'high_quality' => count(array_filter($qualityScores, static fn(float $score): bool => $score >= 70.0)),
            'ingested' => $ingested,
            'suggested_queries' => array_map(static fn(array $row): string => (string) $row['topic'], array_slice($topics, 0, 5)),
            'types' => $typeBreakdown,
        ];
    }

    /**
     * @param mixed $changes
     *
     * @return array<string, mixed>
     */
    private function formatChangeSummary($changes): array
    {
        $default = [
            'summary' => '',
            'keywords_added' => [],
            'keywords_removed' => [],
            'entities_added' => [],
            'entities_removed' => [],
            'length_delta' => 0,
        ];

        if (is_array($changes)) {
            return [
                'summary' => (string) ($changes['summary'] ?? ''),
                'keywords_added' => $this->normaliseStringList($changes['keywords_added'] ?? []),
                'keywords_removed' => $this->normaliseStringList($changes['keywords_removed'] ?? []),
                'entities_added' => $this->normaliseStringList($changes['entities_added'] ?? []),
                'entities_removed' => $this->normaliseStringList($changes['entities_removed'] ?? []),
                'length_delta' => (int) ($changes['length_delta'] ?? 0),
            ];
        }

        if (is_string($changes) && $changes !== '') {
            $default['summary'] = $changes;
        }

        return $default;
    }

    /**
     * @param mixed $versions
     *
     * @return array<int, array<string, mixed>>
     */
    private function formatVersions($versions): array
    {
        if (!is_array($versions)) {
            return [];
        }

        $formatted = [];
        foreach ($versions as $version) {
            if (!is_array($version)) {
                continue;
            }

            $formatted[] = [
                'revision' => (int) ($version['revision'] ?? 0),
                'title' => (string) ($version['title'] ?? ''),
                'summary' => (string) ($version['summary'] ?? ''),
                'fetched_at' => (string) ($version['fetched_at'] ?? ''),
                'url' => (string) ($version['url'] ?? ''),
                'changes' => $this->formatChangeSummary($version['changes'] ?? null),
            ];
        }

        return array_slice($formatted, 0, 6);
    }

    /**
     * @param array<int, mixed> $values
     *
     * @return array<int, string>
     */
    private function normaliseStringList(array $values): array
    {
        $clean = [];

        foreach ($values as $value) {
            if (!is_string($value)) {
                continue;
            }

            $value = trim($value);
            if ($value === '') {
                continue;
            }

            $clean[] = $value;
        }

        return array_values(array_unique($clean));
    }
}
