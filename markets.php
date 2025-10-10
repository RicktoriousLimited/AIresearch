<?php

declare(strict_types=1);

$scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '/markets.php');
$scriptDir = str_replace('\\', '/', dirname($scriptName));
if ($scriptDir === '.' || $scriptDir === '/' || $scriptDir === '\\') {
    $scriptDir = '';
}
$basePath = rtrim($scriptDir, '/');
if ($basePath !== '') {
    $basePath = '/' . ltrim($basePath, '/');
}
$target = ($basePath === '' ? '' : $basePath) . '/index.php';

header('Location: ' . $target, true, 302);
header('Content-Type: text/plain; charset=utf-8');
echo 'Markets has moved to ' . $target . PHP_EOL;
exit;
