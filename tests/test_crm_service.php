<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Ricktorious/Ecommerce/bootstrap.php';

use Ricktorious\Ecommerce\CRM\CRMService;
use Ricktorious\Ecommerce\CRM\CustomerRepository;
use Ricktorious\Ecommerce\CRM\InteractionRepository;

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

$basePath = sys_get_temp_dir() . '/ricktorious-crm-' . bin2hex(random_bytes(4));
@mkdir($basePath, 0777, true);
$customersPath = $basePath . '/customers.json';
$interactionsPath = $basePath . '/interactions.json';

$customerRepository = new CustomerRepository($customersPath);
$interactionRepository = new InteractionRepository($interactionsPath);
$crm = new CRMService($customerRepository, $interactionRepository);

$profile = $crm->upsertCustomer([
    'name' => 'Ava Retail',
    'email' => 'ava@example.com',
    'phone' => '+44 20 7946 0958',
    'company' => 'Ricktorious Labs',
    'tags' => ['VIP', 'POS'],
    'metadata' => ['lifecycle_stage' => 'customer'],
]);
assertTrue(substr($profile->id(), 0, 5) === 'cust-');
assertEquals('ava@example.com', $profile->email());
assertEquals(['vip', 'pos'], $profile->tags());

$profile = $crm->upsertCustomer([
    'email' => 'ava@example.com',
    'tags' => ['loyalty'],
    'metadata' => ['owner' => 'growth-squad'],
]);
assertEquals('growth-squad', $profile->metadata()['owner'] ?? null);
assertTrue(in_array('loyalty', $profile->tags(), true));

$interaction = $crm->recordInteraction($profile->id(), 'follow_up', ['note' => 'Schedule in-store fitting.']);
assertEquals('follow_up', $interaction['type']);

$logged = $crm->interactions($profile->id());
assertEquals(1, count($logged));
assertEquals('Schedule in-store fitting.', $logged[0]['payload']['note']);

echo "CRM service tests passed\n";
