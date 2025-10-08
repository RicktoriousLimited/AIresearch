<?php

declare(strict_types=1);

require __DIR__ . '/../src/App/bootstrap.php';

$scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php');
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
$scriptPath = $assetBase . '/assets/workbench.js';
$stylesVersion = file_exists(__DIR__ . '/assets/workbench.css') ? (string) filemtime(__DIR__ . '/assets/workbench.css') : (string) time();
$scriptVersion = file_exists(__DIR__ . '/assets/workbench.js') ? (string) filemtime(__DIR__ . '/assets/workbench.js') : (string) time();
$apiEndpoint = $assetBase . '/api/analyse.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AIresearch Semantic Workbench</title>
    <link rel="stylesheet" href="<?= htmlspecialchars($stylesPath . '?v=' . $stylesVersion, ENT_QUOTES) ?>">
</head>
<body data-api="<?= htmlspecialchars($apiEndpoint, ENT_QUOTES) ?>">
    <header class="site-header">
        <div class="container">
            <h1>AIresearch Semantic Workbench</h1>
            <p class="tagline">Paste research bios or summaries and instantly extract knowledge graph triples and synonym clusters.</p>
        </div>
    </header>
    <main class="container">
        <section class="panel">
            <form id="workbench-form" class="workbench-form" novalidate>
                <div class="form-row">
                    <label for="input-text" class="form-label">Source text</label>
                    <textarea id="input-text" name="text" placeholder="Paste one or more research bios, project updates, or company summaries..." required></textarea>
                    <p class="help">We normalise entities, extract lightweight relations, and cluster synonyms across the supplied text.</p>
                </div>
                <div class="form-actions">
                    <button type="submit" class="button primary">Run extraction</button>
                    <button type="reset" class="button secondary" id="reset-button">Clear</button>
                </div>
            </form>
            <div class="status" id="status" role="status" aria-live="polite"></div>
        </section>

        <section class="panel results" id="results" hidden>
            <header class="panel-header">
                <h2>Results</h2>
                <div class="panel-actions">
                    <button type="button" class="button tertiary" id="download-json">Download JSON</button>
                    <button type="button" class="button tertiary" id="copy-summary">Copy summary</button>
                </div>
            </header>
            <div class="grid">
                <article class="card span-2">
                    <h3>Summary</h3>
                    <dl id="summary-list" class="summary-list"></dl>
                </article>
                <article class="card span-2">
                    <h3>Relation frequency</h3>
                    <div id="relations-chart" class="list-block"></div>
                </article>
                <article class="card span-2">
                    <h3>Entities encountered</h3>
                    <div id="entities-chart" class="list-block"></div>
                </article>
                <article class="card span-3">
                    <h3>Triples</h3>
                    <div id="triples-table" class="table-wrapper"></div>
                </article>
                <article class="card span-3">
                    <h3>Synonym groups</h3>
                    <div id="synonyms-list" class="list-block"></div>
                </article>
            </div>
        </section>
    </main>

    <footer class="site-footer">
        <div class="container">
            <p>Powered by the open SemanticEngine. View the <a href="../docs">documentation</a> to extend the pipeline.</p>
        </div>
    </footer>

    <script src="<?= htmlspecialchars($scriptPath . '?v=' . $scriptVersion, ENT_QUOTES) ?>" defer></script>
</body>
</html>
