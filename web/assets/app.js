const products = [
  {
    id: 'aurora-knit-sneaker',
    name: 'Aurora Knit Sneaker',
    category: 'footwear',
    price: 188,
    rating: 4.8,
    reviews: 184,
    description: 'Seamless knit sneaker with adaptive cushioning and algae-based foam outsole.',
    longDescription:
      'Engineered for urban exploration with breathable knit uppers, algae-based midsoles, and reflective detailing for late night commutes.',
    badges: ['New', 'Bestseller'],
    popularity: 98,
    releaseDate: '2024-03-22',
    colors: [
      { name: 'Glacier', value: '#dce4f7' },
      { name: 'Nebula', value: '#1f2937' },
      { name: 'Copper', value: '#b45309' }
    ],
    sizes: ['US 6', 'US 7', 'US 8', 'US 9', 'US 10', 'US 11'],
    media: [
      'https://images.unsplash.com/photo-1542291026-7eec264c27ff?auto=format&fit=crop&w=900&q=80',
      'https://images.unsplash.com/photo-1521572267360-ee0c2909d518?auto=format&fit=crop&w=900&q=80',
      'https://images.unsplash.com/photo-1517070208541-6ddc4d3efbcb?auto=format&fit=crop&w=900&q=80'
    ]
  },
  {
    id: 'solace-trench',
    name: 'Solace Technical Trench',
    category: 'apparel',
    price: 248,
    rating: 4.7,
    reviews: 92,
    description: 'Weatherproof trench crafted with recycled nylon and heat-sealed seams.',
    longDescription:
      'A breathable three-layer shell with detachable hood, magnetic closures, and reflective piping for seamless day-to-night transitions.',
    badges: ['Limited'],
    popularity: 87,
    releaseDate: '2024-01-10',
    colors: [
      { name: 'Storm', value: '#1f2937' },
      { name: 'Sandstone', value: '#d6b98c' }
    ],
    sizes: ['XS', 'S', 'M', 'L', 'XL'],
    media: [
      'https://images.unsplash.com/photo-1521572163474-6864f9cf17ab?auto=format&fit=crop&w=900&q=80',
      'https://images.unsplash.com/photo-1507679799987-c73779587ccf?auto=format&fit=crop&w=900&q=80',
      'https://images.unsplash.com/photo-1490481651871-ab68de25d43d?auto=format&fit=crop&w=900&q=80'
    ]
  },
  {
    id: 'flux-backpack',
    name: 'Flux Modular Backpack',
    category: 'accessories',
    price: 198,
    rating: 4.9,
    reviews: 241,
    description: 'Magnetic modular compartments with wireless charging dock and RFID shielding.',
    longDescription:
      'Customize storage with swappable modules, fast access laptop sleeve, and integrated wireless charger powered by solar top panel.',
    badges: ['Top Rated'],
    popularity: 95,
    releaseDate: '2023-11-15',
    colors: [
      { name: 'Graphite', value: '#111827' },
      { name: 'Slate', value: '#374151' }
    ],
    sizes: ['18L', '24L'],
    media: [
      'https://images.unsplash.com/photo-1521572163474-6864f9cf17ab?auto=format&fit=crop&w=900&q=80',
      'https://images.unsplash.com/photo-1531259683007-016a7b628fc3?auto=format&fit=crop&w=900&q=80',
      'https://images.unsplash.com/photo-1529338296731-c4280a44fc47?auto=format&fit=crop&w=900&q=80'
    ]
  },
  {
    id: 'lumina-lamp',
    name: 'Lumina Adaptive Lamp',
    category: 'smart-living',
    price: 162,
    rating: 4.6,
    reviews: 146,
    description: 'Smart lighting that tracks circadian rhythms and syncs with ambient audio.',
    longDescription:
      'Tuneable LED array with biometrics integration, voice control, and automatic brightness mapping across 360° rotation.',
    badges: ['Smart Home'],
    popularity: 82,
    releaseDate: '2023-09-05',
    colors: [
      { name: 'Polar', value: '#f3f4f6' },
      { name: 'Obsidian', value: '#0f172a' }
    ],
    sizes: ['Standard'],
    media: [
      'https://images.unsplash.com/photo-1582719478250-c89cae4dc85b?auto=format&fit=crop&w=900&q=80',
      'https://images.unsplash.com/photo-1524758631624-e2822e304c36?auto=format&fit=crop&w=900&q=80',
      'https://images.unsplash.com/photo-1493663284031-b7e3aefcae8e?auto=format&fit=crop&w=900&q=80'
    ]
  },
  {
    id: 'zenith-coat',
    name: 'Zenith Merino Coat',
    category: 'apparel',
    price: 320,
    rating: 4.9,
    reviews: 68,
    description: 'Double-faced merino with thermoregulating lining and invisible pockets.',
    longDescription:
      'Hand finished merino panels with tonal taping, convertible collar, and heat-mapped interior quilting for maximal comfort.',
    badges: ['Editors’ Pick'],
    popularity: 76,
    releaseDate: '2023-12-01',
    colors: [
      { name: 'Charcoal', value: '#111827' },
      { name: 'Oat', value: '#d6d3ce' }
    ],
    sizes: ['XS', 'S', 'M', 'L', 'XL'],
    media: [
      'https://images.unsplash.com/photo-1521572163474-6864f9cf17ab?auto=format&fit=crop&w=900&q=80',
      'https://images.unsplash.com/photo-1521676259650-675b5bfec8e1?auto=format&fit=crop&w=900&q=80',
      'https://images.unsplash.com/photo-1542293787938-4d2226e676d3?auto=format&fit=crop&w=900&q=80'
    ]
  },
  {
    id: 'pulse-earbuds',
    name: 'Pulse Noise-Cancelling Earbuds',
    category: 'accessories',
    price: 248,
    rating: 4.7,
    reviews: 302,
    description: 'Hi-res audio with adaptive transparency mode and wireless Qi charging case.',
    longDescription:
      'Carbon composite drivers tuned by award-winning engineers, wind reduction microphones, and 32-hour battery life.',
    badges: ['Limited', 'Smart Audio'],
    popularity: 91,
    releaseDate: '2024-02-18',
    colors: [
      { name: 'Matte Black', value: '#111827' },
      { name: 'Moonstone', value: '#d1d5db' }
    ],
    sizes: ['Universal'],
    media: [
      'https://images.unsplash.com/photo-1516116216624-53e697fedbea?auto=format&fit=crop&w=900&q=80',
      'https://images.unsplash.com/photo-1519205080495-3d97d1e28d90?auto=format&fit=crop&w=900&q=80',
      'https://images.unsplash.com/photo-1524678606370-a47ad25cb82a?auto=format&fit=crop&w=900&q=80'
    ]
  },
  {
    id: 'haven-throw',
    name: 'Haven Cashmere Throw',
    category: 'smart-living',
    price: 148,
    rating: 4.5,
    reviews: 77,
    description: 'Traceable cashmere throw infused with temperature balancing minerals.',
    longDescription:
      'Sustainably sourced fibers woven with phase-change minerals to keep you cool or cozy, plus stain resistant nano-coating.',
    badges: ['Sustainable'],
    popularity: 70,
    releaseDate: '2023-10-09',
    colors: [
      { name: 'Mist', value: '#e5e7eb' },
      { name: 'Sea', value: '#0ea5e9' }
    ],
    sizes: ['50" × 70"'],
    media: [
      'https://images.unsplash.com/photo-1524758631624-e2822e304c36?auto=format&fit=crop&w=900&q=80',
      'https://images.unsplash.com/photo-1521590832167-7bcbfaa6381f?auto=format&fit=crop&w=900&q=80',
      'https://images.unsplash.com/photo-1530023367847-a683933f4177?auto=format&fit=crop&w=900&q=80'
    ]
  },
  {
    id: 'atlas-desk',
    name: 'Atlas Sit-Stand Desk',
    category: 'smart-living',
    price: 540,
    rating: 4.8,
    reviews: 119,
    description: 'Smart desk with biometric presets, cable passthrough, and wireless charging surface.',
    longDescription:
      'Solid ash desktop with whisper-quiet motors, dual-zone wireless chargers, and health analytics that sync to the Nova app.',
    badges: ['Pro Studio'],
    popularity: 89,
    releaseDate: '2023-08-21',
    colors: [
      { name: 'Warm Ash', value: '#cbb69b' },
      { name: 'Onyx', value: '#1f2937' }
    ],
    sizes: ['48"', '60"', '72"'],
    media: [
      'https://images.unsplash.com/photo-1527430253228-e93688616381?auto=format&fit=crop&w=900&q=80',
      'https://images.unsplash.com/photo-1487017159836-4e23ece2e4cf?auto=format&fit=crop&w=900&q=80',
      'https://images.unsplash.com/photo-1545239351-1141bd82e8a6?auto=format&fit=crop&w=900&q=80'
    ]
  },
  {
    id: 'stride-runner',
    name: 'Stride Runner V2',
    category: 'footwear',
    price: 168,
    rating: 4.4,
    reviews: 214,
    description: 'Dual-density cushioning with energy return plate and reflective panelling.',
    longDescription:
      'Performance-forward trainer built with recycled mesh, water repellent nano-coating, and energy return composite plate.',
    badges: ['Run Club'],
    popularity: 74,
    releaseDate: '2023-11-30',
    colors: [
      { name: 'Signal', value: '#dc2626' },
      { name: 'Frost', value: '#cbd5f5' }
    ],
    sizes: ['US 6', 'US 7', 'US 8', 'US 9', 'US 10', 'US 11', 'US 12'],
    media: [
      'https://images.unsplash.com/photo-1483721310020-03333e577078?auto=format&fit=crop&w=900&q=80',
      'https://images.unsplash.com/photo-1511556820780-d912e42b4980?auto=format&fit=crop&w=900&q=80',
      'https://images.unsplash.com/photo-1487956382158-bb926046304a?auto=format&fit=crop&w=900&q=80'
    ]
  },
  {
    id: 'equilibrium-mat',
    name: 'Equilibrium Performance Mat',
    category: 'accessories',
    price: 128,
    rating: 4.6,
    reviews: 64,
    description: 'Grippy bio-based mat with antimicrobial finish and laser alignment guides.',
    longDescription:
      'Crafted with FSC-certified natural rubber, closed-cell top layer, and dual alignment systems for mindful movement.',
    badges: ['Wellness'],
    popularity: 66,
    releaseDate: '2024-02-02',
    colors: [
      { name: 'Eucalyptus', value: '#6ee7b7' },
      { name: 'Night', value: '#0f172a' }
    ],
    sizes: ['Standard'],
    media: [
      'https://images.unsplash.com/photo-1548697143-8f0a5f1c9c55?auto=format&fit=crop&w=900&q=80',
      'https://images.unsplash.com/photo-1517964603305-11c0f1f6c63c?auto=format&fit=crop&w=900&q=80',
      'https://images.unsplash.com/photo-1526401281623-359d82f9a5a0?auto=format&fit=crop&w=900&q=80'
    ]
  }
];

const state = {
  category: 'all',
  search: '',
  sort: 'featured',
  maxPrice: 600,
  cart: [],
  theme: 'light'
};

const grid = document.getElementById('product-grid');
const emptyState = document.getElementById('empty-state');
const priceRange = document.getElementById('price-range');
const priceValue = document.getElementById('price-value');
const sortSelect = document.getElementById('sort-select');
const tabButtons = Array.from(document.querySelectorAll('.tab-button'));
const searchInputs = [
  document.getElementById('inline-search'),
  document.getElementById('global-search')
].filter(Boolean);
const cartToggle = document.getElementById('cart-toggle');
const cartPanel = document.getElementById('cart-panel');
const cartClose = document.getElementById('cart-close');
const cartItems = document.getElementById('cart-items');
const cartCount = document.getElementById('cart-count');
const cartSubtotal = document.getElementById('cart-subtotal');
const cartEmpty = document.getElementById('cart-empty');
const resetFilters = document.getElementById('reset-filters');
const checkoutButton = document.getElementById('checkout-button');
const themeToggle = document.getElementById('theme-toggle');
const searchToggle = document.getElementById('search-toggle');
const searchPanel = document.getElementById('search-panel');
const searchClose = document.getElementById('search-close');
const mobileMenuToggle = document.getElementById('mobile-menu');
const mainNav = document.querySelector('.main-nav');
const newsletterForm = document.getElementById('newsletter-form');
const newsletterFeedback = document.getElementById('newsletter-feedback');
const modal = document.getElementById('product-modal');
const modalBackdrop = document.getElementById('modal-backdrop');
const modalClose = document.getElementById('modal-close');
const modalImage = document.getElementById('modal-image');
const modalThumbnails = document.getElementById('modal-thumbnails');
const modalTitle = document.getElementById('modal-title');
const modalPrice = document.getElementById('modal-price');
const modalDescription = document.getElementById('modal-description');
const modalColors = document.getElementById('modal-colors');
const modalSizes = document.getElementById('modal-sizes');
const modalAdd = document.getElementById('modal-add');

let modalState = {
  productId: null,
  color: null,
  size: null
};

function loadPersistedState() {
  try {
    const storedCart = localStorage.getItem('nova-cart');
    if (storedCart) {
      state.cart = JSON.parse(storedCart);
    }
  } catch (error) {
    console.warn('Unable to load cart from storage', error);
  }

  try {
    const storedTheme = localStorage.getItem('nova-theme');
    if (storedTheme === 'dark') {
      state.theme = 'dark';
      document.body.classList.add('dark');
    }
  } catch (error) {
    console.warn('Unable to load theme from storage', error);
  }

  updateThemeIcon();
}

function persistCart() {
  try {
    localStorage.setItem('nova-cart', JSON.stringify(state.cart));
  } catch (error) {
    console.warn('Unable to persist cart', error);
  }
}

function persistTheme() {
  try {
    localStorage.setItem('nova-theme', state.theme);
  } catch (error) {
    console.warn('Unable to persist theme', error);
  }
}

function formatPrice(value) {
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD'
  }).format(value);
}

function renderProducts() {
  grid.innerHTML = '';
  const filtered = products
    .filter((product) => {
      const matchesCategory = state.category === 'all' || product.category === state.category;
      const matchesSearch = state.search === ''
        || product.name.toLowerCase().includes(state.search)
        || product.description.toLowerCase().includes(state.search);
      const matchesPrice = product.price <= state.maxPrice;
      return matchesCategory && matchesSearch && matchesPrice;
    })
    .sort((a, b) => sortProducts(a, b));

  if (filtered.length === 0) {
    emptyState.hidden = false;
    grid.style.display = 'none';
    return;
  }

  emptyState.hidden = true;
  grid.style.display = '';

  for (const product of filtered) {
    grid.appendChild(createProductCard(product));
  }
}

function sortProducts(a, b) {
  switch (state.sort) {
    case 'newest':
      return new Date(b.releaseDate) - new Date(a.releaseDate);
    case 'price-asc':
      return a.price - b.price;
    case 'price-desc':
      return b.price - a.price;
    case 'rating':
      return b.rating - a.rating;
    default:
      return b.popularity - a.popularity;
  }
}

function createProductCard(product) {
  const card = document.createElement('article');
  card.className = 'product-card';

  const badgeMarkup = product.badges
    .map((badge) => `<span class="badge">${badge}</span>`)
    .join('');

  card.innerHTML = `
    <div class="product-media">
      <img src="${product.media[0]}" alt="${product.name}">
      <div class="product-badges">${badgeMarkup}</div>
    </div>
    <div class="product-body">
      <h3>${product.name}</h3>
      <p>${product.description}</p>
      <div class="product-meta">
        <span class="price">${formatPrice(product.price)}</span>
        <span class="rating">
          <span class="rating-stars">${renderStars(product.rating)}</span>
          <span>${product.rating.toFixed(1)}</span>
        </span>
      </div>
      <div class="card-footer">
        <button class="button primary add-to-cart" type="button">Add to bag</button>
        <button class="quick-view" type="button">Quick view</button>
      </div>
    </div>
  `;

  card.querySelector('.add-to-cart')?.addEventListener('click', () => {
    const color = product.colors[0]?.name ?? 'Default';
    const size = product.sizes[0] ?? 'One size';
    addToCart(product, { color, size });
    openCart();
  });

  const quickView = card.querySelector('.quick-view');
  if (quickView) {
    quickView.addEventListener('click', () => openModal(product.id));
  }

  card.querySelector('.product-media img')?.addEventListener('click', () => openModal(product.id));

  return card;
}

function renderStars(value) {
  const rounded = Math.round(value);
  return Array.from({ length: 5 })
    .map((_, index) => `<span class="star ${index < rounded ? 'filled' : ''}"></span>`)
    .join('');
}

function addToCart(product, options) {
  const key = `${product.id}:${options.color}:${options.size}`;
  const existing = state.cart.find((item) => item.key === key);

  if (existing) {
    existing.quantity += 1;
  } else {
    state.cart.push({
      key,
      id: product.id,
      name: product.name,
      price: product.price,
      color: options.color,
      size: options.size,
      image: product.media[0],
      quantity: 1
    });
  }

  renderCart();
  persistCart();
}

function removeFromCart(key) {
  state.cart = state.cart.filter((item) => item.key !== key);
  renderCart();
  persistCart();
}

function updateCartQuantity(key, delta) {
  const item = state.cart.find((entry) => entry.key === key);
  if (!item) return;

  item.quantity += delta;
  if (item.quantity <= 0) {
    removeFromCart(key);
  } else {
    renderCart();
    persistCart();
  }
}

function renderCart() {
  cartItems.innerHTML = '';
  if (state.cart.length === 0) {
    cartPanel.classList.add('empty');
    cartEmpty.hidden = false;
  } else {
    cartPanel.classList.remove('empty');
    cartEmpty.hidden = true;
  }

  let subtotal = 0;
  let totalCount = 0;

  for (const item of state.cart) {
    subtotal += item.price * item.quantity;
    totalCount += item.quantity;
    cartItems.appendChild(createCartItem(item));
  }

  cartSubtotal.textContent = formatPrice(subtotal);
  cartCount.textContent = String(totalCount);
}

function createCartItem(item) {
  const li = document.createElement('li');
  li.className = 'cart-item';
  li.innerHTML = `
    <img src="${item.image}" alt="${item.name}">
    <div>
      <h4>${item.name}</h4>
      <p>${item.color} · ${item.size}</p>
      <div class="cart-meta">
        <div class="quantity-control" role="group" aria-label="Quantity">
          <button type="button" aria-label="Decrease quantity">−</button>
          <span>${item.quantity}</span>
          <button type="button" aria-label="Increase quantity">+</button>
        </div>
        <strong>${formatPrice(item.price * item.quantity)}</strong>
      </div>
      <button class="quick-view" type="button">Remove</button>
    </div>
  `;

  const [decrease, , increase] = li.querySelectorAll('button');
  decrease.addEventListener('click', () => updateCartQuantity(item.key, -1));
  increase.addEventListener('click', () => updateCartQuantity(item.key, 1));

  const remove = li.querySelector('.quick-view');
  remove?.addEventListener('click', () => removeFromCart(item.key));

  return li;
}

function openCart() {
  cartPanel.classList.add('active');
  cartToggle?.setAttribute('aria-expanded', 'true');
  cartPanel.setAttribute('aria-hidden', 'false');
}

function closeCart() {
  cartPanel.classList.remove('active');
  cartToggle?.setAttribute('aria-expanded', 'false');
  cartPanel.setAttribute('aria-hidden', 'true');
}

function openSearch() {
  searchPanel?.classList.add('active');
  searchPanel?.setAttribute('aria-hidden', 'false');
  const globalSearch = document.getElementById('global-search');
  globalSearch?.focus();
}

function closeSearch() {
  searchPanel?.classList.remove('active');
  searchPanel?.setAttribute('aria-hidden', 'true');
}

function updateThemeIcon() {
  const icon = themeToggle?.querySelector('.icon');
  if (!icon) return;
  icon.classList.remove('icon-sun', 'icon-moon');
  icon.classList.add(state.theme === 'dark' ? 'icon-moon' : 'icon-sun');
}

function toggleTheme() {
  state.theme = state.theme === 'dark' ? 'light' : 'dark';
  document.body.classList.toggle('dark', state.theme === 'dark');
  updateThemeIcon();
  persistTheme();
}

function syncSearchInputs(value) {
  for (const input of searchInputs) {
    if (document.activeElement !== input) {
      input.value = value;
    }
  }
}

function setupFilters() {
  tabButtons.forEach((button) => {
    button.addEventListener('click', () => {
      tabButtons.forEach((btn) => {
        btn.classList.remove('active');
        btn.setAttribute('aria-selected', 'false');
      });
      button.classList.add('active');
      button.setAttribute('aria-selected', 'true');
      state.category = button.dataset.category ?? 'all';
      renderProducts();
    });
  });

  searchInputs.forEach((input) => {
    input.addEventListener('input', (event) => {
      const value = event.target.value.trim().toLowerCase();
      state.search = value;
      syncSearchInputs(event.target.value);
      renderProducts();
    });
  });

  sortSelect?.addEventListener('change', (event) => {
    state.sort = event.target.value;
    renderProducts();
  });

  priceRange?.addEventListener('input', (event) => {
    const value = Number(event.target.value);
    const ceiling = Number(priceRange.max ?? 600);
    state.maxPrice = value;
    priceValue.textContent = value >= ceiling ? `Up to ${formatPrice(ceiling)}+` : `Up to ${formatPrice(value)}`;
    renderProducts();
  });

  resetFilters?.addEventListener('click', () => {
    state.category = 'all';
    state.search = '';
    state.sort = 'featured';
    state.maxPrice = Number(priceRange?.max ?? 600);
    tabButtons.forEach((btn) => {
      btn.classList.toggle('active', btn.dataset.category === 'all');
      btn.setAttribute('aria-selected', btn.dataset.category === 'all' ? 'true' : 'false');
    });
    syncSearchInputs('');
    if (sortSelect) sortSelect.value = 'featured';
    if (priceRange) priceRange.value = priceRange.max ?? 600;
    const ceiling = Number(priceRange?.max ?? 600);
    priceValue.textContent = `Up to ${formatPrice(ceiling)}+`;
    renderProducts();
  });
}

function setupCart() {
  cartToggle?.addEventListener('click', () => {
    const expanded = cartPanel.classList.toggle('active');
    cartToggle.setAttribute('aria-expanded', String(expanded));
    cartPanel.setAttribute('aria-hidden', expanded ? 'false' : 'true');
  });

  cartClose?.addEventListener('click', () => closeCart());

  checkoutButton?.addEventListener('click', () => {
    if (state.cart.length === 0) {
      alert('Add items to your bag before checking out.');
      return;
    }
    alert('Secure checkout is coming soon. Your cart is saved for later!');
  });
}

function setupSearchPanel() {
  searchToggle?.addEventListener('click', () => {
    const isOpen = searchPanel?.classList.contains('active');
    if (isOpen) {
      closeSearch();
    } else {
      openSearch();
    }
  });

  searchClose?.addEventListener('click', () => closeSearch());
  searchPanel?.addEventListener('click', (event) => {
    if (event.target === searchPanel) {
      closeSearch();
    }
  });
}

function setupMobileNav() {
  mobileMenuToggle?.addEventListener('click', () => {
    const isOpen = mainNav?.classList.toggle('open');
    mobileMenuToggle.setAttribute('aria-expanded', String(Boolean(isOpen)));
  });

  mainNav?.querySelectorAll('a').forEach((link) => {
    link.addEventListener('click', () => {
      mainNav.classList.remove('open');
      mobileMenuToggle?.setAttribute('aria-expanded', 'false');
    });
  });
}

function setupNewsletter() {
  newsletterForm?.addEventListener('submit', (event) => {
    event.preventDefault();
    const emailField = event.target.elements.email;
    const email = emailField?.value.trim();
    if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      newsletterFeedback.textContent = 'Enter a valid email to join the list.';
      newsletterFeedback.style.color = '#f97316';
      return;
    }

    newsletterFeedback.textContent = 'You\'re in! Expect a welcome note shortly.';
    newsletterFeedback.style.color = 'var(--accent-strong)';
    event.target.reset();
  });
}

function openModal(productId) {
  const product = products.find((item) => item.id === productId);
  if (!product) return;

  modalState = {
    productId: product.id,
    color: product.colors[0]?.name ?? null,
    size: product.sizes[0] ?? null
  };

  modalTitle.textContent = product.name;
  modalPrice.textContent = formatPrice(product.price);
  modalDescription.textContent = product.longDescription;
  updateModalGallery(product.media);
  updateModalOptions(modalColors, product.colors, modalState.color, (color) => {
    modalState.color = color;
  });
  updateModalOptions(modalSizes, product.sizes, modalState.size, (size) => {
    modalState.size = size;
  });

  modal.classList.add('active');
  modal.setAttribute('aria-hidden', 'false');
}

function closeModal() {
  modal.classList.remove('active');
  modal.setAttribute('aria-hidden', 'true');
}

function updateModalGallery(images) {
  modalImage.src = images[0];
  modalImage.alt = 'Product preview';
  modalThumbnails.innerHTML = '';

  images.forEach((src, index) => {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = `thumbnail${index === 0 ? ' active' : ''}`;
    button.innerHTML = `<img src="${src}" alt="Thumbnail ${index + 1}">`;
    button.addEventListener('click', () => {
      modalImage.src = src;
      modalThumbnails.querySelectorAll('.thumbnail').forEach((thumb) => thumb.classList.remove('active'));
      button.classList.add('active');
    });
    modalThumbnails.appendChild(button);
  });
}

function updateModalOptions(container, options, active, onSelect) {
  container.innerHTML = '';
  if (!options || options.length === 0) {
    container.textContent = 'No options';
    return;
  }

  options.forEach((option) => {
    const label = typeof option === 'string' ? option : option.name;
    const chip = document.createElement('button');
    chip.type = 'button';
    chip.className = `chip${label === active ? ' active' : ''}`;
    chip.textContent = label;
    if (typeof option === 'object' && option.value) {
      chip.style.setProperty('--chip-color', option.value);
    }
    chip.addEventListener('click', () => {
      container.querySelectorAll('.chip').forEach((element) => element.classList.remove('active'));
      chip.classList.add('active');
      onSelect(label);
    });
    container.appendChild(chip);
  });
}

modalAdd?.addEventListener('click', () => {
  const product = products.find((item) => item.id === modalState.productId);
  if (!product) return;
  addToCart(product, {
    color: modalState.color ?? product.colors[0]?.name ?? 'Default',
    size: modalState.size ?? product.sizes[0] ?? 'One size'
  });
  closeModal();
  openCart();
});

[modalClose, modalBackdrop].forEach((element) => {
  element?.addEventListener('click', () => closeModal());
});

window.addEventListener('keydown', (event) => {
  if (event.key === 'Escape') {
    closeModal();
    closeCart();
    closeSearch();
  }
});

themeToggle?.addEventListener('click', () => toggleTheme());

loadPersistedState();
renderCart();
renderProducts();
setupFilters();
setupCart();
setupSearchPanel();
setupMobileNav();
setupNewsletter();

const ceiling = Number(priceRange?.max ?? 600);
priceValue.textContent = `Up to ${formatPrice(ceiling)}+`;

