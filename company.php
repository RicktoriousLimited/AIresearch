<?php

declare(strict_types=1);

$scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '/company.php');
$scriptDir = str_replace('\\', '/', dirname($scriptName));
if ($scriptDir === '.' || $scriptDir === '/' || $scriptDir === '\\') {
    $scriptDir = '';
}

$basePath = rtrim($scriptDir, '/');
if ($basePath !== '') {
    $basePath = '/' . ltrim($basePath, '/');
}

$assetBase = $basePath === '' ? '' : $basePath;
$target = $assetBase . '/search.php';

header('Location: ' . $target, true, 302);
header('Content-Type: text/plain; charset=utf-8');
echo 'Entity research has moved to ' . $target . PHP_EOL;
exit;
