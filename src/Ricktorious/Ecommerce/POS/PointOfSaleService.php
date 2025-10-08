<?php

declare(strict_types=1);

namespace Ricktorious\Ecommerce\POS;

use InvalidArgumentException;
use Ricktorious\Ecommerce\CRM\CRMService;
use Ricktorious\Ecommerce\Catalog\ProductRepository;
use Ricktorious\Ecommerce\Checkout\Cart;
use Ricktorious\Ecommerce\Checkout\CheckoutService;
use RuntimeException;

final class PointOfSaleService
{
    public function __construct(
        private CheckoutService $checkout,
        private ProductRepository $products,
        private CRMService $crm,
        private string $ledgerPath
    ) {
        $directory = dirname($this->ledgerPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        if (!file_exists($this->ledgerPath)) {
            file_put_contents($this->ledgerPath, json_encode([]));
        }
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<string, mixed> $customer
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function processSale(array $items, array $customer, array $options = []): array
    {
        $cart = Cart::fromArray();
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $identifier = (string) ($item['product_id'] ?? $item['product'] ?? $item['sku'] ?? '');
            if ($identifier === '') {
                continue;
            }

            $product = $this->products->find($identifier) ?? $this->products->findBySlug($identifier);
            if ($product === null) {
                throw new InvalidArgumentException(sprintf('Product "%s" is not available for POS checkout.', $identifier));
            }

            $quantity = (int) ($item['quantity'] ?? 1);
            if ($quantity < 1) {
                $quantity = 1;
            }

            $cart->addProduct($product, $quantity);
        }

        if ($cart->isEmpty()) {
            throw new RuntimeException('POS sale requires at least one valid product line item.');
        }

        $metadata = [
            'channel' => 'pos',
            'operator' => (string) ($options['operator'] ?? 'unknown'),
            'location' => (string) ($options['location'] ?? 'flagship'),
            'payment_method' => (string) ($options['payment_method'] ?? 'card'),
            'terminal' => (string) ($options['terminal'] ?? 'register-1'),
            'notes' => (string) ($options['notes'] ?? ''),
            'status' => (string) ($options['status'] ?? 'paid'),
        ];

        $order = $this->checkout->createOrder($cart, $customer, $metadata);
        $profile = $this->crm->upsertCustomer(array_merge($customer, [
            'tags' => array_merge(['pos'], (array) ($customer['tags'] ?? [])),
            'source' => 'in_person',
        ]));
        $interaction = $this->crm->recordInteraction($profile->id(), 'pos.sale', [
            'order_id' => $order['id'],
            'total' => $order['total'],
            'location' => $metadata['location'],
            'operator' => $metadata['operator'],
        ]);

        $ledgerEntry = [
            'id' => 'sale-' . bin2hex(random_bytes(5)),
            'order_id' => $order['id'],
            'customer_id' => $profile->id(),
            'recorded_at' => date(DATE_ATOM),
            'metadata' => [
                'operator' => $metadata['operator'],
                'location' => $metadata['location'],
                'payment_method' => $metadata['payment_method'],
                'terminal' => $metadata['terminal'],
            ],
        ];
        $this->appendLedger($ledgerEntry);

        return [
            'order' => $order,
            'customer' => $profile->toArray(),
            'interaction' => $interaction,
            'ledger_entry' => $ledgerEntry,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function ledger(): array
    {
        $contents = file_get_contents($this->ledgerPath);
        if ($contents === false) {
            return [];
        }

        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            return [];
        }

        usort(
            $decoded,
            static fn(array $a, array $b): int => strcmp((string) ($b['recorded_at'] ?? ''), (string) ($a['recorded_at'] ?? ''))
        );

        return $decoded;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function appendLedger(array $entry): void
    {
        $contents = file_get_contents($this->ledgerPath);
        $ledger = [];
        if ($contents !== false) {
            $decoded = json_decode($contents, true);
            if (is_array($decoded)) {
                $ledger = $decoded;
            }
        }

        $ledger[] = $entry;
        file_put_contents(
            $this->ledgerPath,
            json_encode($ledger, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }
}
