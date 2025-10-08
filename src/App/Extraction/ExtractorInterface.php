<?php

declare(strict_types=1);

namespace App\Extraction;

/**
 * Lightweight contract that allows the research service to orchestrate
 * different extractor implementations (including fakes for tests).
 */
interface ExtractorInterface
{
    /**
     * Analyse multiple documents and optionally merge the supplied engine state.
     *
     * @param array<int, string> $documents
     * @param array<string, mixed>|null $state
     */
    public function analyseMany(array $documents, ?array $state = null): ExtractionResult;

    /**
     * Analyse a single document and optionally merge the supplied engine state.
     *
     * @param array<string, mixed>|null $state
     */
    public function analyse(string $document, ?array $state = null): ExtractionResult;
}

