<?php

declare(strict_types=1);

namespace Ricktorious\Ecommerce\Orders;

use InvalidArgumentException;
use RuntimeException;
use Ricktorious\Ecommerce\Shipping\ShippingService;

final class OrderProcessor
{
    /**
     * @var array<string, array<int, string>>
     */
    private const WORKFLOW = [
        'created' => ['pending', 'paid', 'processing', 'cancelled'],
        'pending' => ['paid', 'cancelled'],
        'paid' => ['processing', 'fulfilled', 'cancelled'],
        'processing' => ['fulfilled', 'cancelled'],
        'fulfilled' => ['shipped', 'cancelled'],
        'shipped' => ['delivered'],
        'delivered' => [],
        'cancelled' => [],
    ];

    public function __construct(
        private OrderRepository $orders,
        private ShippingService $shipping
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function transitionStatus(string $orderId, string $status, array $context = []): array
    {
        $order = $this->orders->find($orderId);
        if ($order === null) {
            throw new RuntimeException(sprintf('Order "%s" was not found.', $orderId));
        }

        $current = strtolower((string) ($order['status'] ?? 'pending'));
        $status = strtolower($status);

        if (!array_key_exists($current, self::WORKFLOW)) {
            $current = 'pending';
        }

        $allowedStatuses = array_unique(array_merge([$current], ...array_values(self::WORKFLOW)));
        if (!in_array($status, $allowedStatuses, true)) {
            throw new InvalidArgumentException(sprintf('Status "%s" is not supported.', $status));
        }

        if ($status === $current) {
            return $order;
        }

        $allowedTransitions = self::WORKFLOW[$current] ?? [];
        if (!in_array($status, $allowedTransitions, true)) {
            throw new RuntimeException(sprintf(
                'Transition from "%s" to "%s" is not permitted.',
                $current,
                $status
            ));
        }

        $context['actor'] = $context['actor'] ?? 'system';

        return $this->orders->updateStatus($orderId, $status, $context);
    }

    /**
     * @param array<string, mixed> $address
     * @param array<string, mixed> $options
     *
     * @return array{order: array<string, mixed>, shipment: array<string, mixed>}
     */
    public function createShipment(string $orderId, array $address, string $carrier, string $service, array $options = []): array
    {
        $order = $this->orders->find($orderId);
        if ($order === null) {
            throw new RuntimeException(sprintf('Order "%s" was not found.', $orderId));
        }

        $shipment = $this->shipping->createShipment($order, $address, $carrier, $service, $options);
        $updatedOrder = $this->orders->appendShipment($orderId, $shipment);

        $transition = $options['transition'] ?? true;
        if ($transition) {
            $updatedOrder = $this->transitionStatus($orderId, 'shipped', [
                'carrier' => $shipment['carrier'],
                'service' => $shipment['service'],
                'tracking' => $shipment['tracking_number'],
                'actor' => $options['actor'] ?? 'system',
            ]);
        }

        return [
            'order' => $updatedOrder,
            'shipment' => $shipment,
        ];
    }

    /**
     * @return array<int, string>
     */
    public function availableTransitions(string $orderId): array
    {
        $order = $this->orders->find($orderId);
        if ($order === null) {
            throw new RuntimeException(sprintf('Order "%s" was not found.', $orderId));
        }

        $current = strtolower((string) ($order['status'] ?? 'pending'));

        return self::WORKFLOW[$current] ?? [];
    }
}

