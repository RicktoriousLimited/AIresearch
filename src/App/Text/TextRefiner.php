<?php

declare(strict_types=1);

namespace App\Text;

use EnglishLexicon;

require_once __DIR__ . '/../../EnglishLexicon.php';

/**
 * Utility helpers for transforming unstructured text into structured signals.
 */
final class TextRefiner
{
    private EnglishLexicon $lexicon;

    /** @var array<string, bool> */
    private static array $stopwords = [
        'a' => true,
        'an' => true,
        'and' => true,
        'are' => true,
        'as' => true,
        'at' => true,
        'be' => true,
        'but' => true,
        'by' => true,
        'for' => true,
        'from' => true,
        'has' => true,
        'have' => true,
        'in' => true,
        'is' => true,
        'it' => true,
        'its' => true,
        'of' => true,
        'on' => true,
        'or' => true,
        'that' => true,
        'the' => true,
        'their' => true,
        'this' => true,
        'to' => true,
        'was' => true,
        'were' => true,
        'with' => true,
    ];

    public function __construct(?EnglishLexicon $lexicon = null)
    {
        $this->lexicon = $lexicon ?? EnglishLexicon::loadDefault();
    }

    public function cleanDocument(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $lines = preg_split('/\r\n|\r|\n/', $text);
        if ($lines === false) {
            $lines = [$text];
        }

        $cleanedLines = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                $cleanedLines[] = '';
                continue;
            }

            $normalized = preg_replace('/\s+/', ' ', $trimmed);
            if (!is_string($normalized)) {
                $normalized = $trimmed;
            }

            $normalized = preg_replace('/^[\-*•·]+\s*/u', '- ', $normalized);
            if (!is_string($normalized)) {
                $normalized = $trimmed;
            }

            $cleanedLines[] = $normalized;
        }

        $result = implode("\n", $cleanedLines);
        $result = preg_replace("/\n{3,}/", "\n\n", $result);
        if (!is_string($result)) {
            $result = implode("\n", $cleanedLines);
        }

        return trim($result);
    }

    public function rewriteDocument(string $text): string
    {
        $cleaned = $this->cleanDocument($text);
        if ($cleaned === '') {
            return '';
        }

        $paragraphs = preg_split('/\n\s*\n/', $cleaned);
        if ($paragraphs === false) {
            $paragraphs = [$cleaned];
        }

        $rewritten = [];
        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }

            $lines = preg_split('/\n/', $paragraph);
            if ($lines === false) {
                $lines = [$paragraph];
            }

            $isList = true;
            foreach ($lines as $line) {
                if (strpos(ltrim($line), '- ') !== 0) {
                    $isList = false;
                    break;
                }
            }

            if ($isList) {
                $items = [];
                foreach ($lines as $line) {
                    $content = trim(mb_substr(ltrim($line), 2));
                    if ($content === '') {
                        continue;
                    }
                    $items[] = '- ' . $this->capitaliseSentence($content, false);
                }
                if ($items !== []) {
                    $rewritten[] = implode("\n", $items);
                }
                continue;
            }

            $sentences = preg_split('/(?<=[.!?])\s+/u', $paragraph);
            if ($sentences === false) {
                $sentences = [$paragraph];
            }

            $normalizedSentences = [];
            foreach ($sentences as $sentence) {
                $sentence = trim($sentence, " \t\n\r\0\x0B-•·");
                if ($sentence === '') {
                    continue;
                }

                $normalizedSentences[] = $this->capitaliseSentence($sentence, true);
            }

            if ($normalizedSentences !== []) {
                $rewritten[] = implode(' ', $normalizedSentences);
            }
        }

        return implode("\n\n", $rewritten);
    }

    /**
     * @return array<int, array{token: string, count: int}>
     */
    public function extractKeywords(string $text, int $limit = 10): array
    {
        $cleaned = strtolower($this->cleanDocument($text));
        if ($cleaned === '') {
            return [];
        }

        $tokens = preg_split('/[^a-z0-9\']+/i', $cleaned);
        if ($tokens === false) {
            $tokens = [];
        }

        $counts = [];
        foreach ($tokens as $token) {
            if (!is_string($token)) {
                continue;
            }

            $normalized = strtolower($token);
            if ($normalized === '' || is_numeric($normalized)) {
                continue;
            }

            if (strlen($normalized) < 3) {
                continue;
            }

            if (isset(self::$stopwords[$normalized])) {
                continue;
            }

            if (!$this->lexicon->contains($normalized) && !$this->looksLikeName($normalized)) {
                continue;
            }

            if (!isset($counts[$normalized])) {
                $counts[$normalized] = 0;
            }
            $counts[$normalized]++;
        }

        if ($counts === []) {
            return [];
        }

        arsort($counts, SORT_NUMERIC);

        $keywords = [];
        foreach ($counts as $token => $count) {
            $keywords[] = ['token' => $token, 'count' => $count];
            if (count($keywords) >= $limit) {
                break;
            }
        }

        return $keywords;
    }

    /**
     * @return array<int, array{token: string, count: int, suggestions: array<int, string>}>
     */
    public function spellCheck(string $text, int $maxSuggestions = 3): array
    {
        $tokens = preg_split('/[^A-Za-z\']+/u', $text);
        if ($tokens === false) {
            $tokens = [];
        }

        $counts = [];
        foreach ($tokens as $token) {
            if (!is_string($token)) {
                continue;
            }

            $normalized = strtolower($token);
            if ($normalized === '' || strlen($normalized) < 3) {
                continue;
            }

            if ($this->lexicon->contains($normalized)) {
                continue;
            }

            if (!isset($counts[$normalized])) {
                $counts[$normalized] = 0;
            }
            $counts[$normalized]++;
        }

        if ($counts === []) {
            return [];
        }

        $results = [];
        foreach ($counts as $token => $count) {
            $suggestions = $this->lexicon->suggest($token, $maxSuggestions);
            $results[] = [
                'token' => $token,
                'count' => $count,
                'suggestions' => $suggestions,
            ];
        }

        usort(
            $results,
            static function (array $left, array $right): int {
                if ($left['count'] === $right['count']) {
                    return $left['token'] <=> $right['token'];
                }

                return ($left['count'] < $right['count']) ? 1 : -1;
            }
        );

        return $results;
    }

    /**
     * @return array{original: string, cleaned: string, rewritten: string, keywords: array<int, array{token: string, count: int}>, spelling: array<int, array{token: string, count: int, suggestions: array<int, string>}>}
     */
    public function analyseDocument(string $text): array
    {
        $cleaned = $this->cleanDocument($text);
        $rewritten = $this->rewriteDocument($text);

        return [
            'original' => $text,
            'cleaned' => $cleaned,
            'rewritten' => $rewritten,
            'keywords' => $this->extractKeywords($text),
            'spelling' => $this->spellCheck($text),
        ];
    }

    private function capitaliseSentence(string $sentence, bool $ensurePunctuation): string
    {
        $sentence = trim($sentence);
        if ($sentence === '') {
            return '';
        }

        $sentence = mb_convert_case(mb_substr($sentence, 0, 1), MB_CASE_TITLE, 'UTF-8') . mb_substr($sentence, 1);

        if ($ensurePunctuation) {
            $lastChar = mb_substr($sentence, -1);
            if ($lastChar !== '.' && $lastChar !== '!' && $lastChar !== '?') {
                $sentence .= '.';
            }
        }

        return $sentence;
    }

    private function looksLikeName(string $token): bool
    {
        return preg_match('/^[a-z]+$/', $token) === 1 && strlen($token) >= 3;
    }
}
