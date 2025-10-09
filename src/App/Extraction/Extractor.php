<?php

declare(strict_types=1);

namespace App\Extraction;

use App\Extraction\DatasetBuilder;
use App\Text\TextRefiner;
use DateTimeImmutable;
use SemanticEngine;

/**
 * High-level orchestration around the SemanticEngine for consistent results.
 */
final class Extractor implements ExtractorInterface
{
    /**
     * Analyse a collection of documents.
     *
     * @param array<int, string> $documents
     * @param array<string, mixed>|null $state Previously exported engine state for incremental ingestion.
     */
    public function analyseMany(array $documents, ?array $state = null): ExtractionResult
    {
        $engine = $state === null ? new SemanticEngine() : SemanticEngine::fromArray($state);
        $refiner = new TextRefiner();

        $processedCount = 0;
        $receivedCount = 0;
        $documentsMeta = [];

        foreach ($documents as $document) {
            if (!is_string($document)) {
                continue;
            }

            $receivedCount++;

            $text = trim($document);
            if ($text === '') {
                continue;
            }

            $analysis = $refiner->analyseDocument($text);
            $documentsMeta[] = $analysis;

            $engine->registerDocumentSignals($this->deriveDocumentSignals($analysis));

            $engine->extractRelations($text);
            $processedCount++;
        }

        return $this->buildResult($engine, $processedCount, $receivedCount, $documentsMeta);
    }

    /**
     * Analyse a single document.
     *
     * @param string $document
     * @param array<string, mixed>|null $state Previously exported engine state for incremental ingestion.
     */
    public function analyse(string $document, ?array $state = null): ExtractionResult
    {
        return $this->analyseMany([$document], $state);
    }

    /**
     * @param array<int, array{
     *     original: string,
     *     cleaned: string,
     *     rewritten: string,
     *     keywords: array<int, array{token: string, count: int}>,
     *     spelling: array<int, array{token: string, count: int, suggestions: array<int, string>}>,
     *     qa: array<int, array{question: string, answer: string, response: string}>,
     *     analytics: array<string, mixed>
     * }> $documents
     */
    private function buildResult(
        SemanticEngine $engine,
        int $processedCount,
        int $receivedCount,
        array $documents
    ): ExtractionResult
    {
        $triples = array_map(
            static fn(array $triple): array => [
                'subject' => (string) ($triple[0] ?? ''),
                'relation' => (string) ($triple[1] ?? ''),
                'object' => (string) ($triple[2] ?? ''),
            ],
            $engine->iterTriples()
        );

        $synonyms = array_map(
            static function (array $pair): array {
                $entity = (string) ($pair[0] ?? '');
                $rawValues = $pair[1] ?? [];
                $synonymValues = [];

                if (is_array($rawValues)) {
                    foreach ($rawValues as $value) {
                        if (!is_string($value)) {
                            continue;
                        }
                        $synonymValues[] = $value;
                    }
                }

                return [
                    'entity' => $entity,
                    'synonyms' => $synonymValues,
                ];
            },
            $engine->iterSynonyms()
        );

        $relationFrequency = [];
        $entityFrequency = [];

        foreach ($triples as $triple) {
            $relation = $triple['relation'];
            $relationFrequency[$relation] = ($relationFrequency[$relation] ?? 0) + 1;

            $subject = $triple['subject'];
            $object = $triple['object'];
            $entityFrequency[$subject] = ($entityFrequency[$subject] ?? 0) + 1;
            $entityFrequency[$object] = ($entityFrequency[$object] ?? 0) + 1;
        }

        foreach ($synonyms as $pair) {
            $entity = $pair['entity'];
            $entityFrequency[$entity] = $entityFrequency[$entity] ?? 0;
            foreach ($pair['synonyms'] as $synonym) {
                $entityFrequency[$synonym] = $entityFrequency[$synonym] ?? 0;
            }
        }

        ksort($relationFrequency);
        ksort($entityFrequency);

        $summary = [
            'documents_received' => $receivedCount,
            'documents_processed' => $processedCount,
            'triples' => count($triples),
            'synonym_groups' => count($synonyms),
            'unique_entities' => count($entityFrequency),
            'generated_at' => (new DateTimeImmutable())->format(DATE_ATOM),
        ];

        $datasetBuilder = new DatasetBuilder();
        $dataset = $datasetBuilder->build($documents, $triples, $synonyms, $summary);

        return new ExtractionResult(
            $triples,
            $synonyms,
            $relationFrequency,
            $entityFrequency,
            $summary,
            $engine->toArray(),
            $documents,
            $engine->buildCrossReferences(),
            $dataset
        );
    }

    /**
     * @param array{original: string, cleaned: string, rewritten: string, keywords: array<int, array{token: string, count: int}>, spelling: array<int, array{token: string, count: int, suggestions: array<int, string>}>} $analysis
     * @return array{uniqueness: float, freshness: float, quality: float, consistency: float}
     */
    private function deriveDocumentSignals(array $analysis): array
    {
        $original = (string) ($analysis['original'] ?? '');
        $cleaned = (string) ($analysis['cleaned'] ?? '');
        $rewritten = (string) ($analysis['rewritten'] ?? '');
        $keywords = $analysis['keywords'] ?? [];
        $spelling = $analysis['spelling'] ?? [];

        $wordCount = $this->countWords($cleaned !== '' ? $cleaned : $original);
        $wordCount = max(1, $wordCount);

        $uniqueKeywordCount = 0;
        $keywordCoverage = 0.0;
        if (is_array($keywords)) {
            $uniqueKeywordCount = count($keywords);
            $keywordCoverage = $this->clampScore($uniqueKeywordCount / max(1, min($wordCount, 60)));
        }

        $uniqueness = $this->clampScore((0.6 * $this->clampScore($uniqueKeywordCount / 12)) + (0.4 * $keywordCoverage));

        $latestYear = $this->detectLatestYear($original !== '' ? $original : $cleaned);
        $freshness = 0.45;
        if ($latestYear !== null) {
            $currentYear = (int) date('Y');
            $delta = max(0, $currentYear - $latestYear);
            $freshness = $this->clampScore(1 - min(1, $delta / 6));
        } else {
            $recentHints = $cleaned !== '' ? strtolower($cleaned) : strtolower($original);
            if ($recentHints !== '') {
                if (preg_match('/\b(today|latest|breaking|newly|recent|update[d]?)\b/u', $recentHints) === 1) {
                    $freshness = 0.6;
                }
            }
        }

        $spellingIssues = 0;
        if (is_array($spelling)) {
            foreach ($spelling as $issue) {
                if (!is_array($issue)) {
                    continue;
                }
                $count = (int) ($issue['count'] ?? 0);
                $spellingIssues += max(0, $count);
            }
        }

        $errorRate = $spellingIssues / $wordCount;
        $quality = $this->clampScore(1 - min(1, $errorRate * 5));

        $originalLength = max(1, mb_strlen($original));
        $cleanedLength = mb_strlen($cleaned);
        if ($cleanedLength > 0) {
            $quality = max($quality, $this->clampScore($cleanedLength / $originalLength));
        }

        $rewrittenLength = mb_strlen($rewritten);
        $structureReference = $rewrittenLength > 0 ? $rewritten : $cleaned;
        $sentenceCount = $this->countSentences($structureReference);
        $sentenceCount = max(1, $sentenceCount);

        $typeTokenRatio = $this->computeTypeTokenRatio($structureReference);
        $consistencyBase = 1 - abs($typeTokenRatio - 0.45);
        $consistencyBase = $this->clampScore($consistencyBase);

        $averageSentenceLength = $wordCount / $sentenceCount;
        $sentenceBalance = $this->clampScore(1 - min(1, abs($averageSentenceLength - 18) / 18));

        $consistency = $this->clampScore((0.5 * $consistencyBase) + (0.5 * $sentenceBalance));

        $analytics = $analysis['analytics'] ?? null;
        if (is_array($analytics)) {
            $factualityScore = $this->readNestedFloat($analytics, ['factuality', 'score']);
            if ($factualityScore !== null) {
                $quality = $this->clampScore(($quality * 0.7) + ($factualityScore * 0.3));
            }

            $certaintyScore = $this->readNestedFloat($analytics, ['narrative', 'certainty', 'score']);
            if ($certaintyScore !== null) {
                $consistency = $this->clampScore(($consistency * 0.65) + ($certaintyScore * 0.35));
            }

            $intentConfidence = $this->readNestedFloat($analytics, ['intent', 'confidence']);
            if ($intentConfidence !== null) {
                $uniqueness = $this->clampScore(($uniqueness * 0.75) + ($intentConfidence * 0.25));
            }

            $sentimentMagnitude = $this->readNestedFloat($analytics, ['sentiment', 'magnitude']);
            if ($sentimentMagnitude !== null) {
                $consistency = $this->clampScore(($consistency * 0.8) + ($sentimentMagnitude * 0.2));
            }

            $conversation = $analytics['conversation']['is_conversational'] ?? null;
            if ($conversation === true) {
                $quality = $this->clampScore($quality + 0.05);
            }
        }

        return [
            'uniqueness' => $uniqueness,
            'freshness' => $freshness,
            'quality' => $quality,
            'consistency' => $consistency,
        ];
    }

    /**
     * @param array<string, mixed> $source
     * @param array<int, string> $path
     */
    private function readNestedFloat(array $source, array $path): ?float
    {
        $value = $source;
        foreach ($path as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        return null;
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

    private function countWords(string $text): int
    {
        $tokens = preg_split('/\s+/u', trim($text));
        if ($tokens === false) {
            return 0;
        }

        $count = 0;
        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }
            $count++;
        }

        return $count;
    }

    private function countSentences(string $text): int
    {
        $normalized = trim($text);
        if ($normalized === '') {
            return 0;
        }

        $segments = preg_split('/(?<=[.!?])\s+/u', $normalized);
        if ($segments === false) {
            return 0;
        }

        $count = 0;
        foreach ($segments as $segment) {
            if (trim($segment) === '') {
                continue;
            }
            $count++;
        }

        return $count;
    }

    private function detectLatestYear(string $text): ?int
    {
        if ($text === '') {
            return null;
        }

        $matches = [];
        $result = preg_match_all('/\b(19|20)\d{2}\b/u', $text, $matches);
        if ($result === false || $result === 0) {
            return null;
        }

        $years = array_map('intval', $matches[0]);
        if ($years === []) {
            return null;
        }

        rsort($years, SORT_NUMERIC);

        return $years[0] ?? null;
    }

    private function computeTypeTokenRatio(string $text): float
    {
        $normalized = strtolower(trim($text));
        if ($normalized === '') {
            return 0.0;
        }

        $tokens = preg_split('/[^a-z0-9]+/u', $normalized);
        if ($tokens === false) {
            return 0.0;
        }

        $total = 0;
        $unique = [];
        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }
            $total++;
            $unique[$token] = true;
        }

        if ($total === 0) {
            return 0.0;
        }

        return count($unique) / $total;
    }
}
