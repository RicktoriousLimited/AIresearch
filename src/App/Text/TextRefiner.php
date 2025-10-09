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

    /** @var array<string, bool> */
    private static array $boilerplateVocabulary = [
        'accept' => true,
        'accessibility' => true,
        'account' => true,
        'additional' => true,
        'advertisement' => true,
        'advertisements' => true,
        'bitesize' => true,
        'best' => true,
        'bookmark' => true,
        'cookies' => true,
        'contact' => true,
        'copyright' => true,
        'download' => true,
        'elsewhere' => true,
        'email' => true,
        'emails' => true,
        'guidance' => true,
        'help' => true,
        'homepage' => true,
        'iplayer' => true,
        'login' => true,
        'menu' => true,
        'mobile' => true,
        'more' => true,
        'newsletter' => true,
        'notifications' => true,
        'parental' => true,
        'policy' => true,
        'privacy' => true,
        'register' => true,
        'reject' => true,
        'services' => true,
        'sign' => true,
        'skip' => true,
        'smart' => true,
        'sounds' => true,
        'speakers' => true,
        'terms' => true,
        'use' => true,
        'watchlistadd' => true,
        'viewing' => true,
        'weather' => true,
    ];

    /** @var array<string, bool> */
    private static array $boilerplatePhrases = [
        'accept additional cookies' => true,
        'advertisement' => true,
        'advertisements' => true,
        'reject additional cookies' => true,
        'let me choose' => true,
        'bbc homepage' => true,
        'skip to content' => true,
        'accessibility help' => true,
        'sign in' => true,
        'search bbc' => true,
        'search the web' => true,
        'search query' => true,
        'top stories' => true,
        'view comments' => true,
        'related topics' => true,
        'related articles' => true,
        'scroll to previous item' => true,
        'scroll to next item' => true,
        'live page' => true,
        'watch live' => true,
        'return to homepage' => true,
        'back to homepage' => true,
        'bbc news services' => true,
        'on your mobile' => true,
        'on smart speakers' => true,
        'get news alerts' => true,
        'contact bbc news' => true,
        'terms of use' => true,
        'privacy policy' => true,
        'parental guidance' => true,
        'contact the bbc' => true,
        'make an editorial complaint' => true,
        'bbc emails for you' => true,
        'copyright © 2025 bbc' => true,
        'copyright © 2024 bbc' => true,
        'copyright © 2023 bbc' => true,
    ];

    /** @var array<int, string> */
    private static array $boilerplatePatterns = [
        '/^ad\b/i',
        '/^advertisement\b/i',
        '/^advertorial\b/i',
        '/^related\.{2,}$/i',
        '/^watchlistadd\b/i',
        '/^subscribeadd\b/i',
        '/^published\s+\d{1,2}/i',
        '/^updated\s+\d{1,2}/i',
        '/^media caption/i',
        '/^more to explore/i',
        '/^elsewhere on the bbc/i',
        '/^best of the bbc/i',
        '/^bbc news$/i',
        '/^home$/i',
        '/^news$/i',
        '/^sport$/i',
        '/^weather$/i',
        '/^iplayer$/i',
        '/^sounds$/i',
        '/^bitesize$/i',
    ];

    /** @var array<string, bool> */
    private static array $navigationVocabulary = [
        'about' => true,
        'account' => true,
        'advertisement' => true,
        'advertisements' => true,
        'apps' => true,
        'archive' => true,
        'back' => true,
        'careers' => true,
        'categories' => true,
        'contact' => true,
        'deals' => true,
        'events' => true,
        'finance' => true,
        'games' => true,
        'home' => true,
        'homepage' => true,
        'latest' => true,
        'live' => true,
        'login' => true,
        'mail' => true,
        'main' => true,
        'market' => true,
        'markets' => true,
        'menu' => true,
        'more' => true,
        'movies' => true,
        'news' => true,
        'privacy' => true,
        'return' => true,
        'search' => true,
        'settings' => true,
        'shop' => true,
        'skip' => true,
        'signin' => true,
        'sign' => true,
        'sports' => true,
        'subscribe' => true,
        'technology' => true,
        'terms' => true,
        'topics' => true,
        'trending' => true,
        'video' => true,
        'weather' => true,
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

        $text = $this->preNormalizeDocument($text);

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

        $filtered = $this->filterContextualLines($cleanedLines);

        $result = implode("\n", $filtered);
        $result = preg_replace("/\n{3,}/", "\n\n", $result);
        if (!is_string($result)) {
            $result = implode("\n", $filtered);
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
     * @return array{
     *     original: string,
     *     cleaned: string,
     *     rewritten: string,
     *     keywords: array<int, array{token: string, count: int}>,
     *     spelling: array<int, array{token: string, count: int, suggestions: array<int, string>}>,
     *     qa: array<int, array{question: string, answer: string, response: string}>
     * }
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
            'qa' => $this->generateQuestionAnswerPairs($text),
        ];
    }

    /**
     * @return array<int, array{question: string, answer: string, response: string}>
     */
    public function generateQuestionAnswerPairs(string $text, int $limit = 5): array
    {
        $cleaned = $this->cleanDocument($text);
        if ($cleaned === '') {
            return [];
        }

        $sentences = preg_split('/(?<=[.!?])\s+/u', $cleaned);
        if ($sentences === false) {
            $sentences = [$cleaned];
        }

        $normalizedSentences = [];
        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            if ($sentence === '') {
                continue;
            }

            $normalizedSentences[] = $sentence;
        }

        if ($normalizedSentences === []) {
            return [];
        }

        $keywords = $this->extractKeywords($text, $limit * 2);
        $keywordTokens = array_map(
            static fn(array $entry): string => strtolower($entry['token']),
            $keywords
        );

        $pairs = [];
        $usedSentenceIndexes = [];

        foreach ($keywordTokens as $token) {
            foreach ($normalizedSentences as $index => $sentence) {
                if (isset($usedSentenceIndexes[$index])) {
                    continue;
                }

                if (stripos($sentence, $token) === false) {
                    continue;
                }

                $question = sprintf('What does the text say about %s?', $this->formatKeywordForQuestion($token));
                $answer = $this->ensureSentencePunctuation($sentence);

                $pairs[] = [
                    'question' => $question,
                    'answer' => $answer,
                    'response' => $answer,
                ];

                $usedSentenceIndexes[$index] = true;
                if (count($pairs) >= $limit) {
                    return $pairs;
                }

                break;
            }
        }

        if ($pairs === []) {
            $primarySentence = $this->ensureSentencePunctuation($normalizedSentences[0]);
            $pairs[] = [
                'question' => 'What is the main point of the text?',
                'answer' => $primarySentence,
                'response' => $primarySentence,
            ];
        }

        if (count($pairs) < $limit) {
            foreach ($normalizedSentences as $index => $sentence) {
                if (isset($usedSentenceIndexes[$index])) {
                    continue;
                }

                $question = 'What additional detail does the text provide?';
                $answer = $this->ensureSentencePunctuation($sentence);

                $pairs[] = [
                    'question' => $question,
                    'answer' => $answer,
                    'response' => $answer,
                ];

                if (count($pairs) >= $limit) {
                    break;
                }
            }
        }

        return $pairs;
    }

    private function ensureSentencePunctuation(string $sentence): string
    {
        $sentence = trim($sentence);
        if ($sentence === '') {
            return '';
        }

        if (preg_match('/[.!?]$/u', $sentence) === 1) {
            return $sentence;
        }

        return $sentence . '.';
    }

    private function formatKeywordForQuestion(string $keyword): string
    {
        $keyword = trim($keyword);
        if ($keyword === '') {
            return '';
        }

        $firstChar = mb_substr($keyword, 0, 1);
        $rest = mb_substr($keyword, 1);

        return mb_convert_case($firstChar, MB_CASE_TITLE, 'UTF-8') . $rest;
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

    /**
     * @param array<int, string> $lines
     * @return array<int, string>
     */
    private function filterContextualLines(array $lines): array
    {
        $filtered = [];
        $previousWasBlank = true;
        $seen = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                if (!$previousWasBlank) {
                    $filtered[] = '';
                    $previousWasBlank = true;
                }
                continue;
            }

            if ($this->looksLikeBoilerplate($line)) {
                continue;
            }

            if ($this->looksLikeNavigationCluster($line)) {
                continue;
            }

            if ($this->looksLikeMeaninglessText($line)) {
                continue;
            }

            $signature = $this->lineSignature($line);
            if ($signature !== '' && isset($seen[$signature])) {
                continue;
            }

            if ($signature !== '') {
                $seen[$signature] = true;
            }

            $filtered[] = $line;
            $previousWasBlank = false;
        }

        return $filtered;
    }

    private function looksLikeBoilerplate(string $line): bool
    {
        if ($line === '') {
            return true;
        }

        if (preg_match('/[.!?]/u', $line) === 1) {
            return false;
        }

        $normalized = strtolower(trim($line));
        if ($normalized === '') {
            return true;
        }

        foreach (self::$boilerplatePatterns as $pattern) {
            if (preg_match($pattern, $normalized) === 1) {
                return true;
            }
        }

        if (isset(self::$boilerplatePhrases[$normalized])) {
            return true;
        }

        $stripped = preg_replace('/[^a-z0-9\s]+/u', ' ', $normalized);
        if (!is_string($stripped)) {
            $stripped = $normalized;
        }

        $stripped = trim(preg_replace('/\s+/', ' ', $stripped) ?? $stripped);
        if ($stripped === '') {
            return true;
        }

        $tokens = explode(' ', $stripped);
        $tokenCount = 0;
        $boilerplateMatches = 0;
        $navigationMatches = 0;
        $uniqueTokens = [];
        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }

            $tokenCount++;
            if (isset(self::$boilerplateVocabulary[$token])) {
                $boilerplateMatches++;
            }
            if (isset(self::$navigationVocabulary[$token])) {
                $navigationMatches++;
            }
            $uniqueTokens[$token] = true;
        }

        if ($tokenCount === 0) {
            return true;
        }

        $ratio = $boilerplateMatches / $tokenCount;

        if ($ratio >= 0.6 && $tokenCount <= 8) {
            return true;
        }

        if ($tokenCount <= 3 && $ratio >= 0.5) {
            return true;
        }

        if ($navigationMatches >= 2 && $navigationMatches >= ($tokenCount / 2)) {
            return true;
        }

        if (count($uniqueTokens) === 1 && $tokenCount <= 4) {
            return true;
        }

        return false;
    }

    private function looksLikeMeaninglessText(string $line): bool
    {
        $normalized = trim($line);
        if ($normalized === '') {
            return true;
        }

        $normalized = preg_replace('/^[\-*•·]+\s*/u', '', $normalized);
        if (!is_string($normalized)) {
            $normalized = trim($line);
        }

        if ($normalized === '') {
            return true;
        }

        if (preg_match('/^(?:published|updated)\s+(?:at\s+)?\d{1,2}:\d{2}(?:\s*[a-z]{2,4})?$/i', $normalized) === 1) {
            return true;
        }

        if (preg_match('/^(?:published|updated)\s+\d{1,2}\s+(?:minute|minutes|hour|hours|day|days)\s+ago$/i', $normalized) === 1) {
            return true;
        }

        if (preg_match('/^\d{1,3}(?:,\d{3})*\s+(?:viewing|views)\b/i', $normalized) === 1) {
            return true;
        }

        if (preg_match('/^\d{1,2}:\d{2}$/', $normalized) === 1) {
            return true;
        }

        if (preg_match('/^scroll to (?:previous|next) item/i', $normalized) === 1) {
            return true;
        }

        if (preg_match('/[a-z]/i', $normalized) !== 1) {
            return true;
        }

        if (preg_match_all('/[A-Za-z][A-Za-z\']*/u', $normalized, $matches) === 0) {
            return true;
        }

        if (preg_match('/https?:\/\//i', $normalized) === 1) {
            return true;
        }

        $tokens = $matches[0] ?? [];
        $candidateCount = 0;
        $recognized = 0;
        $capitalized = 0;
        $allCapitalized = true;
        $stopwordCount = 0;
        $navigationMatches = 0;
        $lowerTokens = [];

        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }

            $candidateCount++;

            $lower = strtolower($token);
            if (isset(self::$stopwords[$lower])) {
                $stopwordCount++;
            }
            if ($this->lexicon->contains($lower)) {
                $recognized++;
                continue;
            }

            if (preg_match('/^[A-Z][A-Za-z\']*$/u', $token) === 1 || preg_match('/^[A-Z]{2,}$/u', $token) === 1) {
                $capitalized++;
            } else {
                $allCapitalized = false;
            }

            if (isset(self::$navigationVocabulary[$lower])) {
                $navigationMatches++;
            }

            $lowerTokens[$lower] = true;
        }

        if ($candidateCount === 0) {
            return true;
        }

        if ($recognized > 0) {
            return false;
        }

        if (count($lowerTokens) === 1 && $candidateCount <= 4) {
            return true;
        }

        if ($navigationMatches >= 2 && $navigationMatches >= ($candidateCount / 2)) {
            return true;
        }

        if ($candidateCount === $capitalized && $capitalized > 0) {
            return false;
        }

        if ($capitalized >= 2 && $allCapitalized) {
            return false;
        }

        if ($this->containsBasicSentenceStructure($normalized, $candidateCount, $stopwordCount, $capitalized)) {
            return false;
        }

        return true;
    }

    private function containsBasicSentenceStructure(string $line, int $tokenCount, int $stopwordCount, int $capitalizedTokenCount): bool
    {
        if ($tokenCount < 3) {
            return false;
        }

        $lowerLine = strtolower($line);
        $hasVerb = preg_match(
            '/\b(?:am|is|are|was|were|be|been|being|has|have|had|do|does|did|can|could|will|would|shall|should|may|might|must|[a-z]{3,}(?:ing|ed))\b/u',
            $lowerLine
        ) === 1;

        if (!$hasVerb) {
            return false;
        }

        if ($stopwordCount >= 2) {
            return true;
        }

        if ($stopwordCount >= 1 && $capitalizedTokenCount >= 1) {
            return true;
        }

        if (
            $stopwordCount >= 1
            && preg_match('/\b(?:[a-z]*[0-9][a-z0-9]*)\b/iu', $line) === 1
        ) {
            return true;
        }

        return false;
    }

    private function looksLikeNavigationCluster(string $line): bool
    {
        $normalized = strtolower(trim($line));
        if ($normalized === '') {
            return true;
        }

        if (preg_match('/\d/', $normalized) === 1) {
            return false;
        }

        if (preg_match('/[.!?]/', $normalized) === 1) {
            return false;
        }

        $tokens = preg_split('/\s+/u', $normalized);
        if ($tokens === false) {
            $tokens = [$normalized];
        }

        $tokenCount = 0;
        $navigationMatches = 0;
        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }

            $tokenCount++;
            if (isset(self::$navigationVocabulary[$token])) {
                $navigationMatches++;
            }
        }

        if ($tokenCount === 0) {
            return true;
        }

        if ($navigationMatches >= 2 && $navigationMatches >= ($tokenCount / 2)) {
            return true;
        }

        if ($tokenCount <= 5 && $navigationMatches >= 2) {
            return true;
        }

        return false;
    }

    private function lineSignature(string $line): string
    {
        $normalized = strtolower(trim(preg_replace('/\s+/u', ' ', $line) ?? ''));
        if ($normalized === '') {
            return '';
        }

        $normalized = preg_replace('/[^a-z0-9]+/u', '', $normalized);
        if (!is_string($normalized)) {
            return '';
        }

        if (strlen($normalized) > 48) {
            return '';
        }

        return $normalized;
    }

    private function preNormalizeDocument(string $text): string
    {
        $text = str_replace(["\u{00A0}", "\u{200B}", "\u{200C}", "\u{200D}"], ' ', $text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $text = preg_replace('/<\s*br\s*\/?>/i', "\n", $text);
        $text = preg_replace('/<\s*\/p\s*>/i', "\n\n", $text);
        $text = preg_replace('/<\s*(?:div|section|article|header|footer|nav)\b[^>]*>/i', "\n", $text);
        $text = preg_replace('/<\s*li\b[^>]*>/i', "\n- ", $text);
        $text = preg_replace('/<\s*\/li\s*>/i', '', $text);
        $text = preg_replace('/<\/?(?:span|strong|em|b|i|u|small|sup|sub)\b[^>]*>/i', '', $text);

        $text = strip_tags(is_string($text) ? $text : '');
        $text = preg_replace('/\r\n?/', "\n", $text);

        return is_string($text) ? $text : '';
    }
}
