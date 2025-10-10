<?php
if (PHP_SAPI !== 'cli') {
    require __DIR__ . '/storefront.php';
    return;
}

require __DIR__ . '/src/SemanticEngine.php';

function printUsage(): void
{
    $usage = <<<'USAGE'
Usage: php cli.php [options] [file ...]

Options:
  -h, --help              Show this help message.
  -f, --format FORMAT     Output format: text (default), json, csv.
  -e, --export TYPE       Data to export: triples, synonyms. May be repeated.
  -o, --output PATH       Write output to the specified file instead of STDOUT.
  -s, --snapshot PATH     Load an existing engine snapshot from PATH and
                          overwrite it with the updated state after processing.

If no files are provided, the command reads from STDIN. Use "-" as a file
argument to explicitly read from STDIN alongside other files.
USAGE;

    fwrite(STDOUT, $usage . PHP_EOL);
}

function fatalError(string $message): void
{
    fwrite(STDERR, 'Error: ' . $message . PHP_EOL);
    exit(1);
}

/**
 * @param array<int, string> $argv
 * @return array{options: array{format: string, exports: array<int, string>, output: ?string, help: bool, snapshot: ?string}, files: array<int, string>}
 */
function parseArguments(array $argv): array
{
    $args = $argv;
    array_shift($args);

    $options = [
        'format' => 'text',
        'exports' => [],
        'output' => null,
        'help' => false,
        'snapshot' => null,
    ];
    $files = [];

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
            case '-f':
            case '--format':
                $value = array_shift($args);
                if ($value === null) {
                    fatalError('Missing value for --format option.');
                }
                $options['format'] = $value;
                continue 2;
            case '-e':
            case '--export':
                $value = array_shift($args);
                if ($value === null) {
                    fatalError('Missing value for --export option.');
                }
                $options['exports'][] = $value;
                continue 2;
            case '-o':
            case '--output':
                $value = array_shift($args);
                if ($value === null) {
                    fatalError('Missing value for --output option.');
                }
                $options['output'] = $value;
                continue 2;
            case '-s':
            case '--snapshot':
                $value = array_shift($args);
                if ($value === null) {
                    fatalError('Missing value for --snapshot option.');
                }
                $options['snapshot'] = $value;
                continue 2;
        }

        if (strpos($arg, '--format=') === 0) {
            $options['format'] = substr($arg, 9);
            continue;
        }

        if (strpos($arg, '--export=') === 0) {
            $options['exports'][] = substr($arg, 9);
            continue;
        }

        if (strpos($arg, '--output=') === 0) {
            $options['output'] = substr($arg, 9);
            continue;
        }

        if (strpos($arg, '--snapshot=') === 0) {
            $options['snapshot'] = substr($arg, 11);
            continue;
        }

        $files[] = $arg;
    }

    return ['options' => $options, 'files' => $files];
}

/**
 * @param array<int, string> $files
 * @return array<int, string>
 */
function readInputTexts(array $files): array
{
    $texts = [];
    $stdinConsumed = false;

    if ($files === []) {
        $stdin = stream_get_contents(STDIN);
        if ($stdin === false) {
            fatalError('Unable to read from STDIN.');
        }
        if (trim($stdin) !== '') {
            $texts[] = $stdin;
        }
        return $texts;
    }

    foreach ($files as $file) {
        if ($file === '-') {
            if ($stdinConsumed) {
                continue;
            }
            $stdin = stream_get_contents(STDIN);
            if ($stdin === false) {
                fatalError('Unable to read from STDIN.');
            }
            if (trim($stdin) !== '') {
                $texts[] = $stdin;
            }
            $stdinConsumed = true;
            continue;
        }

        if (!is_file($file) || !is_readable($file)) {
            fatalError(sprintf('Cannot read file "%s".', $file));
        }

        $contents = file_get_contents($file);
        if ($contents === false) {
            fatalError(sprintf('Failed to read file "%s".', $file));
        }

        if (trim($contents) !== '') {
            $texts[] = $contents;
        }
    }

    return $texts;
}

/**
 * @param array<int, array{0: string, 1: string, 2: string}> $triples
 */
function formatTriplesText(array $triples): string
{
    if ($triples === []) {
        return "No triples extracted." . PHP_EOL;
    }

    $lines = ["Triples:" . PHP_EOL];
    foreach ($triples as [$subject, $relation, $object]) {
        $lines[] = sprintf("- %s | %s | %s", $subject, $relation, $object) . PHP_EOL;
    }

    return implode('', $lines);
}

/**
 * @param array<int, array{0: string, 1: array<int, string>}> $synonyms
 */
function formatSynonymsText(array $synonyms): string
{
    if ($synonyms === []) {
        return "No synonyms stored." . PHP_EOL;
    }

    $lines = ["Synonyms:" . PHP_EOL];
    foreach ($synonyms as [$entity, $values]) {
        $lines[] = sprintf("- %s => %s", $entity, implode(', ', $values)) . PHP_EOL;
    }

    return implode('', $lines);
}

/**
 * @param array<int, array<int, string>> $rows
 * @param array<int, string> $header
 */
function buildCsv(array $rows, array $header): string
{
    $handle = fopen('php://temp', 'r+');
    if ($handle === false) {
        fatalError('Failed to initialise CSV buffer.');
    }

    $delimiter = ',';
    $enclosure = "\"";
    $escape = "\\";
    fputcsv($handle, $header, $delimiter, $enclosure, $escape);
    foreach ($rows as $row) {
        fputcsv($handle, $row, $delimiter, $enclosure, $escape);
    }

    rewind($handle);
    $csv = stream_get_contents($handle);
    if ($csv === false) {
        fclose($handle);
        fatalError('Failed to read CSV buffer.');
    }

    fclose($handle);
    return $csv;
}

$parsed = parseArguments($argv);
$options = $parsed['options'];
$files = $parsed['files'];

if ($options['help']) {
    printUsage();
    exit(0);
}

$format = strtolower($options['format']);
$validFormats = ['text', 'json', 'csv'];
if (!in_array($format, $validFormats, true)) {
    fatalError(sprintf('Invalid format "%s". Expected one of: %s.', $options['format'], implode(', ', $validFormats)));
}

$exports = $options['exports'] === [] ? ['triples', 'synonyms'] : $options['exports'];
$exports = array_values(array_unique(array_map('strtolower', $exports)));
$validExports = ['triples', 'synonyms'];
foreach ($exports as $export) {
    if (!in_array($export, $validExports, true)) {
        fatalError(sprintf('Invalid export "%s". Expected one of: %s.', $export, implode(', ', $validExports)));
    }
}

if ($format === 'csv' && count($exports) !== 1) {
    fatalError('CSV format supports exporting a single data type. Provide exactly one --export option.');
}

$texts = readInputTexts($files);
if ($texts === []) {
    fatalError('No input text provided. Specify files or pipe text via STDIN.');
}

$snapshotPath = $options['snapshot'];
if ($snapshotPath !== null) {
    $snapshotPath = trim($snapshotPath);
    if ($snapshotPath === '') {
        $snapshotPath = null;
    }
}

if ($snapshotPath !== null && is_file($snapshotPath)) {
    $contents = file_get_contents($snapshotPath);
    if ($contents === false) {
        fatalError(sprintf('Failed to read snapshot file "%s".', $snapshotPath));
    }

    $decoded = json_decode($contents, true);
    if (!is_array($decoded)) {
        fatalError(sprintf('Snapshot file "%s" does not contain valid JSON.', $snapshotPath));
    }

    $engine = SemanticEngine::fromArray($decoded);
} else {
    if ($snapshotPath !== null && file_exists($snapshotPath) && !is_file($snapshotPath)) {
        fatalError(sprintf('Snapshot path "%s" is not a regular file.', $snapshotPath));
    }
    $engine = new SemanticEngine();
}

foreach ($texts as $text) {
    $engine->extractRelations($text);
}

$outputPayload = [];
$triples = [];
$synonyms = [];

if (in_array('triples', $exports, true)) {
    $triples = $engine->iterTriples();
}
if (in_array('synonyms', $exports, true)) {
    $synonyms = $engine->iterSynonyms();
}

switch ($format) {
    case 'json':
        if (in_array('triples', $exports, true)) {
            $outputPayload['triples'] = array_map(
                static fn(array $triple): array => [
                    'subject' => $triple[0],
                    'relation' => $triple[1],
                    'object' => $triple[2],
                ],
                $triples
            );
        }
        if (in_array('synonyms', $exports, true)) {
            $outputPayload['synonyms'] = array_map(
                static fn(array $pair): array => [
                    'entity' => $pair[0],
                    'synonyms' => $pair[1],
                ],
                $synonyms
            );
        }
        $encoded = json_encode($outputPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            fatalError('Failed to encode JSON output.');
        }
        $outputData = $encoded . PHP_EOL;
        break;
    case 'csv':
        if ($exports[0] === 'triples') {
            $rows = array_map(static fn(array $triple): array => [$triple[0], $triple[1], $triple[2]], $triples);
            $outputData = buildCsv($rows, ['subject', 'relation', 'object']);
        } else {
            $rows = array_map(static fn(array $pair): array => [$pair[0], implode(';', $pair[1])], $synonyms);
            $outputData = buildCsv($rows, ['entity', 'synonyms']);
        }
        break;
    default:
        $sections = [];
        if (in_array('triples', $exports, true)) {
            $sections[] = formatTriplesText($triples);
        }
        if (in_array('synonyms', $exports, true)) {
            $sections[] = formatSynonymsText($synonyms);
        }
        $outputData = implode(PHP_EOL, array_map('rtrim', $sections)) . PHP_EOL;
        break;
}

if ($options['output'] !== null) {
    $bytes = @file_put_contents($options['output'], $outputData);
    if ($bytes === false) {
        fatalError(sprintf('Failed to write output to "%s".', $options['output']));
    }
    if ($snapshotPath !== null) {
        $snapshotData = json_encode($engine->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($snapshotData === false) {
            fatalError('Failed to encode snapshot data as JSON.');
        }
        $bytes = @file_put_contents($snapshotPath, $snapshotData . PHP_EOL);
        if ($bytes === false) {
            fatalError(sprintf('Failed to write snapshot to "%s".', $snapshotPath));
        }
    }
    exit(0);
}

echo $outputData;

if ($snapshotPath !== null) {
    $snapshotData = json_encode($engine->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($snapshotData === false) {
        fatalError('Failed to encode snapshot data as JSON.');
    }
    $bytes = @file_put_contents($snapshotPath, $snapshotData . PHP_EOL);
    if ($bytes === false) {
        fatalError(sprintf('Failed to write snapshot to "%s".', $snapshotPath));
    }
}
