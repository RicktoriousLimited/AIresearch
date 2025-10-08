<?php

declare(strict_types=1);

namespace Ricktorious\Ecommerce\App\Providers;

use Ricktorious\Ecommerce\App\ServiceContainer;
use Ricktorious\Ecommerce\App\ServiceProviderInterface;
use Ricktorious\Ecommerce\Catalog\ProductRepository;
use Ricktorious\Ecommerce\Checkout\CheckoutService;
use Ricktorious\Ecommerce\CRM\CRMService;
use Ricktorious\Ecommerce\POS\PointOfSaleService;

final class POSProvider implements ServiceProviderInterface
{
    public function register(ServiceContainer $container): void
    {
        $container->set(PointOfSaleService::class, function (ServiceContainer $container): PointOfSaleService {
            $config = $container->get('config');
            $ledgerPath = is_array($config) ? (string) ($config['paths']['pos_ledger'] ?? '') : '';

            return new PointOfSaleService(
                $container->get(CheckoutService::class),
                $container->get(ProductRepository::class),
                $container->get(CRMService::class),
                $ledgerPath,
            );
        });
    }
}
