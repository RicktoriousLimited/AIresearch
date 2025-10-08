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
                <div class="form-row form-intro">
                    <p>Drop research updates below or start from one of our curated examples to see how the semantic engine structures messy text.</p>
                </div>
                <div class="form-row">
                    <label for="input-text" class="form-label">Source text</label>
                    <textarea id="input-text" name="text" placeholder="Paste one or more research bios, project updates, or company summaries..." required></textarea>
                    <p class="help">We normalise entities, extract lightweight relations, and cluster synonyms across the supplied text.</p>
                </div>
                <div class="form-row input-meta" id="input-meta" aria-live="polite"></div>
                <div class="form-row quick-actions">
                    <label for="file-upload" class="file-picker">
                        <span class="file-icon" aria-hidden="true">📄</span>
                        <span class="file-label">Import text file</span>
                        <input type="file" id="file-upload" accept=".txt,.md,.markdown,.csv,.json">
                    </label>
                    <div class="sample-pills" aria-label="Sample snippets" role="group">
                        <button type="button" class="chip" data-sample="bios">Load sample bios</button>
                        <button type="button" class="chip" data-sample="updates">Load research update</button>
                        <button type="button" class="chip" data-sample="company">Load company summary</button>
                    </div>
                </div>
                <div class="form-row options-row">
                    <label class="toggle">
                        <input type="checkbox" id="continue-state" checked>
                        <span>Continue enriching the same knowledge graph across submissions</span>
                    </label>
                    <button type="button" class="button tertiary small" id="clear-session" aria-label="Reset knowledge graph state">Reset session</button>
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
                <article class="card span-3" id="insights-card" hidden>
                    <h3>Highlights</h3>
                    <ul class="insights-list" id="insights-list"></ul>
                </article>
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

        <section class="panel guidance">
            <header class="panel-header">
                <h2>Tips for richer extractions</h2>
            </header>
            <ul class="guidance-list">
                <li>Feed multiple bios or updates at once. The workbench automatically separates documents by blank lines.</li>
                <li>Use the <strong>Reset session</strong> button when you want to start a brand new graph without previous context.</li>
                <li>Export JSON for downstream tooling or click <strong>Copy summary</strong> to move quick stats into notes.</li>
                <li>Keep relations short and meaningful. The English lexicon filters noisy spans while preserving proper nouns.</li>
            </ul>
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
