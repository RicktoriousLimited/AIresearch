<?php

declare(strict_types=1);

namespace Ricktorious\Ecommerce\Checkout;

use Ricktorious\Ecommerce\Catalog\ProductRepository;
use RuntimeException;

final class CheckoutService
{
    public function __construct(
        private string $ordersDirectory,
        private ProductRepository $products
    ) {
        if (!is_dir($this->ordersDirectory)) {
            mkdir($this->ordersDirectory, 0777, true);
        }
    }

    /**
     * @param array<string, string> $customer
     * @param array<string, mixed>  $metadata
     *
     * @return array<string, mixed>
     */
    public function createOrder(Cart $cart, array $customer, array $metadata = []): array
    {
        if ($cart->isEmpty()) {
            throw new RuntimeException('Cannot checkout with an empty cart.');
        }

        $items = [];
        $currency = '$';
        foreach ($cart->detailedItems($this->products) as $item) {
            $product = $item['product'];
            $currency = $product->currency();
            $items[] = [
                'product_id' => $product->id(),
                'product_name' => $product->name(),
                'unit_price' => $product->price(),
                'quantity' => $item['quantity'],
                'line_total' => $item['line_total'],
            ];
        }

        if ($items === []) {
            throw new RuntimeException('No valid products found in the cart.');
        }

        $orderId = 'ord-' . bin2hex(random_bytes(6));
        $total = $cart->total($this->products);

        $channel = (string) ($metadata['channel'] ?? 'storefront');
        $status = (string) ($metadata['status'] ?? 'paid');

        $order = [
            'id' => $orderId,
            'customer' => [
                'name' => trim((string) ($customer['name'] ?? '')),
                'email' => trim((string) ($customer['email'] ?? '')),
                'address' => trim((string) ($customer['address'] ?? '')),
            ],
            'items' => $items,
            'total' => $total,
            'currency' => $currency,
            'formatted_total' => $currency . number_format($total, 2),
            'created_at' => date(DATE_ATOM),
            'channel' => $channel,
            'status' => $status,
            'metadata' => $metadata,
        ];

        $path = rtrim($this->ordersDirectory, '/');
        $file = sprintf('%s/%s.json', $path, $orderId);
        file_put_contents($file, json_encode($order, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $order;
    }
}
