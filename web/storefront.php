<?php
declare(strict_types=1);

session_start();

$assetVersion = (string) (file_exists(__DIR__ . '/assets/styles.css') ? filemtime(__DIR__ . '/assets/styles.css') : time());

$scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '/storefront.php');
$scriptDir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
if ($scriptDir === '' || $scriptDir === '.') {
    $scriptDir = '';
} elseif ($scriptDir === '/') {
    $scriptDir = '';
}

$apiBase = ($scriptDir !== '' ? $scriptDir : '') . '/ricktorious.php';
$apiBase = preg_replace('#//+#', '/', $apiBase) ?: '/ricktorious.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ricktorious Limited — Adaptive Commerce Experience</title>
    <meta name="description" content="Deploy the Ricktorious Limited adaptive commerce demo with personalised merchandising, realtime cart APIs, and integrated checkout workflows.">
    <meta property="og:title" content="Ricktorious Limited — Adaptive Commerce Experience">
    <meta property="og:description" content="Explore the adaptive commerce playground with catalogue APIs, behavioural insights, and a live checkout flow you can deploy today.">
    <meta property="og:type" content="website">
    <?php
    $https = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $scheme = $https ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'example.com');
    ?>
    <meta property="og:url" content="<?= htmlspecialchars($scheme) ?>://<?= htmlspecialchars($host) ?><?= htmlspecialchars($scriptName) ?>">
    <meta name="theme-color" content="#38bdf8">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/styles.css?v=<?= htmlspecialchars($assetVersion, ENT_QUOTES) ?>">
</head>
<body data-api-base="<?= htmlspecialchars($apiBase, ENT_QUOTES) ?>">
<header class="site-header">
    <div class="shell header-shell">
        <div class="brand">Ricktorious Limited</div>
        <nav class="primary-nav" aria-label="Primary">
            <a href="#home">Home</a>
            <a href="#catalog">Catalog</a>
            <a href="#insights">Insights</a>
            <a href="#checkout">Checkout</a>
        </nav>
        <div class="header-actions">
            <button type="button" class="button ghost" id="cart-button" aria-expanded="false">
                Cart <span class="badge" id="cart-count">0</span>
            </button>
        </div>
    </div>
</header>

<main>
    <section class="hero" id="home">
        <div class="shell hero-shell">
            <div class="hero-copy">
                <p class="eyebrow">Ricktorious Commerce Lab</p>
                <h1>Launch an AI-personalised storefront in minutes.</h1>
                <p class="lead">The Ricktorious Limited experience blends adaptive merchandising, behavioural telemetry, and extension-ready APIs so you can pilot new retail ideas without reinventing the platform.</p>
                <div class="hero-actions">
                    <a class="button primary" href="#catalog">Browse products</a>
                    <a class="button ghost" href="#insights">View insights</a>
                </div>
                <dl class="metrics">
                    <div>
                        <dt>Realtime cart engine</dt>
                        <dd>Session aware &amp; API driven</dd>
                    </div>
                    <div>
                        <dt>Operational tooling</dt>
                        <dd>CRM, POS &amp; fulfilment APIs</dd>
                    </div>
                    <div>
                        <dt>Personalisation</dt>
                        <dd>Behavioural insights per visitor</dd>
                    </div>
                </dl>
            </div>
            <div class="hero-media" aria-hidden="true">
                <div class="hero-card">Personalised drops</div>
                <div class="hero-card">Commerce extensions</div>
                <div class="hero-card">Fulfilment orchestration</div>
            </div>
        </div>
    </section>

    <section class="catalog" id="catalog">
        <div class="shell catalog-shell">
            <header class="section-header">
                <div>
                    <h2>Catalog</h2>
                    <p>Products are sourced from the Ricktorious content engine and delivered through the headless commerce API.</p>
                </div>
                <div class="catalog-controls">
                    <label>
                        <span class="control-label">Search</span>
                        <input type="search" id="search-input" placeholder="Search products">
                    </label>
                    <label>
                        <span class="control-label">Category</span>
                        <select id="tag-filter">
                            <option value="all">All categories</option>
                        </select>
                    </label>
                </div>
            </header>
            <div class="product-grid" id="product-grid" aria-live="polite"></div>
            <div class="empty-state" id="empty-state" hidden>
                <h3>No products found</h3>
                <p>Try adjusting the search or choose another category to explore Ricktorious drops.</p>
                <button type="button" class="button ghost" id="reset-filters">Reset filters</button>
            </div>
        </div>
    </section>

    <section class="insights" id="insights">
        <div class="shell insights-shell">
            <header class="section-header">
                <div>
                    <h2>Visitor telemetry</h2>
                    <p>Every interaction can be captured for recommendations and experimentation. These insights are sourced directly from the behavioural tracker.</p>
                </div>
            </header>
            <div class="insight-grid" id="insight-panels">
                <article class="insight-card">
                    <h3>Total events</h3>
                    <p class="metric" id="insight-events">0</p>
                    <p class="muted">Events captured across cart, checkout, and content interactions for this session.</p>
                </article>
                <article class="insight-card">
                    <h3>Popular blocks</h3>
                    <ul class="tag-list" id="insight-blocks"></ul>
                    <p class="muted">Block level engagement recorded by the AI personalisation engine.</p>
                </article>
            </div>
        </div>
    </section>

    <section class="checkout" id="checkout">
        <div class="shell checkout-shell">
            <header class="section-header">
                <div>
                    <h2>Checkout</h2>
                    <p>Complete an order using the Ricktorious checkout API. Orders are persisted to the storage layer ready for fulfilment workflows.</p>
                </div>
            </header>
            <div class="messages" id="checkout-messages" hidden></div>
            <form id="checkout-form" class="checkout-form" novalidate>
                <label>
                    <span>Name</span>
                    <input type="text" name="name" id="checkout-name" placeholder="Ada Lovelace" required>
                </label>
                <label>
                    <span>Email</span>
                    <input type="email" name="email" id="checkout-email" placeholder="ada@ricktorious.example" required>
                </label>
                <label class="full">
                    <span>Delivery address</span>
                    <textarea name="address" id="checkout-address" rows="4" placeholder="123 Commerce Avenue, Innovation District" required></textarea>
                </label>
                <button type="submit" class="button primary">Place order</button>
            </form>
        </div>
    </section>
</main>

<div class="cart-overlay" id="cart-overlay" aria-hidden="true">
    <div class="cart-panel" role="dialog" aria-modal="true" aria-labelledby="cart-title">
        <header class="cart-header">
            <h2 id="cart-title">Your cart</h2>
            <button type="button" class="icon-button" id="cart-close" aria-label="Close cart">
                <span aria-hidden="true">&times;</span>
            </button>
        </header>
        <div class="cart-body" id="cart-body" aria-live="polite"></div>
        <footer class="cart-footer">
            <div class="cart-total">
                <span>Total</span>
                <strong id="cart-total">$0.00</strong>
            </div>
            <div class="cart-actions">
                <button type="button" class="button ghost" id="cart-clear">Clear cart</button>
                <a class="button primary" href="#checkout" id="cart-checkout">Checkout</a>
            </div>
        </footer>
    </div>
</div>

<footer class="site-footer">
    <div class="shell footer-shell">
        <p>&copy; <?= date('Y'); ?> Ricktorious Limited. Built on the adaptive commerce kernel with extensible APIs for catalogue, cart, checkout, and fulfilment.</p>
    </div>
</footer>

<div class="toast" id="app-toast" role="status" aria-live="polite" hidden></div>

<script defer src="assets/app.js?v=<?= htmlspecialchars($assetVersion, ENT_QUOTES) ?>"></script>
</body>
</html>
