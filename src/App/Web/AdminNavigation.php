<?php

declare(strict_types=1);

namespace App\Web;

final class AdminNavigation
{
    /**
     * @return array<string, array{label: string, href: string}>
     */
    public static function resolve(?string $scriptName = null): array
    {
        $paths = PathResolver::resolve($scriptName);
        $base = PathResolver::normalizeBase($paths['assetBase']);

        $targets = [
            'home' => ['label' => 'Home', 'path' => 'index.php'],
            'search' => ['label' => 'Search', 'path' => 'search.php'],
            'graph' => ['label' => 'Knowledge graph', 'path' => 'backend/knowledge-graph.php'],
            'crawler' => ['label' => 'Crawler', 'path' => 'backend/crawler.php'],
            'sources' => ['label' => 'Sources', 'path' => 'backend/sources.php'],
        ];

        $links = [];

        foreach ($targets as $key => $config) {
            $href = PathResolver::url($base, $config['path']);
            $links[$key] = [
                'label' => $config['label'],
                'href' => $href,
            ];
        }

        return $links;
    }
}

