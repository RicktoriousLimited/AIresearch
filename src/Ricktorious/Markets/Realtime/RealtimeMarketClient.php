<?php

declare(strict_types=1);

namespace Ricktorious\Markets\Realtime;

use DateTimeImmutable;
use RuntimeException;

final class RealtimeMarketClient
{
    private const DEFAULT_SYMBOLS = ['AAPL', 'MSFT', 'NVDA', 'GOOGL', 'AMZN'];

    public function __construct(
        private HttpJsonClient $http,
        private FileCache $cache,
        private ?string $alphaVantageKey = null
    ) {
        $this->alphaVantageKey = $alphaVantageKey !== null && $alphaVantageKey !== ''
            ? $alphaVantageKey
            : (getenv('ALPHAVANTAGE_KEY') ?: 'demo');
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    public function search(string $query, int $limit = 10): array
    {
        $normalized = trim($query);
        if ($normalized === '') {
            return [];
        }

        $limit = max(1, min($limit, 25));
        $cacheKey = 'search_' . strtolower($normalized) . '_' . $limit;

        /** @var array<int, array<string, string|null>> $results */
        $results = $this->cache->remember($cacheKey, 1800, function () use ($normalized, $limit): array {
            $url = 'https://query1.finance.yahoo.com/v1/finance/search?q=' . urlencode($normalized);
            $payload = $this->http->get($url);
            $quotes = isset($payload['quotes']) && is_array($payload['quotes']) ? $payload['quotes'] : [];

            $items = [];
            foreach ($quotes as $quote) {
                if (!is_array($quote)) {
                    continue;
                }

                $type = (string) ($quote['quoteType'] ?? '');
                if ($type !== 'EQUITY') {
                    continue;
                }

                $symbol = strtoupper((string) ($quote['symbol'] ?? ''));
                if ($symbol === '') {
                    continue;
                }

                $items[] = [
                    'symbol' => $symbol,
                    'name' => (string) ($quote['longname'] ?? $quote['shortname'] ?? ''),
                    'exchange' => (string) ($quote['exchDisp'] ?? $quote['exchange'] ?? ''),
                    'sector' => (string) ($quote['sectorDisp'] ?? $quote['sector'] ?? ''),
                    'industry' => (string) ($quote['industryDisp'] ?? $quote['industry'] ?? ''),
                ];

                if (count($items) >= $limit) {
                    break;
                }
            }

            return $items;
        });

        return $results;
    }

    /**
     * @param array<int, string>|null $symbols
     * @return array<string, mixed>
     */
    public function dashboard(?array $symbols = null): array
    {
        $tickers = $symbols !== null && $symbols !== [] ? $symbols : self::DEFAULT_SYMBOLS;
        $tickers = array_values(array_unique(array_map([$this, 'normalizeSymbol'], $tickers)));

        $entries = [];
        foreach ($tickers as $symbol) {
            try {
                $entries[] = $this->companySummary($symbol);
            } catch (RuntimeException $exception) {
                // Skip symbols that fail to load so the dashboard can still render.
            }
        }

        if ($entries === []) {
            throw new RuntimeException('Unable to assemble market dashboard.');
        }

        $avgChange = 0.0;
        $avgSentiment = 0.0;
        $bullish = 0;
        $bearish = 0;
        $neutral = 0;
        $volatility = 0.0;
        $headline = null;

        $articlePool = [];

        foreach ($entries as $entry) {
            $avgChange += (float) ($entry['quote']['change_percent'] ?? 0.0);
            $sentiment = (float) ($entry['sentiment']['average_score'] ?? 0.0);
            $avgSentiment += $sentiment;
            $volatility += (float) ($entry['insights']['volatility'] ?? 0.0);

            $label = (string) ($entry['sentiment']['label'] ?? 'neutral');
            switch ($label) {
                case 'bullish':
                case 'very_bullish':
                case 'somewhat_bullish':
                    $bullish++;
                    break;
                case 'bearish':
                case 'very_bearish':
                case 'somewhat_bearish':
                    $bearish++;
                    break;
                default:
                    $neutral++;
            }

            $articles = $entry['sentiment']['articles'] ?? [];
            foreach ($articles as $article) {
                if (!is_array($article)) {
                    continue;
                }

                $articlePool[] = $article + ['symbol' => $entry['symbol'] ?? ''];
            }
        }

        $count = count($entries);
        $overview = [
            'average_change_percent' => $count > 0 ? $avgChange / $count : 0.0,
            'average_sentiment' => $count > 0 ? $avgSentiment / $count : 0.0,
            'volatility' => $count > 0 ? $volatility / $count : 0.0,
            'bullish_count' => $bullish,
            'bearish_count' => $bearish,
            'neutral_count' => $neutral,
        ];

        if ($articlePool !== []) {
            usort($articlePool, static function (array $a, array $b): int {
                $scoreA = (float) ($a['sentiment_score'] ?? 0.0);
                $scoreB = (float) ($b['sentiment_score'] ?? 0.0);
                $relA = (float) ($a['relevance_score'] ?? 0.0);
                $relB = (float) ($b['relevance_score'] ?? 0.0);

                return ($scoreB + $relB) <=> ($scoreA + $relA);
            });

            $headline = $articlePool[0];
        }

        $leaders = [
            'bullish' => $this->rankBySentiment($entries, true),
            'bearish' => $this->rankBySentiment($entries, false),
            'movers' => $this->rankByMovement($entries),
        ];

        return [
            'generated_at' => (new DateTimeImmutable())->format(DATE_ATOM),
            'symbols' => $tickers,
            'overview' => array_merge($overview, [
                'headline' => $headline,
            ]),
            'entries' => $entries,
            'leaders' => $leaders,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function companySummary(string $symbol): array
    {
        $normalized = $this->normalizeSymbol($symbol);
        $quote = $this->fetchQuoteMeta($normalized);
        $history = $this->fetchHistoricalSeries($normalized, '1mo', '1d');
        $sentiment = $this->fetchSentiment($normalized);
        $profile = $this->fetchProfile($normalized, $quote);

        $volatility = $this->calculateVolatility($history['points'], 30);

        return [
            'symbol' => $normalized,
            'company' => $profile,
            'quote' => $quote,
            'history' => [
                'range' => '1mo',
                'interval' => '1d',
                'points' => $history['points'],
                'sparkline' => $history['sparkline'],
            ],
            'sentiment' => [
                'average_score' => $sentiment['average_score'],
                'label' => $sentiment['label'],
                'article_count' => $sentiment['article_count'],
                'articles' => array_slice($sentiment['articles'], 0, 5),
            ],
            'insights' => [
                'volatility' => $volatility,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function companyInsights(string $symbol): array
    {
        $normalized = $this->normalizeSymbol($symbol);

        $quote = $this->fetchQuoteMeta($normalized);
        $history = $this->fetchHistoricalSeries($normalized, '6mo', '1d');
        $sentiment = $this->fetchSentiment($normalized);
        $profile = $this->fetchProfile($normalized, $quote);

        $volatility = $this->calculateVolatility($history['points'], 30);
        $returns = $this->calculateReturns($history['points']);
        $insights = $this->buildInsights($returns, $sentiment, $volatility, $history['points']);

        return [
            'symbol' => $normalized,
            'company' => $profile,
            'quote' => $quote,
            'price_history' => [
                'range' => '6mo',
                'interval' => '1d',
                'points' => $history['points'],
                'sparkline' => $history['sparkline'],
            ],
            'sentiment' => $sentiment,
            'insights' => $insights,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @return array<int, array<string, mixed>>
     */
    private function rankBySentiment(array $entries, bool $bullish): array
    {
        $sorted = $entries;
        usort($sorted, static function (array $a, array $b) use ($bullish): int {
            $scoreA = (float) ($a['sentiment']['average_score'] ?? 0.0);
            $scoreB = (float) ($b['sentiment']['average_score'] ?? 0.0);

            return $bullish ? ($scoreB <=> $scoreA) : ($scoreA <=> $scoreB);
        });

        return array_map(static function (array $entry): array {
            return [
                'symbol' => $entry['symbol'] ?? '',
                'name' => $entry['company']['name'] ?? '',
                'change_percent' => $entry['quote']['change_percent'] ?? 0.0,
                'sentiment_score' => $entry['sentiment']['average_score'] ?? 0.0,
                'sentiment_label' => $entry['sentiment']['label'] ?? 'neutral',
            ];
        }, array_slice($sorted, 0, 5));
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @return array<int, array<string, mixed>>
     */
    private function rankByMovement(array $entries): array
    {
        $sorted = $entries;
        usort($sorted, static function (array $a, array $b): int {
            $changeA = abs((float) ($a['quote']['change_percent'] ?? 0.0));
            $changeB = abs((float) ($b['quote']['change_percent'] ?? 0.0));

            return $changeB <=> $changeA;
        });

        return array_map(static function (array $entry): array {
            return [
                'symbol' => $entry['symbol'] ?? '',
                'name' => $entry['company']['name'] ?? '',
                'price' => $entry['quote']['price'] ?? 0.0,
                'change_percent' => $entry['quote']['change_percent'] ?? 0.0,
                'sentiment_label' => $entry['sentiment']['label'] ?? 'neutral',
            ];
        }, array_slice($sorted, 0, 5));
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchQuoteMeta(string $symbol): array
    {
        $chart = $this->fetchChart($symbol, '1mo', '1d', 180);
        $meta = $chart['meta'];
        $timestamps = $chart['timestamps'];
        $closes = $chart['closes'];

        if ($closes === []) {
            throw new RuntimeException('No price data returned for ' . $symbol);
        }

        $price = (float) ($meta['regularMarketPrice'] ?? end($closes));
        $previousClose = (float) ($meta['chartPreviousClose'] ?? (count($closes) > 1 ? $closes[count($closes) - 2] : $closes[count($closes) - 1]));
        $change = $price - $previousClose;
        $changePercent = $previousClose !== 0.0 ? ($change / $previousClose) * 100 : 0.0;
        $time = isset($meta['regularMarketTime']) ? (int) $meta['regularMarketTime'] : (int) end($timestamps);
        $asOf = (new DateTimeImmutable('@' . $time))->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM);

        return [
            'price' => $price,
            'previous_close' => $previousClose,
            'change' => $change,
            'change_percent' => $changePercent,
            'day_high' => isset($meta['regularMarketDayHigh']) ? (float) $meta['regularMarketDayHigh'] : null,
            'day_low' => isset($meta['regularMarketDayLow']) ? (float) $meta['regularMarketDayLow'] : null,
            'fifty_two_week_high' => isset($meta['fiftyTwoWeekHigh']) ? (float) $meta['fiftyTwoWeekHigh'] : null,
            'fifty_two_week_low' => isset($meta['fiftyTwoWeekLow']) ? (float) $meta['fiftyTwoWeekLow'] : null,
            'volume' => isset($meta['regularMarketVolume']) ? (int) $meta['regularMarketVolume'] : null,
            'currency' => (string) ($meta['currency'] ?? 'USD'),
            'exchange' => (string) ($meta['fullExchangeName'] ?? $meta['exchangeName'] ?? ''),
            'as_of' => $asOf,
        ];
    }

    /**
     * @return array{points: array<int, array<string, mixed>>, sparkline: array<int, float>}
     */
    private function fetchHistoricalSeries(string $symbol, string $range, string $interval): array
    {
        $chart = $this->fetchChart($symbol, $range, $interval, 600);
        $timestamps = $chart['timestamps'];
        $closes = $chart['closes'];

        $points = [];
        foreach ($timestamps as $index => $timestamp) {
            $close = $closes[$index] ?? null;
            if ($close === null) {
                continue;
            }

            $points[] = [
                'time' => (new DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM),
                'close' => (float) $close,
            ];
        }

        $sparkline = [];
        $slice = array_slice($closes, -30);
        foreach ($slice as $value) {
            if ($value !== null) {
                $sparkline[] = (float) $value;
            }
        }

        return [
            'points' => $points,
            'sparkline' => $sparkline,
        ];
    }

    /**
     * @return array{meta: array<string, mixed>, timestamps: array<int, int>, closes: array<int, ?float>}
     */
    private function fetchChart(string $symbol, string $range, string $interval, int $ttl): array
    {
        $cacheKey = sprintf('chart_%s_%s_%s', $symbol, $range, $interval);

        /** @var array{meta: array<string, mixed>, timestamps: array<int, int>, closes: array<int, ?float>} $chart */
        $chart = $this->cache->remember($cacheKey, $ttl, function () use ($symbol, $range, $interval): array {
            $url = sprintf(
                'https://query1.finance.yahoo.com/v8/finance/chart/%s?range=%s&interval=%s',
                urlencode($symbol),
                urlencode($range),
                urlencode($interval)
            );

            $payload = $this->http->get($url, ['User-Agent: Mozilla/5.0']);
            $result = $payload['chart']['result'][0] ?? null;
            if (!is_array($result)) {
                throw new RuntimeException('Chart data missing for ' . $symbol);
            }

            $meta = isset($result['meta']) && is_array($result['meta']) ? $result['meta'] : [];
            $timestamps = isset($result['timestamp']) && is_array($result['timestamp']) ? array_values(array_map('intval', $result['timestamp'])) : [];
            $quotes = $result['indicators']['quote'][0] ?? [];
            $closes = isset($quotes['close']) && is_array($quotes['close']) ? array_values($quotes['close']) : [];

            return [
                'meta' => $meta,
                'timestamps' => $timestamps,
                'closes' => $closes,
            ];
        });

        return $chart;
    }

    /**
     * @param array<string, mixed> $quote
     *
     * @return array<string, mixed>
     */
    private function fetchProfile(string $symbol, array $quote): array
    {
        $search = $this->search($symbol, 1);
        $entry = $search[0] ?? null;

        $name = $entry['name'] ?? strtoupper($symbol);
        $sector = $entry['sector'] ?? '';
        $industry = $entry['industry'] ?? '';
        $exchange = $entry['exchange'] ?? ($quote['exchange'] ?? '');

        $descriptionParts = [];
        if ($sector !== '' && $industry !== '') {
            $descriptionParts[] = sprintf('%s operates in the %s industry.', $name, strtolower($industry));
        } elseif ($sector !== '') {
            $descriptionParts[] = sprintf('%s is part of the %s sector.', $name, strtolower($sector));
        }

        $description = $descriptionParts === []
            ? sprintf('%s market snapshot and live sentiment.', $name)
            : implode(' ', $descriptionParts);

        return [
            'symbol' => $symbol,
            'name' => $name,
            'sector' => $sector,
            'industry' => $industry,
            'exchange' => $exchange,
            'description' => $description,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchSentiment(string $symbol): array
    {
        $cacheKey = 'sentiment_' . $symbol;

        /** @var array<string, mixed> $sentiment */
        $sentiment = $this->cache->remember($cacheKey, 600, function () use ($symbol): array {
            $url = sprintf(
                'https://www.alphavantage.co/query?function=NEWS_SENTIMENT&tickers=%s&sort=LATEST&limit=100&apikey=%s',
                urlencode($symbol),
                urlencode((string) $this->alphaVantageKey)
            );

            try {
                $payload = $this->http->get($url);
            } catch (RuntimeException $exception) {
                return $this->emptySentiment('unavailable');
            }

            $feed = isset($payload['feed']) && is_array($payload['feed']) ? $payload['feed'] : [];
            if ($feed === []) {
                return $this->emptySentiment('no_data');
            }

            $articles = [];
            $scores = [];
            $timeline = [];
            $topics = [];

            foreach ($feed as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $tickerSentiment = isset($item['ticker_sentiment']) && is_array($item['ticker_sentiment']) ? $item['ticker_sentiment'] : [];
                $matched = null;
                foreach ($tickerSentiment as $sentimentItem) {
                    if (!is_array($sentimentItem)) {
                        continue;
                    }

                    $ticker = strtoupper((string) ($sentimentItem['ticker'] ?? ''));
                    if ($ticker === strtoupper($symbol)) {
                        $matched = $sentimentItem;
                        break;
                    }
                }

                if ($matched === null) {
                    continue;
                }

                $score = (float) ($matched['ticker_sentiment_score'] ?? 0.0);
                $label = $this->classifyScore($score);
                $scores[] = $score;

                $timePublished = (string) ($item['time_published'] ?? '');
                $publishedAt = $this->parsePublishedTime($timePublished);
                $dateKey = $publishedAt !== null ? $publishedAt->format('Y-m-d') : null;

                if ($dateKey !== null) {
                    if (!isset($timeline[$dateKey])) {
                        $timeline[$dateKey] = [
                            'date' => $dateKey,
                            'scores' => [],
                            'article_count' => 0,
                        ];
                    }

                    $timeline[$dateKey]['scores'][] = $score;
                    $timeline[$dateKey]['article_count']++;
                }

                $topicsList = isset($item['topics']) && is_array($item['topics']) ? $item['topics'] : [];
                foreach ($topicsList as $topic) {
                    if (!is_array($topic)) {
                        continue;
                    }

                    $name = (string) ($topic['topic'] ?? '');
                    if ($name === '') {
                        continue;
                    }

                    $topics[$name] = ($topics[$name] ?? 0) + 1;
                }

                $articles[] = [
                    'title' => (string) ($item['title'] ?? ''),
                    'summary' => (string) ($item['summary'] ?? ''),
                    'url' => (string) ($item['url'] ?? ''),
                    'source' => (string) ($item['source'] ?? ''),
                    'authors' => isset($item['authors']) && is_array($item['authors']) ? array_values(array_filter(array_map('strval', $item['authors']))) : [],
                    'published_at' => $publishedAt?->format(DATE_ATOM),
                    'banner_image' => (string) ($item['banner_image'] ?? ''),
                    'sentiment_score' => $score,
                    'sentiment_label' => $label,
                    'relevance_score' => isset($matched['relevance_score']) ? (float) $matched['relevance_score'] : null,
                ];
            }

            $timelinePoints = [];
            ksort($timeline);
            foreach ($timeline as $date => $entry) {
                $scoresForDay = $entry['scores'];
                $average = $scoresForDay !== [] ? array_sum($scoresForDay) / count($scoresForDay) : 0.0;
                $timelinePoints[] = [
                    'date' => $date,
                    'score' => $average,
                    'label' => $this->classifyScore($average),
                    'article_count' => $entry['article_count'],
                ];
            }

            $averageScore = $scores !== [] ? array_sum($scores) / count($scores) : 0.0;
            $latestScore = $timelinePoints !== [] ? $timelinePoints[count($timelinePoints) - 1]['score'] : 0.0;
            $previousScore = $timelinePoints !== [] ? ($timelinePoints[count($timelinePoints) - 2]['score'] ?? $latestScore) : 0.0;
            $momentum = $latestScore - $previousScore;

            arsort($topics);
            $topicList = [];
            foreach (array_slice($topics, 0, 8, true) as $name => $count) {
                $topicList[] = [
                    'topic' => $name,
                    'mentions' => $count,
                ];
            }

            return [
                'average_score' => $averageScore,
                'latest_score' => $latestScore,
                'previous_score' => $previousScore,
                'momentum' => $momentum,
                'label' => $this->classifyScore($averageScore),
                'article_count' => count($articles),
                'articles' => $articles,
                'timeline' => $timelinePoints,
                'topics' => $topicList,
                'source' => 'alphavantage',
            ];
        });

        return $sentiment;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptySentiment(string $reason): array
    {
        return [
            'average_score' => 0.0,
            'latest_score' => 0.0,
            'previous_score' => 0.0,
            'momentum' => 0.0,
            'label' => 'neutral',
            'article_count' => 0,
            'articles' => [],
            'timeline' => [],
            'topics' => [],
            'source' => $reason,
        ];
    }

    private function normalizeSymbol(string $symbol): string
    {
        $normalized = strtoupper(trim($symbol));
        if ($normalized === '') {
            throw new RuntimeException('Symbol is required.');
        }

        return $normalized;
    }

    private function classifyScore(float $score): string
    {
        if ($score >= 0.35) {
            return 'very_bullish';
        }

        if ($score >= 0.15) {
            return 'bullish';
        }

        if ($score <= -0.35) {
            return 'very_bearish';
        }

        if ($score <= -0.15) {
            return 'bearish';
        }

        return 'neutral';
    }

    /**
     * @param array<int, array{time: string, close: float}> $points
     * @return array<string, float>
     */
    private function calculateReturns(array $points): array
    {
        $closes = array_map(static fn (array $point): float => (float) $point['close'], $points);
        if ($closes === []) {
            return [
                'one_week' => 0.0,
                'one_month' => 0.0,
                'three_month' => 0.0,
                'six_month' => 0.0,
            ];
        }

        $latest = $closes[count($closes) - 1];

        $returnFor = static function (int $sessions) use ($closes, $latest): float {
            $index = max(0, count($closes) - 1 - $sessions);
            $base = $closes[$index] ?? $latest;
            if ($base === 0.0) {
                return 0.0;
            }

            return (($latest - $base) / $base) * 100;
        };

        return [
            'one_week' => $returnFor(5),
            'one_month' => $returnFor(21),
            'three_month' => $returnFor(63),
            'six_month' => $returnFor(126),
        ];
    }

    /**
     * @param array<int, array{time: string, close: float}> $points
     */
    private function calculateVolatility(array $points, int $window): float
    {
        if (count($points) < 2) {
            return 0.0;
        }

        $returns = [];
        for ($i = 1; $i < count($points); $i++) {
            $prev = (float) $points[$i - 1]['close'];
            $current = (float) $points[$i]['close'];
            if ($prev === 0.0) {
                continue;
            }

            $returns[] = ($current - $prev) / $prev;
        }

        $slice = array_slice($returns, -$window);
        if ($slice === []) {
            return 0.0;
        }

        $mean = array_sum($slice) / count($slice);
        $variance = 0.0;
        foreach ($slice as $value) {
            $variance += ($value - $mean) ** 2;
        }

        $variance = $variance / count($slice);
        $dailyVol = sqrt($variance);

        return $dailyVol * sqrt(252) * 100;
    }

    /**
     * @param array<string, float> $returns
     * @param array<string, mixed> $sentiment
     * @param array<int, array{time: string, close: float}> $points
     *
     * @return array<string, mixed>
     */
    private function buildInsights(array $returns, array $sentiment, float $volatility, array $points): array
    {
        $keyPoints = [];
        $sentimentMomentum = (float) ($sentiment['momentum'] ?? 0.0);
        $averageSentiment = (float) ($sentiment['average_score'] ?? 0.0);

        if ($sentimentMomentum > 0.05) {
            $keyPoints[] = 'Market sentiment has strengthened notably in the latest news cycle.';
        } elseif ($sentimentMomentum < -0.05) {
            $keyPoints[] = 'News flow turned negative compared with the previous period.';
        } else {
            $keyPoints[] = 'Sentiment is holding steady without major swings.';
        }

        $volDescriptor = $this->volatilityLabel($volatility);
        $keyPoints[] = sprintf('Estimated 30-day volatility is %s (%.1f%% annualised).', $volDescriptor, $volatility);

        $articleCount = (int) ($sentiment['article_count'] ?? 0);
        if ($articleCount > 0) {
            $keyPoints[] = sprintf('We analysed %d recent articles mentioning the ticker.', $articleCount);
        } else {
            $keyPoints[] = 'No recent articles met the relevance threshold for sentiment analysis.';
        }

        $oneMonth = $returns['one_month'] ?? 0.0;
        if ($oneMonth > 0.0) {
            $keyPoints[] = sprintf('Price advanced %.2f%% over the last month.', $oneMonth);
        } elseif ($oneMonth < 0.0) {
            $keyPoints[] = sprintf('Price retreated %.2f%% over the last month.', abs($oneMonth));
        }

        $latestPrice = $points !== [] ? (float) $points[count($points) - 1]['close'] : 0.0;

        return [
            'key_points' => $keyPoints,
            'returns' => $returns,
            'volatility' => $volatility,
            'sentiment_score' => $averageSentiment,
            'sentiment_label' => $this->classifyScore($averageSentiment),
            'latest_price' => $latestPrice,
        ];
    }

    private function volatilityLabel(float $volatility): string
    {
        if ($volatility >= 60) {
            return 'extremely elevated';
        }

        if ($volatility >= 40) {
            return 'elevated';
        }

        if ($volatility >= 25) {
            return 'moderate';
        }

        return 'calm';
    }

    private function parsePublishedTime(string $value): ?DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('Ymd\THis', $value, new \DateTimeZone('UTC'));
        if ($date instanceof DateTimeImmutable) {
            return $date;
        }

        return null;
    }
}
