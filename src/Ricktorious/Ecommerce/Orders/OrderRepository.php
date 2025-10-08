<?php

declare(strict_types=1);

namespace Ricktorious\Ecommerce\Orders;

use InvalidArgumentException;
use RuntimeException;

final class OrderRepository
{
    public function __construct(private string $ordersDirectory)
    {
        if (!is_dir($this->ordersDirectory)) {
            mkdir($this->ordersDirectory, 0777, true);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        $orders = [];
        $pattern = rtrim($this->ordersDirectory, '/') . '/*.json';
        foreach (glob($pattern) ?: [] as $file) {
            $decoded = $this->decodeFile($file);
            if ($decoded !== null) {
                $orders[] = $decoded;
            }
        }

        usort(
            $orders,
            static fn(array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''))
        );

        return $orders;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $orderId): ?array
    {
        $file = $this->pathFor($orderId);
        if (!file_exists($file)) {
            return null;
        }

        return $this->decodeFile($file);
    }

    /**
     * @param array<string, mixed> $order
     *
     * @return array<string, mixed>
     */
    public function save(array $order): array
    {
        $orderId = (string) ($order['id'] ?? '');
        if ($orderId === '') {
            throw new InvalidArgumentException('Orders must include an id.');
        }

        $normalised = $this->normalise($order);
        $file = $this->pathFor($orderId);
        file_put_contents(
            $file,
            json_encode($normalised, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        return $normalised;
    }

    /**
     * @param callable(array<string, mixed>):array<string, mixed> $mutator
     *
     * @return array<string, mixed>
     */
    public function update(string $orderId, callable $mutator): array
    {
        $order = $this->find($orderId);
        if ($order === null) {
            throw new RuntimeException(sprintf('Order "%s" could not be found.', $orderId));
        }

        $updated = $mutator($order);
        if (!is_array($updated)) {
            throw new RuntimeException('Order updates must return an array representation.');
        }

        return $this->save($updated);
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function updateStatus(string $orderId, string $status, array $context = []): array
    {
        return $this->update($orderId, function (array $order) use ($status, $context): array {
            $order['status'] = $status;
            $timeline = (array) ($order['timeline'] ?? []);
            $timeline[] = [
                'status' => $status,
                'timestamp' => date(DATE_ATOM),
                'context' => $context,
            ];
            $order['timeline'] = $timeline;

            return $order;
        });
    }

    /**
     * @param array<string, mixed> $shipment
     *
     * @return array<string, mixed>
     */
    public function appendShipment(string $orderId, array $shipment): array
    {
        return $this->update($orderId, function (array $order) use ($shipment): array {
            $shipments = (array) ($order['shipments'] ?? []);
            $shipments[] = $shipment;
            $order['shipments'] = $shipments;

            return $order;
        });
    }

    private function pathFor(string $orderId): string
    {
        return rtrim($this->ordersDirectory, '/') . '/' . $orderId . '.json';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeFile(string $file): ?array
    {
        $contents = file_get_contents($file);
        if ($contents === false) {
            return null;
        }

        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            return null;
        }

        return $this->normalise($decoded);
    }

    /**
     * @param array<string, mixed> $order
     *
     * @return array<string, mixed>
     */
    private function normalise(array $order): array
    {
        $order['timeline'] = array_values((array) ($order['timeline'] ?? []));
        $order['shipments'] = array_values((array) ($order['shipments'] ?? []));

        return $order;
    }
}

