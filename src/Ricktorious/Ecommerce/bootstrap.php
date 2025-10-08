<?php

declare(strict_types=1);

use Ricktorious\Ecommerce\App\Kernel;

$baseDir = __DIR__;

spl_autoload_register(static function (string $class) use ($baseDir): void {
    $prefix = 'Ricktorious\\Ecommerce\\';
    if (str_starts_with($class, $prefix)) {
        $relative = substr($class, strlen($prefix));
        $path = $baseDir . '/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    }
});

if (!function_exists('ricktorious_ecommerce_kernel')) {
    /**
     * @param array<string, mixed> $config
     */
    function ricktorious_ecommerce_kernel(array $config = []): Kernel
    {
        $kernel = new Kernel($config);
        $kernel->boot();

        return $kernel;
    }
}
