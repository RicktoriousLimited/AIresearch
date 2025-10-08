<?php

declare(strict_types=1);

namespace Ricktorious\Ecommerce\Core;

use InvalidArgumentException;

/**
 * In-memory content manager supporting drafts, publishing, and revision history.
 */
final class ContentManager
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $pages = [];

    /**
     * @var array<string, array<string, array<string, mixed>>>
     */
    private array $drafts = [];

    /**
     * @var array<string, array<int, array<string, mixed>>>
     */
    private array $revisions = [];

    /**
     * @param array<string, mixed> $definition
     */
    public function definePage(string $identifier, array $definition): void
    {
        $this->publishPage($identifier, $definition, 'system');
    }

    /**
     * @param array<string, mixed> $definition
     *
     * @return array<string, mixed>
     */
    public function publishPage(string $identifier, array $definition, string $author = 'system'): array
    {
        $page = $this->normaliseDefinition($definition);
        $page['__meta'] = [
            'identifier' => $identifier,
            'published_at' => date(DATE_ATOM),
            'author' => $author,
            'version' => count($this->revisions[$identifier] ?? []) + 1,
        ];

        $this->pages[$identifier] = $page;
        $this->revisions[$identifier][] = [
            'version' => $page['__meta']['version'],
            'published_at' => $page['__meta']['published_at'],
            'author' => $author,
        ];

        return $page;
    }

    /**
     * @param array<string, mixed> $definition
     *
     * @return array<string, mixed>
     */
    public function saveDraft(string $identifier, array $definition, string $author = 'system', string $note = ''): array
    {
        $draftId = 'draft-' . bin2hex(random_bytes(5));
        $payload = [
            'id' => $draftId,
            'identifier' => $identifier,
            'definition' => $this->normaliseDefinition($definition),
            'author' => $author,
            'note' => $note,
            'saved_at' => date(DATE_ATOM),
        ];

        $this->drafts[$identifier][$draftId] = $payload;

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function publishDraft(string $identifier, string $draftId, string $author = 'system'): array
    {
        $draft = $this->drafts[$identifier][$draftId] ?? null;
        if ($draft === null) {
            throw new InvalidArgumentException(sprintf('Draft "%s" was not found for page "%s".', $draftId, $identifier));
        }

        unset($this->drafts[$identifier][$draftId]);

        return $this->publishPage($identifier, (array) ($draft['definition'] ?? []), $author);
    }

    public function deletePage(string $identifier): void
    {
        unset($this->pages[$identifier], $this->revisions[$identifier], $this->drafts[$identifier]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getPage(string $identifier): ?array
    {
        return $this->pages[$identifier] ?? null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listPages(): array
    {
        $pages = [];
        foreach ($this->pages as $identifier => $definition) {
            $meta = (array) ($definition['__meta'] ?? []);
            $pages[] = [
                'identifier' => $identifier,
                'title' => (string) ($definition['metadata']['title'] ?? $identifier),
                'status' => 'published',
                'version' => (int) ($meta['version'] ?? 1),
                'published_at' => (string) ($meta['published_at'] ?? ''),
                'author' => (string) ($meta['author'] ?? 'system'),
            ];
        }

        return $pages;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listDrafts(?string $identifier = null): array
    {
        if ($identifier !== null) {
            return array_values($this->drafts[$identifier] ?? []);
        }

        $drafts = [];
        foreach ($this->drafts as $pageDrafts) {
            foreach ($pageDrafts as $draft) {
                $drafts[] = $draft;
            }
        }

        usort(
            $drafts,
            static fn(array $a, array $b): int => strcmp((string) ($b['saved_at'] ?? ''), (string) ($a['saved_at'] ?? ''))
        );

        return $drafts;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function revisionHistory(string $identifier): array
    {
        return $this->revisions[$identifier] ?? [];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function allPages(): array
    {
        return $this->pages;
    }

    /**
     * @param array<string, mixed> $definition
     *
     * @return array<string, mixed>
     */
    private function normaliseDefinition(array $definition): array
    {
        $metadata = (array) ($definition['metadata'] ?? []);
        $blocks = array_values((array) ($definition['blocks'] ?? []));

        return [
            'metadata' => $metadata,
            'blocks' => $blocks,
        ];
    }
}

