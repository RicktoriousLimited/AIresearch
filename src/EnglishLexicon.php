<?php

/**
 * Lightweight English lexicon loader for membership checks.
 */
class EnglishLexicon
{
    /** @var array<string, true> */
    private array $words;

    /** @var array<int, string> */
    private static array $alphabet = [
        'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm',
        'n', 'o', 'p', 'q', 'r', 's', 't', 'u', 'v', 'w', 'x', 'y', 'z',
    ];

    /**
     * @param array<string, true> $words
     */
    private function __construct(array $words)
    {
        $this->words = $words;
    }

    public static function loadDefault(): self
    {
        static $default = null;
        if ($default instanceof self) {
            return $default;
        }

        $path = __DIR__ . '/../resources/lexicon/english_words.txt';
        if (!is_file($path)) {
            throw new RuntimeException('Default English lexicon file missing: ' . $path);
        }

        $default = self::fromFile($path);
        return $default;
    }

    public static function fromFile(string $path): self
    {
        if (!is_readable($path)) {
            throw new RuntimeException('Unable to read lexicon file: ' . $path);
        }

        $words = [];
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Failed to open lexicon file: ' . $path);
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $word = strtolower(trim($line));
                if ($word === '') {
                    continue;
                }

                $words[$word] = true;
            }
        } finally {
            fclose($handle);
        }

        return new self($words);
    }

    public function contains(string $word): bool
    {
        $normalized = strtolower(trim($word));
        if ($normalized === '') {
            return false;
        }

        return isset($this->words[$normalized]);
    }

    public function size(): int
    {
        return count($this->words);
    }

    /**
     * Suggest likely corrections for an unknown token using edit distance heuristics.
     *
     * @return array<int, string>
     */
    public function suggest(string $word, int $limit = 5): array
    {
        $normalized = strtolower(trim($word));
        if ($normalized === '') {
            return [];
        }

        if (isset($this->words[$normalized])) {
            return [];
        }

        $candidates = [];

        $editsOne = $this->generateEdits($normalized);
        foreach ($editsOne as $candidate) {
            if (isset($this->words[$candidate])) {
                $distance = levenshtein($normalized, $candidate);
                $candidates[$candidate] = $this->scoreCandidate($normalized, $candidate, $distance);
            }

            if (count($candidates) >= $limit * 3) {
                break;
            }
        }

        if (count($candidates) < $limit) {
            $iterations = 0;
            foreach ($editsOne as $edit) {
                foreach ($this->generateEdits($edit) as $candidate) {
                    if (isset($candidates[$candidate])) {
                        continue;
                    }

                    if (isset($this->words[$candidate])) {
                        $distance = levenshtein($normalized, $candidate);
                        $candidates[$candidate] = $this->scoreCandidate($normalized, $candidate, $distance);
                    }

                    $iterations++;
                    if ($iterations >= 4000 || count($candidates) >= $limit * 3) {
                        break 2;
                    }
                }
            }
        }

        if ($candidates === []) {
            return [];
        }

        asort($candidates, SORT_NUMERIC);

        return array_slice(array_keys($candidates), 0, $limit);
    }

    /**
     * @return array<int, string>
     */
    private function generateEdits(string $word): array
    {
        $splits = [];
        $length = strlen($word);
        for ($i = 0; $i <= $length; $i++) {
            $splits[] = [substr($word, 0, $i), substr($word, $i)];
        }

        $results = [];

        foreach ($splits as [$left, $right]) {
            if (!is_string($left) || !is_string($right)) {
                continue;
            }

            if ($right !== '') {
                $results[$left . substr($right, 1)] = true;
            }

            if (strlen($right) > 1) {
                $results[$left . $right[1] . $right[0] . substr($right, 2)] = true;
            }

            if ($right !== '') {
                $tail = substr($right, 1);
                foreach (self::$alphabet as $letter) {
                    $results[$left . $letter . $tail] = true;
                }
            }

            foreach (self::$alphabet as $letter) {
                $results[$left . $letter . $right] = true;
            }
        }

        return array_keys($results);
    }

    private function scoreCandidate(string $original, string $candidate, int $distance): float
    {
        $score = (float) $distance;

        if ($original !== '' && $candidate !== '' && $original[0] !== $candidate[0]) {
            $score += 0.1;
        }

        if (strlen($original) >= 2) {
            $prefix = substr($original, 0, 2);
            if ($prefix !== '' && strpos($candidate, $prefix) !== 0) {
                $score += 0.05;
            }
        }

        $score += abs(strlen($candidate) - strlen($original)) * 0.01;

        return $score;
    }
}
