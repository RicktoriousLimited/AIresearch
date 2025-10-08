<?php

declare(strict_types=1);

namespace App\Extraction;

use JsonSerializable;

/**
 * Value object describing the output of the semantic extractor.
 */
final class ExtractionResult implements JsonSerializable
{
    /** @var array<int, array{subject: string, relation: string, object: string}> */
    private array $triples;

    /** @var array<int, array{entity: string, synonyms: array<int, string>}> */
    private array $synonyms;

    /** @var array<string, int> */
    private array $relationFrequency;

    /** @var array<string, int> */
    private array $entityFrequency;

    /** @var array<string, int|string> */
    private array $summary;

    /** @var array<string, mixed> */
    private array $state;

    /**
     * @var array<int, array{original: string, cleaned: string, rewritten: string, keywords: array<int, array{token: string, count: int}>, spelling: array<int, array{token: string, count: int, suggestions: array<int, string>}>}>
     */
    private array $documents;

    /**
     * @param array<int, array{subject: string, relation: string, object: string}> $triples
     * @param array<int, array{entity: string, synonyms: array<int, string>}> $synonyms
     * @param array<string, int> $relationFrequency
     * @param array<string, int> $entityFrequency
     * @param array<string, int|string> $summary
     * @param array<string, mixed> $state
     * @param array<int, array{original: string, cleaned: string, rewritten: string, keywords: array<int, array{token: string, count: int}>, spelling: array<int, array{token: string, count: int, suggestions: array<int, string>}>}> $documents
     */
    public function __construct(
        array $triples,
        array $synonyms,
        array $relationFrequency,
        array $entityFrequency,
        array $summary,
        array $state,
        array $documents = []
    ) {
        $this->triples = $triples;
        $this->synonyms = $synonyms;
        $this->relationFrequency = $relationFrequency;
        $this->entityFrequency = $entityFrequency;
        $this->summary = $summary;
        $this->state = $state;
        $this->documents = $documents;
    }

    /**
     * @return array<int, array{subject: string, relation: string, object: string}>
     */
    public function triples(): array
    {
        return $this->triples;
    }

    /**
     * @return array<int, array{entity: string, synonyms: array<int, string>}>
     */
    public function synonyms(): array
    {
        return $this->synonyms;
    }

    /**
     * @return array<string, int>
     */
    public function relationFrequency(): array
    {
        return $this->relationFrequency;
    }

    /**
     * @return array<string, int>
     */
    public function entityFrequency(): array
    {
        return $this->entityFrequency;
    }

    /**
     * @return array<string, int|string>
     */
    public function summary(): array
    {
        return $this->summary;
    }

    /**
     * @return array<string, mixed>
     */
    public function state(): array
    {
        return $this->state;
    }

    /**
     * @return array<int, array{original: string, cleaned: string, rewritten: string, keywords: array<int, array{token: string, count: int}>, spelling: array<int, array{token: string, count: int, suggestions: array<int, string>}>}>
     */
    public function documents(): array
    {
        return $this->documents;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'triples' => $this->triples,
            'synonyms' => $this->synonyms,
            'relations' => $this->relationFrequency,
            'entities' => $this->entityFrequency,
            'summary' => $this->summary,
            'state' => $this->state,
            'documents' => $this->documents,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
