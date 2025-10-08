<?php

declare(strict_types=1);

require __DIR__ . '/../src/Ricktorious/Ecommerce/bootstrap.php';

use Ricktorious\Ecommerce\AI\PersonalizationEngine;
use Ricktorious\Ecommerce\Analytics\UserBehaviorTracker;
use Ricktorious\Ecommerce\Core\AdhocApiRouter;
use Ricktorious\Ecommerce\Core\Application;
use Ricktorious\Ecommerce\Core\BlockRegistry;
use Ricktorious\Ecommerce\Core\ContentManager;
use Ricktorious\Ecommerce\Core\ExtensionManager;
use Ricktorious\Ecommerce\Extensions\CoreContentExtension;

session_start();

$userId = $_SESSION['ricktorious_user'] ?? null;
if ($userId === null) {
    $userId = 'user-' . bin2hex(random_bytes(4));
    $_SESSION['ricktorious_user'] = $userId;
}

$blockRegistry = new BlockRegistry();
$contentManager = new ContentManager();
$extensionManager = new ExtensionManager();
$behaviorTracker = new UserBehaviorTracker();
$personalization = new PersonalizationEngine($behaviorTracker);
$router = new AdhocApiRouter();

$coreExtension = new CoreContentExtension($behaviorTracker, $personalization);
$extensionManager->addExtension($coreExtension);

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

if (str_starts_with($path, '/api/')) {
    $query = $_GET;
    $payload = $_POST;
    $response = $app->handleApiRequest($method, $path, $query, $payload);

    http_response_code($response['status']);
    foreach ($response['headers'] as $name => $value) {
        header($name . ': ' . $value);
    }
    echo json_encode($response['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$catalogue = [
    ['title' => 'Ricktorious Modular Hoodie', 'price' => '$89', 'image' => 'https://picsum.photos/seed/ricktorious-hoodie/600/600'],
    ['title' => 'Blockbuilder Sneakers', 'price' => '$125', 'image' => 'https://picsum.photos/seed/ricktorious-sneakers/600/600'],
    ['title' => 'Adaptive Strategy Notebook', 'price' => '$22', 'image' => 'https://picsum.photos/seed/ricktorious-notebook/600/600'],
    ['title' => 'AI Commerce Playbook', 'price' => '$42', 'image' => 'https://picsum.photos/seed/ricktorious-playbook/600/600'],
];

$app->boot();
$page = $app->renderPage('home', [
    'user' => $userId,
    'products' => $catalogue,
]);

$recommended = $app->personalizationEngine()->recommendBlocks($userId, array_keys($blockRegistry->all()));
$behaviorTracker->recordEvent($userId, 'page.view', ['page' => 'home']);

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?php echo htmlspecialchars($page['title'], ENT_QUOTES, 'UTF-8'); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="<?php echo htmlspecialchars((string) ($page['metadata']['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
    <style>
        :root {
            color-scheme: light dark;
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: #0f172a;
            color: #e2e8f0;
        }
        body {
            margin: 0;
        }
        header.hero-shell {
            padding: 3rem 1rem 2rem;
            background: radial-gradient(circle at top left, #38bdf8, #0f172a 55%);
        }
        .wrapper {
            max-width: 1080px;
            margin: 0 auto;
            padding: 0 1.5rem 4rem;
        }
        .blocks {
            display: flex;
            flex-direction: column;
            gap: 2rem;
        }
        .block {
            background: rgba(15, 23, 42, 0.75);
            border: 1px solid rgba(148, 163, 184, 0.25);
            border-radius: 16px;
            padding: 2.5rem;
            box-shadow: 0 20px 40px rgba(8, 47, 73, 0.3);
        }
        .block.hero {
            background: linear-gradient(135deg, rgba(56, 189, 248, 0.2), rgba(129, 140, 248, 0.15));
        }
        .block.hero h1 {
            font-size: 2.75rem;
            margin-bottom: 1rem;
        }
        .block.hero p {
            margin-bottom: 1.5rem;
            color: rgba(226, 232, 240, 0.85);
        }
        .block.hero .button {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: #38bdf8;
            color: #0f172a;
            font-weight: 600;
            padding: 0.85rem 1.75rem;
            border-radius: 999px;
            text-decoration: none;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            box-shadow: 0 12px 24px rgba(56, 189, 248, 0.35);
        }
        .block.hero .button:hover {
            transform: translateY(-2px);
            box-shadow: 0 16px 32px rgba(56, 189, 248, 0.45);
        }
        .product-grid h2 {
            margin-bottom: 1.5rem;
        }
        .product-grid__items {
            list-style: none;
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 1.5rem;
            padding: 0;
            margin: 0;
        }
        .product {
            background: rgba(15, 23, 42, 0.8);
            border-radius: 18px;
            overflow: hidden;
            border: 1px solid rgba(148, 163, 184, 0.25);
            display: flex;
            flex-direction: column;
            min-height: 280px;
        }
        .product__image {
            background-size: cover;
            background-position: center;
            padding-top: 100%;
        }
        .product__body {
            padding: 1.25rem;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        .product__price {
            color: #38bdf8;
            font-weight: 600;
        }
        .insights {
            margin-top: 3rem;
            padding: 2rem;
            border-radius: 16px;
            background: rgba(15, 23, 42, 0.65);
            border: 1px solid rgba(148, 163, 184, 0.2);
        }
        .insights h2 {
            margin-top: 0;
        }
        code {
            font-family: 'JetBrains Mono', SFMono-Regular, ui-monospace, monospace;
            background: rgba(15, 23, 42, 0.85);
            padding: 0.35rem 0.5rem;
            border-radius: 8px;
        }
        footer {
            margin-top: 4rem;
            color: rgba(226, 232, 240, 0.55);
            text-align: center;
        }
        @media (max-width: 640px) {
            .block {
                padding: 1.75rem;
            }
            .block.hero h1 {
                font-size: 2.1rem;
            }
        }
    </style>
</head>
<body>
    <header class="hero-shell">
        <div class="wrapper">
            <p style="margin: 0; letter-spacing: 0.2em; font-size: 0.75rem; text-transform: uppercase; color: rgba(226,232,240,0.6);">Ricktorious Limited</p>
            <h1 style="margin: 0.5rem 0 0; font-size: 2.5rem;">Adaptive Ecommerce Playground</h1>
            <p style="max-width: 520px; color: rgba(226,232,240,0.7);">
                Extension-ready, block-based merchandising with an AI-first personalisation engine. Explore how the platform can learn from your audience and remix content automatically.
            </p>
        </div>
    </header>
    <main class="wrapper">
        <section class="blocks">
            <?php echo $page['html']; ?>
        </section>
        <section class="insights">
            <h2>Live personalisation insights</h2>
            <p>Your visitor ID: <code><?php echo htmlspecialchars($userId, ENT_QUOTES, 'UTF-8'); ?></code></p>
            <p>Recommended block order: <code><?php echo htmlspecialchars(implode(', ', $recommended), ENT_QUOTES, 'UTF-8'); ?></code></p>
            <p>Fetch full analytics via <code>/api/insights?user=<?php echo urlencode($userId); ?></code>.</p>
        </section>
        <footer>
            <p>&copy; <?php echo date('Y'); ?> Ricktorious Limited. Building a self-optimising commerce platform.</p>
        </footer>
    </main>
</body>
</html>
