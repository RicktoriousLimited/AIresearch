<?php

declare(strict_types=1);

namespace Ricktorious\Ecommerce\App\Providers;

use Ricktorious\Ecommerce\App\ServiceContainer;
use Ricktorious\Ecommerce\App\ServiceProviderInterface;
use Ricktorious\Ecommerce\Catalog\ProductRepository;
use Ricktorious\Ecommerce\Checkout\Cart;
use Ricktorious\Ecommerce\Checkout\CheckoutService;

final class CheckoutProvider implements ServiceProviderInterface
{
    public function register(ServiceContainer $container): void
    {
        $container->set(Cart::class, function (ServiceContainer $container): Cart {
            $config = $container->get('config');
            $items = is_array($config) ? (array) ($config['session']['cart'] ?? []) : [];

            return Cart::fromArray($items);
        });

        $container->set(CheckoutService::class, function (ServiceContainer $container): CheckoutService {
            $config = $container->get('config');
            $ordersDir = is_array($config) ? (string) ($config['paths']['orders'] ?? '') : '';

            return new CheckoutService($ordersDir, $container->get(ProductRepository::class));
        });
    }
}
