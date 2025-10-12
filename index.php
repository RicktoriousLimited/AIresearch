<?php

declare(strict_types=1);

require __DIR__ . '/src/App/bootstrap.php';

use App\Web\PathResolver;
use App\Web\SiteLayout;

function esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$paths = PathResolver::resolve();
$basePath = $paths['basePath'];
$assetBase = $paths['assetBase'];

$stylesPath = PathResolver::url($assetBase, 'assets/styles.css');
$themePath = PathResolver::url($assetBase, 'assets/theme.css');
$homeScriptPath = PathResolver::url($assetBase, 'assets/home.js');
$stylesVersion = file_exists(__DIR__ . '/assets/styles.css') ? (string) filemtime(__DIR__ . '/assets/styles.css') : (string) time();
$themeVersion = file_exists(__DIR__ . '/assets/theme.css') ? (string) filemtime(__DIR__ . '/assets/theme.css') : (string) time();
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
    <link rel="stylesheet" href="<?= esc($themePath . '?v=' . $themeVersion) ?>">
    <link rel="stylesheet" href="<?= esc($stylesPath . '?v=' . $stylesVersion) ?>">
</head>
<body class="site site--home">
<?php SiteLayout::renderHeader($navigationPaths, 'home'); ?>
<main class="site-main home-main">
    <section class="home-focus">
        <div class="home-focus__logo" aria-hidden="true">AIresearch</div>
        <h1 class="home-focus__headline">Search the live intelligence index</h1>
        <p class="home-focus__lead">A lightweight, private window into market-moving news and analysis.</p>
        <form class="home-focus__form" data-home-search action="<?= esc($searchPath) ?>" method="get">
            <label class="visually-hidden" for="home-query">Search AIresearch</label>
            <div class="home-focus__field">
                <span class="home-focus__icon" aria-hidden="true"></span>
                <input
                    id="home-query"
                    type="search"
                    name="q"
                    placeholder="Search AIresearch"
                    data-home-search-input
                    data-home-phrases='<?= esc($sampleQueriesJson) ?>'
                    required>
                <button type="submit" class="home-focus__submit" aria-label="Search"></button>
            </div>
        </form>
        <?php if ($sampleQueries !== []): ?>
            <ul class="home-focus__suggestions">
                <?php foreach ($sampleQueries as $query): ?>
                    <li><button type="button" class="home-focus__chip" data-home-suggestion="<?= esc($query) ?>"><?= esc($query) ?></button></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</main>
<?php SiteLayout::renderFooter($navigationPaths); ?>
<script src="<?= esc($homeScriptPath . '?v=' . $homeScriptVersion) ?>" defer></script>
</body>
</html>
