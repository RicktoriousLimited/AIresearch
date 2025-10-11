<?php

declare(strict_types=1);

namespace App\News;

use App\Crawler\HiddenCrawler;
use App\KnowledgeGraph\GraphRepository;
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
use function filter_var;
use function implode;
use function is_array;
use function is_string;
use function max;
use function mb_strlen;
use function mb_strtolower;
use function min;
use function parse_url;
use function preg_replace;
use function preg_split;
use function round;
use function strtolower;
use function str_replace;
use function str_contains;
use function trim;
use function ucfirst;
use function usort;

use const DATE_ATOM;
use const FILTER_VALIDATE_URL;
use const PHP_URL_HOST;

final class NewsSearchService
{
    private HiddenCrawler $crawler;

    private GraphRepository $graphRepository;

    public function __construct(HiddenCrawler $crawler, ?GraphRepository $graphRepository = null)
    {
        $this->crawler = $crawler;
        $this->graphRepository = $graphRepository ?? new GraphRepository();
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

        $normalisedQuery = mb_strtolower(trim($query));
        $terms = array_values(array_filter(preg_split('/\s+/u', $normalisedQuery) ?: [], static fn(string $term): bool => $term !== ''));

        $now = new DateTimeImmutable();
        $matches = [];

        foreach ($history as $row) {
            if (!is_array($row)) {
                continue;
            }

            $quality = (float) ($row['quality_score'] ?? 0.0);

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

            $this->upsertMatch($matches, $this->formatRow($row), $weight);
        }

        if (count($matches) < $limit) {
            $this->augmentMatchesFromGraph($matches, $terms, $now, $limit);
        }

        $sorted = array_values($matches);
        usort($sorted, static fn(array $a, array $b): int => $b['weight'] <=> $a['weight']);

        $items = array_map(static fn(array $match): array => $match['item'], $sorted);
        $results = array_slice($items, 0, $limit);
        $meta = $this->buildMeta($items);
        $discovery = $this->crawler->discoveryTree();

        $meta['discovery'] = $discovery;

        return [
            'query' => $query,
            'limit' => $limit,
            'results' => $results,
            'meta' => $meta,
            'discovery' => $discovery,
            'generated_at' => $now->format(DATE_ATOM),
        ];
    }

    /**
     * @param array<string, array{weight: float, item: array<string, mixed>}> $matches
     * @param array<string, mixed> $item
     */
    private function upsertMatch(array &$matches, array $item, float $weight): void
    {
        $key = $this->entryKey($item);
        if ($key === '') {
            return;
        }

        if (!isset($matches[$key])) {
            $matches[$key] = ['weight' => $weight, 'item' => $item];

            return;
        }

        $existing = $matches[$key];
        $merged = $this->mergeItems($existing['item'], $item);
        $matches[$key] = [
            'weight' => max($existing['weight'], $weight),
            'item' => $merged,
        ];
    }

    /**
     * @param array<string, array{weight: float, item: array<string, mixed>}> $matches
     * @param array<int, string> $terms
     */
    private function augmentMatchesFromGraph(
        array &$matches,
        array $terms,
        DateTimeImmutable $now,
        int $limit
    ): void {
        $payload = $this->graphRepository->load();
        $sources = isset($payload['sources']) && is_array($payload['sources']) ? $payload['sources'] : [];
        if ($sources === []) {
            return;
        }

        foreach ($sources as $source) {
            if (!is_array($source)) {
                continue;
            }

            $entry = $this->normaliseGraphSource($source);
            $quality = (float) ($entry['quality_score'] ?? 0.0);

            $matchScore = $this->matchScore($entry, $terms);
            if ($terms !== [] && $matchScore <= 0.0) {
                continue;
            }

            $weight = $quality + $matchScore + $this->recencyBoost((string) ($entry['fetched_at'] ?? ''), $now) + 6.0;

            $this->upsertMatch($matches, $this->formatRow($entry), $weight);

            if ($limit > 0 && count($matches) >= $limit * 3) {
                break;
            }
        }
    }

    /**
     * @param array<string, mixed> $item
     */
    private function entryKey(array $item): string
    {
        $candidates = [];
        if (isset($item['normalized_url']) && is_string($item['normalized_url'])) {
            $candidates[] = $item['normalized_url'];
        }
        if (isset($item['url']) && is_string($item['url'])) {
            $candidates[] = $item['url'];
        }

        foreach ($candidates as $candidate) {
            $value = trim(mb_strtolower((string) $candidate));
            if ($value === '') {
                continue;
            }

            $value = preg_replace('/#.*$/u', '', $value) ?? $value;
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $primary
     * @param array<string, mixed> $secondary
     * @return array<string, mixed>
     */
    private function mergeItems(array $primary, array $secondary): array
    {
        $merged = $primary;

        $stringFields = [
            'title',
            'summary',
            'preview',
            'source_site_name',
            'source_domain',
            'source_language',
            'source_published_at',
            'thumbnail',
            'image_url',
            'meta_description',
            'content_type',
            'fetched_at',
            'last_checked_at',
        ];

        foreach ($stringFields as $field) {
            $current = isset($merged[$field]) ? (string) $merged[$field] : '';
            if ($current !== '') {
                continue;
            }
            if (isset($secondary[$field]) && (string) $secondary[$field] !== '') {
                $merged[$field] = $secondary[$field];
            }
        }

        if (!isset($merged['normalized_url']) || (string) $merged['normalized_url'] === '') {
            if (isset($secondary['normalized_url']) && (string) $secondary['normalized_url'] !== '') {
                $merged['normalized_url'] = $secondary['normalized_url'];
            } elseif (isset($secondary['url']) && (string) $secondary['url'] !== '') {
                $merged['normalized_url'] = $secondary['url'];
            }
        }

        if (!isset($merged['url']) || (string) $merged['url'] === '') {
            if (isset($secondary['url']) && (string) $secondary['url'] !== '') {
                $merged['url'] = $secondary['url'];
            }
        }

        $merged['quality_score'] = max(
            (float) ($primary['quality_score'] ?? 0.0),
            (float) ($secondary['quality_score'] ?? 0.0)
        );
        if ($merged['quality_score'] > 0.0) {
            $merged['quality_label'] = $this->qualityLabelFromScore((float) $merged['quality_score']);
        }

        $merged['ingest'] = !empty($primary['ingest']) || !empty($secondary['ingest']);

        $merged['topics'] = $this->mergeStringLists($primary['topics'] ?? [], $secondary['topics'] ?? []);
        $merged['quality_reasons'] = $this->mergeStringLists($primary['quality_reasons'] ?? [], $secondary['quality_reasons'] ?? []);
        $merged['entities'] = $this->mergeEntities($primary['entities'] ?? [], $secondary['entities'] ?? []);
        $merged['recommended_sources'] = $this->mergeRecommendedSources($primary['recommended_sources'] ?? [], $secondary['recommended_sources'] ?? []);

        $merged['revision'] = max((int) ($primary['revision'] ?? 0), (int) ($secondary['revision'] ?? 0));
        $merged['unchanged'] = !empty($primary['unchanged']) && !empty($secondary['unchanged']);

        $primaryChanges = $primary['changes'] ?? [];
        $secondaryChanges = $secondary['changes'] ?? [];
        if ($this->isEmptyChangeSet($primaryChanges) && !$this->isEmptyChangeSet($secondaryChanges)) {
            $merged['changes'] = $secondaryChanges;
        }

        if (empty($primary['versions']) && !empty($secondary['versions'])) {
            $merged['versions'] = $secondary['versions'];
        }

        $merged['quality_label'] = (string) ($merged['quality_label'] ?? $secondary['quality_label'] ?? '');

        return $merged;
    }

    /**
     * @param mixed $changes
     */
    private function isEmptyChangeSet($changes): bool
    {
        if (!is_array($changes)) {
            return true;
        }

        $summary = (string) ($changes['summary'] ?? '');
        $keywordsAdded = $changes['keywords_added'] ?? [];
        $keywordsRemoved = $changes['keywords_removed'] ?? [];
        $entitiesAdded = $changes['entities_added'] ?? [];
        $entitiesRemoved = $changes['entities_removed'] ?? [];
        $lengthDelta = (int) ($changes['length_delta'] ?? 0);

        return $summary === ''
            && ($keywordsAdded === [] || $keywordsAdded === null)
            && ($keywordsRemoved === [] || $keywordsRemoved === null)
            && ($entitiesAdded === [] || $entitiesAdded === null)
            && ($entitiesRemoved === [] || $entitiesRemoved === null)
            && $lengthDelta === 0;
    }

    /**
     * @param mixed $left
     * @param mixed $right
     * @return array<int, string>
     */
    private function mergeStringLists($left, $right): array
    {
        $values = [];

        if (is_array($left)) {
            foreach ($left as $value) {
                if (!is_string($value)) {
                    continue;
                }
                $trimmed = trim($value);
                if ($trimmed === '') {
                    continue;
                }
                $values[mb_strtolower($trimmed)] = $trimmed;
            }
        }

        if (is_array($right)) {
            foreach ($right as $value) {
                if (!is_string($value)) {
                    continue;
                }
                $trimmed = trim($value);
                if ($trimmed === '') {
                    continue;
                }
                $values[mb_strtolower($trimmed)] = $trimmed;
            }
        }

        return array_values($values);
    }

    /**
     * @param mixed $left
     * @param mixed $right
     * @return array<int, array<string, string>>
     */
    private function mergeEntities($left, $right): array
    {
        $map = [];

        $collect = static function ($list) use (&$map): void {
            if (!is_array($list)) {
                return;
            }

            foreach ($list as $entity) {
                if (!is_array($entity)) {
                    continue;
                }

                $label = (string) ($entity['label'] ?? '');
                if ($label === '') {
                    continue;
                }

                $type = (string) ($entity['type'] ?? '');
                $key = mb_strtolower($label . '|' . $type);
                if (!isset($map[$key])) {
                    $map[$key] = [
                        'label' => $label,
                        'type' => $type,
                    ];
                }
            }
        };

        $collect($left);
        $collect($right);

        return array_values($map);
    }

    /**
     * @param mixed $left
     * @param mixed $right
     * @return array<int, array<string, mixed>>
     */
    private function mergeRecommendedSources($left, $right): array
    {
        $map = [];

        $collect = static function ($list) use (&$map): void {
            if (!is_array($list)) {
                return;
            }

            foreach ($list as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $url = (string) ($entry['url'] ?? '');
                if ($url === '') {
                    continue;
                }

                $key = mb_strtolower($url);
                if (!isset($map[$key])) {
                    $map[$key] = [
                        'url' => $url,
                        'domain' => (string) ($entry['domain'] ?? ''),
                        'trust_score' => (float) ($entry['trust_score'] ?? 0.0),
                    ];
                    continue;
                }

                if ($map[$key]['domain'] === '' && isset($entry['domain'])) {
                    $map[$key]['domain'] = (string) $entry['domain'];
                }

                $map[$key]['trust_score'] = max(
                    (float) ($map[$key]['trust_score'] ?? 0.0),
                    (float) ($entry['trust_score'] ?? 0.0)
                );
            }
        };

        $collect($left);
        $collect($right);

        return array_values($map);
    }

    private function qualityLabelFromScore(float $score): string
    {
        if ($score >= 75.0) {
            return 'High';
        }

        if ($score >= 55.0) {
            return 'Medium';
        }

        if ($score >= 35.0) {
            return 'Low';
        }

        return 'Very low';
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private function normaliseGraphSource(array $source): array
    {
        $url = (string) ($source['url'] ?? '');
        $preview = (string) ($source['preview'] ?? '');
        $title = (string) ($source['title'] ?? $url);
        $fetchedAt = (string) ($source['fetched_at'] ?? $source['verified_at'] ?? '');
        $domain = $this->domainFromUrl($url);
        $siteName = (string) ($source['site_name'] ?? '');
        if ($siteName === '' && $domain !== '') {
            $siteName = $this->humaniseDomain($domain);
        }

        $quality = $this->estimateGraphQualityScore($source);
        $imageUrl = $this->extractImageUrl($source);

        return [
            'title' => $title,
            'summary' => $preview,
            'preview' => $preview,
            'url' => $url,
            'topics' => $this->normaliseStringList($source['topics'] ?? []),
            'entities' => [],
            'fetched_at' => $fetchedAt,
            'last_checked_at' => (string) ($source['verified_at'] ?? $fetchedAt),
            'quality_score' => $quality,
            'quality_label' => $this->qualityLabelFromScore($quality),
            'quality_reasons' => ['Persisted in shared knowledge graph.'],
            'ingest' => true,
            'source_domain' => $domain,
            'source_site_name' => $siteName,
            'source_language' => (string) ($source['language'] ?? ''),
            'source_published_at' => (string) ($source['published_at'] ?? ''),
            'thumbnail' => $imageUrl,
            'image_url' => $imageUrl,
            'meta_description' => (string) ($source['meta_description'] ?? ''),
            'recommended_sources' => [],
            'content_type' => 'article',
            'revision' => 1,
            'normalized_url' => $url,
            'unchanged' => false,
            'changes' => [],
            'versions' => [],
        ];
    }

    /**
     * @param array<string, mixed> $source
     */
    private function estimateGraphQualityScore(array $source): float
    {
        $score = 55.0;

        $status = strtolower((string) ($source['status'] ?? 'active'));
        if ($status === 'active') {
            $score += 8.0;
        }

        $characters = isset($source['characters']) ? (int) $source['characters'] : 0;
        if ($characters >= 3200) {
            $score += 10.0;
        } elseif ($characters >= 1800) {
            $score += 6.0;
        } elseif ($characters >= 900) {
            $score += 3.0;
        }

        $paragraphs = isset($source['paragraphs']) ? (int) $source['paragraphs'] : 0;
        if ($paragraphs >= 12) {
            $score += 6.0;
        } elseif ($paragraphs >= 6) {
            $score += 3.0;
        }

        if (isset($source['preview']) && is_string($source['preview']) && trim($source['preview']) !== '') {
            $score += 4.0;
        }

        if (isset($source['title']) && is_string($source['title']) && trim($source['title']) !== '') {
            $score += 4.0;
        }

        return max(35.0, min(90.0, $score));
    }

    private function domainFromUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return '';
        }

        $host = strtolower($host);
        $host = preg_replace('/^www\d*\./', '', $host) ?? $host;

        return $host;
    }

    private function humaniseDomain(string $domain): string
    {
        $clean = preg_replace('/^www\d*\./', '', $domain) ?? $domain;
        $parts = preg_split('/[\.-]+/', $clean) ?: [];
        if ($parts === []) {
            return $domain;
        }

        $parts = array_map(static function (string $part): string {
            $part = trim($part);

            return $part === '' ? '' : ucfirst($part);
        }, array_slice($parts, 0, 3));

        $parts = array_values(array_filter($parts, static fn(string $part): bool => $part !== ''));

        return $parts === [] ? $domain : implode(' ', $parts);
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

        $contentType = $this->normaliseContentType((string) ($entry['content_type'] ?? 'page'), $entry, $topics);
        $imageUrl = $this->extractImageUrl($entry);

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
            'thumbnail' => $imageUrl,
            'image_url' => $imageUrl,
            'meta_description' => (string) ($entry['meta_description'] ?? ''),
            'recommended_sources' => $recommended,
            'content_type' => $contentType,
            'revision' => (int) ($entry['revision'] ?? 1),
            'normalized_url' => (string) ($entry['normalized_url'] ?? $entry['url'] ?? ''),
            'unchanged' => (bool) ($entry['unchanged'] ?? false),
            'changes' => $this->formatChangeSummary($entry['changes'] ?? null),
            'versions' => $this->formatVersions($entry['versions'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function extractImageUrl(array $entry): string
    {
        $candidates = ['image_url', 'thumbnail', 'image', 'hero_image', 'cover_image', 'og_image', 'meta_image'];

        foreach ($candidates as $field) {
            if (!isset($entry[$field]) || !is_string($entry[$field])) {
                continue;
            }

            $normalised = $this->normaliseImageUrl($entry[$field]);
            if ($normalised !== '') {
                return $normalised;
            }
        }

        return '';
    }

    private function normaliseImageUrl(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        $candidate = trim($value);
        if ($candidate === '' || filter_var($candidate, FILTER_VALIDATE_URL) === false) {
            return '';
        }

        return $candidate;
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<int, string> $topics
     */
    private function normaliseContentType(string $value, array $entry, array $topics): string
    {
        $normalised = trim(mb_strtolower(str_replace(' ', '_', $value)));
        if ($normalised === '' || $normalised === 'unknown') {
            $normalised = 'page';
        }

        $characters = (int) ($entry['character_count'] ?? 0);
        $paragraphs = (int) ($entry['paragraph_count'] ?? 0);
        $summary = (string) ($entry['summary'] ?? $entry['preview'] ?? '');
        $summaryLength = mb_strlen($summary);
        $publishedAt = trim((string) ($entry['source_published_at'] ?? $entry['published_at'] ?? ''));
        $hasMetadata = $publishedAt !== '' || trim((string) ($entry['source_site_name'] ?? '')) !== '';
        $topicCount = count($topics);

        if ($normalised === 'non_article') {
            if ($characters >= 900 || $paragraphs >= 4 || $summaryLength >= 240 || $hasMetadata || $topicCount >= 5) {
                return 'article';
            }

            if ($characters >= 400 || $paragraphs >= 3 || $summaryLength >= 140) {
                return 'page';
            }
        }

        if ($normalised === 'page' && ($characters >= 1400 || $paragraphs >= 6 || $summaryLength >= 360)) {
            return 'article';
        }

        return $normalised === '' ? 'page' : $normalised;
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
