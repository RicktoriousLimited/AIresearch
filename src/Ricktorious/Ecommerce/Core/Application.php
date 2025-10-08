<?php

declare(strict_types=1);

namespace Ricktorious\Ecommerce\Core;

use Ricktorious\Ecommerce\AI\PersonalizationEngine;
use Ricktorious\Ecommerce\Analytics\UserBehaviorTracker;

final class Application
{
    private bool $booted = false;

    public function __construct(
        private BlockRegistry $blockRegistry,
        private ContentManager $contentManager,
        private ExtensionManager $extensionManager,
        private AdhocApiRouter $apiRouter,
        private UserBehaviorTracker $behaviorTracker,
        private PersonalizationEngine $personalizationEngine
    ) {
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->extensionManager->boot($this->blockRegistry, $this->contentManager, $this->apiRouter);
        $this->booted = true;
    }

    /**
     * Render a page based on its block layout definition.
     *
     * @return array{title: string, html: string, metadata: array<string, mixed>}
     */
    public function renderPage(string $identifier, array $context = []): array
    {
        $this->boot();
        $page = $this->contentManager->getPage($identifier);
        if ($page === null) {
            return [
                'title' => 'Page not found',
                'html' => '<p class="empty">Page not found.</p>',
                'metadata' => [],
            ];
        }

        $blocks = (array) ($page['blocks'] ?? []);
        $fragments = [];
        foreach ($blocks as $blockDefinition) {
            $type = (string) ($blockDefinition['type'] ?? '');
            if ($type === '' || !$this->blockRegistry->has($type)) {
                continue;
            }

            $settings = (array) ($blockDefinition['settings'] ?? []);
            $block = $this->blockRegistry->get($type);
            $fragments[] = $block->render($settings, $context);
        }

        $metadata = (array) ($page['metadata'] ?? []);
        $title = (string) ($metadata['title'] ?? 'Ricktorious Storefront');

        return [
            'title' => $title,
            'html' => implode(PHP_EOL, $fragments),
            'metadata' => $metadata,
        ];
    }

    /**
     * Dispatch an API request to the ad-hoc router.
     *
     * @param array<string, mixed> $query
     * @param array<string, mixed> $payload
     *
     * @return array{status: int, headers: array<string, string>, body: mixed}
     */
    public function handleApiRequest(string $method, string $path, array $query = [], array $payload = []): array
    {
        $this->boot();

        return $this->apiRouter->dispatch($method, $path, $query, $payload);
    }

    public function behaviorTracker(): UserBehaviorTracker
    {
        return $this->behaviorTracker;
    }

    public function personalizationEngine(): PersonalizationEngine
    {
        return $this->personalizationEngine;
    }
}
