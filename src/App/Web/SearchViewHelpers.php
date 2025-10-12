<?php

declare(strict_types=1);

namespace App\Web;

final class SearchViewHelpers
{
    private function __construct()
    {
    }

    public static function normaliseWhitespace(string $text): string
    {
        $collapsed = preg_replace('/[\h\v]+/u', ' ', $text);

        return trim(is_string($collapsed) ? $collapsed : $text);
    }

    /**
     * @return list<string>
     */
    public static function extractQueryTokens(string $query): array
    {
        $parts = preg_split('/\s+/u', trim($query)) ?: [];
        $tokens = [];

        foreach ($parts as $part) {
            $token = trim(mb_strtolower($part));
            if ($token === '' || mb_strlen($token) < 2 || isset($tokens[$token])) {
                continue;
            }

            $tokens[$token] = $token;
        }

        return array_values($tokens);
    }

    public static function relevantSnippet(string $text, string $query = ''): string
    {
        $clean = trim($text);
        if ($clean === '') {
            return '';
        }

        $segments = preg_split('/[\r\n]+/u', $clean) ?: [];
        if ($segments === []) {
            $segments = [$clean];
        }

        $candidates = [];
        foreach ($segments as $segment) {
            $trimmedSegment = trim($segment);
            if ($trimmedSegment === '') {
                continue;
            }

            $sentences = preg_split('/(?<=[.!?])\s+/u', $trimmedSegment) ?: [];
            if ($sentences === []) {
                $sentences = [$trimmedSegment];
            }

            foreach ($sentences as $sentence) {
                $candidate = self::normaliseWhitespace($sentence);
                if ($candidate === '') {
                    continue;
                }
                $candidates[] = $candidate;
            }
        }

        if ($candidates === []) {
            return self::normaliseWhitespace($clean);
        }

        $tokens = self::extractQueryTokens($query);

        $bestCandidate = $candidates[0];
        $bestScore = -INF;

        foreach ($candidates as $candidate) {
            $length = mb_strlen($candidate);
            $score = 0.0;

            if ($length >= 40 && $length <= 320) {
                $score += 2;
            } elseif ($length > 320) {
                $score -= 1;
            } else {
                $score -= 0.5;
            }

            foreach ($tokens as $token) {
                if (mb_stripos($candidate, $token) !== false) {
                    $score += 3;
                }
            }

            if ($tokens === [] && $length > 0) {
                $score += min(1, $length / 160);
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestCandidate = $candidate;
            }
        }

        return $bestCandidate;
    }

    public static function highlightTerms(string $text, string $query = ''): string
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $tokens = self::extractQueryTokens($query);
        if ($tokens === []) {
            return $escaped;
        }

        $patterns = array_map(static fn (string $token): string => preg_quote($token, '/'), $tokens);
        $pattern = '/(' . implode('|', $patterns) . ')/iu';
        $highlighted = preg_replace($pattern, '<mark>$1</mark>', $escaped);

        return is_string($highlighted) ? $highlighted : $escaped;
    }
}
