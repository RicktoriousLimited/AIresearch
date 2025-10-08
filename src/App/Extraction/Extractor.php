<?php

declare(strict_types=1);

namespace App\Extraction;

use DateTimeImmutable;
use SemanticEngine;

/**
 * High-level orchestration around the SemanticEngine for consistent results.
 */
final class Extractor
{
    /**
     * Analyse a collection of documents.
     *
     * @param array<int, string> $documents
     * @param array<string, mixed>|null $state Previously exported engine state for incremental ingestion.
     */
    public function analyseMany(array $documents, ?array $state = null): ExtractionResult
    {
        $engine = $state === null ? new SemanticEngine() : SemanticEngine::fromArray($state);

        $processedCount = 0;
        $receivedCount = 0;

        foreach ($documents as $document) {
            if (!is_string($document)) {
                continue;
            }

            $receivedCount++;

            $text = trim($document);
            if ($text === '') {
                continue;
            }

            $engine->extractRelations($text);
            $processedCount++;
        }

        return $this->buildResult($engine, $processedCount, $receivedCount);
    }

    /**
     * Analyse a single document.
     *
     * @param string $document
     * @param array<string, mixed>|null $state Previously exported engine state for incremental ingestion.
     */
    public function analyse(string $document, ?array $state = null): ExtractionResult
    {
        return $this->analyseMany([$document], $state);
    }

    private function buildResult(SemanticEngine $engine, int $processedCount, int $receivedCount): ExtractionResult
    {
        $triples = array_map(
            static fn(array $triple): array => [
                'subject' => (string) ($triple[0] ?? ''),
                'relation' => (string) ($triple[1] ?? ''),
                'object' => (string) ($triple[2] ?? ''),
            ],
            $engine->iterTriples()
        );

        $synonyms = array_map(
            static function (array $pair): array {
                $entity = (string) ($pair[0] ?? '');
                $rawValues = $pair[1] ?? [];
                $synonymValues = [];

                if (is_array($rawValues)) {
                    foreach ($rawValues as $value) {
                        if (!is_string($value)) {
                            continue;
                        }
                        $synonymValues[] = $value;
                    }
                }

                return [
                    'entity' => $entity,
                    'synonyms' => $synonymValues,
                ];
            },
            $engine->iterSynonyms()
        );

        $relationFrequency = [];
        $entityFrequency = [];

        foreach ($triples as $triple) {
            $relation = $triple['relation'];
            $relationFrequency[$relation] = ($relationFrequency[$relation] ?? 0) + 1;

            $subject = $triple['subject'];
            $object = $triple['object'];
            $entityFrequency[$subject] = ($entityFrequency[$subject] ?? 0) + 1;
            $entityFrequency[$object] = ($entityFrequency[$object] ?? 0) + 1;
        }

        foreach ($synonyms as $pair) {
            $entity = $pair['entity'];
            $entityFrequency[$entity] = $entityFrequency[$entity] ?? 0;
            foreach ($pair['synonyms'] as $synonym) {
                $entityFrequency[$synonym] = $entityFrequency[$synonym] ?? 0;
            }
        }

        ksort($relationFrequency);
        ksort($entityFrequency);

        $summary = [
            'documents_received' => $receivedCount,
            'documents_processed' => $processedCount,
            'triples' => count($triples),
            'synonym_groups' => count($synonyms),
            'unique_entities' => count($entityFrequency),
            'generated_at' => (new DateTimeImmutable())->format(DATE_ATOM),
        ];

        return new ExtractionResult(
            $triples,
            $synonyms,
            $relationFrequency,
            $entityFrequency,
            $summary,
            $engine->toArray()
        );
    }
}
