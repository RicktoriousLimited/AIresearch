<?php

declare(strict_types=1);

namespace Ricktorious\Markets;

use Ricktorious\Markets\Insights\MarketIntelligenceReportBuilder;
use Ricktorious\Markets\Insights\SentimentAnalyzer;
use Ricktorious\Markets\Insights\TimelineBuilder;
use Ricktorious\Markets\News\NewsCrawler;
use Ricktorious\Markets\News\Sources\GoogleNewsSource;
use Ricktorious\Markets\News\Sources\StaticNewsSource;

final class Kernel
{
    private bool $booted = false;

    private NewsCrawler $crawler;

    private MarketIntelligenceReportBuilder $reportBuilder;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(private array $config = [])
    {
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $config = $this->buildConfig($this->config);

        $sources = [
            new GoogleNewsSource($config['sources']['google_news']['endpoint']),
            new StaticNewsSource($config['sources']['static']['path']),
        ];

        $this->crawler = new NewsCrawler($sources);
        $sentimentAnalyzer = new SentimentAnalyzer();
        $timelineBuilder = new TimelineBuilder();

        $this->reportBuilder = new MarketIntelligenceReportBuilder(
            $this->crawler,
            $sentimentAnalyzer,
            $timelineBuilder,
            (int) ($config['limits']['articles'] ?? 30)
        );

        $this->booted = true;
    }

    public function reportBuilder(): MarketIntelligenceReportBuilder
    {
        $this->boot();

        return $this->reportBuilder;
    }

    /**
     * @return array<string, mixed>
     */
    public function config(): array
    {
        $this->boot();

        return $this->buildConfig($this->config);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function buildConfig(array $overrides): array
    {
        $root = realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3);
        $resources = $root . '/resources/markets';

        $defaults = [
            'sources' => [
                'google_news' => [
                    'endpoint' => 'https://news.google.com/rss/search',
                ],
                'static' => [
                    'path' => $resources . '/sample-news.json',
                ],
            ],
            'limits' => [
                'articles' => 30,
            ],
        ];

        return array_replace_recursive($defaults, $overrides);
    }
}
