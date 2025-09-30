<?php
declare(strict_types=1);

require __DIR__ . '/../src/SemanticEngine.php';

$inputText = isset($_POST['input_text']) ? trim((string) $_POST['input_text']) : '';
$validExports = ['triples', 'synonyms'];
$selectedExports = isset($_POST['exports']) ? (array) $_POST['exports'] : $validExports;
$selectedExports = array_values(array_intersect($validExports, array_map('strval', $selectedExports)));
if ($selectedExports === []) {
    $selectedExports = $validExports;
}

$errors = [];
$results = [
    'triples' => [],
    'synonyms' => [],
];
$submitted = $_SERVER['REQUEST_METHOD'] === 'POST';

if ($submitted) {
    if ($inputText === '') {
        $errors[] = 'Please provide some text to analyse.';
    }

    if ($errors === []) {
        $engine = new SemanticEngine();
        $engine->extractRelations($inputText);

        if (in_array('triples', $selectedExports, true)) {
            $results['triples'] = $engine->iterTriples();
        }

        if (in_array('synonyms', $selectedExports, true)) {
            $results['synonyms'] = $engine->iterSynonyms();
        }
    }
}

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function renderTriplesTable(array $triples): string
{
    if ($triples === []) {
        return '<p class="empty">No triples extracted.</p>';
    }

    $rows = [];
    foreach ($triples as $triple) {
        $subject = escape((string) ($triple[0] ?? ''));
        $relation = escape((string) ($triple[1] ?? ''));
        $object = escape((string) ($triple[2] ?? ''));
        $rows[] = "            <tr><td>{$subject}</td><td>{$relation}</td><td>{$object}</td></tr>";
    }

    $table = [
        '        <table class="results-table">',
        '            <thead>',
        '                <tr><th>Subject</th><th>Relation</th><th>Object</th></tr>',
        '            </thead>',
        '            <tbody>',
        implode(PHP_EOL, $rows),
        '            </tbody>',
        '        </table>',
    ];

    return implode(PHP_EOL, $table);
}

function renderSynonymsList(array $synonyms): string
{
    if ($synonyms === []) {
        return '<p class="empty">No synonyms stored.</p>';
    }

    $items = [];
    foreach ($synonyms as $pair) {
        $entity = escape((string) ($pair[0] ?? ''));
        $values = array_map(
            static fn($value): string => escape((string) $value),
            array_values($pair[1] ?? [])
        );
        $items[] = sprintf('<li><span class="entity">%s</span> &rarr; %s</li>', $entity, implode(', ', $values));
    }

    return '<ul class="synonyms-list">' . implode(PHP_EOL, $items) . '</ul>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Semantic Engine Playground</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        :root {
            color-scheme: light dark;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background-color: #f5f5f5;
            color: #1c1c1c;
        }
        body {
            margin: 0;
        }
        .page {
            max-width: 960px;
            margin: 0 auto;
            padding: 2rem 1rem 4rem;
        }
        h1 {
            font-size: 2rem;
            margin-bottom: 0.75rem;
        }
        p.subtitle {
            margin-top: 0;
            color: #555;
        }
        .sr-only {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }
        form {
            background: #ffffff;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 1px 12px rgba(0, 0, 0, 0.08);
        }
        textarea {
            width: 100%;
            min-height: 180px;
            font: inherit;
            line-height: 1.5;
            padding: 0.75rem;
            border-radius: 8px;
            border: 1px solid #ccc;
            resize: vertical;
        }
        fieldset {
            border: none;
            padding: 0;
            margin: 1rem 0;
            display: flex;
            gap: 1rem;
        }
        .checkbox {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        button {
            background-color: #2b6cb0;
            border: none;
            color: #fff;
            font-weight: 600;
            padding: 0.75rem 1.5rem;
            border-radius: 999px;
            cursor: pointer;
        }
        button:hover {
            background-color: #2c5282;
        }
        .messages {
            margin: 1.5rem 0 0;
            list-style: none;
            padding: 0;
        }
        .messages li {
            background: #fed7d7;
            color: #742a2a;
            padding: 0.75rem 1rem;
            border-radius: 8px;
            margin-bottom: 0.5rem;
        }
        .results {
            margin-top: 2rem;
        }
        .results section {
            background: #ffffff;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 1px 12px rgba(0, 0, 0, 0.08);
            margin-bottom: 1.5rem;
        }
        .results h2 {
            margin-top: 0;
        }
        .results-table {
            width: 100%;
            border-collapse: collapse;
        }
        .results-table th,
        .results-table td {
            border-bottom: 1px solid #e2e8f0;
            padding: 0.5rem 0.75rem;
            text-align: left;
        }
        .results-table th {
            background: #f1f5f9;
            font-weight: 600;
        }
        .synonyms-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .synonyms-list li {
            padding: 0.4rem 0;
            border-bottom: 1px solid #e2e8f0;
        }
        .synonyms-list li:last-child {
            border-bottom: none;
        }
        .synonyms-list .entity {
            font-weight: 600;
        }
        .empty {
            color: #666;
            font-style: italic;
        }
        @media (max-width: 640px) {
            fieldset {
                flex-direction: column;
                align-items: flex-start;
            }
            button {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="page">
        <header>
            <h1>Semantic Engine Playground</h1>
            <p class="subtitle">Paste a biography or research summary to extract triples and synonyms.</p>
        </header>
        <form method="post" novalidate>
            <label for="input_text">Input text</label>
            <textarea id="input_text" name="input_text" placeholder="Alice Smith is a Senior Data Scientist. Alice Smith aka Ally Smith." required><?php echo escape($inputText); ?></textarea>
            <fieldset>
                <legend class="sr-only">Data to display</legend>
                <?php foreach ($validExports as $export): ?>
                    <?php $checked = in_array($export, $selectedExports, true) ? 'checked' : ''; ?>
                    <label class="checkbox">
                        <input type="checkbox" name="exports[]" value="<?php echo escape($export); ?>" <?php echo $checked; ?>>
                        <?php echo ucfirst($export); ?>
                    </label>
                <?php endforeach; ?>
            </fieldset>
            <button type="submit">Run extraction</button>
        </form>

        <?php if ($errors !== []): ?>
            <ul class="messages">
                <?php foreach ($errors as $error): ?>
                    <li><?php echo escape($error); ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ($submitted && $errors === []): ?>
            <div class="results" aria-live="polite">
                <?php if (in_array('triples', $selectedExports, true)): ?>
                    <section>
                        <h2>Triples</h2>
                        <?php echo renderTriplesTable($results['triples']); ?>
                    </section>
                <?php endif; ?>
                <?php if (in_array('synonyms', $selectedExports, true)): ?>
                    <section>
                        <h2>Synonyms</h2>
                        <?php echo renderSynonymsList($results['synonyms']); ?>
                    </section>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
