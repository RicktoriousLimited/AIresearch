<?php

/**
 * Lightweight English lexicon loader for membership checks.
 */
class EnglishLexicon
{
    /** @var array<string, true> */
    private array $words;

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
}
