<?php

declare(strict_types=1);

require __DIR__ . '/../src/Ricktorious/Ecommerce/bootstrap.php';

use Ricktorious\Ecommerce\AI\PersonalizationEngine;
use Ricktorious\Ecommerce\Analytics\UserBehaviorTracker;
use Ricktorious\Ecommerce\Catalog\ProductRepository;
use Ricktorious\Ecommerce\Checkout\Cart;
use Ricktorious\Ecommerce\Checkout\CheckoutService;
use Ricktorious\Ecommerce\CRM\CRMService;
use Ricktorious\Ecommerce\CRM\CustomerRepository;
use Ricktorious\Ecommerce\CRM\InteractionRepository;
use Ricktorious\Ecommerce\Core\AdhocApiRouter;
use Ricktorious\Ecommerce\Core\Application;
use Ricktorious\Ecommerce\Core\BlockRegistry;
use Ricktorious\Ecommerce\Core\ContentManager;
use Ricktorious\Ecommerce\Core\ExtensionManager;
use Ricktorious\Ecommerce\Extensions\CommerceExtension;
use Ricktorious\Ecommerce\Extensions\CoreContentExtension;
use Ricktorious\Ecommerce\Extensions\OperationsExtension;
use Ricktorious\Ecommerce\POS\PointOfSaleService;

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

$repository = new ProductRepository($catalogPath);
$cart = Cart::fromArray($storedCart);
$checkoutService = new CheckoutService($ordersDirectory, $repository);
$customerRepository = new CustomerRepository($crmCustomersPath);
$interactionRepository = new InteractionRepository($crmInteractionsPath);
$crmService = new CRMService($customerRepository, $interactionRepository);
$posService = new PointOfSaleService($checkoutService, $repository, $crmService, $posLedgerPath);

$blockRegistry = new BlockRegistry();
$contentManager = new ContentManager();
$extensionManager = new ExtensionManager();
$behaviorTracker = new UserBehaviorTracker();
$personalization = new PersonalizationEngine($behaviorTracker);
$router = new AdhocApiRouter();

$coreExtension = new CoreContentExtension($behaviorTracker, $personalization, $repository);
$commerceExtension = new CommerceExtension($repository, $cart, $checkoutService, $behaviorTracker);
$operationsExtension = new OperationsExtension($crmService, $posService);
$extensionManager->addExtension($coreExtension);
$extensionManager->addExtension($commerceExtension);
$extensionManager->addExtension($operationsExtension);

$app = new Application(
    $blockRegistry,
    $contentManager,
    $extensionManager,
    $router,
    $behaviorTracker,
    $personalization
);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($uri, PHP_URL_PATH) ?: '/';
if ($path === '/ricktorious.php') {
    $path = '/';
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

