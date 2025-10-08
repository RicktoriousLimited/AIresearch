<?php

declare(strict_types=1);

namespace Ricktorious\Ecommerce\App\Providers;

use Ricktorious\Ecommerce\AI\PersonalizationEngine;
use Ricktorious\Ecommerce\Analytics\UserBehaviorTracker;
use Ricktorious\Ecommerce\App\ServiceContainer;
use Ricktorious\Ecommerce\App\ServiceProviderInterface;
use Ricktorious\Ecommerce\Core\AdhocApiRouter;
use Ricktorious\Ecommerce\Core\Application;
use Ricktorious\Ecommerce\Core\BlockRegistry;
use Ricktorious\Ecommerce\Core\ContentManager;
use Ricktorious\Ecommerce\Core\ExtensionManager;

final class CoreProvider implements ServiceProviderInterface
{
    public function register(ServiceContainer $container): void
    {
        $container->set(BlockRegistry::class, static fn(): BlockRegistry => new BlockRegistry());
        $container->set(ContentManager::class, static fn(): ContentManager => new ContentManager());
        $container->set(ExtensionManager::class, static fn(): ExtensionManager => new ExtensionManager());
        $container->set(AdhocApiRouter::class, static fn(): AdhocApiRouter => new AdhocApiRouter());
        $container->set(UserBehaviorTracker::class, static fn(): UserBehaviorTracker => new UserBehaviorTracker());
        $container->set(PersonalizationEngine::class, function (ServiceContainer $container): PersonalizationEngine {
            return new PersonalizationEngine($container->get(UserBehaviorTracker::class));
        });

        $container->set(Application::class, function (ServiceContainer $container): Application {
            return new Application(
                $container->get(BlockRegistry::class),
                $container->get(ContentManager::class),
                $container->get(ExtensionManager::class),
                $container->get(AdhocApiRouter::class),
                $container->get(UserBehaviorTracker::class),
                $container->get(PersonalizationEngine::class),
            );
        });
    }
}
