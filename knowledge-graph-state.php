<?php

declare(strict_types=1);

use App\Crawler\HiddenCrawler;
use App\KnowledgeGraph\GraphRepository;
use App\KnowledgeGraph\GraphResearcher;
use App\KnowledgeGraph\ResearchService;
use App\News\NewsSearchService;
use App\Web\AdminNavigation;
use App\Web\PathResolver;

require __DIR__ . '/src/App/bootstrap.php';

return (static function (): array {
    $paths = PathResolver::resolve();
    $assetBase = PathResolver::normalizeBase($paths['assetBase']);
    $paths['assetBase'] = $assetBase;

    $stylesPath = PathResolver::url($assetBase, 'assets/styles.css');
    $themePath = PathResolver::url($assetBase, 'assets/theme.css');
    $researchStylesPath = PathResolver::url($assetBase, 'assets/research.css');
    $knowledgeStylesPath = PathResolver::url($assetBase, 'assets/knowledge.css');
    $autocompleteScriptPath = PathResolver::url($assetBase, 'assets/autocomplete.js');
    $navigationLinks = AdminNavigation::resolve();
    $navigationPaths = [];
    foreach ($navigationLinks as $key => $link) {
        $navigationPaths[$key] = $link['href'];
    }

    $homePath = $navigationPaths['home'] ?? PathResolver::url($assetBase, 'index.php');
    $searchPath = $navigationPaths['search'] ?? PathResolver::url($assetBase, 'search.php');
    $graphPath = $navigationPaths['graph'] ?? PathResolver::url($assetBase, 'backend/knowledge-graph.php');
    $docsPath = PathResolver::url($assetBase, 'docs');
    $apiPath = PathResolver::url($assetBase, 'api/research.php');
    $scriptPath = PathResolver::url($assetBase, 'assets/knowledge-graph.js');

    if (!isset($navigationPaths['graph'])) {
        $navigationPaths['graph'] = $graphPath;
    }

    $stylesVersion = file_exists(__DIR__ . '/assets/styles.css') ? (string) filemtime(__DIR__ . '/assets/styles.css') : (string) time();
    $themeVersion = file_exists(__DIR__ . '/assets/theme.css') ? (string) filemtime(__DIR__ . '/assets/theme.css') : (string) time();
    $researchStylesVersion = file_exists(__DIR__ . '/assets/research.css') ? (string) filemtime(__DIR__ . '/assets/research.css') : (string) time();
    $knowledgeStylesVersion = file_exists(__DIR__ . '/assets/knowledge.css') ? (string) filemtime(__DIR__ . '/assets/knowledge.css') : (string) time();
    $autocompleteScriptVersion = file_exists(__DIR__ . '/assets/autocomplete.js') ? (string) filemtime(__DIR__ . '/assets/autocomplete.js') : (string) time();
    $scriptVersion = file_exists(__DIR__ . '/assets/knowledge-graph.js') ? (string) filemtime(__DIR__ . '/assets/knowledge-graph.js') : (string) time();

    $repository = new GraphRepository();
    $researcher = new GraphResearcher($repository);
    $service = new ResearchService($repository);

    $initialSearch = $researcher->searchGraph('', 12);
    $topEntities = $service->listTopEntities(12);
    $summary = isset($initialSearch['summary']) && is_array($initialSearch['summary']) ? $initialSearch['summary'] : [];
    $sources = isset($initialSearch['sources']) && is_array($initialSearch['sources']) ? $initialSearch['sources'] : [];
    $updatedAt = isset($initialSearch['updated_at']) && is_string($initialSearch['updated_at']) ? $initialSearch['updated_at'] : null;
    $entities = isset($initialSearch['entities']) && is_array($initialSearch['entities']) ? $initialSearch['entities'] : [];
    $relations = isset($initialSearch['relations']) && is_array($initialSearch['relations']) ? $initialSearch['relations'] : [];
    $synonymGroups = isset($initialSearch['synonyms']) && is_array($initialSearch['synonyms']) ? $initialSearch['synonyms'] : [];
    $triples = isset($initialSearch['triples']) && is_array($initialSearch['triples']) ? $initialSearch['triples'] : [];

    $hasGraph = $entities !== [] || $relations !== [] || $triples !== [];

    $trendingTopics = [];

    try {
        $storage = __DIR__ . '/storage/backend/crawler-history.json';
        $crawler = new HiddenCrawler($storage);
        $newsService = new NewsSearchService($crawler, $repository);
        $newsPayload = $newsService->search('', ['limit' => 24]);
        if (is_array($newsPayload)) {
            $meta = isset($newsPayload['meta']) && is_array($newsPayload['meta']) ? $newsPayload['meta'] : [];

            $topics = [];
            if (isset($meta['topics']) && is_array($meta['topics'])) {
                foreach ($meta['topics'] as $topicRow) {
                    if (!is_array($topicRow)) {
                        continue;
                    }

                    $topicName = isset($topicRow['topic']) ? (string) $topicRow['topic'] : '';
                    if ($topicName !== '') {
                        $topics[] = $topicName;
                    }
                }
            }

            $suggestedQueries = [];
            if (isset($meta['suggested_queries']) && is_array($meta['suggested_queries'])) {
                foreach ($meta['suggested_queries'] as $query) {
                    if (!is_string($query)) {
                        continue;
                    }

                    $value = trim($query);
                    if ($value !== '') {
                        $suggestedQueries[] = $value;
                    }
                }
            }

            $trendingTopics = array_values(array_unique(array_filter(array_merge(
                $suggestedQueries,
                $topics
            ), static fn(string $value): bool => trim($value) !== '')));
            $trendingTopics = array_slice($trendingTopics, 0, 12);
        }
    } catch (Throwable) {
        $trendingTopics = [];
    }

    $predictiveSuggestions = (static function () use ($trendingTopics, $topEntities, $entities, $synonymGroups): array {
        $scores = [];

        $add = static function (&$scores, string $value, float $score): void {
            $candidate = trim($value);
            if ($candidate === '') {
                return;
            }
            $key = mb_strtolower($candidate, 'UTF-8');
            if (!isset($scores[$key]) || $score > $scores[$key]['score']) {
                $scores[$key] = ['value' => $candidate, 'score' => $score];
            }
        };

        foreach ($trendingTopics as $index => $topic) {
            $add($scores, $topic, 1.0 - ($index * 0.03));
        }

        foreach ($topEntities as $index => $entityRow) {
            $entityName = isset($entityRow['entity']) ? (string) $entityRow['entity'] : '';
            $add($scores, $entityName, 0.85 - ($index * 0.02));
        }

        foreach ($entities as $entityRow) {
            if (!is_array($entityRow)) {
                continue;
            }

            $entityName = isset($entityRow['entity']) ? (string) $entityRow['entity'] : '';
            $add($scores, $entityName, 0.78);

            if (isset($entityRow['synonyms']) && is_array($entityRow['synonyms'])) {
                foreach ($entityRow['synonyms'] as $synonym) {
                    if (!is_string($synonym)) {
                        continue;
                    }
                    $add($scores, $synonym, 0.7);
                }
            }

            if (isset($entityRow['related_terms']) && is_array($entityRow['related_terms'])) {
                foreach ($entityRow['related_terms'] as $related) {
                    if (!is_array($related)) {
                        continue;
                    }
                    $relatedName = isset($related['entity']) ? (string) $related['entity'] : '';
                    if ($relatedName === '') {
                        continue;
                    }
                    $score = isset($related['score']) && is_numeric($related['score']) ? (float) $related['score'] : 0.0;
                    $add($scores, $relatedName, 0.62 + min(0.18, $score * 0.3));
                }
            }

            if (isset($entityRow['facts']) && is_array($entityRow['facts'])) {
                foreach ($entityRow['facts'] as $fact) {
                    if (!is_string($fact)) {
                        continue;
                    }
                    $add($scores, $fact, 0.55);
                }
            }
        }

        if (is_array($synonymGroups)) {
            foreach ($synonymGroups as $group) {
                if (!is_array($group)) {
                    continue;
                }
                $values = $group['synonyms'] ?? ($group['values'] ?? []);
                if (is_array($values)) {
                    foreach ($values as $value) {
                        if (!is_string($value)) {
                            continue;
                        }
                        $add($scores, $value, 0.68);
                    }
                }
            }
        }

        if ($scores === []) {
            return [];
        }

        $ranked = array_values($scores);
        usort(
            $ranked,
            static function (array $left, array $right): int {
                if ($left['score'] === $right['score']) {
                    return $left['value'] <=> $right['value'];
                }

                return $right['score'] <=> $left['score'];
            }
        );

        return array_slice(array_column($ranked, 'value'), 0, 20);
    })();

    $autocompleteSource = $predictiveSuggestions !== [] ? $predictiveSuggestions : $trendingTopics;

    $autocompleteJson = json_encode($autocompleteSource, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($autocompleteJson)) {
        $autocompleteJson = '[]';
    }

    $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES);
    $formatNumber = static function ($value): string {
        if (!is_numeric($value)) {
            return '0';
        }

        $intValue = (int) round((float) $value);
        return number_format($intValue);
    };
    $formatDate = static function (?string $value): ?string {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            $date = new DateTimeImmutable($value);
        } catch (Exception $exception) {
            return $value;
        }

        return $date->format('F j, Y H:i');
    };

    $graphTimeline = [];

    if ($sources !== []) {
        $timelineBuckets = [];

        foreach ($sources as $sourceRow) {
            if (!is_array($sourceRow)) {
                continue;
            }

            $fetchedAt = isset($sourceRow['fetched_at']) ? (string) $sourceRow['fetched_at'] : '';
            if ($fetchedAt === '') {
                continue;
            }

            try {
                $fetchedDate = new DateTimeImmutable($fetchedAt);
            } catch (Exception $exception) {
                continue;
            }

            $dateKey = $fetchedDate->format('Y-m-d');
            $label = $fetchedDate->format('M j');

            if (!isset($timelineBuckets[$dateKey])) {
                $timelineBuckets[$dateKey] = ['count' => 0, 'label' => $label];
            }

            $timelineBuckets[$dateKey]['count']++;
        }

        if ($timelineBuckets !== []) {
            ksort($timelineBuckets);
            $timelineBuckets = array_slice($timelineBuckets, -8, null, true);

            foreach ($timelineBuckets as $dateKey => $bucket) {
                $graphTimeline[] = [
                    'date' => $dateKey,
                    'label' => (string) ($bucket['label'] ?? $dateKey),
                    'count' => (int) ($bucket['count'] ?? 0),
                ];
            }
        }
    }

    $sortedSources = $sources;
    usort($sortedSources, static function ($first, $second): int {
        $firstIsArray = is_array($first);
        $secondIsArray = is_array($second);

        if (!$firstIsArray && !$secondIsArray) {
            return 0;
        }

        if (!$firstIsArray) {
            return 1;
        }

        if (!$secondIsArray) {
            return -1;
        }

        $firstFetched = isset($first['fetched_at']) ? (string) $first['fetched_at'] : '';
        $secondFetched = isset($second['fetched_at']) ? (string) $second['fetched_at'] : '';

        if ($firstFetched === '' && $secondFetched === '') {
            return 0;
        }

        if ($firstFetched === '') {
            return 1;
        }

        if ($secondFetched === '') {
            return -1;
        }

        try {
            $firstDate = new DateTimeImmutable($firstFetched);
            $secondDate = new DateTimeImmutable($secondFetched);
        } catch (Exception $exception) {
            return strcmp($secondFetched, $firstFetched);
        }

        return $secondDate <=> $firstDate;
    });

    $latestSource = $sortedSources[0] ?? null;

    $spotlightTriple = null;
    foreach ($triples as $tripleRow) {
        if (!is_array($tripleRow)) {
            continue;
        }

        $subject = (string) ($tripleRow['subject'] ?? $tripleRow[0] ?? '');
        $relation = (string) ($tripleRow['relation'] ?? $tripleRow[1] ?? '');
        $object = (string) ($tripleRow['object'] ?? $tripleRow[2] ?? '');

        if ($subject === '' || $relation === '' || $object === '') {
            continue;
        }

        $spotlightTriple = [
            'subject' => $subject,
            'relation' => $relation,
            'object' => $object,
        ];

        break;
    }

    $spotlight = null;
    if ($spotlightTriple !== null) {
        $sourceTitle = '';
        $sourceUrl = '';
        $sourcePreview = '';
        $spotlightFetchedAt = $updatedAt;

        if (is_array($latestSource)) {
            $sourceTitle = isset($latestSource['title']) ? (string) $latestSource['title'] : '';
            $sourceUrl = isset($latestSource['url']) ? (string) $latestSource['url'] : '';
            $sourcePreview = isset($latestSource['preview']) ? (string) $latestSource['preview'] : '';

            $candidateFetchedAt = isset($latestSource['fetched_at']) ? (string) $latestSource['fetched_at'] : '';
            if ($candidateFetchedAt !== '') {
                $spotlightFetchedAt = $candidateFetchedAt;
            }
        }

        $spotlight = [
            'subject' => $spotlightTriple['subject'],
            'relation' => $spotlightTriple['relation'],
            'object' => $spotlightTriple['object'],
            'source_title' => $sourceTitle,
            'source_url' => $sourceUrl,
            'source_preview' => $sourcePreview,
            'fetched_at' => $spotlightFetchedAt,
        ];
    }

    $sourcesCount = count($sources);
    $documentsProcessed = (int) ($summary['documents_processed'] ?? $summary['documents'] ?? 0);
    $uniqueEntities = (int) ($summary['unique_entities'] ?? count($entities));
    $triplesCount = (int) ($summary['triples'] ?? count($triples));
    $synonymGroupsCount = (int) ($summary['synonym_groups'] ?? count($synonymGroups));

    $graphCoverageSignals = [];

    if ($updatedAt !== null) {
        $graphCoverageSignals[] = [
            'label' => 'Last merge',
            'value' => $formatDate($updatedAt) ?? $updatedAt,
            'hint' => 'Timestamp of the latest knowledge graph ingestion.',
        ];
    }

    if ($sourcesCount > 0) {
        $graphCoverageSignals[] = [
            'label' => 'Active sources',
            'value' => $formatNumber($sourcesCount),
            'hint' => 'Documents currently linked to this entity graph.',
        ];
    }

    if ($uniqueEntities > 0 && $triplesCount > 0) {
        $graphCoverageSignals[] = [
            'label' => 'Triples per entity',
            'value' => number_format($triplesCount / max(1, $uniqueEntities), 1),
            'hint' => 'Average number of supporting facts tied to each entity.',
        ];
    }

    if ($uniqueEntities > 0 && $synonymGroupsCount > 0) {
        $coveragePercent = (int) round(($synonymGroupsCount / max(1, $uniqueEntities)) * 100);
        $graphCoverageSignals[] = [
            'label' => 'Synonym coverage',
            'value' => $coveragePercent . '%',
            'hint' => 'Share of tracked entities enriched with alias clusters.',
        ];
    }

    if ($graphTimeline !== []) {
        $totalTimelineSources = array_reduce($graphTimeline, static function (int $carry, array $bucket): int {
            return $carry + (int) ($bucket['count'] ?? 0);
        }, 0);

        $averageDaily = $totalTimelineSources > 0
            ? $totalTimelineSources / max(1, count($graphTimeline))
            : 0.0;

        if ($averageDaily > 0) {
            $graphCoverageSignals[] = [
                'label' => 'Avg daily ingestion',
                'value' => number_format($averageDaily, 1),
                'hint' => 'Mean number of sources merged across recent crawls.',
            ];
        }
    }

    $graphCoverageSignals = array_slice($graphCoverageSignals, 0, 5);

    $heroDigest = [
        ['label' => 'Entities tracked', 'value' => $formatNumber($uniqueEntities)],
        ['label' => 'Facts captured', 'value' => $formatNumber($triplesCount)],
        ['label' => 'Sources linked', 'value' => $formatNumber($sourcesCount)],
        ['label' => 'Documents processed', 'value' => $formatNumber($documentsProcessed)],
    ];

    $heroDigest = array_values(array_filter($heroDigest, static function (array $metric): bool {
        return isset($metric['value']) && trim((string) $metric['value']) !== '';
    }));

    $initialState = [
        'endpoints' => [
            'search' => $apiPath,
            'list' => $apiPath,
            'refresh' => $apiPath,
            'crawl' => $apiPath,
        ],
        'paths' => [
            'home' => $homePath,
            'graph' => $repository->path(),
        ],
        'initial' => [
            'search' => $initialSearch,
            'hasGraph' => $hasGraph,
            'top' => $topEntities,
            'suggestions' => $trendingTopics,
            'analytics' => [
                'timeline' => $graphTimeline,
                'coverage' => $graphCoverageSignals,
                'spotlight' => $spotlight,
            ],
        ],
    ];

    $initialJson = json_encode($initialState, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($initialJson)) {
        $initialJson = '{}';
    }

    return [
        'paths' => $paths,
        'assetBase' => $assetBase,
        'assets' => [
            'styles' => $stylesPath,
            'theme' => $themePath,
            'research' => $researchStylesPath,
            'knowledge' => $knowledgeStylesPath,
            'autocomplete' => $autocompleteScriptPath,
            'script' => $scriptPath,
        ],
        'versions' => [
            'styles' => $stylesVersion,
            'theme' => $themeVersion,
            'research' => $researchStylesVersion,
            'knowledge' => $knowledgeStylesVersion,
            'autocomplete' => $autocompleteScriptVersion,
            'script' => $scriptVersion,
        ],
        'navigationLinks' => $navigationLinks,
        'navigationPaths' => $navigationPaths,
        'homePath' => $homePath,
        'searchPath' => $searchPath,
        'graphPath' => $graphPath,
        'docsPath' => $docsPath,
        'apiPath' => $apiPath,
        'escape' => $escape,
        'formatNumber' => $formatNumber,
        'formatDate' => $formatDate,
        'autocompleteJson' => $autocompleteJson,
        'autocompleteSuggestions' => $autocompleteSource,
        'predictiveSuggestions' => $predictiveSuggestions,
        'heroDigest' => $heroDigest,
        'trendingTopics' => $trendingTopics,
        'summary' => $summary,
        'sources' => $sources,
        'entities' => $entities,
        'relations' => $relations,
        'synonymGroups' => $synonymGroups,
        'triples' => $triples,
        'hasGraph' => $hasGraph,
        'spotlight' => $spotlight,
        'graphCoverageSignals' => $graphCoverageSignals,
        'graphTimeline' => $graphTimeline,
        'latestSource' => $latestSource,
        'topEntities' => $topEntities,
        'updatedAt' => $updatedAt,
        'sourcesCount' => $sourcesCount,
        'documentsProcessed' => $documentsProcessed,
        'uniqueEntities' => $uniqueEntities,
        'triplesCount' => $triplesCount,
        'synonymGroupsCount' => $synonymGroupsCount,
        'initialState' => $initialState,
        'initialJson' => $initialJson,
    ];
})();
