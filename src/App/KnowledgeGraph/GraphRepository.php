<?php

declare(strict_types=1);

namespace App\KnowledgeGraph;

use App\Extraction\ExtractionResult;
use DateTimeImmutable;
use RuntimeException;

use function array_values;
use function dirname;
use function file_get_contents;
use function file_put_contents;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function json_decode;
use function json_encode;
use function mkdir;
use function trim;

final class GraphRepository
{
    private string $path;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? dirname(__DIR__, 3) . '/storage/graphs/scraped-graph.json';
    }

    /**
     * @return array{graph: array<string, mixed>|null, sources: array<int, array<string, mixed>>, updated_at: string|null}
     */
    public function load(): array
    {
        if (!is_file($this->path)) {
            return [
                'graph' => null,
                'sources' => [],
                'updated_at' => null,
            ];
        }

        $contents = file_get_contents($this->path);
        if (!is_string($contents) || trim($contents) === '') {
            return [
                'graph' => null,
                'sources' => [],
                'updated_at' => null,
            ];
        }

        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            return [
                'graph' => null,
                'sources' => [],
                'updated_at' => null,
            ];
        }

        $graph = isset($decoded['graph']) && is_array($decoded['graph']) ? $decoded['graph'] : null;

        $sources = [];
        if (isset($decoded['sources']) && is_array($decoded['sources'])) {
            foreach ($decoded['sources'] as $source) {
                if (!is_array($source)) {
                    continue;
                }
                if (!isset($source['url']) || !is_string($source['url'])) {
                    continue;
                }

                $normalised = [
                    'url' => $source['url'],
                    'title' => isset($source['title']) && is_string($source['title']) ? $source['title'] : '',
                    'characters' => isset($source['characters']) ? (int) $source['characters'] : 0,
                    'paragraphs' => isset($source['paragraphs']) ? (int) $source['paragraphs'] : 0,
                    'preview' => isset($source['preview']) && is_string($source['preview']) ? $source['preview'] : '',
                ];

                if (isset($source['content']) && is_string($source['content'])) {
                    $normalised['content'] = $source['content'];
                }

                if (isset($source['fetched_at']) && is_string($source['fetched_at'])) {
                    $normalised['fetched_at'] = $source['fetched_at'];
                }

                if (isset($source['verified_at']) && is_string($source['verified_at'])) {
                    $normalised['verified_at'] = $source['verified_at'];
                }

                if (isset($source['status']) && is_string($source['status'])) {
                    $normalised['status'] = $source['status'];
                }

                $sources[] = $normalised;
            }
        }

        $updatedAt = null;
        if (isset($decoded['updated_at']) && is_string($decoded['updated_at'])) {
            $updatedAt = $decoded['updated_at'];
        }

        return [
            'graph' => $graph,
            'sources' => $sources,
            'updated_at' => $updatedAt,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $sources
     */
    public function save(ExtractionResult $result, array $sources): void
    {
        $this->ensureStorageDirectory();

        $payload = [
            'graph' => $result->toArray(),
            'sources' => array_values($sources),
            'updated_at' => (new DateTimeImmutable())->format(DATE_ATOM),
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('Failed to encode graph payload.');
        }

        file_put_contents($this->path, $json);
    }

    /**
     * @param array<int, array<string, mixed>> $existing
     * @param array<string, mixed> $candidate
     * @return array<int, array<string, mixed>>
     */
    public function upsertSource(array $existing, array $candidate): array
    {
        $normalised = [];
        $replaced = false;
        foreach ($existing as $source) {
            if (!is_array($source) || !isset($source['url'])) {
                continue;
            }

            if (is_string($source['url']) && $source['url'] === ($candidate['url'] ?? null)) {
                $normalised[] = $candidate;
                $replaced = true;
            } else {
                $normalised[] = $source;
            }
        }

        if (!$replaced) {
            $normalised[] = $candidate;
        }

        return array_values($normalised);
    }

    public function path(): string
    {
        return $this->path;
    }

    private function ensureStorageDirectory(): void
    {
        $directory = dirname($this->path);
        if (is_dir($directory)) {
            return;
        }

        if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create graph storage directory.');
        }
    }
}
