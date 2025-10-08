<?php

declare(strict_types=1);

namespace Ricktorious\Ecommerce\Extensions;

use InvalidArgumentException;
use Ricktorious\Ecommerce\Core\AdhocApiRouter;
use Ricktorious\Ecommerce\Core\BlockRegistry;
use Ricktorious\Ecommerce\Core\ContentManager;
use Ricktorious\Ecommerce\Core\ExtensionInterface;
use Ricktorious\Ecommerce\Orders\OrderProcessor;
use Ricktorious\Ecommerce\Orders\OrderRepository;
use Ricktorious\Ecommerce\Shipping\ShippingService;
use RuntimeException;

final class FulfillmentExtension implements ExtensionInterface
{
    public function __construct(
        private OrderRepository $orders,
        private OrderProcessor $processor,
        private ShippingService $shipping
    ) {
    }

    public function getIdentifier(): string
    {
        return 'ricktorious.fulfillment';
    }

    public function registerBlocks(BlockRegistry $registry): void
    {
        // Fulfilment does not expose storefront blocks yet.
    }

    public function boot(ContentManager $contentManager): void
    {
        // No-op. Fulfilment features are API-driven.
    }

    public function registerApis(AdhocApiRouter $router): void
    {
        $router->addRoute('GET', '/api/orders', function (array $query): array {
            $orderId = isset($query['id']) ? (string) $query['id'] : null;
            $statusFilter = isset($query['status']) ? strtolower((string) $query['status']) : null;

            if ($orderId !== null && $orderId !== '') {
                $order = $this->orders->find($orderId);
                if ($order === null) {
                    return [
                        'status' => 404,
                        'headers' => ['Content-Type' => 'application/json'],
                        'body' => ['error' => 'Order not found'],
                    ];
                }

                return [
                    'status' => 200,
                    'headers' => ['Content-Type' => 'application/json'],
                    'body' => [
                        'order' => $order,
                        'transitions' => $this->processor->availableTransitions($orderId),
                    ],
                ];
            }

            $orders = $this->orders->all();
            if ($statusFilter !== null) {
                $orders = array_values(array_filter(
                    $orders,
                    static fn(array $order): bool => strtolower((string) ($order['status'] ?? '')) === $statusFilter
                ));
            }

            return [
                'status' => 200,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => ['orders' => $orders],
            ];
        });

        $router->addRoute('POST', '/api/orders/transition', function (array $query, array $payload): array {
            $orderId = trim((string) ($payload['order_id'] ?? ''));
            $status = trim((string) ($payload['status'] ?? ''));
            $context = (array) ($payload['context'] ?? []);

            if ($orderId === '' || $status === '') {
                return [
                    'status' => 422,
                    'headers' => ['Content-Type' => 'application/json'],
                    'body' => ['error' => 'order_id and status are required.'],
                ];
            }

            try {
                $order = $this->processor->transitionStatus($orderId, $status, $context);
            } catch (InvalidArgumentException $exception) {
                return [
                    'status' => 422,
                    'headers' => ['Content-Type' => 'application/json'],
                    'body' => ['error' => $exception->getMessage()],
                ];
            } catch (RuntimeException $exception) {
                return [
                    'status' => 404,
                    'headers' => ['Content-Type' => 'application/json'],
                    'body' => ['error' => $exception->getMessage()],
                ];
            }

            return [
                'status' => 200,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => ['order' => $order],
            ];
        });

        $router->addRoute('POST', '/api/orders/ship', function (array $query, array $payload): array {
            $orderId = trim((string) ($payload['order_id'] ?? ''));
            $carrier = trim((string) ($payload['carrier'] ?? ''));
            $service = trim((string) ($payload['service'] ?? ''));
            $address = (array) ($payload['address'] ?? []);
            $options = (array) ($payload['options'] ?? []);

            if ($orderId === '' || $carrier === '' || $service === '') {
                return [
                    'status' => 422,
                    'headers' => ['Content-Type' => 'application/json'],
                    'body' => ['error' => 'order_id, carrier, and service are required to create a shipment.'],
                ];
            }

            try {
                $result = $this->processor->createShipment($orderId, $address, $carrier, $service, $options);
            } catch (InvalidArgumentException $exception) {
                return [
                    'status' => 422,
                    'headers' => ['Content-Type' => 'application/json'],
                    'body' => ['error' => $exception->getMessage()],
                ];
            } catch (RuntimeException $exception) {
                return [
                    'status' => 404,
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

        $router->addRoute('GET', '/api/shipping/quotes', function (array $query): array {
            $orderId = (string) ($query['order'] ?? '');
            $postalCode = (string) ($query['postal_code'] ?? '');
            $country = (string) ($query['country'] ?? 'US');

            if ($orderId === '') {
                return [
                    'status' => 422,
                    'headers' => ['Content-Type' => 'application/json'],
                    'body' => ['error' => 'order query parameter is required.'],
                ];
            }

            $order = $this->orders->find($orderId);
            if ($order === null) {
                return [
                    'status' => 404,
                    'headers' => ['Content-Type' => 'application/json'],
                    'body' => ['error' => 'Order not found'],
                ];
            }

            $quotes = $this->shipping->quote($order, [
                'postal_code' => $postalCode,
                'country' => $country,
            ]);

            return [
                'status' => 200,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => ['quotes' => $quotes],
            ];
        });
    }
}

