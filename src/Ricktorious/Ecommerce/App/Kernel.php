<?php

declare(strict_types=1);

namespace Ricktorious\Ecommerce\App;

use RuntimeException;
use Ricktorious\Ecommerce\App\Providers\CatalogProvider;
use Ricktorious\Ecommerce\App\Providers\CheckoutProvider;
use Ricktorious\Ecommerce\App\Providers\CoreProvider;
use Ricktorious\Ecommerce\App\Providers\CRMProvider;
use Ricktorious\Ecommerce\App\Providers\ExtensionsProvider;
use Ricktorious\Ecommerce\App\Providers\OrdersProvider;
use Ricktorious\Ecommerce\App\Providers\POSProvider;
use Ricktorious\Ecommerce\App\Providers\ShippingProvider;
use Ricktorious\Ecommerce\App\Providers\UserProvider;
use Ricktorious\Ecommerce\Core\Application as EcommerceApplication;
use Ricktorious\Ecommerce\Core\ExtensionInterface;
use Ricktorious\Ecommerce\Core\ExtensionManager;

final class Kernel
{
    private ServiceContainer $container;

    private bool $booted = false;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(private array $config = [])
    {
        $this->container = new ServiceContainer();
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $config = $this->buildConfig($this->config);
        $this->container->set('config', $config);

        foreach ($this->providers() as $provider) {
            if (is_string($provider)) {
                $provider = new $provider();
            }

            if (!$provider instanceof ServiceProviderInterface) {
                throw new RuntimeException('All providers must implement ServiceProviderInterface.');
            }

            $provider->register($this->container);
        }

        $this->initialiseExtensions();

        $this->booted = true;
    }

    public function container(): ServiceContainer
    {
        $this->boot();

        return $this->container;
    }

    public function application(): EcommerceApplication
    {
        $this->boot();

        return $this->container->get(EcommerceApplication::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function config(): array
    {
        $this->boot();

        $config = $this->container->get('config');

        return is_array($config) ? $config : [];
    }

    /**
     * @return array<int, ServiceProviderInterface|string>
     */
    private function providers(): array
    {
        return [
            CatalogProvider::class,
            CheckoutProvider::class,
            OrdersProvider::class,
            ShippingProvider::class,
            CRMProvider::class,
            UserProvider::class,
            POSProvider::class,
            CoreProvider::class,
            ExtensionsProvider::class,
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function buildConfig(array $overrides): array
    {
        $root = realpath(__DIR__ . '/../../../..') ?: dirname(__DIR__, 3);
        $storage = $root . '/storage';

        $defaults = [
            'paths' => [
                'root' => $root,
                'storage' => $storage,
                'catalog' => $storage . '/catalog/products.json',
                'orders' => $storage . '/orders',
                'crm' => [
                    'customers' => $storage . '/crm/customers.json',
                    'interactions' => $storage . '/crm/interactions.json',
                ],
                'pos_ledger' => $storage . '/pos/transactions.json',
                'shipping_ledger' => $storage . '/shipping/shipments.json',
                'users' => $storage . '/users/users.json',
            ],
            'session' => [
                'user_id' => 'guest',
                'cart' => [],
            ],
        ];

        return array_replace_recursive($defaults, $overrides);
    }

    private function initialiseExtensions(): void
    {
        if (!$this->container->has(ExtensionsProvider::REGISTRY_KEY)) {
            return;
        }

        $extensions = $this->container->get(ExtensionsProvider::REGISTRY_KEY);
        if (!is_array($extensions)) {
            throw new RuntimeException('Extensions provider must return an array of extensions.');
        }

        $manager = $this->container->get(ExtensionManager::class);
        foreach ($extensions as $extension) {
            if (!$extension instanceof ExtensionInterface) {
                throw new RuntimeException('Invalid extension registered with the kernel.');
            }

            $manager->addExtension($extension);
        }
    }
}
