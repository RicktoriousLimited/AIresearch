<?php

declare(strict_types=1);

namespace Ricktorious\Ecommerce\Core;

use Ricktorious\Ecommerce\Core\BlockRegistry;
use Ricktorious\Ecommerce\Core\ContentManager;
use Ricktorious\Ecommerce\Core\AdhocApiRouter;

/**
 * Contract for Ricktorious ecommerce extensions.
 */
interface ExtensionInterface
{
    public function getIdentifier(): string;

    public function registerBlocks(BlockRegistry $registry): void;

    public function boot(ContentManager $contentManager): void;

    public function registerApis(AdhocApiRouter $router): void;
}
