<?php

declare(strict_types=1);

namespace Ricktorious\Markets\Services;

use Ricktorious\Markets\Data\LocalMarketDataRepository;
use Ricktorious\Markets\Data\Models\CompanyProfile;

final class SearchService
{
    public function __construct(private LocalMarketDataRepository $repository)
    {
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function suggestions(string $query = '', int $limit = 6): array
    {
        $results = [];
        foreach ($this->repository->search($query, $limit) as $company) {
            $results[] = [
                'symbol' => $company->symbol(),
                'name' => $company->name(),
                'sector' => $company->sector(),
            ];
        }

        return $results;
    }

    /**
     * @return array<int, CompanyProfile>
     */
    public function companies(): array
    {
        return $this->repository->companies();
    }
}
