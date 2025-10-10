<?php

declare(strict_types=1);

namespace Ricktorious\Markets\Services;

use Ricktorious\Markets\Data\LocalMarketDataRepository;
use Ricktorious\Markets\Data\Models\CompanyProfile;

final class MarketOverviewService
{
    public function __construct(private LocalMarketDataRepository $repository)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $companies = $this->repository->companies();
        $advancers = 0;
        $decliners = 0;
        $unchanged = 0;
        $totalMarketCap = 0.0;
        $totalChange = 0.0;
        $sectorMap = [];

        foreach ($companies as $company) {
            $totalMarketCap += $company->marketCap();
            $totalChange += $company->changePercent();

            if ($company->changePercent() > 0) {
                $advancers++;
            } elseif ($company->changePercent() < 0) {
                $decliners++;
            } else {
                $unchanged++;
            }

            $sector = $company->sector();
            if (!isset($sectorMap[$sector])) {
                $sectorMap[$sector] = [
                    'sector' => $sector,
                    'count' => 0,
                    'avg_change' => 0.0,
                ];
            }

            $sectorMap[$sector]['count']++;
            $sectorMap[$sector]['avg_change'] += $company->changePercent();
        }

        $companyCount = count($companies);
        $averageChange = $companyCount > 0 ? $totalChange / $companyCount : 0.0;

        $sectors = [];
        foreach ($sectorMap as $sector) {
            $sectors[] = [
                'sector' => $sector['sector'],
                'avg_change' => $sector['count'] > 0 ? $sector['avg_change'] / $sector['count'] : 0.0,
                'count' => $sector['count'],
            ];
        }

        usort($sectors, static fn (array $a, array $b): int => $b['avg_change'] <=> $a['avg_change']);

        return [
            'advancers' => $advancers,
            'decliners' => $decliners,
            'unchanged' => $unchanged,
            'average_change_percent' => $averageChange,
            'total_market_cap' => $totalMarketCap,
            'sectors' => $sectors,
            'indices' => $this->repository->indices(),
            'last_updated' => $this->repository->lastUpdated()?->format('Y-m-d H:i T'),
            'top_movers' => $this->topMovers($companies),
        ];
    }

    /**
     * @param array<int, CompanyProfile> $companies
     * @return array<int, array<string, mixed>>
     */
    private function topMovers(array $companies, int $limit = 4): array
    {
        usort(
            $companies,
            static fn (CompanyProfile $a, CompanyProfile $b): int => abs($b->changePercent()) <=> abs($a->changePercent())
        );

        $movers = [];
        foreach (array_slice($companies, 0, $limit) as $company) {
            $movers[] = array_merge(
                $company->toArray(),
                [
                    'direction' => $company->changePercent() >= 0 ? 'up' : 'down',
                ]
            );
        }

        return $movers;
    }
}
