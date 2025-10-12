<?php

declare(strict_types=1);

namespace App\News\Ranking;

use function array_filter;
use function array_fill_keys;
use function array_map;
use function array_unique;
use function count;
use function is_string;
use function log;
use function max;
use function mb_strtolower;
use function preg_split;
use function trim;

final class BM25Ranker
{
    private float $k1;

    private float $b;

    /**
     * @var array<int, string>
     */
    private array $terms;

    /**
     * @var array<string, int>
     */
    private array $documentFrequencies;

    private float $averageDocumentLength;

    private int $documentCount;

    private function __construct(
        array $terms,
        array $documentFrequencies,
        float $averageDocumentLength,
        int $documentCount,
        float $k1 = 1.5,
        float $b = 0.75
    ) {
        $this->terms = $terms;
        $this->documentFrequencies = $documentFrequencies;
        $this->averageDocumentLength = $averageDocumentLength;
        $this->documentCount = $documentCount;
        $this->k1 = $k1;
        $this->b = $b;
    }

    /**
     * @param array<int, array<string, mixed>> $documents
     * @param array<int, string> $terms
     * @param callable $textExtractor
     */
    public static function fromDocuments(array $documents, array $terms, callable $textExtractor): self
    {
        $normalisedTerms = array_values(array_unique(array_filter(
            array_map(static fn(string $term): string => trim(mb_strtolower($term, 'UTF-8')), $terms),
            static fn(string $term): bool => $term !== ''
        )));

        if ($normalisedTerms === []) {
            return new self([], [], 0.0, 0);
        }

        $documentCount = 0;
        $lengthSum = 0.0;
        $documentFrequencies = array_fill_keys($normalisedTerms, 0);

        foreach ($documents as $document) {
            if (!is_array($document)) {
                continue;
            }

            $text = $textExtractor($document);
            if (!is_string($text) || trim($text) === '') {
                continue;
            }

            $tokens = self::tokenise($text);
            if ($tokens === []) {
                continue;
            }

            $documentCount++;
            $lengthSum += count($tokens);
            $uniqueTokens = array_fill_keys($tokens, true);

            foreach ($normalisedTerms as $term) {
                if (isset($uniqueTokens[$term])) {
                    $documentFrequencies[$term]++;
                }
            }
        }

        if ($documentCount === 0) {
            return new self($normalisedTerms, $documentFrequencies, 0.0, 0);
        }

        $averageDocumentLength = $lengthSum / $documentCount;

        return new self($normalisedTerms, $documentFrequencies, $averageDocumentLength, $documentCount);
    }

    /**
     * @param array<int, string> $tokens
     * @param array<string, float> $weights
     */
    public function scoreTokens(array $tokens, array $weights = []): float
    {
        if ($tokens === [] || $this->terms === [] || $this->documentCount === 0 || $this->averageDocumentLength <= 0.0) {
            return 0.0;
        }

        $length = count($tokens);
        $frequencies = [];
        foreach ($tokens as $token) {
            $frequencies[$token] = ($frequencies[$token] ?? 0) + 1;
        }

        $score = 0.0;
        foreach ($this->terms as $term) {
            $termFrequency = $frequencies[$term] ?? 0;
            if ($termFrequency === 0) {
                continue;
            }

            $idf = $this->idf($term);
            $weight = $weights[$term] ?? 1.0;
            $numerator = $termFrequency * ($this->k1 + 1.0);
            $denominator = $termFrequency + $this->k1 * (1.0 - $this->b + $this->b * ($length / $this->averageDocumentLength));
            $score += $weight * $idf * ($numerator / max($denominator, 1e-6));
        }

        return $score;
    }

    public function scoreText(string $text, array $weights = []): float
    {
        $tokens = self::tokenise($text);

        return $this->scoreTokens($tokens, $weights);
    }

    /**
     * @return array<int, string>
     */
    public static function tokenise(string $text): array
    {
        $normalised = trim(mb_strtolower($text, 'UTF-8'));
        if ($normalised === '') {
            return [];
        }

        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $normalised) ?: [];
        $tokens = array_filter($tokens, static fn($token): bool => is_string($token) && $token !== '');

        return array_values(array_map(static fn(string $token): string => trim($token), $tokens));
    }

    private function idf(string $term): float
    {
        $frequency = $this->documentFrequencies[$term] ?? 0;
        $frequency = max(0, $frequency);

        $numerator = ($this->documentCount - $frequency + 0.5) / ($frequency + 0.5);

        return log(1.0 + max($numerator, 1e-6));
    }

    /**
     * @return array<int, string>
     */
    public function terms(): array
    {
        return $this->terms;
    }

    public function documentCount(): int
    {
        return $this->documentCount;
    }

    public function averageDocumentLength(): float
    {
        return $this->averageDocumentLength;
    }
}
