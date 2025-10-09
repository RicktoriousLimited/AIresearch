<?php

declare(strict_types=1);

namespace App\Extraction;

use function array_unique;
use function array_values;
use function count;
use function in_array;
use function is_array;
use function ksort;
use function max;
use function preg_split;
use function round;
use function strlen;
use function trim;

/**
 * Convert extraction artefacts into AI-ready training dataset rows.
 */
final class DatasetBuilder
{
    private RelationSchemaMapper $relationMapper;

    public function __construct(?RelationSchemaMapper $relationMapper = null)
    {
        $this->relationMapper = $relationMapper ?? new RelationSchemaMapper();
    }

    /**
     * @param array<int, array{original: string, cleaned: string, rewritten: string, keywords: array<int, array{token: string, count: int}>, spelling: array<int, array{token: string, count: int, suggestions: array<int, string}>>, qa: array<int, array{question: string, answer: string, response: string}>}> $documents
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
        $qaPairTotal = 0;

        $structuredEntities = $this->normaliseTriples($triples);
        $synonymMap = $this->normaliseSynonyms($synonyms);

        $relationTypeDistribution = [];
        $canonicalRelationDistribution = [];
        foreach ($structuredEntities as $entity) {
            $type = (string) ($entity['relation_type'] ?? 'other');
            $relationTypeDistribution[$type] = ($relationTypeDistribution[$type] ?? 0) + 1;

            $canonical = (string) ($entity['canonical_relation'] ?? 'uncategorized');
            $canonicalRelationDistribution[$canonical] = ($canonicalRelationDistribution[$canonical] ?? 0) + 1;
        }

        ksort($relationTypeDistribution);
        ksort($canonicalRelationDistribution);

        foreach ($documents as $index => $document) {
            $original = trim((string) ($document['original'] ?? ''));
            $cleaned = trim((string) ($document['cleaned'] ?? $original));
            $rewrite = trim((string) ($document['rewritten'] ?? ''));
            $keywords = $this->extractKeywords($document['keywords'] ?? []);
            $qaPairs = $this->normaliseQuestionAnswers($document['qa'] ?? []);

            if ($original === '' && $cleaned === '' && $rewrite === '') {
                continue;
            }

            $tasks = $this->deriveTasks($cleaned, $rewrite, $keywords, $structuredEntities, $synonymMap, $qaPairs);
            foreach ($tasks as $task) {
                $taskTally[$task] = ($taskTally[$task] ?? 0) + 1;
            }

            $characterTotal += strlen($original !== '' ? $original : $cleaned);
            $wordTotal += $this->countWords($cleaned !== '' ? $cleaned : $original);
            $qaPairTotal += count($qaPairs);

            $prompt = $this->buildPrompt($original !== '' ? $original : $cleaned, $tasks, $qaPairs !== []);
            $idealResponse = $this->buildTarget($cleaned, $rewrite, $keywords, $structuredEntities, $synonymMap, $qaPairs);

            $rows[] = [
                'record_id' => $index + 1,
                'ai_tasks' => $tasks,
                'input_text' => $original !== '' ? $original : $cleaned,
                'cleaned_text' => $cleaned,
                'summary' => $rewrite,
                'key_phrases' => $keywords,
                'structured_entities' => $structuredEntities,
                'synonym_clusters' => $synonymMap,
                'question_answer_pairs' => $qaPairs,
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
                [
                    'name' => 'structured_entities',
                    'type' => 'object[]',
                    'description' => 'Normalised subject/object assertions with canonical relation labels, confidence, status, and provenance.',
                ],
                ['name' => 'synonym_clusters', 'type' => 'object'],
                ['name' => 'question_answer_pairs', 'type' => 'object[]'],
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
            'question_answer_pair_count' => $qaPairTotal,
            'documents_received' => $summary['documents_received'] ?? null,
            'documents_processed' => $summary['documents_processed'] ?? null,
            'relation_type_distribution' => $relationTypeDistribution,
            'canonical_relation_distribution' => $canonicalRelationDistribution,
        ];

        return [
            'rows' => $rows,
            'schema' => $schema,
            'statistics' => $statistics,
        ];
    }

    /**
     * @param array<int, array{subject: string, relation: string, object: string}> $triples
     * @return array<int, array{subject: string, relation: string, canonical_relation: string, relation_type: string, object: string, confidence: float, status: string, provenance: array<string, mixed>}> 
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

            $schema = $this->relationMapper->map($relation);

            $clean[] = [
                'subject' => $subject,
                'relation' => $relation,
                'canonical_relation' => $schema['canonical'],
                'relation_type' => $schema['type'],
                'object' => $object,
                'confidence' => $this->clampConfidence($schema['confidence']),
                'status' => $schema['status'],
                'provenance' => $this->defaultProvenance(),
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
     * @param array<int, array{subject: string, relation: string, canonical_relation: string, relation_type: string, object: string, confidence: float, status: string, provenance: array<string, mixed>}> $structuredEntities
     * @param array<string, array<int, string>> $synonymMap
     * @param array<int, array{question: string, answer: string, response: string}> $qaPairs
     * @return array<int, string>
     */
    private function deriveTasks(string $cleaned, string $rewrite, array $keywords, array $structuredEntities, array $synonymMap, array $qaPairs): array
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

        if ($qaPairs !== []) {
            $tasks[] = 'question_answering';
            $tasks[] = 'reading_comprehension';
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
    private function buildPrompt(string $input, array $tasks, bool $includeQa): string
    {
        $taskList = implode(', ', $tasks);
        $deliverables = [
            'normalised content',
            'a concise summary',
            'high-signal key phrases',
            'a structured entity graph',
            'synonym clusters',
        ];

        if ($includeQa) {
            $deliverables[] = 'representative question-answer pairs';
        }

        $deliverableList = implode(', ', $deliverables);

        return <<<PROMPT
You are preparing machine learning training data for enterprise text analytics workflows ({$taskList}).
Return {$deliverableList} as JSON.

Source text:
{$input}
PROMPT;
    }

    /**
     * @param array<int, string> $keywords
     * @param array<int, array{subject: string, relation: string, canonical_relation: string, relation_type: string, object: string, confidence: float, status: string, provenance: array<string, mixed>}> $structuredEntities
     * @param array<string, array<int, string>> $synonymMap
     * @param array<int, array{question: string, answer: string, response: string}> $qaPairs
     * @return array<string, mixed>
     */
    private function buildTarget(string $cleaned, string $rewrite, array $keywords, array $structuredEntities, array $synonymMap, array $qaPairs): array
    {
        return [
            'cleaned_text' => $cleaned,
            'summary' => $rewrite !== '' ? $rewrite : null,
            'key_phrases' => $keywords,
            'structured_entities' => $structuredEntities,
            'synonym_clusters' => $synonymMap,
            'question_answer_pairs' => $qaPairs,
        ];
    }

    /**
     * @param array<int, array{question: string, answer: string, response: string}> $qaPairs
     * @return array<int, array{question: string, answer: string, response: string}>
     */
    private function normaliseQuestionAnswers(array $qaPairs): array
    {
        $clean = [];

        foreach ($qaPairs as $pair) {
            if (!is_array($pair)) {
                continue;
            }

            $question = trim((string) ($pair['question'] ?? ''));
            $answer = trim((string) ($pair['answer'] ?? ''));
            $response = trim((string) ($pair['response'] ?? $answer));

            if ($question === '' || $answer === '') {
                continue;
            }

            if ($response === '') {
                $response = $answer;
            }

            $clean[] = [
                'question' => $question,
                'answer' => $answer,
                'response' => $response,
            ];
        }

        return $clean;
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

    private function clampConfidence(float $value): float
    {
        if ($value < 0.0) {
            return 0.0;
        }
        if ($value > 1.0) {
            return 1.0;
        }

        return round($value, 2);
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultProvenance(): array
    {
        return [
            'source' => 'input_documents',
            'document_index' => null,
        ];
    }
}
