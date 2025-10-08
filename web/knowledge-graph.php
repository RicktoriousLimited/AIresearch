<?php

declare(strict_types=1);

require __DIR__ . '/../src/App/bootstrap.php';

use App\KnowledgeGraph\GraphRepository;

$scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '/knowledge-graph.php');
$scriptDir = str_replace('\\', '/', dirname($scriptName));
if ($scriptDir === '.' || $scriptDir === '/' || $scriptDir === '\\') {
    $scriptDir = '';
}
$basePath = rtrim($scriptDir, '/');
if ($basePath !== '') {
    $basePath = '/' . ltrim($basePath, '/');
}

$assetBase = $basePath === '' ? '' : $basePath;

$stylesPath = $assetBase . '/assets/workbench.css';
$homePath = $assetBase . '/index.php';
$stylesVersion = file_exists(__DIR__ . '/assets/workbench.css') ? (string) filemtime(__DIR__ . '/assets/workbench.css') : (string) time();

$repository = new GraphRepository();
$data = $repository->load();
$graph = isset($data['graph']) && is_array($data['graph']) ? $data['graph'] : null;
$sources = isset($data['sources']) && is_array($data['sources']) ? $data['sources'] : [];
$updatedAt = isset($data['updated_at']) && is_string($data['updated_at']) ? $data['updated_at'] : null;

$summary = isset($graph['summary']) && is_array($graph['summary']) ? $graph['summary'] : [];
$relations = isset($graph['relations']) && is_array($graph['relations']) ? $graph['relations'] : [];
$entities = isset($graph['entities']) && is_array($graph['entities']) ? $graph['entities'] : [];
$triples = isset($graph['triples']) && is_array($graph['triples']) ? $graph['triples'] : [];
$synonyms = isset($graph['synonyms']) && is_array($graph['synonyms']) ? $graph['synonyms'] : [];

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
        $date = new \DateTimeImmutable($value);
    } catch (\Exception $exception) {
        return $value;
    }

    return $date->format('F j, Y H:i');
};

$triplesPreview = array_slice($triples, 0, 50);
$synonymPreview = array_slice($synonyms, 0, 25);
$relationsPreview = array_slice($relations, 0, 25, true);
$entitiesPreview = array_slice($entities, 0, 25, true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Global Knowledge Graph &ndash; AIresearch</title>
    <link rel="stylesheet" href="<?= $escape($stylesPath . '?v=' . $stylesVersion) ?>">
</head>
<body class="knowledge-graph">
    <header class="site-header">
        <div class="container">
            <h1>AIresearch Knowledge Graph</h1>
            <p class="tagline">A living graph enriched from every scraped article and research update.</p>
            <p><a class="button tertiary" href="<?= $escape($homePath) ?>">Back to workbench</a></p>
        </div>
    </header>
    <main class="container">
        <section class="panel">
            <header class="panel-header">
                <h2>Shared intelligence</h2>
                <p>The scraper continuously ingests public URLs, extracts semantic triples, and fuses them into this shared knowledge base. Every visitor can explore the latest state of the graph.</p>
            </header>

            <?php if ($graph === null): ?>
                <p class="empty-state">No scraped documents yet. Use the <a href="<?= $escape($homePath) ?>">Semantic Workbench</a> to fetch an article and the results will appear here.</p>
            <?php else: ?>
                <div class="results-overview is-static">
                    <article class="metric-card">
                        <span class="metric-label">Documents processed</span>
                        <span class="metric-value"><?= $escape($formatNumber($summary['documents_processed'] ?? 0)) ?></span>
                        <span class="metric-sub">Sources tracked: <?= $escape($formatNumber(count($sources))) ?></span>
                    </article>
                    <article class="metric-card">
                        <span class="metric-label">Triples extracted</span>
                        <span class="metric-value"><?= $escape($formatNumber($summary['triples'] ?? count($triples))) ?></span>
                        <span class="metric-sub">Synonym groups: <?= $escape($formatNumber($summary['synonym_groups'] ?? count($synonyms))) ?></span>
                    </article>
                    <article class="metric-card">
                        <span class="metric-label">Unique entities</span>
                        <span class="metric-value"><?= $escape($formatNumber($summary['unique_entities'] ?? count($entities))) ?></span>
                        <?php if ($updatedAt !== null): ?>
                            <span class="metric-sub">Updated <?= $escape($formatDate($updatedAt) ?? $updatedAt) ?></span>
                        <?php endif; ?>
                    </article>
                </div>

                <div class="grid">
                    <article class="card span-2">
                        <h3>Sources</h3>
                        <?php if ($sources === []): ?>
                            <p>No sources recorded yet.</p>
                        <?php else: ?>
                            <ul class="sources-list">
                                <?php foreach ($sources as $source): ?>
                                    <?php
                                    $label = is_string($source['title'] ?? null) && trim((string) $source['title']) !== ''
                                        ? (string) $source['title']
                                        : (string) ($source['url'] ?? '');
                                    $sourceUrl = (string) ($source['url'] ?? '');
                                    $characters = $formatNumber($source['characters'] ?? 0);
                                    $fetchedAt = isset($source['fetched_at']) && is_string($source['fetched_at'])
                                        ? ($formatDate($source['fetched_at']) ?? $source['fetched_at'])
                                        : null;
                                    $preview = (string) ($source['preview'] ?? '');
                                    ?>
                                    <li>
                                        <p class="source-title"><a href="<?= $escape($sourceUrl) ?>" target="_blank" rel="noopener noreferrer"><?= $escape($label) ?></a></p>
                                        <p class="source-meta"><?= $escape($characters) ?> characters<?php if ($fetchedAt): ?> • <?= $escape($fetchedAt) ?><?php endif; ?></p>
                                        <?php if ($preview !== ''): ?>
                                            <p class="source-preview"><?= $escape($preview) ?></p>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </article>

                    <article class="card span-2">
                        <h3>Relation frequency</h3>
                        <?php if ($relationsPreview === []): ?>
                            <p>No relations yet.</p>
                        <?php else: ?>
                            <ul class="list-block">
                                <?php foreach ($relationsPreview as $relation => $count): ?>
                                    <li>
                                        <span class="label"><?= $escape((string) $relation) ?></span>
                                        <span class="value"><?= $escape($formatNumber($count)) ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </article>

                    <article class="card span-2">
                        <h3>Entities encountered</h3>
                        <?php if ($entitiesPreview === []): ?>
                            <p>No entities yet.</p>
                        <?php else: ?>
                            <ul class="list-block">
                                <?php foreach ($entitiesPreview as $entity => $count): ?>
                                    <li>
                                        <span class="label"><?= $escape((string) $entity) ?></span>
                                        <span class="value"><?= $escape($formatNumber($count)) ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </article>

                    <article class="card span-3">
                        <h3>Triples</h3>
                        <?php if ($triplesPreview === []): ?>
                            <p>No triples extracted yet.</p>
                        <?php else: ?>
                            <div class="table-wrapper">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th scope="col">Subject</th>
                                            <th scope="col">Relation</th>
                                            <th scope="col">Object</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($triplesPreview as $triple): ?>
                                            <tr>
                                                <td><?= $escape((string) ($triple['subject'] ?? $triple[0] ?? '')) ?></td>
                                                <td><?= $escape((string) ($triple['relation'] ?? $triple[1] ?? '')) ?></td>
                                                <td><?= $escape((string) ($triple['object'] ?? $triple[2] ?? '')) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                                <?php if (count($triples) > count($triplesPreview)): ?>
                                    <p class="table-note">Showing first <?= $escape((string) count($triplesPreview)) ?> of <?= $escape((string) count($triples)) ?> triples.</p>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </article>

                    <article class="card span-3">
                        <h3>Synonym groups</h3>
                        <?php if ($synonymPreview === []): ?>
                            <p>No synonym groups yet.</p>
                        <?php else: ?>
                            <ul class="list-block">
                                <?php foreach ($synonymPreview as $pair): ?>
                                    <?php
                                    $entity = is_array($pair) ? ($pair['entity'] ?? $pair[0] ?? '') : '';
                                    $synonymList = [];
                                    if (is_array($pair)) {
                                        $rawSynonyms = $pair['synonyms'] ?? $pair[1] ?? [];
                                        if (is_array($rawSynonyms)) {
                                            foreach ($rawSynonyms as $synonym) {
                                                if (is_string($synonym)) {
                                                    $synonymList[] = $synonym;
                                                }
                                            }
                                        }
                                    }
                                    ?>
                                    <li>
                                        <span class="label"><?= $escape((string) $entity) ?></span>
                                        <?php if ($synonymList !== []): ?>
                                            <span class="value"><?= $escape(implode(', ', $synonymList)) ?></span>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </article>
                </div>
            <?php endif; ?>
        </section>
    </main>
    <footer class="site-footer">
        <div class="container">
            <p>Knowledge graph snapshots are stored at <code><?= $escape($repository->path()) ?></code>. Scrape additional URLs from the <a href="<?= $escape($homePath) ?>">Semantic Workbench</a>.</p>
        </div>
    </footer>
</body>
</html>
