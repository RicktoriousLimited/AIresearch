<?php

declare(strict_types=1);

namespace Ricktorious\Ecommerce\App\Providers;

use Ricktorious\Ecommerce\App\ServiceContainer;
use Ricktorious\Ecommerce\App\ServiceProviderInterface;
use Ricktorious\Ecommerce\User\OneTimePasswordManager;
use Ricktorious\Ecommerce\User\UserRepository;
use Ricktorious\Ecommerce\User\UserService;

final class UserProvider implements ServiceProviderInterface
{
    public function register(ServiceContainer $container): void
    {
        $container->set(UserRepository::class, function (ServiceContainer $container): UserRepository {
            $config = $container->get('config');
            $path = is_array($config) ? (string) ($config['paths']['users'] ?? '') : '';

            return new UserRepository($path);
        });

        $container->set(UserService::class, function (ServiceContainer $container): UserService {
            return new UserService($container->get(UserRepository::class));
        });

        $container->set(OneTimePasswordManager::class, function (ServiceContainer $container): OneTimePasswordManager {
            $config = $container->get('config');
            $path = is_array($config) ? (string) ($config['paths']['otp_tokens'] ?? '') : '';

            return new OneTimePasswordManager($path);
        });
    }
}
