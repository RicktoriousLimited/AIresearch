<?php

declare(strict_types=1);

namespace Ricktorious\Ecommerce\App\Providers;

use Ricktorious\Ecommerce\AI\PersonalizationEngine;
use Ricktorious\Ecommerce\Analytics\UserBehaviorTracker;
use Ricktorious\Ecommerce\App\ServiceContainer;
use Ricktorious\Ecommerce\App\ServiceProviderInterface;
use Ricktorious\Ecommerce\Catalog\ProductRepository;
use Ricktorious\Ecommerce\Checkout\Cart;
use Ricktorious\Ecommerce\Checkout\CheckoutService;
use Ricktorious\Ecommerce\CRM\CRMService;
use Ricktorious\Ecommerce\Extensions\CommerceExtension;
use Ricktorious\Ecommerce\Extensions\CoreContentExtension;
use Ricktorious\Ecommerce\Extensions\FulfillmentExtension;
use Ricktorious\Ecommerce\Extensions\OperationsExtension;
use Ricktorious\Ecommerce\Extensions\UserManagementExtension;
use Ricktorious\Ecommerce\Orders\OrderProcessor;
use Ricktorious\Ecommerce\Orders\OrderRepository;
use Ricktorious\Ecommerce\POS\PointOfSaleService;
use Ricktorious\Ecommerce\Shipping\ShippingService;
use Ricktorious\Ecommerce\User\UserService;

final class ExtensionsProvider implements ServiceProviderInterface
{
    public const REGISTRY_KEY = 'ricktorious.extensions';

    public function register(ServiceContainer $container): void
    {
        $container->set(self::REGISTRY_KEY, function (ServiceContainer $container): array {
            return [
                new CoreContentExtension(
                    $container->get(UserBehaviorTracker::class),
                    $container->get(PersonalizationEngine::class),
                    $container->get(ProductRepository::class),
                ),
                new CommerceExtension(
                    $container->get(ProductRepository::class),
                    $container->get(Cart::class),
                    $container->get(CheckoutService::class),
                    $container->get(UserBehaviorTracker::class),
                ),
                new OperationsExtension(
                    $container->get(CRMService::class),
                    $container->get(PointOfSaleService::class),
                ),
                new FulfillmentExtension(
                    $container->get(OrderRepository::class),
                    $container->get(OrderProcessor::class),
                    $container->get(ShippingService::class),
                ),
                new UserManagementExtension(
                    $container->get(UserService::class),
                ),
            ];
        });
    }
}
