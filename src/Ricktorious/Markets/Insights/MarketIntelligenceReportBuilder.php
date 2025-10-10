<?php

declare(strict_types=1);

namespace Ricktorious\Markets\Insights;

use DateTimeInterface;
use Ricktorious\Markets\News\NewsArticle;
use Ricktorious\Markets\News\NewsCrawler;

use function arsort;
use function array_key_first;
use function array_keys;
use function array_map;
use function array_slice;
use function array_values;
use function array_sum;
use function count;
use function implode;
use function max;
use function round;
use function sort;
use function sprintf;
use function strtolower;
use function uasort;

final class MarketIntelligenceReportBuilder
{
    private InvestorSentimentModel $model;

    public function __construct(
        private NewsCrawler $crawler,
        private SentimentAnalyzer $sentimentAnalyzer,
        private TimelineBuilder $timelineBuilder,
        private int $defaultLimit = 30,
        ?InvestorSentimentModel $model = null
    ) {
        $this->model = $model ?? new InvestorSentimentModel();
    }

    /**
     * @param array<string, mixed> $options
     */
    public function build(string $company, array $options = []): Report
    {
        $limit = (int) ($options['limit'] ?? $this->defaultLimit);
        $articles = $this->crawler->discover($company, $limit);

        $snapshots = [];
        foreach ($articles as $article) {
            $score = $this->sentimentAnalyzer->score($article->title() . ' ' . $article->summary());
            $snapshots[] = $this->model->analyse($article, $score);
        }

        $timeline = $this->timelineBuilder->build($snapshots);
        $segments = $this->summariseSegments($snapshots);
        $overview = $this->buildOverview($company, $articles, $segments);
        $highlights = $this->buildHighlights($segments, $articles);

        $articlesData = array_map(function (SentimentSnapshot $snapshot): array {
            $article = $snapshot->article();
            $score = $snapshot->score();

            return [
                'title' => $article->title(),
                'url' => $article->url(),
                'source' => $article->source(),
                'published_at' => $article->isoTimestamp(),
                'summary' => $article->summary(),
                'sentiment' => [
                    'score' => round($score, 3),
                    'label' => $this->labelForScore($score),
                    'segments' => array_map(static fn (float $value): float => round($value, 3), $snapshot->segments()),
                ],
            ];
        }, $snapshots);

        return new Report(
            $company,
            (new \DateTimeImmutable())->format(DateTimeInterface::ATOM),
            $overview,
            $highlights,
            $segments,
            $timeline,
            $articlesData
        );
    }

    /**
     * @param array<int, SentimentSnapshot> $snapshots
     *
     * @return array<string, array<string, mixed>>
     */
    private function summariseSegments(array $snapshots): array
    {
        $summary = [];

        foreach ($snapshots as $snapshot) {
            $article = $snapshot->article();
            $timestamp = $article->publishedAt();
            foreach ($snapshot->segments() as $segment => $score) {
                if (!isset($summary[$segment])) {
                    $summary[$segment] = [
                        'scores' => [],
                        'latest_score' => $score,
                        'latest_label' => $this->labelForScore($score),
                        'latest_headline' => $article->title(),
                        'latest_published_at' => $article->isoTimestamp(),
                        'latest_timestamp' => $timestamp->getTimestamp(),
                    ];
                }

                $summary[$segment]['scores'][] = $score;

                if ($timestamp->getTimestamp() >= $summary[$segment]['latest_timestamp']) {
                    $summary[$segment]['latest_score'] = $score;
                    $summary[$segment]['latest_label'] = $this->labelForScore($score);
                    $summary[$segment]['latest_headline'] = $article->title();
                    $summary[$segment]['latest_published_at'] = $article->isoTimestamp();
                    $summary[$segment]['latest_timestamp'] = $timestamp->getTimestamp();
                }
            }
        }

        foreach ($summary as $segment => $data) {
            $scores = $data['scores'] ?? [];
            $average = $scores === [] ? 0.0 : array_sum($scores) / max(count($scores), 1);

            $summary[$segment] = [
                'average_score' => round($average, 3),
                'average_label' => $this->labelForScore($average),
                'current_score' => round((float) ($data['latest_score'] ?? 0.0), 3),
                'current_label' => (string) ($data['latest_label'] ?? 'neutral'),
                'latest_headline' => (string) ($data['latest_headline'] ?? ''),
                'latest_published_at' => (string) ($data['latest_published_at'] ?? ''),
                'article_count' => count($scores),
            ];
        }

        uasort($summary, static function (array $a, array $b): int {
            return ($b['current_score'] ?? 0) <=> ($a['current_score'] ?? 0);
        });

        return $summary;
    }

    /**
     * @param array<int, NewsArticle> $articles
     * @param array<string, array<string, mixed>> $segments
     */
    private function buildOverview(string $company, array $articles, array $segments): string
    {
        $countArticles = count($articles);
        if ($countArticles === 0) {
            return sprintf('No recent coverage for %s was discovered. Consider expanding the discovery window.', $company);
        }

        $dates = array_map(static fn (NewsArticle $article): int => $article->publishedAt()->getTimestamp(), $articles);
        sort($dates);
        $start = (new \DateTimeImmutable())->setTimestamp($dates[0])->format('M j, Y');
        $end = (new \DateTimeImmutable())->setTimestamp($dates[count($dates) - 1])->format('M j, Y');

        $strongestSegment = array_key_first($segments);
        $strongestLabel = $strongestSegment !== null ? ($segments[$strongestSegment]['current_label'] ?? 'neutral') : 'neutral';

        return sprintf(
            'Autonomous discovery collected %d articles about %s between %s and %s. %s investors currently show the strongest %s sentiment.',
            $countArticles,
            $company,
            $start,
            $end,
            $strongestSegment !== null ? ucfirst($strongestSegment) : 'All',
            strtolower($strongestLabel)
        );
    }

    /**
     * @param array<string, array<string, mixed>> $segments
     * @param array<int, NewsArticle> $articles
     *
     * @return array<int, string>
     */
    private function buildHighlights(array $segments, array $articles): array
    {
        $highlights = [];

        foreach ($segments as $segment => $data) {
            $highlights[] = sprintf(
                '%s investors sit at %s sentiment (%.2f) based on %d signal%s.',
                ucfirst($segment),
                strtolower((string) ($data['current_label'] ?? 'neutral')),
                (float) ($data['current_score'] ?? 0.0),
                (int) ($data['article_count'] ?? 0),
                ((int) ($data['article_count'] ?? 0) === 1 ? '' : 's')
            );
        }

        $sources = [];
        foreach ($articles as $article) {
            $sources[$article->source()] = ($sources[$article->source()] ?? 0) + 1;
        }

        if ($sources !== []) {
            arsort($sources);
            $topSources = array_slice(array_map(
                static fn (string $source, int $count): string => sprintf('%s (%d)', $source, $count),
                array_keys($sources),
                array_values($sources)
            ), 0, 5);

            $highlights[] = 'Top sources: ' . implode(', ', $topSources);
        }

        return $highlights;
    }

    private function labelForScore(float $score): string
    {
        if ($score > 0.2) {
            return 'positive';
        }

        if ($score < -0.2) {
            return 'negative';
        }

        return 'neutral';
    }
}
