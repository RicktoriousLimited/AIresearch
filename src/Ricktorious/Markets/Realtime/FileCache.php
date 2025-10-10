<?php

declare(strict_types=1);

namespace Ricktorious\Markets\Realtime;

use RuntimeException;

final class FileCache
{
    public function __construct(private string $directory)
    {
        if (!is_dir($this->directory)) {
            if (!mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
                throw new RuntimeException('Unable to create cache directory: ' . $this->directory);
            }
        }
    }

    public function clear(string $prefix = ''): void
    {
        $pattern = $this->directory . '/' . ($prefix !== '' ? $prefix . '*' : '*');
        foreach (glob($pattern) as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    /**
     * @template TValue
     * @param callable():TValue $callback
     * @return TValue
     */
    public function remember(string $key, int $ttl, callable $callback)
    {
        $path = $this->pathFor($key);
        if (is_file($path)) {
            $expiresAt = (int) filemtime($path) + $ttl;
            if ($expiresAt > time()) {
                $contents = file_get_contents($path);
                if ($contents !== false) {
                    $decoded = json_decode($contents, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        return $decoded;
                    }
                }
            }
        }

        $value = $callback();
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new RuntimeException('Unable to encode cache payload.');
        }

        file_put_contents($path, $encoded, LOCK_EX);

        return $value;
    }

    private function pathFor(string $key): string
    {
        return $this->directory . '/' . sha1($key) . '.json';
    }
}
