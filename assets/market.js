(function () {
    const state = {
        watchlist: Array.isArray(window.SignalLedger?.watchlist) ? window.SignalLedger.watchlist : ['AAPL', 'MSFT', 'NVDA'],
        refreshHandle: null,
        searchDebounce: null,
    };

    const els = {
        root: document.querySelector('main[data-page="market"]'),
        averageChange: document.querySelector('[data-dashboard="average-change"]'),
        averageSentiment: document.querySelector('[data-dashboard="average-sentiment"]'),
        volatility: document.querySelector('[data-dashboard="volatility"]'),
        generated: document.querySelector('[data-dashboard="generated"]'),
        movers: document.querySelector('[data-dashboard="movers"]'),
        bullish: document.querySelector('[data-dashboard="bullish"]'),
        bearish: document.querySelector('[data-dashboard="bearish"]'),
        headline: document.querySelector('[data-dashboard="headline"]'),
        headlineSymbol: document.querySelector('[data-headline-symbol]'),
        headlineMeta: document.querySelector('[data-headline-meta]'),
        headlineTitle: document.querySelector('[data-headline-title]'),
        headlineSummary: document.querySelector('[data-headline-summary]'),
        headlineAction: document.querySelector('[data-headline-action]'),
        searchForm: document.querySelector('[data-role="global-search-form"]'),
        searchInput: document.querySelector('[data-role="global-search-input"]'),
        searchSuggestions: document.querySelector('[data-role="global-suggestions"]'),
        watchlist: document.querySelector('[data-role="watchlist"]'),
    };

    function formatPercent(value) {
        const number = Number(value);
        if (!Number.isFinite(number)) {
            return '—';
        }
        const fixed = Math.abs(number).toFixed(2);
        return `${number >= 0 ? '+' : '−'}${fixed}%`;
    }

    function formatCurrency(value) {
        const number = Number(value);
        if (!Number.isFinite(number)) {
            return '—';
        }
        return number.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function classifyChange(value) {
        const number = Number(value);
        if (!Number.isFinite(number)) {
            return 'change-flat';
        }
        if (number > 0) {
            return 'change-up';
        }
        if (number < 0) {
            return 'change-down';
        }
        return 'change-flat';
    }

    function labelForSentiment(slug) {
        switch (slug) {
            case 'very_bullish':
                return 'Very bullish';
            case 'bullish':
                return 'Bullish';
            case 'somewhat_bullish':
                return 'Somewhat bullish';
            case 'bearish':
                return 'Bearish';
            case 'very_bearish':
                return 'Very bearish';
            case 'somewhat_bearish':
                return 'Somewhat bearish';
            default:
                return 'Neutral';
        }
    }

    function relativeTime(iso) {
        if (!iso) {
            return '';
        }
        const date = new Date(iso);
        if (Number.isNaN(date.getTime())) {
            return '';
        }
        const diff = Math.max(0, Date.now() - date.getTime());
        const seconds = Math.round(diff / 1000);
        if (seconds < 60) {
            return 'just now';
        }
        if (seconds < 3600) {
            const minutes = Math.floor(seconds / 60);
            return `${minutes} minute${minutes === 1 ? '' : 's'} ago`;
        }
        if (seconds < 86400) {
            const hours = Math.floor(seconds / 3600);
            return `${hours} hour${hours === 1 ? '' : 's'} ago`;
        }
        const days = Math.floor(seconds / 86400);
        return `${days} day${days === 1 ? '' : 's'} ago`;
    }

    function renderOverview(payload) {
        const overview = payload.overview ?? {};
        if (els.averageChange) {
            els.averageChange.textContent = formatPercent(overview.average_change_percent ?? 0);
            els.averageChange.classList.remove('change-up', 'change-down', 'change-flat');
            els.averageChange.classList.add(classifyChange(overview.average_change_percent ?? 0));
        }
        if (els.averageSentiment) {
            const sentiment = Number(overview.average_sentiment ?? 0);
            els.averageSentiment.textContent = sentiment.toFixed(2);
            els.averageSentiment.classList.remove('change-up', 'change-down', 'change-flat');
            els.averageSentiment.classList.add(classifyChange(sentiment));
        }
        if (els.volatility) {
            const vol = Number(overview.volatility ?? 0);
            els.volatility.textContent = Number.isFinite(vol) ? `${vol.toFixed(1)}%` : '—';
        }
        if (els.generated) {
            els.generated.textContent = `Updated ${relativeTime(payload.generated_at)} · ${overview.bullish_count ?? 0} bullish · ${overview.bearish_count ?? 0} bearish · ${overview.neutral_count ?? 0} neutral`;
        }
    }

    function renderMovers(payload) {
        if (!els.movers) {
            return;
        }
        const entries = Array.isArray(payload.leaders?.movers) ? payload.leaders.movers : [];
        els.movers.innerHTML = '';
        if (entries.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'empty-state';
            empty.innerHTML = '<p>No movers detected.</p>';
            els.movers.appendChild(empty);
            return;
        }

        entries.slice(0, 6).forEach((entry) => {
            const card = document.createElement('article');
            card.className = 'watch-card';

            const header = document.createElement('header');
            const title = document.createElement('h3');
            title.textContent = entry.symbol ?? '';
            const subtitle = document.createElement('p');
            subtitle.className = 'muted';
            subtitle.textContent = entry.name ?? '';
            header.append(title, subtitle);

            const price = document.createElement('p');
            price.className = 'watch-price';
            price.textContent = formatCurrency(entry.price ?? 0);

            const change = document.createElement('p');
            change.className = `watch-change ${classifyChange(entry.change_percent)}`;
            change.textContent = formatPercent(entry.change_percent ?? 0);

            const sentiment = document.createElement('span');
            sentiment.className = 'badge';
            sentiment.textContent = labelForSentiment(entry.sentiment_label);

            card.append(header, price, change, sentiment);
            card.addEventListener('click', () => {
                window.location.href = `/company.php?symbol=${encodeURIComponent(entry.symbol ?? '')}`;
            });

            els.movers.appendChild(card);
        });
    }

    function renderSentimentList(target, entries) {
        if (!target) {
            return;
        }
        target.innerHTML = '';
        if (!Array.isArray(entries) || entries.length === 0) {
            const li = document.createElement('li');
            li.className = 'muted';
            li.textContent = 'Awaiting data…';
            target.appendChild(li);
            return;
        }

        entries.slice(0, 5).forEach((entry) => {
            const li = document.createElement('li');
            const symbol = document.createElement('strong');
            symbol.textContent = entry.symbol ?? '';
            const score = document.createElement('span');
            score.textContent = entry.sentiment_score != null ? entry.sentiment_score.toFixed(2) : '0.00';
            li.append(symbol, score);
            li.addEventListener('click', () => {
                window.location.href = `/company.php?symbol=${encodeURIComponent(entry.symbol ?? '')}`;
            });
            target.appendChild(li);
        });
    }

    function renderHeadline(payload) {
        if (!els.headline) {
            return;
        }
        const headline = payload.overview?.headline ?? null;
        if (!headline) {
            els.headline.classList.add('is-empty');
            if (els.headlineMeta) {
                els.headlineMeta.textContent = 'No high-signal headline selected yet.';
            }
            return;
        }

        els.headline.classList.remove('is-empty');
        if (els.headlineSymbol) {
            els.headlineSymbol.textContent = headline.symbol ?? '—';
            els.headlineSymbol.classList.remove('change-up', 'change-down', 'change-flat');
            els.headlineSymbol.classList.add(classifyChange(headline.sentiment_score ?? 0));
        }
        if (els.headlineMeta) {
            const metaParts = [];
            if (headline.source) {
                metaParts.push(headline.source);
            }
            if (headline.published_at) {
                metaParts.push(relativeTime(headline.published_at));
            }
            els.headlineMeta.textContent = metaParts.join(' · ');
        }
        if (els.headlineTitle) {
            els.headlineTitle.textContent = headline.title ?? 'Live coverage highlight';
        }
        if (els.headlineSummary) {
            els.headlineSummary.textContent = headline.summary ?? 'Stay on site with our curated digest.';
        }
        if (els.headlineAction) {
            const symbol = headline.symbol ?? '';
            els.headlineAction.href = `/company.php?symbol=${encodeURIComponent(symbol)}`;
        }
    }

    function applyDashboard(payload) {
        renderOverview(payload);
        renderMovers(payload);
        renderSentimentList(els.bullish, payload.leaders?.bullish);
        renderSentimentList(els.bearish, payload.leaders?.bearish);
        renderHeadline(payload);
    }

    async function fetchDashboard() {
        try {
            const params = state.watchlist && state.watchlist.length > 0
                ? `?symbols=${encodeURIComponent(state.watchlist.join(','))}`
                : '';
            const response = await fetch(`/api/market-dashboard.php${params}`, {
                method: 'GET',
                cache: 'no-store',
            });
            if (!response.ok) {
                throw new Error(`Request failed with status ${response.status}`);
            }
            const payload = await response.json();
            applyDashboard(payload);
        } catch (error) {
            console.error('Unable to refresh dashboard', error);
            if (els.generated) {
                els.generated.textContent = 'Unable to refresh dashboard right now. Retrying soon…';
            }
        } finally {
            scheduleRefresh();
        }
    }

    function scheduleRefresh() {
        if (state.refreshHandle) {
            clearTimeout(state.refreshHandle);
        }
        state.refreshHandle = window.setTimeout(fetchDashboard, 180000);
    }

    async function searchCompanies(query) {
        const trimmed = query.trim();
        if (trimmed.length < 2) {
            hideSuggestions();
            return;
        }
        try {
            const response = await fetch(`/api/company-search.php?q=${encodeURIComponent(trimmed)}&limit=8`, {
                method: 'GET',
                cache: 'force-cache',
            });
            if (!response.ok) {
                throw new Error('Search failed');
            }
            const payload = await response.json();
            renderSuggestions(payload.results ?? []);
        } catch (error) {
            hideSuggestions();
        }
    }

    function renderSuggestions(results) {
        if (!els.searchSuggestions) {
            return;
        }
        els.searchSuggestions.innerHTML = '';
        if (!Array.isArray(results) || results.length === 0) {
            els.searchSuggestions.hidden = true;
            return;
        }
        results.forEach((item) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'suggestion';
            button.dataset.symbol = item.symbol ?? '';
            button.innerHTML = `<strong>${item.symbol ?? ''}</strong><span>${item.name ?? ''}</span>`;
            button.addEventListener('click', () => {
                window.location.href = `/company.php?symbol=${encodeURIComponent(item.symbol ?? '')}`;
            });
            els.searchSuggestions.appendChild(button);
        });
        els.searchSuggestions.hidden = false;
    }

    function hideSuggestions() {
        if (els.searchSuggestions) {
            els.searchSuggestions.hidden = true;
            els.searchSuggestions.innerHTML = '';
        }
    }

    function onSearchInput(event) {
        const value = event.currentTarget.value;
        if (state.searchDebounce) {
            clearTimeout(state.searchDebounce);
        }
        state.searchDebounce = window.setTimeout(() => searchCompanies(value), 200);
    }

    function onSearchSubmit(event) {
        if (!els.searchInput) {
            return;
        }
        const value = els.searchInput.value.trim();
        if (value === '') {
            event.preventDefault();
            return;
        }
        // allow form submission to redirect to company page
    }

    function bindWatchlist() {
        if (!els.watchlist) {
            return;
        }
        els.watchlist.querySelectorAll('button[data-symbol]').forEach((button) => {
            button.addEventListener('click', () => {
                const symbol = button.dataset.symbol ?? '';
                window.location.href = `/company.php?symbol=${encodeURIComponent(symbol)}`;
            });
        });
    }

    function populateWatchlist() {
        if (!els.watchlist || !state.watchlist || state.watchlist.length === 0) {
            return;
        }
        if (els.watchlist.children.length === 0) {
            state.watchlist.forEach((symbol) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'pill';
                button.dataset.symbol = symbol;
                button.textContent = symbol;
                els.watchlist.appendChild(button);
            });
        }
        bindWatchlist();
    }

    function bootstrap() {
        if (!els.root) {
            return;
        }
        if (els.searchInput) {
            els.searchInput.addEventListener('input', onSearchInput);
            els.searchInput.addEventListener('focus', () => {
                if (els.searchInput && els.searchInput.value.trim().length >= 2) {
                    searchCompanies(els.searchInput.value);
                }
            });
        }
        if (els.searchForm) {
            els.searchForm.addEventListener('submit', onSearchSubmit);
        }
        if (els.searchSuggestions) {
            document.addEventListener('click', (event) => {
                if (!els.searchSuggestions.contains(event.target) && event.target !== els.searchInput) {
                    hideSuggestions();
                }
            });
        }
        populateWatchlist();
        fetchDashboard();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootstrap);
    } else {
        bootstrap();
    }
})();
