<?php

declare(strict_types=1);

namespace App\Support;

use function array_filter;
use function array_map;
use function array_unique;
use function array_values;
use function is_array;
use function is_string;
use function preg_split;
use function trim;

/**
 * Helper utilities for normalising comma and newline separated user input.
 */
final class InputNormaliser
{
    private function __construct()
    {
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    public static function selectors($value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\n,]+/', $value) ?: [];
        }

        if (!is_array($value)) {
            return [];
        }

        $selectors = array_map(
            static function ($entry): string {
                return is_string($entry) ? trim($entry) : '';
            },
            $value
        );

        $selectors = array_values(array_filter(
            $selectors,
            static fn(string $selector): bool => $selector !== ''
        ));

        return array_values(array_unique($selectors));
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    public static function seeds($value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\n,]+/', $value) ?: [];
        }

        if (!is_array($value)) {
            return [];
        }

        $seeds = array_map(
            static function ($seed): string {
                return is_string($seed) ? trim($seed) : '';
            },
            $value
        );

        $seeds = array_values(array_filter(
            $seeds,
            static fn(string $seed): bool => $seed !== ''
        ));

        return array_values(array_unique($seeds));
    }
}
