<?php

declare(strict_types=1);

namespace App\Extraction;

use function count;
use function max;
use function round;
use function strlen;

/**
 * Convert extraction artefacts into AI-ready training dataset rows.
 */
final class DatasetBuilder
{
    /**
     * @param array<int, array{original: string, cleaned: string, rewritten: string, keywords: array<int, array{token: string, count: int}>, spelling: array<int, array{token: string, count: int, suggestions: array<int, string>}>}> $documents
     * @param array<int, array{subject: string, relation: string, object: string}> $triples
     * @param array<int, array{entity: string, synonyms: array<int, string>}> $synonyms
     * @param array<string, int|string> $summary
     *
     * @return array{rows: array<int, array<string, mixed>>, schema: array<string, mixed>, statistics: array<string, mixed>}
     */
    public function build(array $documents, array $triples, array $synonyms, array $summary): array
    {
        $rows = [];
        $taskTally = [];
        $characterTotal = 0;
        $wordTotal = 0;

        $structuredEntities = $this->normaliseTriples($triples);
        $synonymMap = $this->normaliseSynonyms($synonyms);

        foreach ($documents as $index => $document) {
            $original = trim((string) ($document['original'] ?? ''));
            $cleaned = trim((string) ($document['cleaned'] ?? $original));
            $rewrite = trim((string) ($document['rewritten'] ?? ''));
            $keywords = $this->extractKeywords($document['keywords'] ?? []);

            if ($original === '' && $cleaned === '' && $rewrite === '') {
                continue;
            }

            $tasks = $this->deriveTasks($cleaned, $rewrite, $keywords, $structuredEntities, $synonymMap);
            foreach ($tasks as $task) {
                $taskTally[$task] = ($taskTally[$task] ?? 0) + 1;
            }

            $characterTotal += strlen($original !== '' ? $original : $cleaned);
            $wordTotal += $this->countWords($cleaned !== '' ? $cleaned : $original);

            $prompt = $this->buildPrompt($original !== '' ? $original : $cleaned, $tasks);
            $idealResponse = $this->buildTarget($cleaned, $rewrite, $keywords, $structuredEntities, $synonymMap);

            $rows[] = [
                'record_id' => $index + 1,
                'ai_tasks' => $tasks,
                'input_text' => $original !== '' ? $original : $cleaned,
                'cleaned_text' => $cleaned,
                'summary' => $rewrite,
                'key_phrases' => $keywords,
                'structured_entities' => $structuredEntities,
                'synonym_clusters' => $synonymMap,
                'prompt' => $prompt,
                'ideal_response' => $idealResponse,
            ];
        }

        $rowCount = count($rows);
        $averageCharacters = $rowCount > 0 ? (int) round($characterTotal / $rowCount) : 0;
        $averageWords = $rowCount > 0 ? (int) round($wordTotal / max(1, $rowCount)) : 0;

        $schema = [
            'description' => 'Training-ready records with paired prompts and responses for downstream fine-tuning.',
            'fields' => [
                ['name' => 'record_id', 'type' => 'integer'],
                ['name' => 'ai_tasks', 'type' => 'string[]'],
                ['name' => 'input_text', 'type' => 'string'],
                ['name' => 'cleaned_text', 'type' => 'string'],
                ['name' => 'summary', 'type' => 'string|null'],
                ['name' => 'key_phrases', 'type' => 'string[]'],
                ['name' => 'structured_entities', 'type' => 'object[]'],
                ['name' => 'synonym_clusters', 'type' => 'object'],
                ['name' => 'prompt', 'type' => 'string'],
                ['name' => 'ideal_response', 'type' => 'object'],
            ],
        ];

        $statistics = [
            'records' => $rowCount,
            'average_characters' => $averageCharacters,
            'average_words' => $averageWords,
            'task_distribution' => $taskTally,
            'triple_count' => count($structuredEntities),
            'synonym_cluster_count' => count($synonymMap),
            'documents_received' => $summary['documents_received'] ?? null,
            'documents_processed' => $summary['documents_processed'] ?? null,
        ];

        return [
            'rows' => $rows,
            'schema' => $schema,
            'statistics' => $statistics,
        ];
    }

    /**
     * @param array<int, array{subject: string, relation: string, object: string}> $triples
     * @return array<int, array{subject: string, relation: string, object: string}>
     */
    private function normaliseTriples(array $triples): array
    {
        $clean = [];
        foreach ($triples as $triple) {
            $subject = trim((string) ($triple['subject'] ?? ''));
            $relation = trim((string) ($triple['relation'] ?? ''));
            $object = trim((string) ($triple['object'] ?? ''));
            if ($subject === '' || $relation === '' || $object === '') {
                continue;
            }
            $clean[] = [
                'subject' => $subject,
                'relation' => $relation,
                'object' => $object,
            ];
        }

        return $clean;
    }

    /**
     * @param array<int, array{entity: string, synonyms: array<int, string>}> $synonyms
     * @return array<string, array<int, string>>
     */
    private function normaliseSynonyms(array $synonyms): array
    {
        $map = [];
        foreach ($synonyms as $entry) {
            $entity = trim((string) ($entry['entity'] ?? ''));
            if ($entity === '') {
                continue;
            }
            $values = [];
            $rawValues = $entry['synonyms'] ?? [];
            if (is_array($rawValues)) {
                foreach ($rawValues as $value) {
                    $value = trim((string) $value);
                    if ($value === '') {
                        continue;
                    }
                    $values[] = $value;
                }
            }
            $map[$entity] = $values;
        }

        return $map;
    }

    /**
     * @param array<int, array{token: string, count: int}> $keywords
     * @return array<int, string>
     */
    private function extractKeywords(array $keywords): array
    {
        $tokens = [];
        foreach ($keywords as $keyword) {
            $token = trim((string) ($keyword['token'] ?? ''));
            if ($token === '') {
                continue;
            }
            $tokens[] = $token;
        }

        return array_values(array_unique($tokens));
    }

    /**
     * @param array<int, string> $keywords
     * @param array<int, array{subject: string, relation: string, object: string}> $structuredEntities
     * @param array<string, array<int, string>> $synonymMap
     * @return array<int, string>
     */
    private function deriveTasks(string $cleaned, string $rewrite, array $keywords, array $structuredEntities, array $synonymMap): array
    {
        $tasks = ['text_cleaning'];

        if ($rewrite !== '') {
            $tasks[] = 'summarization';
        }

        if ($keywords !== []) {
            $tasks[] = 'keyword_extraction';
            if (count($keywords) <= 12) {
                $tasks[] = 'topic_tagging';
            }
        }

        if ($structuredEntities !== []) {
            $tasks[] = 'entity_extraction';
        }

        if ($synonymMap !== []) {
            $tasks[] = 'entity_linking';
        }

        if ($cleaned !== '') {
            $tasks[] = 'text_normalisation';
        }

        $unique = [];
        foreach ($tasks as $task) {
            if (!in_array($task, $unique, true)) {
                $unique[] = $task;
            }
        }

        return $unique;
    }

    /**
     * @param array<int, string> $tasks
     */
    private function buildPrompt(string $input, array $tasks): string
    {
        $taskList = implode(', ', $tasks);
        return <<<PROMPT
You are preparing machine learning training data for enterprise text analytics workflows ({$taskList}).
Return normalised content, a concise summary, high-signal key phrases, and a structured entity graph as JSON.

Source text:
{$input}
PROMPT;
    }

    /**
     * @param array<int, string> $keywords
     * @param array<int, array{subject: string, relation: string, object: string}> $structuredEntities
     * @param array<string, array<int, string>> $synonymMap
     * @return array<string, mixed>
     */
    private function buildTarget(string $cleaned, string $rewrite, array $keywords, array $structuredEntities, array $synonymMap): array
    {
        return [
            'cleaned_text' => $cleaned,
            'summary' => $rewrite !== '' ? $rewrite : null,
            'key_phrases' => $keywords,
            'structured_entities' => $structuredEntities,
            'synonym_clusters' => $synonymMap,
        ];
    }

    private function countWords(string $text): int
    {
        if ($text === '') {
            return 0;
        }

        $parts = preg_split('/\s+/u', $text);
        if (!is_array($parts)) {
            return 0;
        }

        $count = 0;
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $count++;
        }

        return $count;
    }
}
