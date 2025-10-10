<?php

declare(strict_types=1);

namespace App\Markets;

use App\KnowledgeGraph\GraphRepository;
use Ricktorious\Markets\Realtime\RealtimeMarketClient;

use function array_map;
use function array_merge;
use function array_slice;
use function array_sum;
use function array_unique;
use function array_values;
use function count;
use function file_get_contents;
use function is_array;
use function is_file;
use function is_string;
use function json_decode;
use function max;
use function min;
use function preg_match;
use function preg_quote;
use function str_contains;
use function strtolower;
use function strtoupper;
use function trim;
use function usort;

final class MarketIntelligenceBuilder
{
    private GraphRepository $graphRepository;

    public function __construct(
        private RealtimeMarketClient $marketClient,
        private string $crawlerHistoryPath,
        ?GraphRepository $graphRepository = null
    ) {
        $this->graphRepository = $graphRepository ?? new GraphRepository();
    }

    /**
     * @param array<int, string>|null $symbols
     * @return array<string, mixed>
     */
    public function dashboard(?array $symbols = null): array
    {
        $payload = $this->marketClient->dashboard($symbols);

        $entries = isset($payload['entries']) && is_array($payload['entries']) ? $payload['entries'] : [];
        if ($entries === []) {
            return $payload;
        }

        $symbolUniverse = [];
        $companyNames = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $symbol = strtoupper((string) ($entry['symbol'] ?? ''));
            if ($symbol === '') {
                continue;
            }
            $symbolUniverse[$symbol] = true;
            $companyNames[$symbol] = strtolower((string) ($entry['company']['name'] ?? ''));
        }

        if ($symbolUniverse === []) {
            return $payload;
        }

        $historyArticles = $this->buildArticlesFromCrawlerHistory(array_keys($symbolUniverse), $companyNames);
        if ($historyArticles === []) {
            // fall back to knowledge graph sources when no crawler history is available
            $historyArticles = $this->buildArticlesFromGraph(array_keys($symbolUniverse), $companyNames);
        }

        if ($historyArticles === []) {
            return $payload;
        }

        $indexed = $this->indexArticlesBySymbol($historyArticles);
        $payload = $this->enrichPayload($payload, $indexed);

        return $payload;
    }

    /**
     * @param array<int, string> $symbols
     * @param array<string, string> $companyNames
     * @return array<int, array<string, mixed>>
     */
    private function buildArticlesFromCrawlerHistory(array $symbols, array $companyNames): array
    {
        if ($this->crawlerHistoryPath === '' || !is_file($this->crawlerHistoryPath)) {
            return [];
        }

        $contents = file_get_contents($this->crawlerHistoryPath);
        if (!is_string($contents) || trim($contents) === '') {
            return [];
        }

        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            return [];
        }

        $articles = [];
        foreach ($decoded as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $contentType = isset($entry['content_type']) ? (string) $entry['content_type'] : 'article';
            if ($contentType !== 'article') {
                continue;
            }

            $matches = $this->matchSymbols($entry, $symbols, $companyNames);
            if ($matches === []) {
                continue;
            }

            $articles[] = $this->formatArticle($entry, $matches);
        }

        return $articles;
    }

    /**
     * @param array<int, string> $symbols
     * @param array<string, string> $companyNames
     * @return array<int, array<string, mixed>>
     */
    private function buildArticlesFromGraph(array $symbols, array $companyNames): array
    {
        $payload = $this->graphRepository->load();
        $sources = isset($payload['sources']) && is_array($payload['sources']) ? $payload['sources'] : [];
        if ($sources === []) {
            return [];
        }

        $articles = [];
        foreach ($sources as $source) {
            if (!is_array($source)) {
                continue;
            }

            $matches = $this->matchSymbols($source, $symbols, $companyNames);
            if ($matches === []) {
                continue;
            }

            $articles[] = [
                'symbols' => $matches,
                'title' => (string) ($source['title'] ?? ''),
                'summary' => (string) ($source['preview'] ?? ''),
                'url' => (string) ($source['url'] ?? ''),
                'source' => (string) ($source['site_name'] ?? ''),
                'published_at' => (string) ($source['fetched_at'] ?? ''),
                'sentiment_score' => 0.0,
                'sentiment_label' => 'neutral',
                'relevance_score' => 0.15,
                'topics' => [],
                'symbol' => null,
            ];
        }

        return $articles;
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<int, string> $symbols
     * @param array<string, string> $companyNames
     * @return array<int, string>
     */
    private function matchSymbols(array $entry, array $symbols, array $companyNames): array
    {
        $textFields = [];
        foreach (['title', 'summary', 'preview', 'meta_description', 'site_name'] as $field) {
            if (isset($entry[$field]) && is_string($entry[$field]) && $entry[$field] !== '') {
                $textFields[] = (string) $entry[$field];
            }
        }

        $keywords = [];
        if (isset($entry['keywords']) && is_array($entry['keywords'])) {
            foreach ($entry['keywords'] as $keyword) {
                if (!is_array($keyword)) {
                    continue;
                }
                $token = (string) ($keyword['token'] ?? '');
                if ($token !== '') {
                    $keywords[] = $token;
                }
            }
        }

        $entities = [];
        if (isset($entry['entities']) && is_array($entry['entities'])) {
            foreach ($entry['entities'] as $entity) {
                if (!is_array($entity)) {
                    continue;
                }
                $label = (string) ($entity['label'] ?? '');
                if ($label !== '') {
                    $entities[] = $label;
                }
            }
        }

        $matches = [];
        foreach ($symbols as $symbol) {
            $symbolUpper = strtoupper($symbol);
            $companyName = strtolower($companyNames[$symbol] ?? '');

            if ($this->symbolAppearsInText($symbolUpper, $textFields)) {
                $matches[] = $symbolUpper;
                continue;
            }

            if ($companyName !== '' && $this->companyAppearsInText($companyName, $textFields)) {
                $matches[] = $symbolUpper;
                continue;
            }

            if ($this->symbolAppearsInTokens($symbolUpper, $keywords, $entities)) {
                $matches[] = $symbolUpper;
            }
        }

        return array_values(array_unique($matches));
    }

    /**
     * @param array<int, string> $fields
     */
    private function symbolAppearsInText(string $symbol, array $fields): bool
    {
        foreach ($fields as $field) {
            $upper = strtoupper($field);
            if (preg_match('/\\b' . preg_quote($symbol, '/') . '\\b/u', $upper) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, string> $fields
     */
    private function companyAppearsInText(string $company, array $fields): bool
    {
        foreach ($fields as $field) {
            if ($company !== '' && str_contains(strtolower($field), $company)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, string> $keywords
     * @param array<int, string> $entities
     */
    private function symbolAppearsInTokens(string $symbol, array $keywords, array $entities): bool
    {
        foreach ($keywords as $token) {
            if (strtoupper($token) === $symbol) {
                return true;
            }
        }

        foreach ($entities as $label) {
            $upper = strtoupper($label);
            if (preg_match('/\\b' . preg_quote($symbol, '/') . '\\b/u', $upper) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<int, string> $symbols
     * @return array<string, mixed>
     */
    private function formatArticle(array $entry, array $symbols): array
    {
        $summary = (string) ($entry['summary'] ?? '');
        if ($summary === '') {
            $summary = (string) ($entry['preview'] ?? '');
        }

        $narrative = isset($entry['narrative']) && is_array($entry['narrative']) ? $entry['narrative'] : [];
        $sentiment = isset($narrative['sentiment']) && is_array($narrative['sentiment']) ? $narrative['sentiment'] : [];
        $score = isset($sentiment['score']) ? (float) $sentiment['score'] : 0.0;

        $topics = [];
        if (isset($entry['topics']) && is_array($entry['topics'])) {
            foreach ($entry['topics'] as $topic) {
                if (is_string($topic) && $topic !== '') {
                    $topics[] = $topic;
                }
            }
        }
        if (isset($narrative['topics']) && is_array($narrative['topics'])) {
            $focus = $narrative['topics']['focus'] ?? [];
            if (is_array($focus)) {
                foreach ($focus as $topic) {
                    if (is_string($topic) && $topic !== '') {
                        $topics[] = $topic;
                    }
                }
            }
        }

        if ($topics !== []) {
            $topics = array_values(array_unique($topics));
        }

        $qualityScore = isset($entry['quality_score']) ? (float) $entry['quality_score'] : 0.0;
        $relevance = $qualityScore > 0 ? min(1.0, max(0.0, $qualityScore / 100)) : 0.2;

        $publishedAt = (string) ($entry['source_published_at'] ?? $entry['published_at'] ?? $entry['fetched_at'] ?? '');

        return [
            'symbols' => $symbols,
            'title' => (string) ($entry['title'] ?? ''),
            'summary' => $summary,
            'url' => (string) ($entry['url'] ?? ''),
            'source' => (string) ($entry['site_name'] ?? $entry['source_domain'] ?? ''),
            'published_at' => $publishedAt,
            'sentiment_score' => $score,
            'sentiment_label' => $this->labelForAverage($score),
            'relevance_score' => $relevance,
            'topics' => $topics,
            'fetched_at' => (string) ($entry['fetched_at'] ?? ''),
            'quality_label' => (string) ($entry['quality_label'] ?? ''),
            'content_type' => (string) ($entry['content_type'] ?? 'article'),
            'revision' => (int) ($entry['revision'] ?? 1),
            'last_checked_at' => (string) ($entry['last_checked_at'] ?? $entry['fetched_at'] ?? ''),
            'changes' => is_array($entry['changes'] ?? null) ? $entry['changes'] : [],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $articles
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function indexArticlesBySymbol(array $articles): array
    {
        $indexed = [];
        foreach ($articles as $article) {
            if (!isset($article['symbols']) || !is_array($article['symbols'])) {
                continue;
            }
            foreach ($article['symbols'] as $symbol) {
                $symbolUpper = strtoupper((string) $symbol);
                if ($symbolUpper === '') {
                    continue;
                }
                $clone = $article;
                $clone['symbol'] = $symbolUpper;
                $indexed[$symbolUpper][] = $clone;
            }
        }

        return $indexed;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, array<int, array<string, mixed>>> $indexed
     * @return array<string, mixed>
     */
    private function enrichPayload(array $payload, array $indexed): array
    {
        $entries = isset($payload['entries']) && is_array($payload['entries']) ? $payload['entries'] : [];
        if ($entries === []) {
            return $payload;
        }

        $totalSentiment = 0.0;
        $bullish = 0;
        $bearish = 0;
        $neutral = 0;

        foreach ($entries as $i => $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $symbol = strtoupper((string) ($entry['symbol'] ?? ''));
            if ($symbol === '' || !isset($indexed[$symbol])) {
                $label = (string) ($entry['sentiment']['label'] ?? 'neutral');
                $totalSentiment += (float) ($entry['sentiment']['average_score'] ?? 0.0);
                $this->tallyLabel($label, $bullish, $bearish, $neutral);
                continue;
            }

            $articles = $indexed[$symbol];
            usort($articles, static function (array $a, array $b): int {
                $scoreA = (float) ($a['sentiment_score'] ?? 0.0);
                $scoreB = (float) ($b['sentiment_score'] ?? 0.0);
                $relA = (float) ($a['relevance_score'] ?? 0.0);
                $relB = (float) ($b['relevance_score'] ?? 0.0);

                return ($scoreB + $relB) <=> ($scoreA + $relA);
            });

            $existingArticles = $this->normaliseArticles($entry['sentiment']['articles'] ?? []);
            $mergedArticles = array_merge($articles, $existingArticles);
            $entries[$i]['sentiment']['articles'] = $mergedArticles;
            $entries[$i]['sentiment']['article_count'] = count($mergedArticles);

            $scores = array_map(
                static fn(array $article): float => isset($article['sentiment_score']) ? (float) $article['sentiment_score'] : 0.0,
                $mergedArticles
            );

            if ($scores !== []) {
                $average = array_sum($scores) / count($scores);
                $entries[$i]['sentiment']['average_score'] = $average;
                $entries[$i]['sentiment']['latest_score'] = $scores[0];
                $entries[$i]['sentiment']['previous_score'] = $scores[1] ?? $scores[0];
                $entries[$i]['sentiment']['momentum'] = $entries[$i]['sentiment']['latest_score'] - $entries[$i]['sentiment']['previous_score'];
                $entries[$i]['sentiment']['label'] = $this->labelForAverage($average);
            }

            $entries[$i]['sentiment']['topics'] = $this->mergeTopics($entry['sentiment']['topics'] ?? [], $articles);

            $totalSentiment += (float) ($entries[$i]['sentiment']['average_score'] ?? 0.0);
            $this->tallyLabel((string) ($entries[$i]['sentiment']['label'] ?? 'neutral'), $bullish, $bearish, $neutral);
        }

        $payload['entries'] = $entries;

        $count = count($entries);
        if ($count > 0) {
            $payload['overview']['average_sentiment'] = $totalSentiment / $count;
            $payload['overview']['bullish_count'] = $bullish;
            $payload['overview']['bearish_count'] = $bearish;
            $payload['overview']['neutral_count'] = $neutral;
        }

        $headline = $this->selectHeadline($entries);
        if ($headline !== null) {
            $payload['overview']['headline'] = $headline;
        }

        $payload['leaders']['bullish'] = $this->rankBySentiment($entries, true);
        $payload['leaders']['bearish'] = $this->rankBySentiment($entries, false);

        return $payload;
    }

    /**
     * @param array<int, array<string, mixed>> $articles
     * @return array<int, array<string, mixed>>
     */
    private function normaliseArticles(array $articles): array
    {
        $normalised = [];
        foreach ($articles as $article) {
            if (!is_array($article)) {
                continue;
            }
            $normalised[] = $article;
        }

        return $normalised;
    }

    /**
     * @param array<int, array<string, mixed>>|array<string, mixed> $existing
     * @param array<int, array<string, mixed>> $articles
     * @return array<int, array<string, int|string>>
     */
    private function mergeTopics($existing, array $articles): array
    {
        $totals = [];
        if (is_array($existing)) {
            foreach ($existing as $topic) {
                if (!is_array($topic)) {
                    continue;
                }
                $name = (string) ($topic['topic'] ?? '');
                if ($name === '') {
                    continue;
                }
                $mentions = isset($topic['mentions']) ? (int) $topic['mentions'] : 1;
                $totals[$name] = ($totals[$name] ?? 0) + max(1, $mentions);
            }
        }

        foreach ($articles as $article) {
            $topics = isset($article['topics']) && is_array($article['topics']) ? $article['topics'] : [];
            foreach ($topics as $topicName) {
                if (!is_string($topicName) || $topicName === '') {
                    continue;
                }
                $totals[$topicName] = ($totals[$topicName] ?? 0) + 1;
            }
        }

        $items = [];
        foreach ($totals as $name => $count) {
            $items[] = [
                'topic' => $name,
                'mentions' => $count,
            ];
        }

        usort($items, static fn(array $a, array $b): int => ($b['mentions'] ?? 0) <=> ($a['mentions'] ?? 0));

        return array_slice($items, 0, 8);
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @return array<string, mixed>|null
     */
    private function selectHeadline(array $entries): ?array
    {
        $pool = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $symbol = (string) ($entry['symbol'] ?? '');
            $articles = isset($entry['sentiment']['articles']) && is_array($entry['sentiment']['articles']) ? $entry['sentiment']['articles'] : [];
            foreach ($articles as $article) {
                if (!is_array($article)) {
                    continue;
                }
                $candidate = $article;
                $candidate['symbol'] = $symbol;
                $pool[] = $candidate;
            }
        }

        if ($pool === []) {
            return null;
        }

        usort($pool, static function (array $a, array $b): int {
            $scoreA = (float) ($a['sentiment_score'] ?? 0.0);
            $scoreB = (float) ($b['sentiment_score'] ?? 0.0);
            $relA = (float) ($a['relevance_score'] ?? 0.0);
            $relB = (float) ($b['relevance_score'] ?? 0.0);

            return ($scoreB + $relB) <=> ($scoreA + $relA);
        });

        $headline = $pool[0];
        return [
            'symbol' => (string) ($headline['symbol'] ?? ''),
            'source' => (string) ($headline['source'] ?? ''),
            'title' => (string) ($headline['title'] ?? ''),
            'summary' => (string) ($headline['summary'] ?? ''),
            'published_at' => (string) ($headline['published_at'] ?? ''),
            'sentiment_score' => (float) ($headline['sentiment_score'] ?? 0.0),
            'sentiment_label' => (string) ($headline['sentiment_label'] ?? 'neutral'),
            'relevance_score' => (float) ($headline['relevance_score'] ?? 0.0),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @return array<int, array<string, mixed>>
     */
    private function rankBySentiment(array $entries, bool $bullish): array
    {
        $sorted = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $sorted[] = $entry;
        }

        usort($sorted, static function (array $a, array $b) use ($bullish): int {
            $scoreA = (float) ($a['sentiment']['average_score'] ?? 0.0);
            $scoreB = (float) ($b['sentiment']['average_score'] ?? 0.0);

            return $bullish ? ($scoreB <=> $scoreA) : ($scoreA <=> $scoreB);
        });

        $leaders = [];
        foreach (array_slice($sorted, 0, 5) as $entry) {
            $leaders[] = [
                'symbol' => (string) ($entry['symbol'] ?? ''),
                'name' => (string) ($entry['company']['name'] ?? ''),
                'change_percent' => (float) ($entry['quote']['change_percent'] ?? 0.0),
                'sentiment_score' => (float) ($entry['sentiment']['average_score'] ?? 0.0),
                'sentiment_label' => (string) ($entry['sentiment']['label'] ?? 'neutral'),
            ];
        }

        return $leaders;
    }

    private function labelForAverage(float $score): string
    {
        if ($score >= 0.5) {
            return 'very_bullish';
        }
        if ($score >= 0.2) {
            return 'bullish';
        }
        if ($score >= 0.05) {
            return 'somewhat_bullish';
        }
        if ($score <= -0.5) {
            return 'very_bearish';
        }
        if ($score <= -0.2) {
            return 'bearish';
        }
        if ($score <= -0.05) {
            return 'somewhat_bearish';
        }

        return 'neutral';
    }

    private function tallyLabel(string $label, int &$bullish, int &$bearish, int &$neutral): void
    {
        switch ($label) {
            case 'very_bullish':
            case 'bullish':
            case 'somewhat_bullish':
                $bullish++;
                break;
            case 'very_bearish':
            case 'bearish':
            case 'somewhat_bearish':
                $bearish++;
                break;
            default:
                $neutral++;
        }
    }
}
