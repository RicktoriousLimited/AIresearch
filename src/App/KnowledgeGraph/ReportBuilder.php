<?php

declare(strict_types=1);

namespace App\KnowledgeGraph;

use App\Text\TextRefiner;
use DateTimeImmutable;

use function array_flip;
use function array_intersect;
use function array_keys;
use function array_map;
use function array_merge;
use function array_slice;
use function array_unique;
use function count;
use function in_array;
use function is_array;
use function is_numeric;
use function is_string;
use function mb_strlen;
use function mb_substr;
use function preg_replace;
use function preg_split;
use function similar_text;
use function sort;
use function str_contains;
use function strtolower;
use function trim;
use function usort;

/**
 * Builds cross-referenced research packets and comparison analytics from the stored knowledge base.
 */
final class ReportBuilder
{
    private const SIMILARITY_TEXT_LIMIT = 6000;

    private const TEXT_SIMILARITY_THRESHOLD = 0.05;

    private GraphRepository $repository;

    private TextRefiner $refiner;

    public function __construct(?GraphRepository $repository = null, ?TextRefiner $refiner = null)
    {
        $this->repository = $repository ?? new GraphRepository();
        $this->refiner = $refiner ?? new TextRefiner();
    }

    /**
     * Score source uniqueness and compute overlaps across the stored corpus.
     *
     * @param array<int, string> $selectors Optional list of URLs to prioritise.
     * @return array{
     *     generated_at: string,
     *     document_count: int,
     *     documents: array<int, array<string, mixed>>,
     *     matrix: array<int, array<int, array{score: float, shared_keywords: array<int, string>}>>
     * }
     */
    public function compareSources(array $selectors = [], int $limit = 12): array
    {
        $sources = $this->loadSources($selectors, $limit);
        $records = $this->analyseSources($sources);

        if ($records === []) {
            return [
                'generated_at' => (new DateTimeImmutable())->format(DATE_ATOM),
                'document_count' => 0,
                'documents' => [],
                'matrix' => [],
            ];
        }

        [$matrix, $uniqueness] = $this->computeSimilarityMatrix($records);

        $documents = [];
        foreach ($records as $index => $record) {
            $identifier = 'S' . ($index + 1);
            $related = [];
            foreach ($matrix[$index] as $candidateIndex => $entry) {
                if ($candidateIndex === $index || $entry['score'] <= 0.0) {
                    continue;
                }

                if ($entry['score'] < 0.15) {
                    continue;
                }

                $candidate = $records[$candidateIndex];
                $related[] = [
                    'id' => 'S' . ($candidateIndex + 1),
                    'title' => $candidate['title'] !== '' ? $candidate['title'] : $candidate['url'],
                    'url' => $candidate['url'],
                    'score' => $this->roundScore($entry['score']),
                    'shared_keywords' => array_slice($entry['shared_keywords'], 0, 6),
                ];
            }

            usort(
                $related,
                static function (array $left, array $right): int {
                    if ($left['score'] === $right['score']) {
                        return $left['title'] <=> $right['title'];
                    }

                    return ($left['score'] < $right['score']) ? 1 : -1;
                }
            );

            $documents[] = [
                'id' => $identifier,
                'title' => $record['title'] !== '' ? $record['title'] : $record['url'],
                'url' => $record['url'],
                'preview' => $record['preview'],
                'characters' => $record['characters'],
                'fetched_at' => $record['fetched_at'],
                'summary' => $this->summariseText($record['summary']),
                'keywords' => array_slice($record['keywords'], 0, 12),
                'analytics' => $record['analytics'],
                'qa' => array_slice($record['qa'], 0, 5),
                'images' => $record['images'],
                'uniqueness' => $this->roundScore($uniqueness[$index] ?? 0.0),
                'related' => array_slice($related, 0, 5),
            ];
        }

        return [
            'generated_at' => (new DateTimeImmutable())->format(DATE_ATOM),
            'document_count' => count($documents),
            'documents' => $documents,
            'matrix' => $matrix,
        ];
    }

    /**
     * Assemble a real-time research brief by cross-referencing stored documents.
     *
     * @param array<int, string> $selectors Optional list of URLs to prioritise.
     * @return array{
     *     query: string,
     *     generated_at: string,
     *     document_count: int,
     *     highlights: array<int, array<string, mixed>>,
     *     combined_summary: array<int, array<string, mixed>>,
     *     topics: array<int, array<string, mixed>>,
     *     citations: array<int, array<string, mixed>>,
     *     related_documents: array<int, array<string, mixed>>
     * }
     */
    public function buildReport(string $query, int $limit = 5, array $selectors = []): array
    {
        $sources = $this->loadSources($selectors, max($limit * 3, 12));
        $records = $this->analyseSources($sources);

        if ($records === []) {
            return [
                'query' => $query,
                'generated_at' => (new DateTimeImmutable())->format(DATE_ATOM),
                'document_count' => 0,
                'highlights' => [],
                'combined_summary' => [],
                'topics' => [],
                'citations' => [],
                'related_documents' => [],
            ];
        }

        [$matrix, $uniqueness] = $this->computeSimilarityMatrix($records);

        $queryTokens = $this->tokeniseQuery($query);

        $highlights = [];
        $matchedIndices = [];
        $matchedRecords = [];
        foreach ($records as $index => $record) {
            $identifier = 'S' . ($index + 1);
            $keywordMatches = $queryTokens === []
                ? count($record['keywords'])
                : count(array_intersect($queryTokens, $record['keywords']));
            $keywordCoverage = $record['keywords'] !== []
                ? $this->clampScore($keywordMatches / max(1, count($record['keywords'])))
                : 0.0;
            $queryScore = $queryTokens === []
                ? 0.5
                : $this->clampScore($keywordMatches / max(1, count($queryTokens)));

            $analytics = $record['analytics'];
            $certainty = 0.5;
            if (isset($analytics['narrative']['certainty']['score']) && is_numeric($analytics['narrative']['certainty']['score'])) {
                $certainty = (float) $analytics['narrative']['certainty']['score'];
            } elseif (isset($analytics['factuality']['score']) && is_numeric($analytics['factuality']['score'])) {
                $certainty = (float) $analytics['factuality']['score'];
            }

            $uniquenessScore = $uniqueness[$index] ?? 0.0;
            $relevance = $this->clampScore(($queryScore * 0.5) + ($keywordCoverage * 0.2) + ($certainty * 0.2) + ($uniquenessScore * 0.1));

            if ($queryTokens !== [] && $keywordMatches === 0) {
                continue;
            }

            $matchedIndices[] = $index;
            $matchedRecords[$index] = $record;

            $highlights[] = [
                'record_index' => $index,
                'id' => $identifier,
                'title' => $record['title'] !== '' ? $record['title'] : $record['url'],
                'summary' => $this->summariseText($record['summary'], 360),
                'relevance' => $this->roundScore($relevance),
                'uniqueness' => $this->roundScore($uniquenessScore),
                'keywords' => array_slice($record['keywords'], 0, 10),
                'analytics' => $analytics,
                'qa' => array_slice($record['qa'], 0, 3),
                'source' => [
                    'url' => $record['url'],
                    'title' => $record['title'],
                    'fetched_at' => $record['fetched_at'],
                ],
                'citations' => [$identifier],
                'images' => $record['images'],
            ];
        }

        usort(
            $highlights,
            static function (array $left, array $right): int {
                if ($left['relevance'] === $right['relevance']) {
                    return $left['title'] <=> $right['title'];
                }

                return ($left['relevance'] < $right['relevance']) ? 1 : -1;
            }
        );

        $highlights = array_slice($highlights, 0, max(1, $limit));

        $highlightedIndices = [];
        foreach ($highlights as $key => $highlight) {
            if (isset($highlight['record_index'])) {
                $highlightedIndices[] = (int) $highlight['record_index'];
                unset($highlights[$key]['record_index']);
            }
        }

        $combined = [];
        foreach ($highlights as $highlight) {
            foreach ($highlight['qa'] as $qa) {
                if (!is_array($qa)) {
                    continue;
                }

                $answer = (string) ($qa['answer'] ?? $qa['response'] ?? '');
                if ($answer === '') {
                    continue;
                }

                $combined[] = [
                    'question' => (string) ($qa['question'] ?? ''),
                    'answer' => $this->summariseText($answer, 200),
                    'citation' => $highlight['citations'][0] ?? $highlight['id'],
                    'source' => $highlight['source'],
                ];

                if (count($combined) >= $limit * 3) {
                    break 2;
                }
            }
        }

        if ($combined === []) {
            foreach ($highlights as $highlight) {
                $combined[] = [
                    'question' => 'Key takeaway',
                    'answer' => $highlight['summary'],
                    'citation' => $highlight['citations'][0] ?? $highlight['id'],
                    'source' => $highlight['source'],
                ];
            }
        }

        $recordsForTopics = [];
        if ($queryTokens === []) {
            $recordsForTopics = $records;
        } elseif ($highlightedIndices !== []) {
            foreach ($highlightedIndices as $recordIndex) {
                if (isset($records[$recordIndex])) {
                    $recordsForTopics[] = $records[$recordIndex];
                }
            }
        } else {
            $recordsForTopics = array_values($matchedRecords);
        }

        $topics = $this->summariseTopics($recordsForTopics);

        $related = [];
        if ($queryTokens === []) {
            $allowedIndices = array_keys($records);
        } elseif ($highlightedIndices !== []) {
            $allowedIndices = $highlightedIndices;
        } else {
            $allowedIndices = $matchedIndices;
        }
        $allowedSet = $allowedIndices !== [] ? array_flip($allowedIndices) : [];
        $matrixSize = count($matrix);
        for ($i = 0; $i < $matrixSize; $i++) {
            if ($allowedSet !== [] && !isset($allowedSet[$i])) {
                continue;
            }

            for ($j = $i + 1; $j < $matrixSize; $j++) {
                if ($allowedSet !== [] && !isset($allowedSet[$j])) {
                    continue;
                }

                $entry = $matrix[$i][$j];
                if ($entry['score'] < 0.2) {
                    continue;
                }

                $related[] = [
                    'source_a' => 'S' . ($i + 1),
                    'source_b' => 'S' . ($j + 1),
                    'score' => $this->roundScore($entry['score']),
                    'shared_keywords' => array_slice($entry['shared_keywords'], 0, 6),
                ];
            }
        }

        usort(
            $related,
            static function (array $left, array $right): int {
                if ($left['score'] === $right['score']) {
                    return $left['source_a'] <=> $right['source_a'];
                }

                return ($left['score'] < $right['score']) ? 1 : -1;
            }
        );

        $citations = [];
        if ($queryTokens === []) {
            $citationIndices = array_keys($records);
        } elseif ($allowedIndices !== []) {
            $citationIndices = $allowedIndices;
        } else {
            $citationIndices = array_keys($matchedRecords);
        }
        foreach ($citationIndices as $index) {
            if (!isset($records[$index])) {
                continue;
            }

            $record = $records[$index];
            $citations[] = [
                'id' => 'S' . ($index + 1),
                'title' => $record['title'] !== '' ? $record['title'] : $record['url'],
                'url' => $record['url'],
                'preview' => $record['preview'],
                'fetched_at' => $record['fetched_at'],
                'images' => $record['images'],
            ];
        }

        return [
            'query' => $query,
            'generated_at' => (new DateTimeImmutable())->format(DATE_ATOM),
            'document_count' => count($matchedRecords),
            'highlights' => $highlights,
            'combined_summary' => $combined,
            'topics' => array_slice($topics, 0, 12),
            'citations' => $citations,
            'related_documents' => array_slice($related, 0, 12),
        ];
    }

    /**
     * @param array<int, string> $selectors
     * @return array<int, array<string, mixed>>
     */
    private function loadSources(array $selectors, int $limit): array
    {
        $payload = $this->repository->load();
        $sources = is_array($payload['sources']) ? array_values($payload['sources']) : [];

        $limit = max(1, $limit);

        if ($selectors !== []) {
            $urls = array_values(array_unique(array_map('strval', $selectors)));
            $indexed = [];
            foreach ($sources as $source) {
                if (!is_array($source)) {
                    continue;
                }

                $url = isset($source['url']) && is_string($source['url']) ? $source['url'] : '';
                if ($url === '' || isset($indexed[$url])) {
                    continue;
                }

                $indexed[$url] = $source;
            }

            $ordered = [];
            $seen = [];
            foreach ($urls as $url) {
                if (!isset($indexed[$url]) || isset($seen[$url])) {
                    continue;
                }

                $ordered[] = $indexed[$url];
                $seen[$url] = true;
            }

            if ($ordered !== []) {
                foreach ($sources as $source) {
                    if (!is_array($source)) {
                        continue;
                    }

                    $url = isset($source['url']) && is_string($source['url']) ? $source['url'] : '';
                    if ($url === '' || isset($seen[$url])) {
                        continue;
                    }

                    $ordered[] = $source;
                    $seen[$url] = true;
                }

                $sources = $ordered;
            }
        }

        return array_slice($sources, 0, $limit);
    }

    /**
     * @param array<int, array<string, mixed>> $sources
     * @return array<int, array<string, mixed>>
     */
    private function analyseSources(array $sources): array
    {
        $records = [];

        foreach ($sources as $source) {
            if (!is_array($source)) {
                continue;
            }

            $content = isset($source['content']) && is_string($source['content']) ? trim($source['content']) : '';
            if ($content === '') {
                continue;
            }

            $analysis = $this->refiner->analyseDocument($content);

            $keywords = [];
            if (isset($analysis['keywords']) && is_array($analysis['keywords'])) {
                foreach ($analysis['keywords'] as $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }

                    $token = isset($entry['token']) && is_string($entry['token']) ? strtolower(trim($entry['token'])) : '';
                    if ($token === '') {
                        continue;
                    }

                    $keywords[] = $token;
                }
            }

            $keywords = array_values(array_unique($keywords));
            sort($keywords);

            $preview = isset($source['preview']) && is_string($source['preview']) ? trim($source['preview']) : '';
            if ($preview === '' && isset($analysis['cleaned']) && is_string($analysis['cleaned'])) {
                $preview = $this->summariseText($analysis['cleaned'], 160);
            }

            $images = [];
            if (isset($source['links']) && is_array($source['links'])) {
                foreach ($source['links'] as $link) {
                    if (!is_string($link)) {
                        continue;
                    }
                    $normalized = trim($link);
                    if ($normalized === '') {
                        continue;
                    }

                    $lower = strtolower($normalized);
                    if (str_contains($lower, '.jpg') || str_contains($lower, '.jpeg') || str_contains($lower, '.png') || str_contains($lower, '.webp')) {
                        $images[] = $normalized;
                    }
                }
            }

            $records[] = [
                'url' => isset($source['url']) && is_string($source['url']) ? $source['url'] : '',
                'title' => isset($source['title']) && is_string($source['title']) ? trim($source['title']) : '',
                'preview' => $preview,
                'characters' => isset($source['characters']) ? (int) $source['characters'] : mb_strlen($content),
                'fetched_at' => isset($source['fetched_at']) && is_string($source['fetched_at']) ? $source['fetched_at'] : null,
                'analysis' => $analysis,
                'similarity_text' => $this->prepareSimilarityText($analysis),
                'summary' => isset($analysis['rewritten']) && is_string($analysis['rewritten']) && trim($analysis['rewritten']) !== ''
                    ? (string) $analysis['rewritten']
                    : ((isset($analysis['cleaned']) && is_string($analysis['cleaned'])) ? (string) $analysis['cleaned'] : ''),
                'keywords' => $keywords,
                'analytics' => isset($analysis['analytics']) && is_array($analysis['analytics']) ? $analysis['analytics'] : [],
                'qa' => isset($analysis['qa']) && is_array($analysis['qa']) ? $analysis['qa'] : [],
                'images' => $images,
            ];
        }

        return $records;
    }

    /**
     * @param array<string, mixed> $analysis
     */
    private function prepareSimilarityText(array $analysis): string
    {
        $segments = [];

        if (isset($analysis['rewritten']) && is_string($analysis['rewritten'])) {
            $rewritten = trim($analysis['rewritten']);
            if ($rewritten !== '') {
                $segments[] = $rewritten;
            }
        }

        if (isset($analysis['cleaned']) && is_string($analysis['cleaned'])) {
            $cleaned = trim($analysis['cleaned']);
            if ($cleaned !== '') {
                $segments[] = $cleaned;
            }
        }

        if ($segments === []) {
            return '';
        }

        $text = trim(implode("\n", $segments));
        if ($text === '') {
            return '';
        }

        $normalized = strtolower($text);
        $normalized = preg_replace('/\s+/u', ' ', $normalized);
        if (is_string($normalized)) {
            $text = trim($normalized);
        }

        if ($text === '') {
            return '';
        }

        if (mb_strlen($text) > self::SIMILARITY_TEXT_LIMIT) {
            $text = mb_substr($text, 0, self::SIMILARITY_TEXT_LIMIT);
        }

        return $text;
    }

    /**
     * @param array<int, array<string, mixed>> $records
     * @return array{array<int, array<int, array{score: float, shared_keywords: array<int, string>}>>, array<int, float>}
     */
    private function computeSimilarityMatrix(array $records): array
    {
        $matrix = [];
        $uniqueness = [];

        $count = count($records);

        for ($i = 0; $i < $count; $i++) {
            $matrix[$i] = [];
            $matrix[$i][$i] = ['score' => 1.0, 'shared_keywords' => $records[$i]['keywords']];
        }

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $left = $records[$i];
                $right = $records[$j];

                $leftKeywords = $left['keywords'];
                $rightKeywords = $right['keywords'];

                $shared = array_values(array_intersect($leftKeywords, $rightKeywords));
                $union = array_values(array_unique(array_merge($leftKeywords, $rightKeywords)));
                $jaccard = $union !== [] ? count($shared) / count($union) : 0.0;

                $textScore = 0.0;
                $leftText = isset($left['similarity_text']) && is_string($left['similarity_text'])
                    ? $left['similarity_text']
                    : '';
                $rightText = isset($right['similarity_text']) && is_string($right['similarity_text'])
                    ? $right['similarity_text']
                    : '';

                if ($leftText !== '' && $rightText !== '' && ($shared !== [] || $jaccard >= self::TEXT_SIMILARITY_THRESHOLD)) {
                    similar_text($leftText, $rightText, $percent);
                    $textScore = $percent / 100.0;
                }

                $score = $this->clampScore(($jaccard * 0.6) + ($textScore * 0.4));

                $matrix[$i][$j] = ['score' => $score, 'shared_keywords' => $shared];
                $matrix[$j][$i] = ['score' => $score, 'shared_keywords' => $shared];
            }
        }

        for ($i = 0; $i < $count; $i++) {
            $row = $matrix[$i];
            $maxSimilarity = 0.0;
            foreach ($row as $j => $entry) {
                if ($i === $j) {
                    continue;
                }
                if ($entry['score'] > $maxSimilarity) {
                    $maxSimilarity = $entry['score'];
                }
            }

            $uniqueness[$i] = $this->clampScore(1 - $maxSimilarity);
        }

        return [$matrix, $uniqueness];
    }

    /**
     * @param array<int, array<string, mixed>> $records
     * @return array<int, array<string, mixed>>
     */
    private function summariseTopics(array $records): array
    {
        $topics = [];

        foreach ($records as $index => $record) {
            foreach (array_slice($record['keywords'], 0, 12) as $keyword) {
                if (!isset($topics[$keyword])) {
                    $topics[$keyword] = [
                        'label' => $keyword,
                        'count' => 0,
                        'citations' => [],
                    ];
                }

                $topics[$keyword]['count']++;
                $citation = 'S' . ($index + 1);
                if (!in_array($citation, $topics[$keyword]['citations'], true)) {
                    $topics[$keyword]['citations'][] = $citation;
                }
            }
        }

        $topicList = array_values($topics);

        usort(
            $topicList,
            static function (array $left, array $right): int {
                if ($left['count'] === $right['count']) {
                    return $left['label'] <=> $right['label'];
                }

                return ($left['count'] < $right['count']) ? 1 : -1;
            }
        );

        return $topicList;
    }

    private function clampScore(float $value): float
    {
        if ($value < 0) {
            return 0.0;
        }

        if ($value > 1) {
            return 1.0;
        }

        return $value;
    }

    private function roundScore(float $value): float
    {
        return round($this->clampScore($value), 4);
    }

    private function summariseText(string $text, int $limit = 240): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        $snippet = mb_substr($text, 0, $limit);
        $parts = preg_split('/(?<=[.!?])\s+/u', $snippet);
        if (is_array($parts) && $parts !== []) {
            $snippet = trim($parts[0]);
        }

        return trim($snippet) . '…';
    }

    /**
     * @return array<int, string>
     */
    private function tokeniseQuery(string $query): array
    {
        $normalized = strtolower(trim($query));
        if ($normalized === '') {
            return [];
        }

        $parts = preg_split('/[^a-z0-9]+/u', $normalized);
        if (!is_array($parts)) {
            return [];
        }

        $tokens = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            $tokens[] = $part;
        }

        return array_values(array_unique($tokens));
    }
}

