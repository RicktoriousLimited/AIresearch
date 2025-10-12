<?php

declare(strict_types=1);

require __DIR__ . '/src/App/bootstrap.php';

use App\Web\PathResolver;

function esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$paths = PathResolver::resolve();
$basePath = $paths['basePath'];
$assetBase = $paths['assetBase'];

$homeStylesPath = PathResolver::url($assetBase, 'assets/home-page.css');
$homeScriptPath = PathResolver::url($assetBase, 'assets/home.js');
$homeStylesVersion = file_exists(__DIR__ . '/assets/home-page.css') ? (string) filemtime(__DIR__ . '/assets/home-page.css') : (string) time();
$homeScriptVersion = file_exists(__DIR__ . '/assets/home.js') ? (string) filemtime(__DIR__ . '/assets/home.js') : (string) time();

$homePath = PathResolver::url($assetBase, 'index.php');
$searchPath = PathResolver::url($assetBase, 'search.php');

$navigationPaths = [
    'home' => $homePath,
    'search' => $searchPath,
];

$sampleQueries = [
    'emerging ai regulation',
    'semiconductor supply chain',
    'climate tech investments',
    'cybersecurity breach response',
];

$sampleQueriesJson = json_encode($sampleQueries, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($sampleQueriesJson)) {
    $sampleQueriesJson = '[]';
}

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AIresearch – Search intelligence</title>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="<?= esc($homeStylesPath . '?v=' . $homeStylesVersion) ?>">
</head>
<body class="home-page">
    <header class="topbar" id="topbar" hidden>
        <div class="topbar__inner">
            <a class="brand" href="<?= esc($homePath) ?>" aria-label="AIresearch home">AI<span>research</span></a>
            <form class="searchbox" action="<?= esc($searchPath) ?>" method="get" role="search" aria-label="Search AIresearch" data-home-search>
                <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M15.5 14h-.79l-.28-.27A6.471 6.471 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79L20 21.5 21.5 20l-6-6zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>
                <label for="q" class="sr-only">Search</label>
                <input id="q" type="search" name="q" placeholder="Search the web" value="" data-home-search-input data-home-phrases='<?= esc($sampleQueriesJson) ?>' required />
                <button class="btn pill" type="submit">Search</button>
            </form>
            <div class="pill" aria-hidden="true">Safe results</div>
        </div>
    </header>
    <main class="home" id="home">
        <div class="home__inner">
            <div class="logo" aria-label="AIresearch">AIresearch<span class="dot">.</span></div>
            <form class="searchbox" action="<?= esc($searchPath) ?>" method="get" role="search" aria-label="Search AIresearch" data-home-search>
                <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M15.5 14h-.79l-.28-.27A6.471 6.471 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79L20 21.5 21.5 20l-6-6zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>
                <label for="home-query" class="sr-only">Search</label>
                <input id="home-query" type="search" name="q" placeholder="Search AIresearch" autofocus data-home-search-input data-home-phrases='<?= esc($sampleQueriesJson) ?>' required />
                <button class="btn pill" type="submit">Google‑like Search</button>
            </form>
            <?php if ($sampleQueries !== []): ?>
                <div class="sub">Try:
                    <div class="chips">
                        <?php foreach ($sampleQueries as $query): ?>
                            <button type="button" class="pill" data-home-suggestion="<?= esc($query) ?>"><?= esc($query) ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>
    <footer class="footer">© <?= date('Y') ?> AIresearch · Fast briefings from your crawler</footer>
    <script>
        (function(){
            const params = new URLSearchParams(window.location.search);
            const hasQuery = params.get('q');
            const topbar = document.getElementById('topbar');
            if (hasQuery && topbar) {
                topbar.hidden = false;
            }
        })();
    </script>
<script src="<?= esc($homeScriptPath . '?v=' . $homeScriptVersion) ?>" defer></script>
</body>
</html>
