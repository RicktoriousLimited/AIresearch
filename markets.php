<?php

declare(strict_types=1);

require __DIR__ . '/src/App/bootstrap.php';

use App\Web\PathResolver;

$paths = PathResolver::resolve();
$assetBase = $paths['assetBase'];
$target = PathResolver::url($assetBase, 'index.php');

header('Location: ' . $target, true, 302);
header('Content-Type: text/plain; charset=utf-8');
echo 'Markets has moved to ' . $target . PHP_EOL;
exit;
