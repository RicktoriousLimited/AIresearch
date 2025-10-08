<?php

declare(strict_types=1);

namespace Ricktorious\Ecommerce\App\Providers;

use Ricktorious\Ecommerce\App\ServiceContainer;
use Ricktorious\Ecommerce\App\ServiceProviderInterface;
use Ricktorious\Ecommerce\Shipping\ShippingService;

final class ShippingProvider implements ServiceProviderInterface
{
    public function register(ServiceContainer $container): void
    {
        $container->set(ShippingService::class, function (ServiceContainer $container): ShippingService {
            $config = $container->get('config');
            $path = is_array($config) ? (string) ($config['paths']['shipping_ledger'] ?? '') : '';

            return new ShippingService($path);
        });
    }
}
