<?php

declare(strict_types=1);

namespace Ricktorious\Ecommerce\Extensions;

use InvalidArgumentException;
use Ricktorious\Ecommerce\CRM\CRMService;
use Ricktorious\Ecommerce\Core\AdhocApiRouter;
use Ricktorious\Ecommerce\Core\BlockRegistry;
use Ricktorious\Ecommerce\Core\ContentManager;
use Ricktorious\Ecommerce\Core\ExtensionInterface;
use Ricktorious\Ecommerce\POS\PointOfSaleService;
use RuntimeException;

final class OperationsExtension implements ExtensionInterface
{
    public function __construct(
        private CRMService $crm,
        private PointOfSaleService $pos
    ) {
    }

    public function getIdentifier(): string
    {
        return 'ricktorious.operations';
    }

    public function registerBlocks(BlockRegistry $registry): void
    {
        // Blocks will be introduced in a future iteration for CRM dashboards.
    }

    public function boot(ContentManager $contentManager): void
    {
        // No-op for now. Content seeding is not required for CRM/POS APIs.
    }

    public function registerApis(AdhocApiRouter $router): void
    {
        $router->addRoute('GET', '/api/crm/customers', function (): array {
            $customers = array_map(
                static fn($customer) => $customer->toArray(),
                $this->crm->customers()
            );

            return [
                'status' => 200,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => ['customers' => $customers],
            ];
        });

        $router->addRoute('POST', '/api/crm/customers', function (array $query, array $payload): array {
            try {
                $customer = $this->crm->upsertCustomer($payload);
            } catch (InvalidArgumentException $exception) {
                return [
                    'status' => 422,
                    'headers' => ['Content-Type' => 'application/json'],
                    'body' => ['error' => $exception->getMessage()],
                ];
            }

            return [
                'status' => 200,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => ['customer' => $customer->toArray()],
            ];
        });

        $router->addRoute('POST', '/api/crm/interactions', function (array $query, array $payload): array {
            $customerId = (string) ($payload['customer_id'] ?? '');
            $type = (string) ($payload['type'] ?? '');
            $details = (array) ($payload['payload'] ?? []);

            if ($customerId === '' || $type === '') {
                return [
                    'status' => 422,
                    'headers' => ['Content-Type' => 'application/json'],
                    'body' => ['error' => 'customer_id and type are required to record an interaction.'],
                ];
            }

            try {
                $interaction = $this->crm->recordInteraction($customerId, $type, $details);
            } catch (InvalidArgumentException $exception) {
                return [
                    'status' => 404,
                    'headers' => ['Content-Type' => 'application/json'],
                    'body' => ['error' => $exception->getMessage()],
                ];
            }

            return [
                'status' => 201,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => ['interaction' => $interaction],
            ];
        });

        $router->addRoute('GET', '/api/crm/interactions', function (array $query): array {
            $customerId = isset($query['customer']) ? (string) $query['customer'] : null;
            $interactions = $this->crm->interactions($customerId);

            return [
                'status' => 200,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => ['interactions' => $interactions],
            ];
        });

        $router->addRoute('POST', '/api/pos/sale', function (array $query, array $payload): array {
            $items = (array) ($payload['items'] ?? []);
            $customer = (array) ($payload['customer'] ?? []);
            $options = (array) ($payload['options'] ?? []);

            try {
                $result = $this->pos->processSale($items, $customer, $options);
            } catch (InvalidArgumentException $exception) {
                return [
                    'status' => 422,
                    'headers' => ['Content-Type' => 'application/json'],
                    'body' => ['error' => $exception->getMessage()],
                ];
            } catch (RuntimeException $exception) {
                return [
                    'status' => 422,
                    'headers' => ['Content-Type' => 'application/json'],
                    'body' => ['error' => $exception->getMessage()],
                ];
            }

            return [
                'status' => 201,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => $result,
            ];
        });
    }
}
