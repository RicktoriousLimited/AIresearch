<?php

declare(strict_types=1);

require __DIR__ . '/../src/Ricktorious/Ecommerce/bootstrap.php';

use Ricktorious\Ecommerce\Analytics\UserBehaviorTracker;
use Ricktorious\Ecommerce\Catalog\ProductRepository;
use Ricktorious\Ecommerce\Checkout\Cart;
use Ricktorious\Ecommerce\Checkout\CheckoutService;
use Ricktorious\Ecommerce\CRM\CRMService;
use Ricktorious\Ecommerce\Core\BlockRegistry;
use Ricktorious\Ecommerce\Orders\OrderProcessor;
use Ricktorious\Ecommerce\Orders\OrderRepository;
use Ricktorious\Ecommerce\POS\PointOfSaleService;
use Ricktorious\Ecommerce\Shipping\ShippingService;
use Ricktorious\Ecommerce\User\UserService;

session_start();

$userId = $_SESSION['ricktorious_user'] ?? null;
if ($userId === null) {
    $userId = 'user-' . bin2hex(random_bytes(4));
    $_SESSION['ricktorious_user'] = $userId;
}

$storedCart = $_SESSION['ricktorious_cart'] ?? [];
if (!is_array($storedCart)) {
    $storedCart = [];
}

$catalogPath = __DIR__ . '/../storage/catalog/products.json';
$ordersDirectory = __DIR__ . '/../storage/orders';
$crmCustomersPath = __DIR__ . '/../storage/crm/customers.json';
$crmInteractionsPath = __DIR__ . '/../storage/crm/interactions.json';
$posLedgerPath = __DIR__ . '/../storage/pos/transactions.json';
$shipmentsPath = __DIR__ . '/../storage/shipping/shipments.json';
$usersPath = __DIR__ . '/../storage/users/users.json';

$kernel = ricktorious_ecommerce_kernel([
    'paths' => [
        'catalog' => $catalogPath,
        'orders' => $ordersDirectory,
        'crm' => [
            'customers' => $crmCustomersPath,
            'interactions' => $crmInteractionsPath,
        ],
        'pos_ledger' => $posLedgerPath,
        'shipping_ledger' => $shipmentsPath,
        'users' => $usersPath,
    ],
    'session' => [
        'user_id' => $userId,
        'cart' => $storedCart,
    ],
]);

$container = $kernel->container();
$app = $kernel->application();

$repository = $container->get(ProductRepository::class);
$cart = $container->get(Cart::class);
$checkoutService = $container->get(CheckoutService::class);
$orderRepository = $container->get(OrderRepository::class);
$orderProcessor = $container->get(OrderProcessor::class);
$shippingService = $container->get(ShippingService::class);
$crmService = $container->get(CRMService::class);
$posService = $container->get(PointOfSaleService::class);
$userService = $container->get(UserService::class);
$blockRegistry = $container->get(BlockRegistry::class);
$behaviorTracker = $container->get(UserBehaviorTracker::class);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($uri, PHP_URL_PATH) ?: '/';
$scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '/ricktorious.php');
$scriptName = str_replace('\\', '/', $scriptName);
if ($scriptName === '') {
    $scriptName = '/ricktorious.php';
}

$pathInfo = $_SERVER['PATH_INFO'] ?? null;
if (is_string($pathInfo) && $pathInfo !== '') {
    $path = '/' . ltrim(str_replace('\\', '/', $pathInfo), '/');
} else {
    if ($path === $scriptName) {
        $path = '/';
    } elseif ($scriptName !== '' && str_starts_with($path, $scriptName . '/')) {
        $path = substr($path, strlen($scriptName));
        if ($path === '') {
            $path = '/';
        }
    } else {
        $scriptDir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
        if ($scriptDir === '.' || $scriptDir === '\\') {
            $scriptDir = '';
        }
        if ($scriptDir !== '' && $scriptDir !== '/') {
            if ($path === $scriptDir) {
                $path = '/';
            } elseif (str_starts_with($path, $scriptDir . '/')) {
                $path = substr($path, strlen($scriptDir));
                if ($path === '') {
                    $path = '/';
                }
            }
        }
    }
}
if (str_starts_with($path, '/api/')) {
    $payload = $_POST;
    if ($method !== 'GET' && $payload === []) {
        $rawInput = file_get_contents('php://input');
        if (is_string($rawInput) && $rawInput !== '') {
            $decoded = json_decode($rawInput, true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }
    }

    $query = $_GET;
    $query['user'] = $userId;
    $response = $app->handleApiRequest($method, $path, $query, $payload);

    $_SESSION['ricktorious_cart'] = $cart->toArray();

    http_response_code($response['status']);
    foreach ($response['headers'] as $name => $value) {
        header($name . ': ' . $value);
    }

    echo json_encode($response['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method === 'POST' && $path === '/cart/add') {
    $productId = (string) ($_POST['product'] ?? '');
    $quantity = (int) ($_POST['quantity'] ?? 1);
    $product = $repository->find($productId) ?? $repository->findBySlug($productId);

    if ($product !== null) {
        $cart->addProduct($product, $quantity);
        $behaviorTracker->recordEvent($userId, 'cart.added', [
            'product' => $product->id(),
            'quantity' => $quantity,
            'channel' => 'storefront',
        ]);
    }

    $_SESSION['ricktorious_cart'] = $cart->toArray();
    header('Location: /cart');
    exit;
}

if ($method === 'POST' && $path === '/cart/update') {
    $quantities = $_POST['quantities'] ?? [];
    if (is_array($quantities)) {
        foreach ($quantities as $productId => $quantity) {
            $cart->updateQuantity((string) $productId, (int) $quantity);
        }
    }

    $behaviorTracker->recordEvent($userId, 'cart.updated', ['items' => $cart->items()]);
    $_SESSION['ricktorious_cart'] = $cart->toArray();
    header('Location: /cart');
    exit;
}

if ($method === 'POST' && $path === '/cart/remove') {
    $productId = (string) ($_POST['product'] ?? '');
    $cart->removeProduct($productId);
    $behaviorTracker->recordEvent($userId, 'cart.removed', ['product' => $productId]);
    $_SESSION['ricktorious_cart'] = $cart->toArray();
    header('Location: /cart');
    exit;
}
function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function format_price(float $value, string $currency = '$'): string
{
    return $currency . number_format($value, 2);
}

function format_datetime(?string $timestamp): string
{
    if ($timestamp === null || $timestamp === '') {
        return '—';
    }

    $time = strtotime($timestamp);
    if ($time === false) {
        return escape($timestamp);
    }

    return escape(date('M j, Y H:i', $time));
}

function render_status_badge(string $status): string
{
    $slug = strtolower(trim($status));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? 'created';
    $slug = trim($slug, '-');
    if ($slug === '') {
        $slug = 'created';
    }

    $label = ucwords(str_replace(['-', '_'], ' ', $status));

    return '<span class="badge badge--' . escape($slug) . '">' . escape($label) . '</span>';
}

/**
 * @param array<string, mixed> $options
 */
function render_layout(string $title, string $content, array $options = []): void
{
    $description = (string) ($options['description'] ?? 'Extension-driven ecommerce playground for Ricktorious Limited.');
    $cartCount = (int) ($options['cart_count'] ?? 0);
    $active = (string) ($options['active'] ?? 'home');

    $navigation = [
        ['href' => '/', 'label' => 'Home', 'key' => 'home'],
        ['href' => '/catalog', 'label' => 'Catalog', 'key' => 'catalog'],
        ['href' => '/client-hub', 'label' => 'Client hub', 'key' => 'client'],
        ['href' => '/operations', 'label' => 'Staff ops', 'key' => 'operations'],
        ['href' => '/partners', 'label' => 'Partner hub', 'key' => 'partners'],
        ['href' => '/cart', 'label' => 'Cart (' . $cartCount . ')', 'key' => 'cart'],
    ];

    echo '<!DOCTYPE html>';
    ?>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?php echo escape($title); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="<?php echo escape($description); ?>">
    <style>
        :root {
            color-scheme: light dark;
            font-family: "Inter", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: #0b1220;
            color: #e2e8f0;
        }
        body {
            margin: 0;
            min-height: 100vh;
            background: radial-gradient(circle at top left, rgba(56,189,248,0.15), rgba(15,23,42,0.95));
        }
        a { color: inherit; }
        header.site-header {
            border-bottom: 1px solid rgba(148, 163, 184, 0.2);
            backdrop-filter: blur(20px);
            background: rgba(15, 23, 42, 0.85);
            position: sticky;
            top: 0;
            z-index: 20;
        }
        .shell {
            max-width: 1100px;
            margin: 0 auto;
            padding: 1.25rem 1.5rem;
        }
        .brand {
            font-size: 1.25rem;
            font-weight: 600;
            letter-spacing: 0.06em;
        }
        nav ul {
            list-style: none;
            display: flex;
            gap: 1.25rem;
            padding: 0;
            margin: 0;
        }
        nav a {
            text-decoration: none;
            font-weight: 500;
            padding-bottom: 0.25rem;
            border-bottom: 2px solid transparent;
        }
        nav a.active {
            border-color: #38bdf8;
            color: #38bdf8;
        }
        main {
            padding: 2rem 1.5rem 4rem;
        }
        .blocks {
            display: flex;
            flex-direction: column;
            gap: 2.5rem;
        }
        .block {
            background: rgba(15, 23, 42, 0.75);
            border-radius: 20px;
            border: 1px solid rgba(148, 163, 184, 0.2);
            padding: 2.5rem;
            box-shadow: 0 24px 40px rgba(8, 47, 73, 0.25);
        }
        .product-grid__items {
            list-style: none;
            padding: 0;
            margin: 0;
            display: grid;
            gap: 1.5rem;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        }
        .product-card {
            background: rgba(15, 23, 42, 0.8);
            border-radius: 16px;
            border: 1px solid rgba(148, 163, 184, 0.2);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            min-height: 340px;
            box-shadow: 0 24px 40px rgba(8, 47, 73, 0.25);
        }
        .product-card__media {
            background-size: cover;
            background-position: center;
            padding-top: 70%;
        }
        .product-card__body {
            padding: 1.5rem;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }
        .product-card__body p {
            margin: 0;
            color: rgba(226, 232, 240, 0.7);
        }
        .price {
            color: #38bdf8;
            font-weight: 600;
        }
        form.inline {
            margin-top: auto;
        }
        button.cta {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            border-radius: 999px;
            border: none;
            padding: 0.75rem 1.5rem;
            background: linear-gradient(135deg, #38bdf8, #818cf8);
            color: #0f172a;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            box-shadow: 0 12px 24px rgba(56, 189, 248, 0.35);
        }
        button.cta:hover {
            transform: translateY(-2px);
            box-shadow: 0 20px 32px rgba(56, 189, 248, 0.45);
        }
        table.cart {
            width: 100%;
            border-collapse: collapse;
            background: rgba(15, 23, 42, 0.8);
            border-radius: 16px;
            overflow: hidden;
        }
        table.cart th,
        table.cart td {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid rgba(148, 163, 184, 0.2);
        }
        table.cart th {
            text-align: left;
            font-weight: 600;
            background: rgba(30, 41, 59, 0.9);
        }
        table.cart td:last-child,
        table.cart th:last-child {
            text-align: right;
        }
        .empty-state {
            padding: 2rem;
            border-radius: 16px;
            background: rgba(15, 23, 42, 0.7);
            border: 1px solid rgba(148, 163, 184, 0.2);
            text-align: center;
            color: rgba(226, 232, 240, 0.7);
        }
        .pill {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            background: rgba(56, 189, 248, 0.15);
            border-radius: 999px;
            padding: 0.35rem 0.8rem;
            font-size: 0.85rem;
        }
        .dashboard-grid {
            display: grid;
            gap: 1.5rem;
        }
        .dashboard-grid--three {
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        }
        .dashboard-grid--two {
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
        }
        .stat-card {
            background: rgba(15, 23, 42, 0.75);
            border: 1px solid rgba(148, 163, 184, 0.2);
            border-radius: 18px;
            padding: 1.75rem;
            box-shadow: 0 18px 32px rgba(8, 47, 73, 0.2);
        }
        .stat-card h3 {
            margin: 0 0 0.5rem;
            font-size: 0.95rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: rgba(148, 163, 184, 0.9);
        }
        .stat-card strong {
            font-size: 2rem;
            display: block;
            font-weight: 700;
        }
        table.data-table {
            width: 100%;
            border-collapse: collapse;
            background: rgba(15, 23, 42, 0.8);
            border-radius: 16px;
            overflow: hidden;
        }
        table.data-table th,
        table.data-table td {
            padding: 0.85rem 1rem;
            border-bottom: 1px solid rgba(148, 163, 184, 0.18);
        }
        table.data-table th {
            text-align: left;
            font-weight: 600;
            background: rgba(30, 41, 59, 0.9);
        }
        table.data-table tbody tr:last-child td {
            border-bottom: none;
        }
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 0.2rem 0.75rem;
            border-radius: 999px;
            font-size: 0.8rem;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }
        .badge--created { background: rgba(148, 163, 184, 0.2); color: #e2e8f0; }
        .badge--pending { background: rgba(251, 191, 36, 0.2); color: #facc15; }
        .badge--paid { background: rgba(56, 189, 248, 0.2); color: #38bdf8; }
        .badge--processing { background: rgba(129, 140, 248, 0.2); color: #a5b4fc; }
        .badge--fulfilled { background: rgba(34, 197, 94, 0.2); color: #34d399; }
        .badge--shipped { background: rgba(14, 165, 233, 0.2); color: #38bdf8; }
        .badge--delivered { background: rgba(34, 197, 94, 0.25); color: #4ade80; }
        .badge--cancelled { background: rgba(248, 113, 113, 0.2); color: #fca5a5; }
        .split-layout {
            display: grid;
            gap: 2rem;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
        }
        .callout {
            background: rgba(15, 23, 42, 0.7);
            border: 1px solid rgba(148, 163, 184, 0.25);
            border-radius: 16px;
            padding: 1.5rem;
        }
        .callout h4 {
            margin-top: 0;
        }
        .form-grid {
            display: grid;
            gap: 1rem;
        }
        .form-grid.two-column {
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        }
        .form-grid label {
            display: flex;
            flex-direction: column;
            gap: 0.4rem;
            font-weight: 600;
        }
        .form-grid input,
        .form-grid textarea,
        .form-grid select {
            padding: 0.75rem 1rem;
            border-radius: 12px;
            border: 1px solid rgba(148, 163, 184, 0.35);
            background: rgba(15, 23, 42, 0.75);
            color: inherit;
            font: inherit;
        }
        .muted {
            color: rgba(226, 232, 240, 0.6);
        }
        .tag-list {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        .tag {
            padding: 0.35rem 0.65rem;
            border-radius: 10px;
            background: rgba(30, 64, 175, 0.35);
            color: #c7d2fe;
            font-size: 0.75rem;
            letter-spacing: 0.05em;
        }
        .table-empty {
            padding: 1.25rem;
            text-align: center;
            color: rgba(226, 232, 240, 0.6);
        }
        .success {
            color: #4ade80;
        }
        .error {
            color: #fca5a5;
        }
        form.checkout {
            display: grid;
            gap: 1rem;
            max-width: 520px;
        }
        form.checkout label {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            font-weight: 600;
        }
        form.checkout input,
        form.checkout textarea {
            padding: 0.85rem 1rem;
            border-radius: 12px;
            border: 1px solid rgba(148, 163, 184, 0.35);
            background: rgba(15, 23, 42, 0.75);
            color: inherit;
            font: inherit;
        }
        form.checkout textarea {
            min-height: 140px;
            resize: vertical;
        }
        .messages {
            list-style: none;
            padding: 0;
            margin: 0 0 1.5rem;
        }
        .messages li {
            background: rgba(248, 113, 113, 0.25);
            border: 1px solid rgba(248, 113, 113, 0.4);
            color: #fecaca;
            padding: 0.75rem 1rem;
            border-radius: 12px;
            margin-bottom: 0.75rem;
        }
        footer {
            margin: 3rem auto 0;
            padding: 2rem 1.5rem 4rem;
            color: rgba(226, 232, 240, 0.5);
            text-align: center;
        }
        @media (max-width: 720px) {
            nav ul {
                flex-wrap: wrap;
            }
            table.cart th,
            table.cart td {
                padding: 0.75rem 0.5rem;
            }
        }
    </style>
</head>
<body>
<header class="site-header">
    <div class="shell" style="display: flex; justify-content: space-between; align-items: center; gap: 1.5rem;">
        <div class="brand">Ricktorious Commerce Lab</div>
        <nav>
            <ul>
                <?php foreach ($navigation as $item): ?>
                    <?php $class = $item['key'] === $active ? 'active' : ''; ?>
                    <li><a class="<?php echo $class; ?>" href="<?php echo escape($item['href']); ?>"><?php echo escape($item['label']); ?></a></li>
                <?php endforeach; ?>
            </ul>
        </nav>
    </div>
</header>
<main class="shell">
    <?php echo $content; ?>
</main>
<footer>
    <p>&copy; <?php echo date('Y'); ?> Ricktorious Limited. Building an adaptive, AI-first commerce platform.</p>
</footer>
</body>
</html>
<?php
}

function render_product_card(array $product): string
{
    $image = escape($product['image'] ?? '');
    $name = escape($product['name'] ?? '');
    $price = escape($product['price'] ?? '');
    $description = escape($product['description'] ?? '');
    $id = escape($product['id'] ?? '');
    $slug = escape($product['slug'] ?? '');

    return <<<HTML
<article class="product-card">
    <a href="/product/{$slug}" class="product-card__media" style="background-image: url('{$image}');" aria-label="View {$name}"></a>
    <div class="product-card__body">
        <h3>{$name}</h3>
        <p>{$description}</p>
        <div class="price">{$price}</div>
        <form method="post" action="/cart/add" class="inline">
            <input type="hidden" name="product" value="{$id}">
            <input type="hidden" name="quantity" value="1">
            <button class="cta" type="submit">Add to cart</button>
        </form>
    </div>
</article>
HTML;
}

$app->boot();

if ($path === '/' || $path === '') {
    $featured = array_map(
        static fn($product) => [
            'title' => $product->name(),
            'price' => $product->formattedPrice(),
            'image' => $product->primaryImage() ?? 'https://picsum.photos/seed/ricktorious-default/600/600',
        ],
        $repository->featured(4)
    );

    $page = $app->renderPage('home', [
        'user' => $userId,
        'products' => $featured,
    ]);

    $recommended = $app->personalizationEngine()->recommendBlocks($userId, array_keys($blockRegistry->all()));
    $behaviorTracker->recordEvent($userId, 'page.view', ['page' => 'home']);

    $insightsHtml = '<section class="block" style="background: rgba(15,23,42,0.7); border-radius: 16px; padding: 2rem;">'
        . '<h2>Personalisation insights</h2>'
        . '<p>Your visitor ID: <code style="background: rgba(15,23,42,0.95); padding: 0.25rem 0.5rem; border-radius: 8px;">'
        . escape($userId)
        . '</code></p>'
        . '<p>Recommended block order: <code style="background: rgba(15,23,42,0.95); padding: 0.25rem 0.5rem; border-radius: 8px;">'
        . escape(implode(', ', $recommended))
        . '</code></p>'
        . '<p>Fetch detailed analytics via <code>/api/insights?user=' . escape($userId) . '</code>.</p>'
        . '</section>';

    $content = '<section class="blocks">' . $page['html'] . $insightsHtml . '</section>';

    render_layout($page['title'], $content, [
        'description' => (string) ($page['metadata']['description'] ?? ''),
        'cart_count' => $cart->itemCount(),
        'active' => 'home',
    ]);
    $_SESSION['ricktorious_cart'] = $cart->toArray();

    return;
}

if ($path === '/client-hub') {
    $behaviorTracker->recordEvent($userId, 'page.view', ['page' => 'client-hub']);

    $registrationMessages = [];
    $registrationSuccess = '';
    $lookupError = '';
    $lookupResult = null;

    if ($method === 'POST') {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'register') {
            $name = trim((string) ($_POST['name'] ?? ''));
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            $password = (string) ($_POST['password'] ?? '');
            $company = trim((string) ($_POST['company'] ?? ''));

            try {
                $user = $userService->register([
                    'email' => $email,
                    'password' => $password,
                    'roles' => ['customer'],
                    'profile' => [
                        'name' => $name,
                        'company' => $company,
                    ],
                ]);

                $crmService->upsertCustomer([
                    'name' => $name,
                    'email' => $email,
                    'company' => $company !== '' ? $company : null,
                    'tags' => ['client'],
                    'metadata' => [
                        'lifecycle_stage' => 'client',
                        'source' => 'client_hub',
                    ],
                ]);

                $registrationSuccess = 'Account created for ' . escape((string) ($user['email'] ?? $email)) . '. You can now checkout with saved details.';
                $_POST['name'] = '';
                $_POST['email'] = '';
                $_POST['password'] = '';
                $_POST['company'] = '';
                $behaviorTracker->recordEvent($userId, 'client.registered', ['email' => $email]);
            } catch (\Throwable $exception) {
                $registrationMessages[] = $exception->getMessage();
            }
        } elseif ($action === 'lookup') {
            $orderId = trim((string) ($_POST['order_id'] ?? ''));
            if ($orderId === '') {
                $lookupError = 'Provide a valid order ID to fetch its status.';
            } else {
                $order = $orderRepository->find($orderId);
                if ($order === null) {
                    $lookupError = 'No order could be found for that reference.';
                } else {
                    $lookupResult = $order;
                    $behaviorTracker->recordEvent($userId, 'client.order_lookup', ['order' => $orderId]);
                }
            }
        }
    }

    $registrationFeedback = '';
    if ($registrationMessages !== []) {
        $registrationFeedback .= '<ul class="messages">' . implode('', array_map(static fn(string $message): string => '<li>' . escape($message) . '</li>', $registrationMessages)) . '</ul>';
    }
    if ($registrationSuccess !== '') {
        $registrationFeedback .= '<p class="success">' . $registrationSuccess . '</p>';
    }

    $orderMarkup = '';
    if ($lookupResult !== null) {
        $itemsMarkup = '';
        $currency = (string) ($lookupResult['currency'] ?? '$');
        foreach ((array) ($lookupResult['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $itemsMarkup .= '<li><strong>' . escape((string) ($item['product_name'] ?? 'Unknown item')) . '</strong> × '
                . (int) ($item['quantity'] ?? 0)
                . ' — ' . escape(format_price((float) ($item['line_total'] ?? 0), $currency)) . '</li>';
        }

        $timelineMarkup = '';
        foreach ((array) ($lookupResult['timeline'] ?? []) as $event) {
            if (!is_array($event)) {
                continue;
            }

            $timelineMarkup .= '<li>'
                . render_status_badge((string) ($event['status'] ?? ''))
                . ' <span class="muted">' . format_datetime($event['timestamp'] ?? '') . '</span>'
                . '</li>';
        }

        if ($itemsMarkup === '') {
            $itemsMarkup = '<li class="muted">No line items available.</li>';
        }
        if ($timelineMarkup === '') {
            $timelineMarkup = '<li class="muted">Timeline events will appear once the order progresses.</li>';
        }

        $orderMarkup = '<div class="callout">'
            . '<h3 style="margin-top:0;">Order ' . escape((string) ($lookupResult['id'] ?? '')) . '</h3>'
            . '<p>Status ' . render_status_badge((string) ($lookupResult['status'] ?? 'pending')) . '</p>'
            . '<p>Total <strong>' . escape((string) ($lookupResult['formatted_total'] ?? format_price((float) ($lookupResult['total'] ?? 0), $currency))) . '</strong></p>'
            . '<p class="muted">Placed ' . format_datetime($lookupResult['created_at'] ?? '') . '</p>'
            . '<h4>Items</h4><ul>' . $itemsMarkup . '</ul>'
            . '<h4>Timeline</h4><ul class="muted" style="padding-left:1.2rem;">' . $timelineMarkup . '</ul>'
            . '</div>';
    } elseif ($lookupError !== '') {
        $orderMarkup = '<p class="error">' . escape($lookupError) . '</p>';
    }

    $events = $behaviorTracker->eventsForUser($userId);
    $eventsMarkup = '';
    if ($events === []) {
        $eventsMarkup = '<p class="muted">Browse the catalog and interact with the storefront to populate personalised insights.</p>';
    } else {
        $eventsMarkup = '<ul class="muted">';
        foreach ($events as $event) {
            $eventsMarkup .= '<li>' . '<strong>' . escape((string) ($event['event'] ?? 'event')) . '</strong>'
                . ' — ' . format_datetime(isset($event['timestamp']) ? (string) $event['timestamp'] : null)
                . '</li>';
        }
        $eventsMarkup .= '</ul>';
    }

    $content = '<section class="blocks">'
        . '<section class="block">'
        . '<h1 style="margin-top:0;">Client success hub</h1>'
        . '<p class="muted">Register an account to unlock saved profiles, faster checkout, and personalised experiments powered by the Ricktorious labs.</p>'
        . $registrationFeedback
        . '<form method="post" class="form-grid two-column" action="/client-hub">'
        . '<input type="hidden" name="action" value="register">'
        . '<label>Full name<input type="text" name="name" value="' . escape((string) ($_POST['name'] ?? '')) . '" placeholder="Alex Rivera" required></label>'
        . '<label>Company<input type="text" name="company" value="' . escape((string) ($_POST['company'] ?? '')) . '" placeholder="Optional"></label>'
        . '<label>Email address<input type="email" name="email" value="' . escape((string) ($_POST['email'] ?? '')) . '" placeholder="you@company.com" required></label>'
        . '<label>Password<input type="password" name="password" value="' . escape((string) ($_POST['password'] ?? '')) . '" required></label>'
        . '<div style="grid-column: 1 / -1; display: flex; justify-content: flex-end;"><button class="cta" type="submit">Create account</button></div>'
        . '</form>'
        . '</section>'
        . '<section class="block">'
        . '<h2 style="margin-top:0;">Track an order</h2>'
        . '<p class="muted">Enter your order reference to inspect its fulfilment status, shipment timeline, and line items.</p>'
        . '<form method="post" class="form-grid" action="/client-hub">'
        . '<input type="hidden" name="action" value="lookup">'
        . '<label>Order reference<input type="text" name="order_id" value="' . escape((string) ($_POST['order_id'] ?? '')) . '" placeholder="ord-xxxxxxxx" required></label>'
        . '<div><button class="cta" type="submit">Lookup</button></div>'
        . '</form>'
        . $orderMarkup
        . '</section>'
        . '<section class="block">'
        . '<h2 style="margin-top:0;">Your live session insights</h2>'
        . '<p class="muted">The personalisation engine is already observing how you interact with the storefront. Use this feed to tailor experiments.</p>'
        . $eventsMarkup
        . '<p class="muted">Need API access? Review <a href="/partners" style="color: #38bdf8;">partner integrations</a> to stream your telemetry into existing stacks.</p>'
        . '</section>'
        . '</section>';

    render_layout('Client hub', $content, [
        'cart_count' => $cart->itemCount(),
        'active' => 'client',
    ]);
    $_SESSION['ricktorious_cart'] = $cart->toArray();

    return;
}

if ($path === '/catalog') {
    $behaviorTracker->recordEvent($userId, 'catalog.view', []);

    $products = array_map(
        static fn($product) => [
            'id' => $product->id(),
            'slug' => $product->slug(),
            'name' => $product->name(),
            'price' => $product->formattedPrice(),
            'description' => mb_substr($product->description(), 0, 120) . '…',
            'image' => $product->primaryImage() ?? 'https://picsum.photos/seed/ricktorious-default/600/600',
        ],
        $repository->all()
    );

    $cards = array_map('render_product_card', $products);
    $content = '<section class="blocks">'
        . '<section class="block">'
        . '<h1 style="margin-top: 0;">Explore the Ricktorious catalog</h1>'
        . '<p style="color: rgba(226,232,240,0.7); max-width: 640px;">Curated modules, apparel, and knowledge artefacts engineered for adaptive commerce teams.</p>'
        . '</section>'
        . '<section class="block">'
        . '<div class="product-grid__items">' . implode('', $cards) . '</div>'
        . '</section>'
        . '</section>';

    render_layout('Catalog', $content, [
        'cart_count' => $cart->itemCount(),
        'active' => 'catalog',
        'description' => 'Browse the Ricktorious Limited product catalog.',
    ]);
    $_SESSION['ricktorious_cart'] = $cart->toArray();

    return;
}

if ($path === '/operations') {
    $behaviorTracker->recordEvent($userId, 'page.view', ['page' => 'operations']);

    $orders = $orderRepository->all();
    $totalOrders = count($orders);
    $openOrders = 0;
    $totalRevenue = 0.0;
    $statusCounts = [];

    foreach ($orders as $order) {
        $status = strtolower((string) ($order['status'] ?? 'pending'));
        $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
        if (!in_array($status, ['delivered', 'cancelled'], true)) {
            $openOrders++;
        }
        $totalRevenue += (float) ($order['total'] ?? 0);
    }

    $avgOrderValue = $totalOrders > 0 ? $totalRevenue / $totalOrders : 0.0;
    $processingCount = (int) ($statusCounts['processing'] ?? 0);
    $paidCount = (int) ($statusCounts['paid'] ?? 0);

    $shipments = $shippingService->shipments();
    $shipmentCount = count($shipments);
    $latestShipments = array_slice($shipments, 0, 5);

    $customers = array_map(
        static fn($customer) => $customer->toArray(),
        $crmService->customers()
    );
    $customerCount = count($customers);
    $interactions = array_slice(array_reverse($crmService->interactions()), 0, 6);

    $ledger = $posService->ledger();
    $posCount = count($ledger);
    $latestLedger = array_slice($ledger, 0, 5);

    $staffAccounts = $userService->list('staff');
    $partnerAccounts = $userService->list('partner');

    $latestOrders = array_slice($orders, 0, 5);
    $orderRows = '';
    foreach ($latestOrders as $order) {
        $orderId = (string) ($order['id'] ?? '');
        $orderRows .= '<tr>'
            . '<td><a href="' . escape('/operations/order/' . rawurlencode($orderId)) . '">' . escape($orderId) . '</a></td>'
            . '<td>' . escape((string) ($order['customer']['name'] ?? 'Guest')) . '</td>'
            . '<td>' . render_status_badge((string) ($order['status'] ?? 'pending')) . '</td>'
            . '<td>' . escape((string) ($order['formatted_total'] ?? format_price((float) ($order['total'] ?? 0), (string) ($order['currency'] ?? '$')))) . '</td>'
            . '<td>' . format_datetime($order['created_at'] ?? '') . '</td>'
            . '</tr>';
    }
    if ($orderRows === '') {
        $orderRows = '<tr><td colspan="5" class="table-empty">Orders will appear here once checkouts are completed.</td></tr>';
    }

    $shipmentRows = '';
    foreach ($latestShipments as $shipment) {
        $shipmentRows .= '<tr>'
            . '<td>' . escape((string) ($shipment['id'] ?? '')) . '</td>'
            . '<td>' . escape((string) ($shipment['carrier'] ?? '')) . ' · ' . escape((string) ($shipment['service'] ?? '')) . '</td>'
            . '<td>' . escape((string) ($shipment['tracking_number'] ?? '')) . '</td>'
            . '<td>' . format_datetime($shipment['created_at'] ?? '') . '</td>'
            . '</tr>';
    }
    if ($shipmentRows === '') {
        $shipmentRows = '<tr><td colspan="4" class="table-empty">No shipments have been generated yet.</td></tr>';
    }

    $ledgerRows = '';
    foreach ($latestLedger as $entry) {
        $ledgerRows .= '<tr>'
            . '<td>' . escape((string) ($entry['id'] ?? '')) . '</td>'
            . '<td>' . escape((string) ($entry['order_id'] ?? '')) . '</td>'
            . '<td>' . escape((string) ($entry['metadata']['operator'] ?? '')) . ' · ' . escape((string) ($entry['metadata']['location'] ?? '')) . '</td>'
            . '<td>' . format_datetime($entry['recorded_at'] ?? '') . '</td>'
            . '</tr>';
    }
    if ($ledgerRows === '') {
        $ledgerRows = '<tr><td colspan="4" class="table-empty">Capture a point-of-sale transaction to populate this ledger.</td></tr>';
    }

    $customerCards = '';
    foreach (array_slice($customers, 0, 5) as $customer) {
        $tags = '';
        foreach ((array) ($customer['tags'] ?? []) as $tag) {
            $tags .= '<span class="tag">' . escape((string) $tag) . '</span>';
        }
        if ($tags !== '') {
            $tags = '<div class="tag-list">' . $tags . '</div>';
        }

        $customerCards .= '<div class="callout">'
            . '<h4 style="margin-top:0;">' . escape((string) ($customer['name'] ?? 'Unknown customer')) . '</h4>'
            . '<p class="muted">' . escape((string) ($customer['email'] ?? '')) . '</p>'
            . $tags
            . '</div>';
    }
    if ($customerCards === '') {
        $customerCards = '<p class="muted">No CRM profiles yet. Captured checkouts will create unified records automatically.</p>';
    }

    $interactionItems = '';
    foreach ($interactions as $interaction) {
        $interactionItems .= '<li><strong>' . escape((string) ($interaction['type'] ?? 'event')) . '</strong> — '
            . escape((string) ($interaction['customer_id'] ?? ''))
            . '<br><span class="muted">' . format_datetime($interaction['recorded_at'] ?? '') . '</span></li>';
    }
    if ($interactionItems === '') {
        $interactionItems = '<li class="muted">Interactions from CRM and POS activity will appear here.</li>';
    }

    $staffList = '';
    foreach ($staffAccounts as $staff) {
        $staffList .= '<li><strong>' . escape((string) ($staff['profile']['name'] ?? $staff['email'] ?? 'Staff member')) . '</strong> — ' . escape((string) ($staff['email'] ?? '')) . '</li>';
    }
    if ($staffList === '') {
        $staffList = '<li class="muted">Invite operators via the user management API to coordinate fulfilment.</li>';
    }

    $partnerList = '';
    foreach ($partnerAccounts as $partner) {
        $partnerList .= '<li><strong>' . escape((string) ($partner['profile']['name'] ?? $partner['email'] ?? 'Partner')) . '</strong> — ' . escape((string) ($partner['email'] ?? '')) . '</li>';
    }
    if ($partnerList === '') {
        $partnerList = '<li class="muted">Onboard partners from the partner hub to unlock co-marketing programmes.</li>';
    }

    $statCards = '<div class="dashboard-grid dashboard-grid--three">'
        . '<div class="stat-card"><h3>Total orders</h3><strong>' . $totalOrders . '</strong><p class="muted">Lifetime orders across all channels.</p></div>'
        . '<div class="stat-card"><h3>Open pipeline</h3><strong>' . $openOrders . '</strong><p class="muted">Processing ' . $processingCount . ' · Paid ' . $paidCount . '</p></div>'
        . '<div class="stat-card"><h3>Revenue</h3><strong>' . escape(format_price($totalRevenue)) . '</strong><p class="muted">Avg order ' . escape(format_price($avgOrderValue)) . '</p></div>'
        . '<div class="stat-card"><h3>Customer network</h3><strong>' . $customerCount . '</strong><p class="muted">' . $shipmentCount . ' shipments · ' . $posCount . ' POS sales</p></div>'
        . '</div>';

    $content = '<section class="blocks">'
        . '<section class="block">'
        . '<h1 style="margin-top:0;">Operations mission control</h1>'
        . '<p class="muted">Monitor fulfilment, CRM health, and in-person sales from a single Ricktorious console.</p>'
        . $statCards
        . '</section>'
        . '<section class="block">'
        . '<h2 style="margin-top:0;">Latest orders</h2>'
        . '<table class="data-table">'
        . '<thead><tr><th>Order</th><th>Customer</th><th>Status</th><th>Total</th><th>Created</th></tr></thead>'
        . '<tbody>' . $orderRows . '</tbody>'
        . '</table>'
        . '</section>'
        . '<section class="block">'
        . '<div class="split-layout">'
        . '<div>'
        . '<h2 style="margin-top:0;">Shipments</h2>'
        . '<table class="data-table">'
        . '<thead><tr><th>ID</th><th>Service</th><th>Tracking</th><th>Created</th></tr></thead>'
        . '<tbody>' . $shipmentRows . '</tbody>'
        . '</table>'
        . '</div>'
        . '<div>'
        . '<h2 style="margin-top:0;">POS ledger</h2>'
        . '<table class="data-table">'
        . '<thead><tr><th>Sale</th><th>Order</th><th>Operator</th><th>Recorded</th></tr></thead>'
        . '<tbody>' . $ledgerRows . '</tbody>'
        . '</table>'
        . '</div>'
        . '</div>'
        . '</section>'
        . '<section class="block">'
        . '<div class="split-layout">'
        . '<div>'
        . '<h2 style="margin-top:0;">CRM directory</h2>'
        . $customerCards
        . '</div>'
        . '<div>'
        . '<h2 style="margin-top:0;">Latest interactions</h2>'
        . '<ul class="muted">' . $interactionItems . '</ul>'
        . '</div>'
        . '</div>'
        . '</section>'
        . '<section class="block">'
        . '<div class="split-layout">'
        . '<div>'
        . '<h2 style="margin-top:0;">Staff roster</h2>'
        . '<ul>' . $staffList . '</ul>'
        . '</div>'
        . '<div>'
        . '<h2 style="margin-top:0;">Partner ecosystem</h2>'
        . '<ul>' . $partnerList . '</ul>'
        . '</div>'
        . '</div>'
        . '<p class="muted">Use the <code>/api/users/*</code> endpoints to invite collaborators with precise roles.</p>'
        . '</section>'
        . '</section>';

    render_layout('Operations control centre', $content, [
        'cart_count' => $cart->itemCount(),
        'active' => 'operations',
    ]);
    $_SESSION['ricktorious_cart'] = $cart->toArray();

    return;
}

if (preg_match('#^/operations/order/([A-Za-z0-9\-]+)$#', $path, $matches)) {
    $orderId = $matches[1];
    $order = $orderRepository->find($orderId);

    if ($order === null) {
        http_response_code(404);
        render_layout('Order not found', '<div class="empty-state">No order exists for that reference.</div>', [
            'cart_count' => $cart->itemCount(),
            'active' => 'operations',
        ]);
        $_SESSION['ricktorious_cart'] = $cart->toArray();

        return;
    }

    $feedback = [];
    $positiveMessage = '';

    if ($method === 'POST') {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'transition') {
            $status = trim((string) ($_POST['status'] ?? ''));

            if ($status === '') {
                $feedback[] = 'Choose a status to apply before submitting.';
            } else {
                try {
                    $order = $orderProcessor->transitionStatus($orderId, $status, ['actor' => 'operations_console']);
                    $positiveMessage = 'Order advanced to ' . ucwords($status) . '.';
                } catch (\Throwable $exception) {
                    $feedback[] = $exception->getMessage();
                }
            }
        } elseif ($action === 'ship') {
            $carrier = trim((string) ($_POST['carrier'] ?? ''));
            $service = trim((string) ($_POST['service'] ?? ''));
            $address = [
                'name' => (string) ($_POST['ship_name'] ?? ($order['customer']['name'] ?? '')),
                'line1' => (string) ($_POST['line1'] ?? ''),
                'line2' => (string) ($_POST['line2'] ?? ''),
                'city' => (string) ($_POST['city'] ?? ''),
                'state' => (string) ($_POST['state'] ?? ''),
                'postal_code' => (string) ($_POST['postal_code'] ?? ''),
                'country' => (string) ($_POST['country'] ?? 'US'),
            ];

            if ($carrier === '' || $service === '') {
                $feedback[] = 'Carrier and service are required to create a shipment.';
            } else {
                try {
                    $result = $orderProcessor->createShipment($orderId, $address, $carrier, $service, [
                        'actor' => 'operations_console',
                    ]);
                    $order = $result['order'];
                    $shipment = $result['shipment'];
                    $positiveMessage = 'Shipment ' . escape((string) ($shipment['tracking_number'] ?? '')) . ' created successfully.';
                } catch (\Throwable $exception) {
                    $feedback[] = $exception->getMessage();
                }
            }
        }
    }

    $transitions = [];
    try {
        $transitions = $orderProcessor->availableTransitions($orderId);
    } catch (\Throwable $exception) {
        $transitions = [];
    }

    $transitionOptions = '';
    foreach ($transitions as $transition) {
        $transitionOptions .= '<option value="' . escape($transition) . '">' . escape(ucwords($transition)) . '</option>';
    }
    if ($transitionOptions === '') {
        $transitionOptions = '<option value="">No transitions available</option>';
    }

    $itemsMarkup = '';
    $currency = (string) ($order['currency'] ?? '$');
    foreach ((array) ($order['items'] ?? []) as $item) {
        if (!is_array($item)) {
            continue;
        }

        $itemsMarkup .= '<li><strong>' . escape((string) ($item['product_name'] ?? 'Item')) . '</strong> × '
            . (int) ($item['quantity'] ?? 0)
            . ' — ' . escape(format_price((float) ($item['line_total'] ?? 0), $currency)) . '</li>';
    }
    if ($itemsMarkup === '') {
        $itemsMarkup = '<li class="muted">No line items available.</li>';
    }

    $timelineMarkup = '';
    foreach ((array) ($order['timeline'] ?? []) as $event) {
        if (!is_array($event)) {
            continue;
        }

        $timelineMarkup .= '<li>' . render_status_badge((string) ($event['status'] ?? ''))
            . ' <span class="muted">' . format_datetime($event['timestamp'] ?? '') . '</span>'
            . '</li>';
    }
    if ($timelineMarkup === '') {
        $timelineMarkup = '<li class="muted">Timeline will populate as the order progresses.</li>';
    }

    $shipmentCards = '';
    foreach ((array) ($order['shipments'] ?? []) as $shipment) {
        if (!is_array($shipment)) {
            continue;
        }

        $shipmentCards .= '<div class="callout">'
            . '<h4 style="margin-top:0;">' . escape((string) ($shipment['carrier'] ?? '')) . ' — ' . escape((string) ($shipment['service'] ?? '')) . '</h4>'
            . '<p class="muted">Tracking ' . escape((string) ($shipment['tracking_number'] ?? '')) . '</p>'
            . '<p class="muted">Created ' . format_datetime($shipment['created_at'] ?? '') . '</p>'
            . '</div>';
    }
    if ($shipmentCards === '') {
        $shipmentCards = '<p class="muted">No shipments created. Generate a label to trigger fulfilment.</p>';
    }

    $formDefaults = [
        'ship_name' => (string) ($_POST['ship_name'] ?? ($order['customer']['name'] ?? '')),
        'line1' => (string) ($_POST['line1'] ?? ($order['customer']['address'] ?? '')),
        'line2' => (string) ($_POST['line2'] ?? ''),
        'city' => (string) ($_POST['city'] ?? ''),
        'state' => (string) ($_POST['state'] ?? ''),
        'postal_code' => (string) ($_POST['postal_code'] ?? ''),
        'country' => (string) ($_POST['country'] ?? 'US'),
        'carrier' => (string) ($_POST['carrier'] ?? 'UPS'),
        'service' => (string) ($_POST['service'] ?? 'ground'),
    ];

    $quoteAddress = [
        'postal_code' => $formDefaults['postal_code'],
        'country' => $formDefaults['country'],
    ];
    $quotes = $shippingService->quote($order, $quoteAddress);
    $quoteRows = '';
    foreach ($quotes as $quote) {
        $quoteRows .= '<tr>'
            . '<td>' . escape((string) ($quote['carrier'] ?? '')) . '</td>'
            . '<td>' . escape((string) ($quote['service'] ?? '')) . '</td>'
            . '<td>' . escape(format_price((float) ($quote['amount'] ?? 0), (string) ($quote['currency'] ?? '$'))) . '</td>'
            . '<td>' . format_datetime($quote['estimated_delivery'] ?? '') . '</td>'
            . '</tr>';
    }

    $alerts = '';
    if ($feedback !== []) {
        $alerts .= '<ul class="messages">' . implode('', array_map(static fn(string $message): string => '<li>' . escape($message) . '</li>', $feedback)) . '</ul>';
    }
    if ($positiveMessage !== '') {
        $alerts .= '<p class="success">' . $positiveMessage . '</p>';
    }

    $content = '<section class="blocks">'
        . '<section class="block">'
        . '<p><a href="/operations" style="color:#38bdf8;">&larr; Back to operations</a></p>'
        . '<h1 style="margin-top:0;">' . escape((string) $orderId) . '</h1>'
        . '<p class="muted">Status ' . render_status_badge((string) ($order['status'] ?? 'pending')) . '</p>'
        . $alerts
        . '<div class="split-layout">'
        . '<div>'
        . '<h2 style="margin-top:0;">Line items</h2>'
        . '<ul>' . $itemsMarkup . '</ul>'
        . '</div>'
        . '<div>'
        . '<h2 style="margin-top:0;">Timeline</h2>'
        . '<ul class="muted">' . $timelineMarkup . '</ul>'
        . '</div>'
        . '</div>'
        . '</section>'
        . '<section class="block">'
        . '<h2 style="margin-top:0;">Update status</h2>'
        . '<form method="post" class="form-grid" action="' . escape('/operations/order/' . rawurlencode($orderId)) . '">' .
            '<input type="hidden" name="action" value="transition">'
            . '<label>Next status<select name="status">' . $transitionOptions . '</select></label>'
            . '<div><button class="cta" type="submit">Apply status</button></div>'
        . '</form>'
        . '</section>'
        . '<section class="block">'
        . '<div class="split-layout">'
        . '<div>'
        . '<h2 style="margin-top:0;">Create shipment</h2>'
        . '<form method="post" class="form-grid two-column" action="' . escape('/operations/order/' . rawurlencode($orderId)) . '">' .
            '<input type="hidden" name="action" value="ship">'
            . '<label>Carrier<select name="carrier">'
            . '<option value="UPS"' . ($formDefaults['carrier'] === 'UPS' ? ' selected' : '') . '>UPS</option>'
            . '<option value="FedEx"' . ($formDefaults['carrier'] === 'FedEx' ? ' selected' : '') . '>FedEx</option>'
            . '<option value="DHL"' . ($formDefaults['carrier'] === 'DHL' ? ' selected' : '') . '>DHL</option>'
            . '</select></label>'
            . '<label>Service<select name="service">'
            . '<option value="ground"' . ($formDefaults['service'] === 'ground' ? ' selected' : '') . '>Ground</option>'
            . '<option value="express"' . ($formDefaults['service'] === 'express' ? ' selected' : '') . '>Express</option>'
            . '<option value="priority"' . ($formDefaults['service'] === 'priority' ? ' selected' : '') . '>Priority</option>'
            . '</select></label>'
            . '<label>Recipient name<input type="text" name="ship_name" value="' . escape($formDefaults['ship_name']) . '" required></label>'
            . '<label>Address line 1<input type="text" name="line1" value="' . escape($formDefaults['line1']) . '" required></label>'
            . '<label>Address line 2<input type="text" name="line2" value="' . escape($formDefaults['line2']) . '"></label>'
            . '<label>City<input type="text" name="city" value="' . escape($formDefaults['city']) . '"></label>'
            . '<label>State / Region<input type="text" name="state" value="' . escape($formDefaults['state']) . '"></label>'
            . '<label>Postal code<input type="text" name="postal_code" value="' . escape($formDefaults['postal_code']) . '"></label>'
            . '<label>Country<input type="text" name="country" value="' . escape($formDefaults['country']) . '"></label>'
            . '<div style="grid-column:1 / -1; display:flex; justify-content:flex-end;"><button class="cta" type="submit">Generate shipment</button></div>'
        . '</form>'
        . '</div>'
        . '<div>'
        . '<h2 style="margin-top:0;">Quote preview</h2>'
        . '<table class="data-table">'
        . '<thead><tr><th>Carrier</th><th>Service</th><th>Cost</th><th>Delivery</th></tr></thead>'
        . '<tbody>' . $quoteRows . '</tbody>'
        . '</table>'
        . '</div>'
        . '</div>'
        . '</section>'
        . '<section class="block">'
        . '<h2 style="margin-top:0;">Shipments</h2>'
        . $shipmentCards
        . '</section>'
        . '</section>';

    render_layout('Order ' . $orderId, $content, [
        'cart_count' => $cart->itemCount(),
        'active' => 'operations',
    ]);
    $_SESSION['ricktorious_cart'] = $cart->toArray();

    return;
}

if ($path === '/partners') {
    $behaviorTracker->recordEvent($userId, 'page.view', ['page' => 'partners']);

    $orders = $orderRepository->all();
    $orderCount = count($orders);
    $revenue = 0.0;
    foreach ($orders as $order) {
        $revenue += (float) ($order['total'] ?? 0);
    }

    $shipments = $shippingService->shipments();
    $customers = array_map(
        static fn($customer) => $customer->toArray(),
        $crmService->customers()
    );
    $partners = $userService->list('partner');

    $products = array_map(
        static fn($product) => [
            'name' => $product->name(),
            'slug' => $product->slug(),
            'price' => $product->price(),
            'formatted_price' => $product->formattedPrice(),
            'tags' => $product->tags(),
            'inventory' => $product->inventory(),
        ],
        $repository->all()
    );
    usort($products, static fn(array $a, array $b): int => $b['price'] <=> $a['price']);
    $products = array_slice($products, 0, 4);

    $productRows = '';
    foreach ($products as $product) {
        $tagBadges = implode('', array_map(static fn(string $tag): string => '<span class="pill">' . escape($tag) . '</span>', (array) ($product['tags'] ?? [])));
        $productRows .= '<tr>'
            . '<td><strong>' . escape((string) ($product['name'] ?? '')) . '</strong><br><span class="muted">' . escape((string) ($product['slug'] ?? '')) . '</span></td>'
            . '<td>' . escape((string) ($product['formatted_price'] ?? format_price((float) ($product['price'] ?? 0)))) . '</td>'
            . '<td>' . (int) ($product['inventory'] ?? 0) . '</td>'
            . '<td>' . $tagBadges . '</td>'
            . '</tr>';
    }

    $partnerList = '';
    foreach ($partners as $partner) {
        $partnerList .= '<li><strong>' . escape((string) ($partner['profile']['name'] ?? $partner['email'] ?? 'Partner')) . '</strong> — ' . escape((string) ($partner['email'] ?? '')) . '</li>';
    }
    if ($partnerList === '') {
        $partnerList = '<li class="muted">Register via the client hub and request partner privileges to appear here.</li>';
    }

    $apiEndpoints = [
        '/api/catalog/products',
        '/api/cart/summary',
        '/api/orders',
        '/api/users/register',
        '/api/analytics/events',
    ];
    $endpointList = '<ul>' . implode('', array_map(static fn(string $endpoint): string => '<li><code>' . escape($endpoint) . '</code></li>', $apiEndpoints)) . '</ul>';

    $statCards = '<div class="dashboard-grid dashboard-grid--three">'
        . '<div class="stat-card"><h3>Orders processed</h3><strong>' . $orderCount . '</strong><p class="muted">Across storefront, POS, and partner channels.</p></div>'
        . '<div class="stat-card"><h3>Revenue unlocked</h3><strong>' . escape(format_price($revenue)) . '</strong><p class="muted">Connect to stream performance dashboards.</p></div>'
        . '<div class="stat-card"><h3>Shipments</h3><strong>' . count($shipments) . '</strong><p class="muted">Fulfilled through Ricktorious labs network.</p></div>'
        . '<div class="stat-card"><h3>Customers</h3><strong>' . count($customers) . '</strong><p class="muted">Unified CRM profiles ready for segmentation.</p></div>'
        . '</div>';

    $content = '<section class="blocks">'
        . '<section class="block">'
        . '<h1 style="margin-top:0;">Partner innovation hub</h1>'
        . '<p class="muted">Extend Ricktorious commerce experiences into your storefronts, marketplaces, and data platforms with a partner-ready API surface.</p>'
        . $statCards
        . '</section>'
        . '<section class="block">'
        . '<h2 style="margin-top:0;">Integration surface</h2>'
        . '<p class="muted">Prototype against our PHP runtime or bring your own stack with these JSON endpoints.</p>'
        . $endpointList
        . '<p class="muted">Need a sandbox token? Contact <a href="mailto:commerce@ricktorious.local" style="color:#38bdf8;">commerce@ricktorious.local</a>.</p>'
        . '</section>'
        . '<section class="block">'
        . '<h2 style="margin-top:0;">Merchandising feed</h2>'
        . '<table class="data-table">'
        . '<thead><tr><th>Product</th><th>Price</th><th>Inventory</th><th>Tags</th></tr></thead>'
        . '<tbody>' . $productRows . '</tbody>'
        . '</table>'
        . '</section>'
        . '<section class="block">'
        . '<div class="split-layout">'
        . '<div>'
        . '<h2 style="margin-top:0;">Partner roster</h2>'
        . '<ul>' . $partnerList . '</ul>'
        . '</div>'
        . '<div>'
        . '<h2 style="margin-top:0;">Launch checklist</h2>'
        . '<ol class="muted">'
        . '<li>Call <code>/api/users/register</code> to provision API users.</li>'
        . '<li>Synchronise catalog data via <code>/api/catalog/products</code>.</li>'
        . '<li>Pipe behavioural signals into <code>/api/analytics/events</code>.</li>'
        . '<li>Stream orders and fulfilment via <code>/api/orders</code>.</li>'
        . '<li>Co-build go-to-market plans with the Ricktorious labs team.</li>'
        . '</ol>'
        . '</div>'
        . '</div>'
        . '</section>'
        . '</section>';

    render_layout('Partner hub', $content, [
        'cart_count' => $cart->itemCount(),
        'active' => 'partners',
    ]);
    $_SESSION['ricktorious_cart'] = $cart->toArray();

    return;
}

if (preg_match('#^/product/([A-Za-z0-9-]+)$#', $path, $matches)) {
    $slug = $matches[1];
    $product = $repository->findBySlug($slug);
    if ($product === null) {
        http_response_code(404);
        render_layout('Product not found', '<div class="empty-state">Product not found.</div>', [
            'cart_count' => $cart->itemCount(),
            'active' => '',
        ]);
        $_SESSION['ricktorious_cart'] = $cart->toArray();

        return;
    }

    $behaviorTracker->recordEvent($userId, 'product.view', [
        'product' => $product->id(),
        'slug' => $product->slug(),
    ]);

    $tags = $product->tags() === []
        ? ''
        : '<p>' . implode('', array_map(static fn(string $tag): string => '<span class="pill">' . escape($tag) . '</span>', $product->tags())) . '</p>';

    $image = escape($product->primaryImage() ?? 'https://picsum.photos/seed/ricktorious-default/800/800');
    $name = escape($product->name());
    $price = escape($product->formattedPrice());
    $description = nl2br(escape($product->description()));
    $id = escape($product->id());

    $content = <<<HTML
<section class="blocks">
    <article class="block" style="display: grid; gap: 2rem; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); align-items: start;">
        <div style="border-radius: 18px; overflow: hidden; box-shadow: 0 20px 40px rgba(8,47,73,0.35); min-height: 320px; background: url('{$image}') center/cover;"></div>
        <div>
            <h1 style="margin-top: 0;">{$name}</h1>
            <div class="price" style="font-size: 1.5rem;">{$price}</div>
            <div style="margin: 1.5rem 0; color: rgba(226,232,240,0.75);">{$description}</div>
            {$tags}
            <form method="post" action="/cart/add" style="margin-top: 1.5rem; display: flex; gap: 1rem; align-items: center;">
                <input type="hidden" name="product" value="{$id}">
                <label style="display: flex; flex-direction: column; font-weight: 600; gap: 0.5rem;">
                    Quantity
                    <input type="number" name="quantity" value="1" min="1" style="width: 100px; padding: 0.6rem 0.75rem; border-radius: 12px; border: 1px solid rgba(148,163,184,0.35); background: rgba(15,23,42,0.75); color: inherit;">
                </label>
                <button class="cta" type="submit">Add to cart</button>
            </form>
        </div>
    </article>
</section>
HTML;

    render_layout($product->name(), $content, [
        'cart_count' => $cart->itemCount(),
        'active' => '',
        'description' => $product->description(),
    ]);
    $_SESSION['ricktorious_cart'] = $cart->toArray();

    return;
}

if ($path === '/cart') {
    $behaviorTracker->recordEvent($userId, 'cart.view', ['items' => $cart->items()]);
    $detailed = $cart->detailedItems($repository);
    $currency = '$';

    if ($detailed === []) {
        $content = '<div class="empty-state">Your cart is currently empty. Explore the catalog to add products.</div>';
        render_layout('Your cart', $content, [
            'cart_count' => 0,
            'active' => 'cart',
        ]);
        $_SESSION['ricktorious_cart'] = $cart->toArray();

        return;
    }

    $rows = '';
    foreach ($detailed as $item) {
        $product = $item['product'];
        $currency = $product->currency();
        $rows .= '<tr>';
        $rows .= '<td><strong>' . escape($product->name()) . '</strong><br><span style="color: rgba(226,232,240,0.6);">' . escape($product->description()) . '</span></td>';
        $rows .= '<td>' . escape($product->formattedPrice()) . '</td>';
        $rows .= '<td><input type="number" name="quantities[' . escape($product->id()) . ']" value="' . $item['quantity'] . '" min="0" style="width: 80px; padding: 0.5rem; border-radius: 12px; border: 1px solid rgba(148,163,184,0.35); background: rgba(15,23,42,0.75); color: inherit;"></td>';
        $rows .= '<td>' . escape(format_price($item['line_total'], $currency)) . '</td>';
        $rows .= '<td>';
        $rows .= '<form method="post" action="/cart/remove" style="display: inline;">';
        $rows .= '<input type="hidden" name="product" value="' . escape($product->id()) . '">';
        $rows .= '<button type="submit" style="background: none; border: none; color: rgba(248,113,113,0.8); cursor: pointer;">Remove</button>';
        $rows .= '</form>';
        $rows .= '</td>';
        $rows .= '</tr>';
    }

    $total = $cart->total($repository);

    $content = <<<HTML
<section class="blocks">
    <section class="block">
        <h1 style="margin-top: 0;">Your cart</h1>
        <form method="post" action="/cart/update">
            <table class="cart">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Unit price</th>
                        <th>Quantity</th>
                        <th>Line total</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    {$rows}
                </tbody>
            </table>
            <div style="display: flex; justify-content: space-between; align-items: center; gap: 1rem; margin-top: 1.5rem;">
                <button class="cta" type="submit" style="background: rgba(148, 163, 184, 0.2); color: #e2e8f0; box-shadow: none;">Update quantities</button>
                <div style="font-size: 1.25rem; font-weight: 600;">Total: {TOTAL}</div>
            </div>
        </form>
        <div style="margin-top: 2rem; text-align: right;">
            <a href="/checkout" class="cta" style="text-decoration: none;">Proceed to checkout</a>
        </div>
    </section>
</section>
HTML;

    $content = str_replace('{TOTAL}', escape(format_price($total, $currency)), $content);

    render_layout('Your cart', $content, [
        'cart_count' => $cart->itemCount(),
        'active' => 'cart',
    ]);
    $_SESSION['ricktorious_cart'] = $cart->toArray();

    return;
}

if ($path === '/checkout') {
    $errors = [];
    $submitted = $method === 'POST';
    $order = null;

    if ($submitted) {
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $address = trim((string) ($_POST['address'] ?? ''));

        if ($name === '') {
            $errors[] = 'Please provide your name.';
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please provide a valid email address.';
        }
        if ($address === '') {
            $errors[] = 'Please provide a delivery address.';
        }
        if ($cart->isEmpty()) {
            $errors[] = 'Your cart is empty. Add items before checking out.';
        }

        if ($errors === []) {
            try {
                $order = $checkoutService->createOrder($cart, [
                    'name' => $name,
                    'email' => $email,
                    'address' => $address,
                ], [
                    'channel' => 'storefront',
                    'status' => 'paid',
                    'source' => 'web_checkout',
                ]);
                $behaviorTracker->recordEvent($userId, 'order.completed', [
                    'order' => $order['id'],
                    'total' => $order['total'],
                ]);
                $cart->clear();
                $_SESSION['ricktorious_cart'] = $cart->toArray();
            } catch (\Throwable $exception) {
                $errors[] = $exception->getMessage();
            }
        }
    }

    if ($order !== null) {
        $itemsMarkup = '';
        foreach ($order['items'] as $item) {
            $itemsMarkup .= '<li><strong>' . escape($item['product_name']) . '</strong> × ' . (int) $item['quantity'] . ' — ' . escape(format_price((float) $item['line_total'], (string) $order['currency'])) . '</li>';
        }

        $content = '<section class="blocks">'
            . '<section class="block">'
            . '<h1>Thank you!</h1>'
            . '<p>Your order <code>' . escape($order['id']) . '</code> has been received.</p>'
            . '<ul>' . $itemsMarkup . '</ul>'
            . '<p style="font-size: 1.25rem; font-weight: 600;">Total: ' . escape($order['formatted_total']) . '</p>'
            . '<p>We have emailed a confirmation to ' . escape($order['customer']['email']) . '.</p>'
            . '<p><a href="/catalog" class="cta" style="text-decoration: none;">Continue shopping</a></p>'
            . '</section>'
            . '</section>';

        render_layout('Order confirmed', $content, [
            'cart_count' => 0,
            'active' => '',
        ]);

        return;
    }

    $behaviorTracker->recordEvent($userId, 'checkout.view', ['items' => $cart->items()]);

    $messages = '';
    if ($errors !== []) {
        $messages = '<ul class="messages">' . implode('', array_map(static fn(string $error): string => '<li>' . escape($error) . '</li>', $errors)) . '</ul>';
    }

    $content = '<section class="blocks">'
        . '<section class="block">'
        . '<h1 style="margin-top: 0;">Checkout</h1>'
        . '<p style="color: rgba(226,232,240,0.7);">Provide your details and we will orchestrate fulfilment through the Ricktorious labs network.</p>'
        . $messages
        . '<form method="post" class="checkout">'
        . '<label>Name<input type="text" name="name" value="' . escape((string) ($_POST['name'] ?? '')) . '" required></label>'
        . '<label>Email<input type="email" name="email" value="' . escape((string) ($_POST['email'] ?? '')) . '" required></label>'
        . '<label>Delivery address<textarea name="address" required>' . escape((string) ($_POST['address'] ?? '')) . '</textarea></label>'
        . '<button class="cta" type="submit">Place order</button>'
        . '</form>'
        . '</section>'
        . '</section>';

    render_layout('Checkout', $content, [
        'cart_count' => $cart->itemCount(),
        'active' => '',
    ]);
    $_SESSION['ricktorious_cart'] = $cart->toArray();

    return;
}

http_response_code(404);
render_layout('Page not found', '<div class="empty-state">The page you are looking for does not exist.</div>', [
    'cart_count' => $cart->itemCount(),
    'active' => '',
]);
$_SESSION['ricktorious_cart'] = $cart->toArray();

