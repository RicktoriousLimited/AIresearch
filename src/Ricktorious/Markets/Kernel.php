<?php

declare(strict_types=1);

namespace Ricktorious\Markets;

use Ricktorious\Markets\Data\LocalMarketDataRepository;
use Ricktorious\Markets\Services\CompanyNewsService;
use Ricktorious\Markets\Services\MarketOverviewService;
use Ricktorious\Markets\Services\SearchService;
use RuntimeException;

final class Kernel
{
    private bool $booted = false;

    private LocalMarketDataRepository $repository;

    private MarketOverviewService $overviewService;

    private CompanyNewsService $companyNewsService;

    private SearchService $searchService;

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
        $datasetPath = (string) ($config['data']['path'] ?? '');

        if ($datasetPath === '') {
            throw new RuntimeException('Market dataset path is not configured.');
        }

        $this->repository = new LocalMarketDataRepository($datasetPath);
        $this->overviewService = new MarketOverviewService($this->repository);
        $this->companyNewsService = new CompanyNewsService($this->repository);
        $this->searchService = new SearchService($this->repository);

        $this->booted = true;
    }

    public function overviewService(): MarketOverviewService
    {
        $this->boot();

        return $this->overviewService;
    }

    public function companyNewsService(): CompanyNewsService
    {
        $this->boot();

        return $this->companyNewsService;
    }

    public function searchService(): SearchService
    {
        $this->boot();

        return $this->searchService;
    }

    public function repository(): LocalMarketDataRepository
    {
        $this->boot();

        return $this->repository;
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
            'data' => [
                'path' => $resources . '/market-data.json',
            ],
        ];

        return array_replace_recursive($defaults, $overrides);
    }
}
