<?php

declare(strict_types=1);

namespace Ricktorious\Ecommerce\Shipping;

use InvalidArgumentException;

final class ShippingService
{
    public function __construct(private string $shipmentsPath)
    {
        $directory = dirname($this->shipmentsPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        if (!file_exists($this->shipmentsPath)) {
            file_put_contents($this->shipmentsPath, json_encode([]));
        }
    }

    /**
     * @param array<string, mixed> $order
     * @param array<string, mixed> $address
     *
     * @return array<int, array<string, mixed>>
     */
    public function quote(array $order, array $address): array
    {
        $orderTotal = (float) ($order['total'] ?? 0.0);
        $items = (array) ($order['items'] ?? []);
        $quantity = 0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $quantity += (int) ($item['quantity'] ?? 0);
        }

        $weight = max(0.5, $quantity * 0.45);
        $distanceFactor = $this->distanceFactor((string) ($address['postal_code'] ?? ''));
        $base = max(6.0, $orderTotal * 0.04 + $weight * 1.5 + $distanceFactor);
        $currency = (string) ($order['currency'] ?? '$');

        $now = time();

        return [
            [
                'carrier' => 'UPS',
                'service' => 'ground',
                'speed' => 'standard',
                'amount' => round($base, 2),
                'currency' => $currency,
                'estimated_delivery' => date(DATE_ATOM, $now + 5 * 24 * 60 * 60),
            ],
            [
                'carrier' => 'FedEx',
                'service' => 'express',
                'speed' => 'express',
                'amount' => round($base * 1.35, 2),
                'currency' => $currency,
                'estimated_delivery' => date(DATE_ATOM, $now + 2 * 24 * 60 * 60),
            ],
            [
                'carrier' => 'DHL',
                'service' => 'international_priority',
                'speed' => 'priority',
                'amount' => round($base * 1.65, 2),
                'currency' => $currency,
                'estimated_delivery' => date(DATE_ATOM, $now + 7 * 24 * 60 * 60),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $order
     * @param array<string, mixed> $address
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function createShipment(array $order, array $address, string $carrier, string $service, array $options = []): array
    {
        $quotes = $this->quote($order, $address);
        $selected = null;
        foreach ($quotes as $quote) {
            if (strcasecmp($quote['carrier'], $carrier) === 0 && $quote['service'] === $service) {
                $selected = $quote;
                break;
            }
        }

        if ($selected === null) {
            throw new InvalidArgumentException(sprintf('No %s %s service available for shipment.', $carrier, $service));
        }

        $shipment = [
            'id' => 'shp-' . bin2hex(random_bytes(6)),
            'order_id' => (string) ($order['id'] ?? ''),
            'carrier' => strtoupper($selected['carrier']),
            'service' => $selected['service'],
            'speed' => $selected['speed'],
            'tracking_number' => strtoupper(substr($selected['carrier'], 0, 3)) . '-' . strtoupper(bin2hex(random_bytes(5))),
            'label_url' => sprintf(
                'https://labels.ricktorious.local/%s/%s',
                rawurlencode((string) ($order['id'] ?? 'unknown')),
                bin2hex(random_bytes(4))
            ),
            'cost' => $selected['amount'],
            'currency' => $selected['currency'],
            'created_at' => date(DATE_ATOM),
            'estimated_delivery' => $selected['estimated_delivery'],
            'address' => [
                'name' => (string) ($address['name'] ?? ($order['customer']['name'] ?? '')), 
                'line1' => (string) ($address['line1'] ?? ''),
                'line2' => (string) ($address['line2'] ?? ''),
                'city' => (string) ($address['city'] ?? ''),
                'state' => (string) ($address['state'] ?? ''),
                'postal_code' => (string) ($address['postal_code'] ?? ''),
                'country' => (string) ($address['country'] ?? 'US'),
            ],
            'status' => 'label_created',
            'metadata' => $options,
        ];

        $this->persistShipment($shipment);

        return $shipment;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function shipments(): array
    {
        $contents = file_get_contents($this->shipmentsPath);
        if ($contents === false) {
            return [];
        }

        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            return [];
        }

        usort(
            $decoded,
            static fn(array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''))
        );

        return $decoded;
    }

    /**
     * @param array<string, mixed> $shipment
     */
    private function persistShipment(array $shipment): void
    {
        $contents = file_get_contents($this->shipmentsPath);
        $ledger = [];
        if ($contents !== false) {
            $decoded = json_decode($contents, true);
            if (is_array($decoded)) {
                $ledger = $decoded;
            }
        }

        $ledger[] = $shipment;

        file_put_contents(
            $this->shipmentsPath,
            json_encode($ledger, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    private function distanceFactor(string $postalCode): float
    {
        if ($postalCode === '') {
            return 2.0;
        }

        $digits = preg_replace('/\D/', '', $postalCode) ?? '';
        if ($digits === '') {
            return 3.0;
        }

        $fragment = (int) substr($digits, 0, 3);

        return max(1.0, ($fragment % 10) * 0.8);
    }
}

