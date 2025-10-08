<?php

declare(strict_types=1);

namespace Ricktorious\Ecommerce\App\Providers;

use Ricktorious\Ecommerce\App\ServiceContainer;
use Ricktorious\Ecommerce\App\ServiceProviderInterface;
use Ricktorious\Ecommerce\Catalog\ProductRepository;

final class CatalogProvider implements ServiceProviderInterface
{
    public function register(ServiceContainer $container): void
    {
        $container->set(ProductRepository::class, function (ServiceContainer $container): ProductRepository {
            $config = $container->get('config');
            $path = is_array($config) ? (string) ($config['paths']['catalog'] ?? '') : '';

            return new ProductRepository($path);
        });
    }
}
