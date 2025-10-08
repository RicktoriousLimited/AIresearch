const API_BASE = '/ricktorious.php';
const endpoints = {
  products: `${API_BASE}/api/catalog/products`,
  cartSummary: `${API_BASE}/api/cart/summary`,
  cartAdd: `${API_BASE}/api/cart/add`,
  cartUpdate: `${API_BASE}/api/cart/update`,
  cartRemove: `${API_BASE}/api/cart/remove`,
  cartClear: `${API_BASE}/api/cart/clear`,
  checkout: `${API_BASE}/api/checkout`,
  insights: `${API_BASE}/api/insights`,
  analytics: `${API_BASE}/api/analytics/events`
};

const state = {
  products: [],
  filters: {
    search: '',
    tag: 'all'
  },
  cart: {
    items: [],
    total: 0,
    formatted_total: '$0.00',
    item_count: 0
  }
};

const els = {
  productGrid: document.getElementById('product-grid'),
  emptyState: document.getElementById('empty-state'),
  searchInput: document.getElementById('search-input'),
  tagFilter: document.getElementById('tag-filter'),
  resetFilters: document.getElementById('reset-filters'),
  cartButton: document.getElementById('cart-button'),
  cartOverlay: document.getElementById('cart-overlay'),
  cartBody: document.getElementById('cart-body'),
  cartTotal: document.getElementById('cart-total'),
  cartCount: document.getElementById('cart-count'),
  cartClose: document.getElementById('cart-close'),
  cartClear: document.getElementById('cart-clear'),
  cartCheckout: document.getElementById('cart-checkout'),
  checkoutForm: document.getElementById('checkout-form'),
  checkoutMessages: document.getElementById('checkout-messages'),
  toast: document.getElementById('app-toast'),
  insightEvents: document.getElementById('insight-events'),
  insightBlocks: document.getElementById('insight-blocks')
};

let toastTimeout;

function showToast(message, tone = 'info') {
  if (!els.toast) {
    return;
  }

  els.toast.textContent = message;
  els.toast.dataset.tone = tone;
  els.toast.hidden = false;
  clearTimeout(toastTimeout);
  toastTimeout = window.setTimeout(() => {
    els.toast.hidden = true;
  }, 3600);
}

async function request(url, options = {}) {
  const opts = {
    headers: {
      'Accept': 'application/json'
    },
    ...options
  };

  if (opts.body && typeof opts.body !== 'string') {
    opts.headers['Content-Type'] = 'application/json';
    opts.body = JSON.stringify(opts.body);
  }

  const response = await fetch(url, opts);
  let data = null;

  if (response.status !== 204) {
    try {
      data = await response.json();
    } catch (error) {
      data = null;
    }
  }

  if (!response.ok) {
    const message = data && typeof data.error === 'string' ? data.error : 'Request failed';
    throw new Error(message);
  }

  return data;
}

function productMatchesFilters(product) {
  const search = state.filters.search.trim().toLowerCase();
  const tag = state.filters.tag;
  const name = (product.name || '').toLowerCase();
  const description = (product.description || '').toLowerCase();
  const tags = Array.isArray(product.tags) ? product.tags : [];

  const matchesSearch = search === '' || name.includes(search) || description.includes(search);
  const matchesTag = tag === 'all' || tags.includes(tag);

  return matchesSearch && matchesTag;
}

function formatCurrency(value, currency = '$') {
  const amount = Number.isFinite(value) ? Number(value) : 0;
  return `${currency}${amount.toFixed(2)}`;
}

function buildProductCard(product) {
  const image = Array.isArray(product.images) && product.images.length > 0
    ? product.images[0]
    : 'https://picsum.photos/seed/ricktorious-default/600/600';

  const tags = Array.isArray(product.tags) ? product.tags : [];
  const tagHtml = tags.map(tag => `<span class="pill">${escapeHtml(tag)}</span>`).join('');

  return `
    <article class="product-card">
      <div class="product-media" style="background-image: url('${escapeHtml(image)}');"></div>
      <div class="product-body">
        <h3>${escapeHtml(product.name || 'Untitled product')}</h3>
        <p class="product-description">${escapeHtml(product.description || '')}</p>
        <div class="product-meta">
          <span class="price">${escapeHtml(product.formatted_price || formatCurrency(product.price, product.currency))}</span>
          <div class="product-tags">${tagHtml}</div>
        </div>
        <button type="button" class="button primary add-to-cart" data-product="${escapeHtml(product.id)}">Add to cart</button>
      </div>
    </article>
  `;
}

function escapeHtml(value) {
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

function renderProducts() {
  if (!els.productGrid || !els.emptyState) {
    return;
  }

  const filtered = state.products.filter(productMatchesFilters);

  if (filtered.length === 0) {
    els.productGrid.innerHTML = '';
    els.emptyState.hidden = false;
    return;
  }

  els.emptyState.hidden = true;
  els.productGrid.innerHTML = filtered.map(buildProductCard).join('');

  els.productGrid.querySelectorAll('.add-to-cart').forEach(button => {
    button.addEventListener('click', async (event) => {
      const target = event.currentTarget;
      const productId = target.dataset.product;
      if (!productId) {
        return;
      }

      target.disabled = true;
      try {
        await addToCart(productId);
        showToast('Added to cart');
      } catch (error) {
        showToast(error.message, 'error');
      } finally {
        target.disabled = false;
      }
    });
  });
}

function updateTagFilterOptions() {
  if (!els.tagFilter) {
    return;
  }

  const tags = new Set();
  state.products.forEach(product => {
    if (Array.isArray(product.tags)) {
      product.tags.forEach(tag => tags.add(tag));
    }
  });

  const current = els.tagFilter.value;
  els.tagFilter.innerHTML = '<option value="all">All categories</option>' +
    Array.from(tags).sort().map(tag => `<option value="${escapeHtml(tag)}">${escapeHtml(tag)}</option>`).join('');

  if (Array.from(tags).includes(current)) {
    els.tagFilter.value = current;
  } else {
    els.tagFilter.value = 'all';
    state.filters.tag = 'all';
  }
}

function renderCart() {
  if (!els.cartBody || !els.cartTotal || !els.cartClear) {
    return;
  }

  const summary = state.cart;
  if (!summary || !Array.isArray(summary.items) || summary.items.length === 0) {
    els.cartBody.innerHTML = '<p class="empty">Your cart is currently empty.</p>';
    els.cartTotal.textContent = formatCurrency(0);
    els.cartClear.disabled = true;
  } else {
    const lines = summary.items.map(item => {
      const product = item.product || {};
      const quantity = Number(item.quantity) || 0;
      const productId = product.id || '';
      const currency = product.currency || '$';
      const price = product.formatted_price || formatCurrency(product.price, currency);
      const lineTotal = item.formatted_line_total || formatCurrency(item.line_total, currency);
      const image = Array.isArray(product.images) && product.images.length > 0 ? product.images[0] : 'https://picsum.photos/seed/ricktorious-default/200/200';

      return `
        <article class="cart-line" data-product="${escapeHtml(productId)}">
          <div class="cart-media" style="background-image: url('${escapeHtml(image)}');"></div>
          <div class="cart-info">
            <h3>${escapeHtml(product.name || 'Product')}</h3>
            <p class="muted">${escapeHtml(product.description || '')}</p>
            <p class="price">${escapeHtml(price)}</p>
          </div>
          <div class="cart-controls">
            <label>
              <span class="sr-only">Quantity</span>
              <input type="number" class="cart-quantity" min="0" value="${quantity}" data-product="${escapeHtml(productId)}">
            </label>
            <span class="line-total">${escapeHtml(lineTotal)}</span>
            <button type="button" class="icon-button cart-remove" data-product="${escapeHtml(productId)}" aria-label="Remove ${escapeHtml(product.name || 'product')} from cart">&times;</button>
          </div>
        </article>
      `;
    }).join('');

    els.cartBody.innerHTML = lines;
    els.cartTotal.textContent = summary.formatted_total || formatCurrency(summary.total || 0);
    els.cartClear.disabled = false;

    els.cartBody.querySelectorAll('.cart-quantity').forEach(input => {
      input.addEventListener('change', async (event) => {
        const target = event.currentTarget;
        const productId = target.dataset.product;
        const quantity = Math.max(0, Number(target.value) || 0);
        if (!productId) {
          return;
        }

        target.disabled = true;
        try {
          await updateCart([{ product: productId, quantity }]);
          showToast('Cart updated');
        } catch (error) {
          showToast(error.message, 'error');
        } finally {
          target.disabled = false;
        }
      });
    });

    els.cartBody.querySelectorAll('.cart-remove').forEach(button => {
      button.addEventListener('click', async (event) => {
        const productId = event.currentTarget.dataset.product;
        if (!productId) {
          return;
        }

        try {
          await removeFromCart(productId);
          showToast('Item removed');
        } catch (error) {
          showToast(error.message, 'error');
        }
      });
    });
  }

  const count = summary && Number.isFinite(summary.item_count) ? summary.item_count : 0;
  if (els.cartCount) {
    els.cartCount.textContent = String(count);
  }
}

async function addToCart(productId) {
  const data = await request(endpoints.cartAdd, {
    method: 'POST',
    body: {
      product: productId,
      quantity: 1
    }
  });

  if (data && data.summary) {
    state.cart = data.summary;
  }
  await refreshCart();
  await refreshInsights();
}

async function updateCart(items) {
  const summary = await request(endpoints.cartUpdate, {
    method: 'POST',
    body: { items }
  });
  state.cart = summary || state.cart;
  renderCart();
  await refreshInsights();
}

async function removeFromCart(productId) {
  const summary = await request(endpoints.cartRemove, {
    method: 'POST',
    body: { product: productId }
  });
  state.cart = summary || state.cart;
  renderCart();
  await refreshInsights();
}

async function clearCart() {
  const summary = await request(endpoints.cartClear, { method: 'POST' });
  state.cart = summary || state.cart;
  renderCart();
  await refreshInsights();
}

async function refreshCart() {
  const summary = await request(endpoints.cartSummary);
  if (summary) {
    state.cart = summary;
  }
  renderCart();
}

function toggleCart(open) {
  if (!els.cartOverlay) {
    return;
  }

  if (open) {
    els.cartOverlay.classList.add('open');
    els.cartOverlay.setAttribute('aria-hidden', 'false');
    if (els.cartButton) {
      els.cartButton.setAttribute('aria-expanded', 'true');
    }
  } else {
    els.cartOverlay.classList.remove('open');
    els.cartOverlay.setAttribute('aria-hidden', 'true');
    if (els.cartButton) {
      els.cartButton.setAttribute('aria-expanded', 'false');
    }
  }
}

function bindCartControls() {
  if (els.cartButton) {
    els.cartButton.addEventListener('click', () => {
      toggleCart(!els.cartOverlay?.classList.contains('open'));
    });
  }

  if (els.cartClose) {
    els.cartClose.addEventListener('click', () => toggleCart(false));
  }

  if (els.cartOverlay) {
    els.cartOverlay.addEventListener('click', (event) => {
      if (event.target === els.cartOverlay) {
        toggleCart(false);
      }
    });
  }

  if (els.cartClear) {
    els.cartClear.addEventListener('click', async () => {
      try {
        await clearCart();
        showToast('Cart cleared');
      } catch (error) {
        showToast(error.message, 'error');
      }
    });
  }

  if (els.cartCheckout) {
    els.cartCheckout.addEventListener('click', () => toggleCart(false));
  }
}

function bindFilters() {
  if (els.searchInput) {
    els.searchInput.addEventListener('input', (event) => {
      state.filters.search = event.currentTarget.value || '';
      renderProducts();
    });
  }

  if (els.tagFilter) {
    els.tagFilter.addEventListener('change', (event) => {
      state.filters.tag = event.currentTarget.value || 'all';
      renderProducts();
    });
  }

  if (els.resetFilters) {
    els.resetFilters.addEventListener('click', () => {
      state.filters.search = '';
      state.filters.tag = 'all';
      if (els.searchInput) {
        els.searchInput.value = '';
      }
      if (els.tagFilter) {
        els.tagFilter.value = 'all';
      }
      renderProducts();
    });
  }
}

async function submitCheckout(event) {
  event.preventDefault();
  if (!els.checkoutForm) {
    return;
  }

  const formData = new FormData(els.checkoutForm);
  const payload = {
    name: formData.get('name') || '',
    email: formData.get('email') || '',
    address: formData.get('address') || ''
  };

  try {
    const order = await request(endpoints.checkout, {
      method: 'POST',
      body: payload
    });

    if (els.checkoutMessages) {
      els.checkoutMessages.innerHTML = `<div class="message success">Order <strong>${escapeHtml(order.id || '')}</strong> confirmed. We have emailed ${escapeHtml(order.customer?.email || payload.email)}.</div>`;
      els.checkoutMessages.hidden = false;
    }

    els.checkoutForm.reset();
    await refreshCart();
    await refreshInsights();
    showToast('Checkout complete');
  } catch (error) {
    if (els.checkoutMessages) {
      els.checkoutMessages.innerHTML = `<div class="message error">${escapeHtml(error.message)}</div>`;
      els.checkoutMessages.hidden = false;
    }
    showToast(error.message, 'error');
  }
}

async function fetchProducts() {
  try {
    const data = await request(endpoints.products);
    state.products = Array.isArray(data?.products) ? data.products : [];
    updateTagFilterOptions();
    renderProducts();
    await recordEvent('catalog.view', {
      block: 'storefront.catalog',
      total_products: state.products.length
    });
  } catch (error) {
    showToast(error.message, 'error');
  }
}

async function refreshInsights() {
  try {
    const data = await request(endpoints.insights);
    if (els.insightEvents) {
      els.insightEvents.textContent = String(data?.total_events ?? 0);
    }

    if (els.insightBlocks) {
      const popularity = data?.block_popularity || {};
      const entries = Object.entries(popularity);
      if (entries.length === 0) {
        els.insightBlocks.innerHTML = '<li class="muted">Interact with the storefront to surface block insights.</li>';
      } else {
        els.insightBlocks.innerHTML = entries
          .map(([block, count]) => `<li><span class="pill">${escapeHtml(block)}</span> ${Number(count)} events</li>`)
          .join('');
      }
    }
  } catch (error) {
    showToast(error.message, 'error');
  }
}

async function recordEvent(eventName, payload = {}) {
  try {
    await request(endpoints.analytics, {
      method: 'POST',
      body: {
        event: eventName,
        payload
      }
    });
  } catch (error) {
    // Silent fail to avoid interrupting UX
  }
}

async function init() {
  bindCartControls();
  bindFilters();

  if (els.checkoutForm) {
    els.checkoutForm.addEventListener('submit', submitCheckout);
  }

  await Promise.all([
    fetchProducts(),
    refreshCart(),
    refreshInsights(),
    recordEvent('page.view', {
      block: 'storefront.home',
      path: window.location.pathname + window.location.hash
    })
  ]);
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', init);
} else {
  init();
}
