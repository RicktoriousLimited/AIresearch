<?php

declare(strict_types=1);

namespace Ricktorious\Ecommerce\App\Providers;

use Ricktorious\Ecommerce\App\ServiceContainer;
use Ricktorious\Ecommerce\App\ServiceProviderInterface;
use Ricktorious\Ecommerce\CRM\CRMService;
use Ricktorious\Ecommerce\CRM\CustomerRepository;
use Ricktorious\Ecommerce\CRM\InteractionRepository;

final class CRMProvider implements ServiceProviderInterface
{
    public function register(ServiceContainer $container): void
    {
        $container->set(CustomerRepository::class, function (ServiceContainer $container): CustomerRepository {
            $config = $container->get('config');
            $path = is_array($config) ? (string) ($config['paths']['crm']['customers'] ?? '') : '';

            return new CustomerRepository($path);
        });

        $container->set(InteractionRepository::class, function (ServiceContainer $container): InteractionRepository {
            $config = $container->get('config');
            $path = is_array($config) ? (string) ($config['paths']['crm']['interactions'] ?? '') : '';

            return new InteractionRepository($path);
        });

        $container->set(CRMService::class, function (ServiceContainer $container): CRMService {
            return new CRMService(
                $container->get(CustomerRepository::class),
                $container->get(InteractionRepository::class),
            );
        });
    }
}
