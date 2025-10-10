<?php

declare(strict_types=1);

use Ricktorious\Markets\Kernel;

$baseDir = __DIR__;

spl_autoload_register(static function (string $class) use ($baseDir): void {
    $prefix = 'Ricktorious\\Markets\\';
    if (str_starts_with($class, $prefix)) {
        $relative = substr($class, strlen($prefix));
        $path = $baseDir . '/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    }
});

if (!function_exists('ricktorious_markets_kernel')) {
    /**
     * @param array<string, mixed> $config
     */
    function ricktorious_markets_kernel(array $config = []): Kernel
    {
        $kernel = new Kernel($config);
        $kernel->boot();

        return $kernel;
    }
}
