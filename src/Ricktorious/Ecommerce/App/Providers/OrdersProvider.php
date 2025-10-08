<?php

declare(strict_types=1);

namespace Ricktorious\Ecommerce\App\Providers;

use Ricktorious\Ecommerce\App\ServiceContainer;
use Ricktorious\Ecommerce\App\ServiceProviderInterface;
use Ricktorious\Ecommerce\Orders\OrderProcessor;
use Ricktorious\Ecommerce\Orders\OrderRepository;
use Ricktorious\Ecommerce\Shipping\ShippingService;

final class OrdersProvider implements ServiceProviderInterface
{
    public function register(ServiceContainer $container): void
    {
        $container->set(OrderRepository::class, function (ServiceContainer $container): OrderRepository {
            $config = $container->get('config');
            $ordersDir = is_array($config) ? (string) ($config['paths']['orders'] ?? '') : '';

            return new OrderRepository($ordersDir);
        });

        $container->set(OrderProcessor::class, function (ServiceContainer $container): OrderProcessor {
            return new OrderProcessor(
                $container->get(OrderRepository::class),
                $container->get(ShippingService::class),
            );
        });
    }
}
