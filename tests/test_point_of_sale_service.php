<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Ricktorious/Ecommerce/bootstrap.php';

use Ricktorious\Ecommerce\CRM\CRMService;
use Ricktorious\Ecommerce\CRM\CustomerRepository;
use Ricktorious\Ecommerce\CRM\InteractionRepository;
use Ricktorious\Ecommerce\Catalog\ProductRepository;
use Ricktorious\Ecommerce\Checkout\CheckoutService;
use Ricktorious\Ecommerce\POS\PointOfSaleService;

ini_set('assert.exception', '1');

function assertEquals(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected != $actual) {
        $prefix = $message !== '' ? $message . ' ' : '';
        throw new AssertionError($prefix . 'Expected ' . var_export($expected, true) . ' but got ' . var_export($actual, true));
    }
}

function assertTrue(bool $condition, string $message = ''): void
{
    if (!$condition) {
        throw new AssertionError($message !== '' ? $message : 'Expected condition to be true.');
    }
}

$basePath = sys_get_temp_dir() . '/ricktorious-pos-' . bin2hex(random_bytes(4));
@mkdir($basePath, 0777, true);
$ordersDirectory = $basePath . '/orders';
$ledgerPath = $basePath . '/ledger.json';
$customersPath = $basePath . '/customers.json';
$interactionsPath = $basePath . '/interactions.json';

$customerRepository = new CustomerRepository($customersPath);
$interactionRepository = new InteractionRepository($interactionsPath);
$crm = new CRMService($customerRepository, $interactionRepository);
$catalog = new ProductRepository(__DIR__ . '/../storage/catalog/products.json');
$checkout = new CheckoutService($ordersDirectory, $catalog);
$pos = new PointOfSaleService($checkout, $catalog, $crm, $ledgerPath);

$result = $pos->processSale([
    ['product_id' => 'prod-hoodie', 'quantity' => 2],
    ['product' => 'strategy-notebook', 'quantity' => 1],
], [
    'name' => 'Retail Walk-in',
    'email' => 'walkin@example.com',
], [
    'operator' => 'alice',
    'location' => 'London Lab',
    'payment_method' => 'cash',
]);

assertEquals('pos', $result['order']['channel']);
assertEquals('alice', $result['order']['metadata']['operator']);
assertEquals('London Lab', $result['order']['metadata']['location']);
assertTrue(isset($result['customer']['id']));

$ledger = json_decode((string) file_get_contents($ledgerPath), true);
assertEquals(1, count($ledger));
assertEquals($result['order']['id'], $ledger[0]['order_id']);

$interactions = $crm->interactions($result['customer']['id']);
assertEquals(1, count($interactions));
assertEquals('pos.sale', $interactions[0]['type']);

echo "POS service tests passed\n";
