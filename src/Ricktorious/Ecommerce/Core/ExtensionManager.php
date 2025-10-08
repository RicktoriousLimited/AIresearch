<?php

declare(strict_types=1);

namespace Ricktorious\Ecommerce\Core;

use InvalidArgumentException;

final class ExtensionManager
{
    /** @var array<string, ExtensionInterface> */
    private array $extensions = [];

    public function addExtension(ExtensionInterface $extension): void
    {
        $identifier = $extension->getIdentifier();
        if ($identifier === '') {
            throw new InvalidArgumentException('Extension identifiers must not be empty.');
        }
        if (isset($this->extensions[$identifier])) {
            throw new InvalidArgumentException(sprintf('Extension "%s" is already registered.', $identifier));
        }

        $this->extensions[$identifier] = $extension;
    }

    public function boot(BlockRegistry $registry, ContentManager $contentManager, AdhocApiRouter $router): void
    {
        foreach ($this->extensions as $extension) {
            $extension->registerBlocks($registry);
            $extension->boot($contentManager);
            $extension->registerApis($router);
        }
    }

    /**
     * @return array<string, ExtensionInterface>
     */
    public function all(): array
    {
        return $this->extensions;
    }
}
