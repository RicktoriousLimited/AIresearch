<?php

declare(strict_types=1);

namespace Ricktorious\Ecommerce\Extensions;

use Ricktorious\Ecommerce\Core\AdhocApiRouter;
use Ricktorious\Ecommerce\Core\BlockRegistry;
use Ricktorious\Ecommerce\Core\BlockType;
use Ricktorious\Ecommerce\Core\ContentManager;
use Ricktorious\Ecommerce\Core\ExtensionInterface;
use Ricktorious\Ecommerce\AI\PersonalizationEngine;
use Ricktorious\Ecommerce\Analytics\UserBehaviorTracker;
use Ricktorious\Ecommerce\Catalog\ProductRepository;

final class CoreContentExtension implements ExtensionInterface
{
    public function __construct(
        private UserBehaviorTracker $tracker,
        private PersonalizationEngine $personalization,
        private ProductRepository $products
    ) {
    }

    public function getIdentifier(): string
    {
        return 'ricktorious.core_content';
    }

    public function registerBlocks(BlockRegistry $registry): void
    {
        $registry->register(new BlockType(
            'core.hero',
            'Hero banner',
            static function (array $settings, array $context): string {
                $heading = htmlspecialchars((string) ($settings['heading'] ?? ''), ENT_QUOTES, 'UTF-8');
                $subtitle = htmlspecialchars((string) ($settings['subtitle'] ?? ''), ENT_QUOTES, 'UTF-8');
                $ctaLabel = htmlspecialchars((string) ($settings['cta_label'] ?? 'Shop now'), ENT_QUOTES, 'UTF-8');
                $ctaUrl = htmlspecialchars((string) ($settings['cta_url'] ?? '#'), ENT_QUOTES, 'UTF-8');

                return <<<HTML
<section class="block hero">
    <div class="hero__content">
        <h1>{$heading}</h1>
        <p>{$subtitle}</p>
        <a class="button" href="{$ctaUrl}">{$ctaLabel}</a>
    </div>
</section>
HTML;
            },
            schema: [
                'heading' => ['type' => 'string', 'label' => 'Heading'],
                'subtitle' => ['type' => 'text', 'label' => 'Subtitle'],
                'cta_label' => ['type' => 'string', 'label' => 'CTA label'],
                'cta_url' => ['type' => 'string', 'label' => 'CTA URL'],
            ],
            defaultSettings: [
                'heading' => 'Discover Ricktorious Limited',
                'subtitle' => 'A block-based ecommerce experience for modern brands.',
                'cta_label' => 'Explore products',
                'cta_url' => '#products',
            ]
        ));

        $registry->register(new BlockType(
            'core.product_grid',
            'Product grid',
            function (array $settings, array $context): string {
                $products = $settings['products'] ?? $context['products'] ?? [];
                if (!is_array($products)) {
                    $products = [];
                }

                $items = [];
                foreach ($products as $product) {
                    $title = htmlspecialchars((string) ($product['title'] ?? 'Unnamed product'), ENT_QUOTES, 'UTF-8');
                    $price = htmlspecialchars((string) ($product['price'] ?? ''), ENT_QUOTES, 'UTF-8');
                    $image = htmlspecialchars((string) ($product['image'] ?? ''), ENT_QUOTES, 'UTF-8');
                    $items[] = <<<HTML
<li class="product">
    <div class="product__image" style="background-image: url('{$image}');"></div>
    <div class="product__body">
        <h3>{$title}</h3>
        <p class="product__price">{$price}</p>
    </div>
</li>
HTML;
                }

                $itemsMarkup = $items === []
                    ? '<li class="product product--empty">Products will appear here soon.</li>'
                    : implode("\n", $items);

                $this->tracker->recordEvent($context['user'] ?? 'guest', 'block.rendered', ['block' => 'core.product_grid']);

                return <<<HTML
<section class="block product-grid" id="products">
    <h2>Featured products</h2>
    <ul class="product-grid__items">
        {$itemsMarkup}
    </ul>
</section>
HTML;
            },
            schema: [
                'products' => ['type' => 'collection', 'label' => 'Products'],
            ],
            defaultSettings: []
        ));
    }

    public function boot(ContentManager $contentManager): void
    {
        $featured = array_map(
            function ($product): array {
                return [
                    'title' => $product->name(),
                    'price' => $product->formattedPrice(),
                    'image' => $product->primaryImage() ?? 'https://picsum.photos/seed/ricktorious-default/600/600',
                ];
            },
            $this->products->featured(4)
        );

        $contentManager->definePage('home', [
            'metadata' => [
                'title' => 'Ricktorious Limited Storefront',
                'description' => 'AI-personalised ecommerce blocks for the Ricktorious brand.',
            ],
            'blocks' => [
                [
                    'type' => 'core.hero',
                    'settings' => [
                        'heading' => 'The Ricktorious Experience',
                        'subtitle' => 'Build an adaptive storefront in minutes with extension-ready blocks.',
                        'cta_label' => 'View collection',
                        'cta_url' => '#products',
                    ],
                ],
                [
                    'type' => 'core.product_grid',
                    'settings' => [
                        'products' => $featured,
                    ],
                ],
            ],
        ]);
    }

    public function registerApis(AdhocApiRouter $router): void
    {
        $router->addRoute('GET', '/api/insights', function (array $query): array {
            $user = (string) ($query['user'] ?? 'guest');
            $insights = $this->personalization->insights($user);

            return [
                'status' => 200,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => $insights,
            ];
        });
    }
}
