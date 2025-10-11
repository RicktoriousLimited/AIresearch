<?php

declare(strict_types=1);

namespace App\Web;

final class PathResolver
{
    /**
     * @return array{basePath: string, assetBase: string}
     */
    public static function resolve(?string $scriptName = null): array
    {
        $script = is_string($scriptName) && $scriptName !== ''
            ? $scriptName
            : (string) ($_SERVER['SCRIPT_NAME'] ?? '');

        if ($script === '') {
            $script = '/';
        }

        $directory = str_replace('\\', '/', dirname($script));
        if ($directory === '.' || $directory === '/' || $directory === '\\') {
            $directory = '';
        }

        $basePath = rtrim($directory, '/');
        if ($basePath !== '') {
            $basePath = '/' . ltrim($basePath, '/');
        }

        $assetBase = $basePath === '' ? '' : $basePath;

        return [
            'basePath' => $basePath,
            'assetBase' => $assetBase,
        ];
    }

    public static function url(string $basePath, string $path): string
    {
        $normalized = '/' . ltrim($path, '/');

        if ($basePath === '' || $basePath === '/') {
            return $normalized;
        }

        return rtrim($basePath, '/') . $normalized;
    }
}
