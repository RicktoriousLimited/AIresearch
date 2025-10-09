<?php

declare(strict_types=1);

namespace App\Extraction;

use function preg_match;
use function str_contains;
use function strtolower;
use function trim;

/**
 * Provide a consistent, typed schema for relations emitted by the semantic engine.
 */
final class RelationSchemaMapper
{
    /**
     * Static definitions for the high-signal relations that the engine emits.
     *
     * @var array<string, array{canonical: string, type: string, confidence: float}>
     */
    private const PRIMARY_RELATION_MAP = [
        'isa' => ['canonical' => 'classification', 'type' => 'taxonomy', 'confidence' => 0.95],
        'tagline' => ['canonical' => 'description', 'type' => 'summary', 'confidence' => 0.7],
        'synonym' => ['canonical' => 'alias', 'type' => 'equivalence', 'confidence' => 0.9],
    ];

    /**
     * Map a raw relation string to a compact schema that downstream systems can rely on.
     *
     * @return array{canonical: string, type: string, confidence: float, status: string}
     */
    public function map(string $relation): array
    {
        $normalized = strtolower(trim($relation));
        if ($normalized === '') {
            return $this->fallback('uncategorized');
        }

        if (isset(self::PRIMARY_RELATION_MAP[$normalized])) {
            $definition = self::PRIMARY_RELATION_MAP[$normalized];

            return [
                'canonical' => $definition['canonical'],
                'type' => $definition['type'],
                'confidence' => $definition['confidence'],
                'status' => 'asserted',
            ];
        }

        if (str_contains($normalized, 'hostage')) {
            return [
                'canonical' => 'hostage_status',
                'type' => 'event',
                'confidence' => 0.65,
                'status' => 'reported',
            ];
        }

        if (preg_match('/^action-[a-z0-9]+$/', $normalized) === 1) {
            return [
                'canonical' => 'event_action',
                'type' => 'event',
                'confidence' => 0.6,
                'status' => 'asserted',
            ];
        }

        if (preg_match('/^(?:located|location)/', $normalized) === 1) {
            return [
                'canonical' => 'location',
                'type' => 'spatial',
                'confidence' => 0.8,
                'status' => 'asserted',
            ];
        }

        if (preg_match('/^(?:count|total|number)/', $normalized) === 1) {
            return [
                'canonical' => 'statistic',
                'type' => 'metric',
                'confidence' => 0.75,
                'status' => 'reported',
            ];
        }

        return $this->fallback($normalized);
    }

    /**
     * Provide a conservative default for unknown relations.
     *
     * @return array{canonical: string, type: string, confidence: float, status: string}
     */
    private function fallback(string $canonical): array
    {
        return [
            'canonical' => $canonical,
            'type' => 'other',
            'confidence' => 0.5,
            'status' => 'asserted',
        ];
    }
}
