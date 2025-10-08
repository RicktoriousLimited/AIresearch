<?php

declare(strict_types=1);

namespace Ricktorious\Ecommerce\Extensions;

use Ricktorious\Ecommerce\Catalog\ProductRepository;
use Ricktorious\Ecommerce\Checkout\Cart;
use Ricktorious\Ecommerce\Checkout\CheckoutService;
use Ricktorious\Ecommerce\Core\AdhocApiRouter;
use Ricktorious\Ecommerce\Core\BlockRegistry;
use Ricktorious\Ecommerce\Core\ContentManager;
use Ricktorious\Ecommerce\Core\ExtensionInterface;
use Ricktorious\Ecommerce\Analytics\UserBehaviorTracker;

final class CommerceExtension implements ExtensionInterface
{
    public function __construct(
        private ProductRepository $products,
        private Cart $cart,
        private CheckoutService $checkout,
        private UserBehaviorTracker $tracker
    ) {
    }

    public function getIdentifier(): string
    {
        return 'ricktorious.commerce';
    }

    public function registerBlocks(BlockRegistry $registry): void
    {
        // No-op for now. Blocks are supplied by other extensions.
    }

    public function boot(ContentManager $contentManager): void
    {
        // Content definitions handled elsewhere for now.
    }

    public function registerApis(AdhocApiRouter $router): void
    {
        $router->addRoute('GET', '/api/catalog/products', function (): array {
            $catalogue = array_map(
                static fn($product) => $product->toArray(),
                $this->products->all()
            );

            return [
                'status' => 200,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => [
                    'products' => $catalogue,
                ],
            ];
        });

        $router->addRoute('GET', '/api/cart/summary', function (): array {
            $items = [];
            foreach ($this->cart->detailedItems($this->products) as $item) {
                $product = $item['product'];
                $items[] = [
                    'product' => $product->toArray(),
                    'quantity' => $item['quantity'],
                    'line_total' => $item['line_total'],
                ];
            }

            $total = $this->cart->total($this->products);

            return [
                'status' => 200,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => [
                    'items' => $items,
                    'total' => $total,
                    'formatted_total' => '$' . number_format($total, 2),
                ],
            ];
        });

        $router->addRoute('POST', '/api/cart/add', function (array $query, array $payload): array {
            $productId = (string) ($payload['product'] ?? '');
            $quantity = (int) ($payload['quantity'] ?? 1);
            $product = $this->products->find($productId) ?? $this->products->findBySlug($productId);

            if ($product === null) {
                return [
                    'status' => 404,
                    'headers' => ['Content-Type' => 'application/json'],
                    'body' => ['error' => 'Product not found'],
                ];
            }

            $this->cart->addProduct($product, $quantity);
            $this->tracker->recordEvent($query['user'] ?? 'guest', 'cart.added', [
                'product' => $product->id(),
                'quantity' => $quantity,
                'channel' => 'api',
            ]);

            return [
                'status' => 200,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => [
                    'message' => 'Product added to cart',
                    'cart' => $this->cart->toArray(),
                ],
            ];
        });

        $router->addRoute('POST', '/api/checkout', function (array $query, array $payload): array {
            try {
                $order = $this->checkout->createOrder($this->cart, [
                    'name' => (string) ($payload['name'] ?? ''),
                    'email' => (string) ($payload['email'] ?? ''),
                    'address' => (string) ($payload['address'] ?? ''),
                ], [
                    'channel' => 'api',
                    'status' => 'paid',
                    'source' => 'headless_checkout',
                ]);
            } catch (\Throwable $exception) {
                return [
                    'status' => 422,
                    'headers' => ['Content-Type' => 'application/json'],
                    'body' => ['error' => $exception->getMessage()],
                ];
            }

            $this->tracker->recordEvent($query['user'] ?? 'guest', 'order.completed', [
                'order' => $order['id'],
                'total' => $order['total'],
            ]);
            $this->cart->clear();

            return [
                'status' => 201,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => $order,
            ];
        });
    }
}
