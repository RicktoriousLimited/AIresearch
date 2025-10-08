#!/usr/bin/env php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    if (!headers_sent()) {
        http_response_code(405);
        header('Content-Type: text/plain; charset=utf-8');
    }

    echo 'The research tool is only available via the command line.' . PHP_EOL;

    return;
}

require __DIR__ . '/src/App/bootstrap.php';

use App\KnowledgeGraph\GraphRepository;
use App\KnowledgeGraph\GraphResearcher;
use App\KnowledgeGraph\ResearchService;

/**
 * @param array<int, string> $argv
 * @return array{
 *     graph: string|null,
 *     entity: string|null,
 *     list: bool,
 *     limit: int,
 *     facts: int,
 *     help: bool,
 *     refresh: bool,
 *     max_age: int
 * }
 */
function parseArguments(array $argv): array
{
    $args = $argv;
    array_shift($args);

    $options = [
        'graph' => null,
        'entity' => null,
        'list' => false,
        'limit' => 10,
        'facts' => 12,
        'help' => false,
        'refresh' => false,
        'max_age' => 168,
    ];

    while ($args !== []) {
        $arg = array_shift($args);
        if ($arg === null) {
            break;
        }

        switch ($arg) {
            case '-h':
            case '--help':
                $options['help'] = true;
                continue 2;
            case '-e':
            case '--entity':
                $value = array_shift($args);
                if ($value === null) {
                    fwrite(STDERR, "Missing value for --entity option." . PHP_EOL);
                    exit(1);
                }
                $options['entity'] = $value;
                continue 2;
            case '-g':
            case '--graph':
                $value = array_shift($args);
                if ($value === null) {
                    fwrite(STDERR, "Missing value for --graph option." . PHP_EOL);
                    exit(1);
                }
                $options['graph'] = $value;
                continue 2;
            case '-l':
            case '--list':
                $options['list'] = true;
                continue 2;
            case '-r':
            case '--refresh':
                $options['refresh'] = true;
                continue 2;
            case '-n':
            case '--limit':
                $value = array_shift($args);
                if ($value === null) {
                    fwrite(STDERR, "Missing value for --limit option." . PHP_EOL);
                    exit(1);
                }
                $options['limit'] = max(1, (int) $value);
                continue 2;
            case '-f':
            case '--facts':
                $value = array_shift($args);
                if ($value === null) {
                    fwrite(STDERR, "Missing value for --facts option." . PHP_EOL);
                    exit(1);
                }
                $options['facts'] = max(1, (int) $value);
                continue 2;
            case '--max-age':
                $value = array_shift($args);
                if ($value === null) {
                    fwrite(STDERR, "Missing value for --max-age option." . PHP_EOL);
                    exit(1);
                }
                $options['max_age'] = max(0, (int) $value);
                continue 2;
        }

        if (strpos($arg, '--entity=') === 0) {
            $options['entity'] = substr($arg, 9);
            continue;
        }

        if (strpos($arg, '--graph=') === 0) {
            $options['graph'] = substr($arg, 8);
            continue;
        }

        if (strpos($arg, '--limit=') === 0) {
            $options['limit'] = max(1, (int) substr($arg, 8));
            continue;
        }

        if (strpos($arg, '--facts=') === 0) {
            $options['facts'] = max(1, (int) substr($arg, 8));
            continue;
        }

        if (strpos($arg, '--max-age=') === 0) {
            $options['max_age'] = max(0, (int) substr($arg, 10));
            continue;
        }

        if ($arg === '--list') {
            $options['list'] = true;
            continue;
        }

        if ($arg === '--refresh') {
            $options['refresh'] = true;
            continue;
        }

        fwrite(STDERR, 'Unknown option: ' . $arg . PHP_EOL);
        exit(1);
    }

    return $options;
}

function printUsage(): void
{
    $usage = <<<'USAGE'
Usage: php research.php [options]

Options:
  -h, --help           Show this help message.
  -g, --graph PATH     Path to a graph snapshot (defaults to storage/graphs/scraped-graph.json).
  -l, --list           Display the highest ranked entities.
  -n, --limit N        Maximum number of entities to show when listing (default 10).
  -e, --entity NAME    Summarise a specific entity (exact name or synonym).
  -f, --facts N        Number of facts to display for an entity summary (default 12).
  -r, --refresh        Re-verify stored sources and rebuild the knowledge graph.
      --max-age HOURS  Maximum age in hours before a source is re-scraped during refresh (default 168).

Examples:
  php research.php --list
  php research.php --entity "Alice Smith"
  php research.php --graph data/custom.json --entity "Horizon Lab"
  php research.php --refresh --list --max-age=24
USAGE;

    fwrite(STDOUT, $usage . PHP_EOL);
}

function renderMetadata(GraphResearcher $researcher): void
{
    $meta = $researcher->metadata();
    $sources = $meta['sources'];
    $updatedAt = $meta['updated_at'];

    if (is_array($sources) && $sources !== []) {
        fwrite(STDOUT, 'Sources tracked: ' . count($sources) . PHP_EOL);
    }

    if ($updatedAt !== null && is_string($updatedAt) && trim($updatedAt) !== '') {
        fwrite(STDOUT, 'Last updated: ' . $updatedAt . PHP_EOL);
    }

    if ((is_array($sources) && $sources !== []) || ($updatedAt !== null && trim((string) $updatedAt) !== '')) {
        fwrite(STDOUT, str_repeat('-', 60) . PHP_EOL);
    }
}

function renderList(GraphResearcher $researcher, int $limit): void
{
    $rows = $researcher->listTopEntities($limit);

    if ($rows === []) {
        fwrite(STDOUT, "No entities found in the graph." . PHP_EOL);
        return;
    }

    fwrite(STDOUT, sprintf("%-32s %6s %8s %8s\n", 'Entity', 'Score', 'Facts', 'Synonyms'));
    fwrite(STDOUT, str_repeat('-', 60) . PHP_EOL);

    foreach ($rows as $row) {
        $score = number_format($row['score'], 2);
        $facts = (string) $row['fact_count'];
        $synonyms = (string) $row['synonym_count'];
        $label = $row['entity'];
        if ($row['eligible']) {
            $label .= ' *';
        }

        $display = $label;
        if (function_exists('mb_strimwidth')) {
            $display = mb_strimwidth($label, 0, 32, '…');
        } elseif (strlen($label) > 32) {
            $display = substr($label, 0, 31) . '…';
        }

        fwrite(
            STDOUT,
            sprintf(
                "%-32s %6s %8s %8s\n",
                $display,
                $score,
                $facts,
                $synonyms
            )
        );
    }
}

/**
 * @param array{
 *     summary: array{refreshed: int, removed: int, skipped: int, active: int},
 *     removed_sources: array<int, array<string, string>>
 * } $report
 */
function renderRefreshSummary(array $report): void
{
    $summary = $report['summary'];
    fwrite(STDOUT, 'Sources refreshed: ' . $summary['refreshed'] . PHP_EOL);
    fwrite(STDOUT, 'Sources skipped:   ' . $summary['skipped'] . PHP_EOL);
    fwrite(STDOUT, 'Sources removed:   ' . $summary['removed'] . PHP_EOL);
    fwrite(STDOUT, 'Active sources:    ' . $summary['active'] . PHP_EOL);

    if ($report['removed_sources'] !== []) {
        fwrite(STDOUT, PHP_EOL . 'Removed URLs:' . PHP_EOL);
        foreach ($report['removed_sources'] as $removed) {
            $url = (string) ($removed['url'] ?? '');
            $reason = (string) ($removed['reason'] ?? '');
            fwrite(STDOUT, '  - ' . $url);
            if ($reason !== '') {
                fwrite(STDOUT, ' (' . $reason . ')');
            }
            fwrite(STDOUT, PHP_EOL);
        }
    }
}

/**
 * @param array<string, int> $histogram
 */
function renderHistogram(string $heading, array $histogram, int $limit = 5): void
{
    if ($histogram === []) {
        return;
    }

    fwrite(STDOUT, $heading . ':' . PHP_EOL);
    $slice = array_slice($histogram, 0, $limit, true);
    foreach ($slice as $key => $count) {
        fwrite(STDOUT, sprintf("  - %s (%d)\n", $key, $count));
    }
}

function renderEntity(GraphResearcher $researcher, string $query, int $factLimit): int
{
    $summary = $researcher->summariseEntity($query, $factLimit);
    if ($summary === null) {
        fwrite(STDOUT, 'No graph facts found for "' . $query . '".' . PHP_EOL);
        return 1;
    }

    fwrite(STDOUT, 'Entity: ' . $summary['entity'] . PHP_EOL);
    fwrite(STDOUT, 'Score:  ' . number_format($summary['score'], 2) . ($summary['eligible'] ? ' (recommended)' : '') . PHP_EOL);
    fwrite(STDOUT, 'Facts:  ' . $summary['fact_count'] . PHP_EOL);

    if ($summary['synonyms'] !== []) {
        fwrite(STDOUT, 'Synonyms: ' . implode(', ', $summary['synonyms']) . PHP_EOL);
    }

    if ($summary['signals'] !== []) {
        fwrite(STDOUT, 'Signals:' . PHP_EOL);
        foreach ($summary['signals'] as $key => $value) {
            fwrite(STDOUT, sprintf("  - %s: %.2f\n", $key, $value));
        }
    }

    if ($summary['support'] !== []) {
        fwrite(STDOUT, 'Support:' . PHP_EOL);
        foreach ($summary['support'] as $key => $value) {
            fwrite(STDOUT, sprintf("  - %s: %d\n", $key, $value));
        }
    }

    renderHistogram('Top relations', $summary['relation_counts']);
    renderHistogram('Top counterparts', $summary['counterpart_counts']);

    $context = $summary['context'];
    if ($context['as_subject'] !== []) {
        renderHistogram('Subject relations', $context['as_subject']);
    }
    if ($context['as_object'] !== []) {
        renderHistogram('Object relations', $context['as_object']);
    }

    if ($summary['facts'] !== []) {
        fwrite(STDOUT, 'Sample facts:' . PHP_EOL);
        foreach ($summary['facts'] as $fact) {
            $prefix = $fact['direction'] === 'incoming' ? '←' : '→';
            fwrite(STDOUT, sprintf("  %s %s %s\n", $prefix, $fact['relation'], $fact['counterpart']));
        }
    }

    return 0;
}

$options = parseArguments($argv);

if ($options['help']) {
    printUsage();
    exit(0);
}

if (!$options['list'] && $options['entity'] === null && !$options['refresh']) {
    printUsage();
    exit(1);
}

$repository = $options['graph'] !== null
    ? new GraphRepository($options['graph'])
    : new GraphRepository();

$service = new ResearchService($repository);

if ($options['refresh']) {
    $report = $service->refreshSources($options['max_age']);
    renderRefreshSummary($report);
    if ($options['list'] || $options['entity']) {
        fwrite(STDOUT, PHP_EOL);
    }
}

$researcher = new GraphResearcher($repository);

renderMetadata($researcher);

$status = 0;

if ($options['list']) {
    renderList($researcher, $options['limit']);
    if ($options['entity'] !== null) {
        fwrite(STDOUT, PHP_EOL);
    }
}

if ($options['entity'] !== null) {
    $status = renderEntity($researcher, $options['entity'], $options['facts']);
}

exit($status);
