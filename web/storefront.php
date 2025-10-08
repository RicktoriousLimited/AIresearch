<?php
$assetVersion = (string) (file_exists(__DIR__ . '/assets/styles.css') ? filemtime(__DIR__ . '/assets/styles.css') : time());
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nova Market &mdash; Modern Lifestyle Store</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/web/assets/styles.css?v=<?= htmlspecialchars($assetVersion, ENT_QUOTES) ?>">
</head>
<body>
    <div class="page">
        <header class="top-bar">
            <div class="brand">Nova Market</div>
            <nav class="main-nav" aria-label="Primary">
                <a href="#collections">Collections</a>
                <a href="#products">Products</a>
                <a href="#stories">Stories</a>
                <a href="#newsletter">Subscribe</a>
            </nav>
            <div class="top-actions">
                <button class="icon-button" id="search-toggle" aria-label="Open search" aria-expanded="false">
                    <span class="icon icon-search"></span>
                </button>
                <button class="icon-button" id="cart-toggle" aria-label="Open shopping cart" aria-expanded="false">
                    <span class="icon icon-cart"></span>
                    <span class="cart-count" id="cart-count">0</span>
                </button>
                <button class="icon-button" id="theme-toggle" aria-label="Toggle theme">
                    <span class="icon icon-sun"></span>
                </button>
                <button class="icon-button mobile-menu" id="mobile-menu" aria-label="Toggle menu" aria-expanded="false">
                    <span class="icon icon-menu"></span>
                </button>
            </div>
        </header>

        <div class="search-panel" id="search-panel" role="dialog" aria-modal="true" aria-hidden="true">
            <div class="search-content">
                <label for="global-search" class="sr-only">Search for products</label>
                <input id="global-search" type="search" placeholder="Search for anything from sneakers to smart homes..." autocomplete="off">
                <button class="icon-button" id="search-close" aria-label="Close search">
                    <span class="icon icon-close"></span>
                </button>
            </div>
        </div>

        <main>
            <section class="hero" id="collections">
                <div class="hero-content">
                    <p class="eyebrow">SS24 Capsule</p>
                    <h1>Design-led essentials crafted for modern living.</h1>
                    <p class="lead">Shop limited-run drops from emerging designers, sustainable staples from world-class brands, and smart tech that elevates every space.</p>
                    <div class="hero-actions">
                        <a class="button primary" href="#products">Shop the collection</a>
                        <a class="button secondary" href="#stories">Explore stories</a>
                    </div>
                    <dl class="hero-stats">
                        <div>
                            <dt>Premium labels</dt>
                            <dd>150+</dd>
                        </div>
                        <div>
                            <dt>Community reviews</dt>
                            <dd>12k+</dd>
                        </div>
                        <div>
                            <dt>Planet positive</dt>
                            <dd>82% sustainable</dd>
                        </div>
                    </dl>
                </div>
                <div class="hero-showcase">
                    <div class="showcase-card footwear">
                        <h3>Footwear</h3>
                        <p>Future-proof sneakers engineered for daily performance.</p>
                    </div>
                    <div class="showcase-card apparel">
                        <h3>Apparel</h3>
                        <p>Modular layers built with recycled technical fabrics.</p>
                    </div>
                    <div class="showcase-card smart-home">
                        <h3>Smart Living</h3>
                        <p>Connected devices that adapt to your routines.</p>
                    </div>
                </div>
            </section>

            <section class="filters" aria-label="Product filters">
                <div class="filter-tabs" role="tablist">
                    <button class="tab-button active" data-category="all" role="tab" aria-selected="true">All</button>
                    <button class="tab-button" data-category="footwear" role="tab" aria-selected="false">Footwear</button>
                    <button class="tab-button" data-category="apparel" role="tab" aria-selected="false">Apparel</button>
                    <button class="tab-button" data-category="accessories" role="tab" aria-selected="false">Accessories</button>
                    <button class="tab-button" data-category="smart-living" role="tab" aria-selected="false">Smart living</button>
                </div>
                <div class="filter-controls">
                    <label class="control search-control">
                        <span class="sr-only">Search products</span>
                        <input type="search" id="inline-search" placeholder="Search products" autocomplete="off">
                        <span class="icon icon-search"></span>
                    </label>
                    <label class="control">
                        <span class="control-label">Sort</span>
                        <select id="sort-select">
                            <option value="featured">Featured</option>
                            <option value="newest">Newest</option>
                            <option value="price-asc">Price: Low to High</option>
                            <option value="price-desc">Price: High to Low</option>
                            <option value="rating">Top Rated</option>
                        </select>
                    </label>
                    <label class="control">
                        <span class="control-label">Price</span>
                        <input type="range" id="price-range" min="80" max="600" value="600">
                        <span class="range-value" id="price-value">Up to $600+</span>
                    </label>
                </div>
            </section>

            <section class="product-grid" id="products" aria-live="polite">
                <div class="grid" id="product-grid"></div>
                <div class="empty-state" id="empty-state" hidden>
                    <div class="empty-icon"></div>
                    <h2>No products found</h2>
                    <p>Try adjusting the filters or explore another category.</p>
                    <button class="button secondary" id="reset-filters">Reset filters</button>
                </div>
            </section>

            <section class="collections" id="stories">
                <article class="collection-card">
                    <div class="collection-media collection-lounge"></div>
                    <div class="collection-body">
                        <p class="eyebrow">Editorial</p>
                        <h2>The lounge remix</h2>
                        <p>Slow down in reimagined loungewear sets and adaptive lighting that transforms any living room into a sanctuary.</p>
                        <a href="#" class="link">Read the story</a>
                    </div>
                </article>
                <article class="collection-card reverse">
                    <div class="collection-media collection-commute"></div>
                    <div class="collection-body">
                        <p class="eyebrow">Spotlight</p>
                        <h2>City commute essentials</h2>
                        <p>Weatherproof jackets, antimicrobial knits, and the smartest carry solutions engineered to move with you.</p>
                        <a href="#" class="link">Explore the curation</a>
                    </div>
                </article>
            </section>

            <section class="highlights" aria-label="Store highlights">
                <article>
                    <span class="icon icon-delivery"></span>
                    <h3>Carbon-neutral delivery</h3>
                    <p>Fast worldwide shipping with offset emissions and recyclable packaging by default.</p>
                </article>
                <article>
                    <span class="icon icon-shield"></span>
                    <h3>Secure payments</h3>
                    <p>Checkout with Apple Pay, Google Pay, major cards, and split payments with 3D Secure.</p>
                </article>
                <article>
                    <span class="icon icon-support"></span>
                    <h3>Concierge support</h3>
                    <p>Dedicated stylists and product specialists ready to assist 7 days a week.</p>
                </article>
            </section>

            <section class="testimonials">
                <header>
                    <h2>Loved by creators &amp; founders</h2>
                    <p>Our community shares how Nova Market elevates their routines.</p>
                </header>
                <div class="testimonial-grid">
                    <article>
                        <p>“Everything feels curated with intention. The product detail pages are like having a stylist and engineer co-sign every purchase.”</p>
                        <footer>
                            <strong>Jamie Patel</strong>
                            <span>Founder, Studio Eleven</span>
                        </footer>
                    </article>
                    <article>
                        <p>“Nova’s smart home lineup is the only place I’ve found devices that blend into my space while working flawlessly together.”</p>
                        <footer>
                            <strong>Maya Cortez</strong>
                            <span>Architect &amp; content creator</span>
                        </footer>
                    </article>
                    <article>
                        <p>“From responsive support to sustainable sourcing, Nova Market sets the benchmark for modern retail.”</p>
                        <footer>
                            <strong>Luke Anders</strong>
                            <span>Creative Director</span>
                        </footer>
                    </article>
                </div>
            </section>

            <section class="newsletter" id="newsletter">
                <div class="newsletter-card">
                    <div class="newsletter-body">
                        <p class="eyebrow">Stay in the loop</p>
                        <h2>Access private drops, invites, and insider pricing.</h2>
                        <p>Subscribe to be the first to shop limited collaborations and receive insights from our design partners.</p>
                        <form class="newsletter-form" id="newsletter-form" novalidate>
                            <label class="sr-only" for="newsletter-email">Email</label>
                            <input id="newsletter-email" type="email" name="email" placeholder="you@example.com" required>
                            <button type="submit" class="button primary">Join the list</button>
                            <p class="form-feedback" id="newsletter-feedback" role="status" aria-live="polite"></p>
                        </form>
                    </div>
                    <div class="newsletter-media"></div>
                </div>
            </section>
        </main>

        <footer class="footer">
            <div>
                <strong>Nova Market</strong>
                <p>Design-focused retail for the modern era.</p>
            </div>
            <div>
                <h3>Support</h3>
                <ul>
                    <li><a href="#">Help center</a></li>
                    <li><a href="#">Shipping &amp; returns</a></li>
                    <li><a href="#">Order tracking</a></li>
                    <li><a href="#">Accessibility</a></li>
                </ul>
            </div>
            <div>
                <h3>Company</h3>
                <ul>
                    <li><a href="#">About</a></li>
                    <li><a href="#">Careers</a></li>
                    <li><a href="#">Press</a></li>
                    <li><a href="#">Affiliate</a></li>
                </ul>
            </div>
            <div>
                <h3>Follow</h3>
                <ul class="social-links">
                    <li><a href="#">Instagram</a></li>
                    <li><a href="#">TikTok</a></li>
                    <li><a href="#">Pinterest</a></li>
                    <li><a href="#">YouTube</a></li>
                </ul>
            </div>
        </footer>
    </div>

    <aside class="cart-panel" id="cart-panel" aria-hidden="true">
        <div class="cart-header">
            <h2>Your bag</h2>
            <button class="icon-button" id="cart-close" aria-label="Close cart">
                <span class="icon icon-close"></span>
            </button>
        </div>
        <div class="cart-body">
            <ul class="cart-items" id="cart-items"></ul>
            <div class="cart-empty" id="cart-empty">
                <div class="empty-icon"></div>
                <h3>Your bag is empty</h3>
                <p>Add some statement pieces to see them here.</p>
            </div>
        </div>
        <div class="cart-footer">
            <div class="totals">
                <div>
                    <span>Subtotal</span>
                    <strong id="cart-subtotal">$0.00</strong>
                </div>
                <div>
                    <span>Shipping</span>
                    <strong>Calculated at checkout</strong>
                </div>
            </div>
            <button class="button primary" id="checkout-button">Secure checkout</button>
            <p class="cart-note">Checkout is secured by end-to-end encryption and supports express payment providers.</p>
        </div>
    </aside>

    <div class="modal" id="product-modal" role="dialog" aria-modal="true" aria-hidden="true">
        <div class="modal-backdrop" id="modal-backdrop"></div>
        <div class="modal-dialog" role="document">
            <button class="icon-button modal-close" id="modal-close" aria-label="Close product details">
                <span class="icon icon-close"></span>
            </button>
            <div class="modal-gallery">
                <img src="" alt="" id="modal-image">
                <div class="thumbnail-row" id="modal-thumbnails"></div>
            </div>
            <div class="modal-body">
                <h2 id="modal-title"></h2>
                <p class="modal-price" id="modal-price"></p>
                <p id="modal-description"></p>
                <div class="modal-options">
                    <div>
                        <h3>Color</h3>
                        <div class="chip-group" id="modal-colors"></div>
                    </div>
                    <div>
                        <h3>Size</h3>
                        <div class="chip-group" id="modal-sizes"></div>
                    </div>
                </div>
                <button class="button primary" id="modal-add">Add to bag</button>
            </div>
        </div>
    </div>

    <script type="module" src="/web/assets/app.js?v=<?= htmlspecialchars($assetVersion, ENT_QUOTES) ?>"></script>
</body>
</html>
