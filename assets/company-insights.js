(function () {
    const state = {
        symbol: null,
        loading: false,
        priceChart: null,
        sentimentChart: null,
        debounceTimer: null,
        popular: Array.isArray(window.SignalLedger?.popularTickers) ? window.SignalLedger.popularTickers : [],
    };

    const els = {
        root: document.querySelector('main[data-page="company"]'),
        name: document.querySelector('[data-company-name]'),
        tagline: document.querySelector('[data-company-tagline]'),
        price: document.querySelector('[data-field="price"]'),
        change: document.querySelector('[data-field="change"]'),
        volume: document.querySelector('[data-field="volume"]'),
        updated: document.querySelector('[data-field="updated"]'),
        snapshotMeta: document.querySelector('[data-field="snapshot-meta"]'),
        dayRange: document.querySelector('[data-field="day-range"]'),
        yearRange: document.querySelector('[data-field="year-range"]'),
        return1m: document.querySelector('[data-field="return-1m"]'),
        return6m: document.querySelector('[data-field="return-6m"]'),
        return1wDetail: document.querySelector('[data-field="return-1w"]'),
        return1mDetail: document.querySelector('[data-field="return-1m-detail"]'),
        return3mDetail: document.querySelector('[data-field="return-3m"]'),
        return6mDetail: document.querySelector('[data-field="return-6m-detail"]'),
        sentimentScore: document.querySelector('[data-field="sentiment-score"]'),
        sentimentLabel: document.querySelector('[data-field="sentiment-label"]'),
        volatility: document.querySelector('[data-field="volatility"]'),
        priceRangeLabel: document.querySelector('[data-field="price-range-label"]'),
        priceSummary: document.querySelector('[data-field="price-summary"]'),
        sentimentTrendLabel: document.querySelector('[data-field="sentiment-trend-label"]'),
        sentimentSummary: document.querySelector('[data-field="sentiment-summary"]'),
        insightPoints: document.querySelector('[data-field="insight-points"]'),
        topicList: document.querySelector('[data-field="topic-list"]'),
        newsList: document.querySelector('[data-field="news-list"]'),
        newsMeta: document.querySelector('[data-field="news-meta"]'),
        searchForm: document.querySelector('[data-role="company-search-form"]'),
        searchInput: document.querySelector('[data-role="company-search-input"]'),
        suggestions: document.querySelector('[data-role="company-suggestions"]'),
        popular: document.querySelector('[data-role="popular-tickers"]'),
    };

    const charts = {
        price: document.getElementById('price-chart'),
        sentiment: document.getElementById('sentiment-chart'),
    };

    function formatNumber(value) {
        const number = Number(value);
        if (!Number.isFinite(number)) {
            return '—';
        }

        return number.toLocaleString(undefined, { maximumFractionDigits: 0 });
    }

    function formatCurrency(value, currency = 'USD') {
        const number = Number(value);
        if (!Number.isFinite(number)) {
            return '—';
        }

        return new Intl.NumberFormat(undefined, {
            style: 'currency',
            currency,
            maximumFractionDigits: number >= 100 ? 2 : 4,
        }).format(number);
    }

    function formatPercent(value) {
        const number = Number(value);
        if (!Number.isFinite(number)) {
            return '—';
        }

        return `${number >= 0 ? '+' : '−'}${Math.abs(number).toFixed(2)}%`;
    }

    function formatSentiment(value) {
        const number = Number(value);
        if (!Number.isFinite(number)) {
            return '0.00';
        }

        return number.toFixed(2);
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

        const now = new Date();
        const diff = Math.max(0, now.getTime() - date.getTime());
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

    function setLoading(isLoading) {
        state.loading = isLoading;
        if (els.root) {
            els.root.setAttribute('data-loading', isLoading ? 'true' : 'false');
        }
    }

    function renderHero(data) {
        if (!data) {
            return;
        }

        const company = data.company ?? {};
        const quote = data.quote ?? {};

        if (els.name) {
            els.name.textContent = company.name || data.symbol || 'Unknown company';
        }

        const parts = [];
        if (company.symbol) {
            parts.push(company.symbol);
        }
        if (company.exchange) {
            parts.push(company.exchange);
        }
        if (company.sector || company.industry) {
            parts.push([company.sector, company.industry].filter(Boolean).join(' / '));
        }
        const tagline = parts.join(' · ');
        if (els.tagline) {
            els.tagline.textContent = tagline || 'Live market overview';
        }

        if (els.price) {
            els.price.textContent = formatCurrency(quote.price, quote.currency || 'USD');
        }

        if (els.change) {
            els.change.textContent = `${formatCurrency(quote.change, quote.currency || 'USD')} (${formatPercent(quote.change_percent)})`;
            els.change.classList.remove('change-up', 'change-down', 'change-flat');
            els.change.classList.add(classifyChange(quote.change_percent));
        }

        if (els.volume) {
            els.volume.textContent = `${formatNumber(quote.volume)} shares`;
        }

        if (els.updated) {
            els.updated.textContent = `Last updated ${relativeTime(quote.as_of) || 'recently'}`;
        }

        if (document.title) {
            document.title = `${company.name || data.symbol} · Signal Ledger`; 
        }
    }

    function renderSnapshot(data) {
        const quote = data.quote ?? {};
        const insights = data.insights ?? {};
        const sentiment = data.sentiment ?? {};

        if (els.snapshotMeta) {
            els.snapshotMeta.textContent = `As of ${relativeTime(quote.as_of) || 'just now'}`;
        }

        if (els.dayRange) {
            if (quote.day_low != null && quote.day_high != null) {
                els.dayRange.textContent = `${formatCurrency(quote.day_low, quote.currency)} – ${formatCurrency(quote.day_high, quote.currency)}`;
            } else {
                els.dayRange.textContent = '—';
            }
        }

        if (els.yearRange) {
            if (quote.fifty_two_week_low != null && quote.fifty_two_week_high != null) {
                els.yearRange.textContent = `${formatCurrency(quote.fifty_two_week_low, quote.currency)} – ${formatCurrency(quote.fifty_two_week_high, quote.currency)}`;
            } else {
                els.yearRange.textContent = '—';
            }
        }

        if (els.return1m) {
            els.return1m.textContent = formatPercent(insights.returns?.one_month ?? 0);
        }

        if (els.return6m) {
            els.return6m.textContent = formatPercent(insights.returns?.six_month ?? 0);
        }

        if (els.return1wDetail) {
            els.return1wDetail.textContent = formatPercent(insights.returns?.one_week ?? 0);
        }
        if (els.return1mDetail) {
            els.return1mDetail.textContent = formatPercent(insights.returns?.one_month ?? 0);
        }
        if (els.return3mDetail) {
            els.return3mDetail.textContent = formatPercent(insights.returns?.three_month ?? 0);
        }
        if (els.return6mDetail) {
            els.return6mDetail.textContent = formatPercent(insights.returns?.six_month ?? 0);
        }

        if (els.sentimentScore) {
            els.sentimentScore.textContent = formatSentiment(sentiment.average_score ?? 0);
        }
        if (els.sentimentLabel) {
            els.sentimentLabel.textContent = labelForSentiment(sentiment.label);
            els.sentimentLabel.classList.remove('change-up', 'change-down', 'change-flat');
            els.sentimentLabel.classList.add(classifyChange(sentiment.average_score ?? 0));
        }
        if (els.volatility) {
            const vol = Number(insights.volatility ?? 0);
            els.volatility.textContent = Number.isFinite(vol) ? `${vol.toFixed(1)}%` : '—';
        }
    }

    function renderInsights(data) {
        const insights = data.insights ?? {};
        const sentiment = data.sentiment ?? {};

        if (els.insightPoints) {
            els.insightPoints.innerHTML = '';
            const points = Array.isArray(insights.key_points) && insights.key_points.length > 0
                ? insights.key_points
                : ['We are still collecting insights for this company.'];
            points.forEach((point) => {
                const li = document.createElement('li');
                li.textContent = point;
                els.insightPoints.appendChild(li);
            });
        }

        if (els.topicList) {
            els.topicList.innerHTML = '';
            const topics = Array.isArray(sentiment.topics) ? sentiment.topics : [];
            if (topics.length === 0) {
                const li = document.createElement('li');
                li.className = 'muted';
                li.textContent = 'No dominant themes detected yet.';
                els.topicList.appendChild(li);
            } else {
                topics.forEach((topic) => {
                    const li = document.createElement('li');
                    const name = topic.topic ?? '';
                    const mentions = Number(topic.mentions ?? 0);
                    li.innerHTML = `<strong>${name}</strong><span>${mentions} mention${mentions === 1 ? '' : 's'}</span>`;
                    els.topicList.appendChild(li);
                });
            }
        }
    }

    function renderPriceChart(data) {
        if (!charts.price || !window.Chart) {
            return;
        }

        const history = data.price_history ?? {};
        const points = Array.isArray(history.points) ? history.points : [];

        const labels = points.map((point) => {
            const time = point.time ?? '';
            const date = new Date(time);
            if (Number.isNaN(date.getTime())) {
                return '';
            }
            return new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric' }).format(date);
        });
        const values = points.map((point) => Number(point.close ?? 0));

        if (els.priceSummary) {
            if (values.length > 1) {
                const change = values[values.length - 1] - values[0];
                const percent = values[0] !== 0 ? (change / values[0]) * 100 : 0;
                els.priceSummary.textContent = `Moved ${formatPercent(percent)} across the selected range.`;
            } else {
                els.priceSummary.textContent = 'Not enough history to chart.';
            }
        }

        if (state.priceChart) {
            state.priceChart.data.labels = labels;
            state.priceChart.data.datasets[0].data = values;
            state.priceChart.update();
            return;
        }

        state.priceChart = new window.Chart(charts.price, {
            type: 'line',
            data: {
                labels,
                datasets: [
                    {
                        label: 'Close',
                        data: values,
                        borderColor: '#3A7AFE',
                        backgroundColor: 'rgba(58, 122, 254, 0.1)',
                        pointRadius: 0,
                        tension: 0.25,
                        fill: true,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                    },
                },
                scales: {
                    x: {
                        display: true,
                        ticks: { maxRotation: 0 },
                        grid: { display: false },
                    },
                    y: {
                        display: true,
                        grid: { color: 'rgba(58, 122, 254, 0.1)' },
                    },
                },
            },
        });
    }

    function renderSentimentChart(data) {
        if (!charts.sentiment || !window.Chart) {
            return;
        }

        const sentiment = data.sentiment ?? {};
        const timeline = Array.isArray(sentiment.timeline) ? sentiment.timeline : [];

        if (els.sentimentSummary) {
            if (timeline.length === 0) {
                els.sentimentSummary.textContent = 'No recent sentiment trend available.';
            } else {
                const latest = timeline[timeline.length - 1];
                const previous = timeline.length > 1 ? timeline[timeline.length - 2] : latest;
                const delta = (latest.score ?? 0) - (previous.score ?? 0);
                if (delta > 0.05) {
                    els.sentimentSummary.textContent = 'Coverage momentum turned more positive in the latest sessions.';
                } else if (delta < -0.05) {
                    els.sentimentSummary.textContent = 'Narrative momentum softened compared with the prior period.';
                } else {
                    els.sentimentSummary.textContent = 'Sentiment is broadly steady across recent coverage.';
                }
            }
        }

        const labels = timeline.map((entry) => entry.date ?? '');
        const scores = timeline.map((entry) => Number(entry.score ?? 0));

        if (state.sentimentChart) {
            state.sentimentChart.data.labels = labels;
            state.sentimentChart.data.datasets[0].data = scores;
            state.sentimentChart.update();
            return;
        }

        state.sentimentChart = new window.Chart(charts.sentiment, {
            type: 'line',
            data: {
                labels,
                datasets: [
                    {
                        label: 'Sentiment score',
                        data: scores,
                        borderColor: '#26C485',
                        backgroundColor: 'rgba(38, 196, 133, 0.16)',
                        pointRadius: 0,
                        tension: 0.25,
                        fill: true,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: { intersect: false },
                },
                scales: {
                    x: {
                        display: true,
                        grid: { display: false },
                    },
                    y: {
                        display: true,
                        suggestedMin: -1,
                        suggestedMax: 1,
                        grid: { color: 'rgba(38, 196, 133, 0.1)' },
                    },
                },
            },
        });
    }

    function renderNews(data) {
        if (!els.newsList) {
            return;
        }

        els.newsList.innerHTML = '';
        const articles = Array.isArray(data.sentiment?.articles) ? data.sentiment.articles.slice(0, 8) : [];

        if (articles.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'empty-state';
            empty.innerHTML = '<p>No recent articles cleared the relevance filter. Check back soon.</p>';
            els.newsList.appendChild(empty);
            if (els.newsMeta) {
                els.newsMeta.textContent = 'We could not find high confidence articles for this ticker.';
            }
            return;
        }

        if (els.newsMeta) {
            els.newsMeta.textContent = `Curated ${articles.length} high-signal articles with inline summaries.`;
        }

        articles.forEach((article) => {
            const card = document.createElement('article');
            card.className = 'news-card';

            const header = document.createElement('header');
            const badge = document.createElement('span');
            badge.className = `badge ${classifyChange(article.sentiment_score)}`;
            badge.textContent = labelForSentiment(article.sentiment_label);

            const meta = document.createElement('p');
            meta.className = 'news-source';
            const source = article.source || 'Unknown source';
            const published = article.published_at ? new Date(article.published_at) : null;
            const timeLabel = published && !Number.isNaN(published.getTime())
                ? new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }).format(published)
                : '';
            meta.textContent = timeLabel ? `${source} · ${timeLabel}` : source;

            const title = document.createElement('h3');
            title.textContent = article.title || 'Untitled article';

            header.append(badge, meta, title);

            const summary = document.createElement('p');
            summary.textContent = article.summary || 'No summary provided.';

            const footer = document.createElement('footer');
            if (article.url) {
                const link = document.createElement('a');
                link.href = article.url;
                link.target = '_blank';
                link.rel = 'noopener';
                link.className = 'button ghost';
                link.textContent = 'View original (new tab)';
                footer.appendChild(link);
            }

            card.append(header, summary, footer);
            els.newsList.appendChild(card);
        });
    }

    function applyData(data) {
        renderHero(data);
        renderSnapshot(data);
        renderInsights(data);
        renderPriceChart(data);
        renderSentimentChart(data);
        renderNews(data);
        setLoading(false);
    }

    async function fetchInsights(symbol) {
        const target = symbol?.trim();
        if (!target) {
            return;
        }

        setLoading(true);
        state.symbol = target;

        try {
            const response = await fetch(`/api/company-insights.php?symbol=${encodeURIComponent(target)}`, {
                method: 'GET',
                cache: 'no-store',
            });

            if (!response.ok) {
                throw new Error(`Request failed with status ${response.status}`);
            }

            const payload = await response.json();
            state.data = payload;
            applyData(payload);
            const url = new URL(window.location.href);
            url.searchParams.set('symbol', target);
            window.history.replaceState({}, '', url.toString());
        } catch (error) {
            console.error('Unable to load company insights', error);
            setLoading(false);
            if (els.insightPoints) {
                els.insightPoints.innerHTML = '<li>We hit an API limit while fetching insights. Please try again shortly.</li>';
            }
            if (els.newsList) {
                els.newsList.innerHTML = '<div class="empty-state"><p>Unable to fetch coverage at the moment.</p></div>';
            }
        }
    }

    async function searchCompanies(term, target) {
        const trimmed = term.trim();
        if (trimmed.length < 2) {
            hideSuggestions(target);
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
            renderSuggestions(payload.results ?? [], target);
        } catch (error) {
            hideSuggestions(target);
        }
    }

    function renderSuggestions(results, target) {
        const container = target || els.suggestions;
        if (!container) {
            return;
        }

        container.innerHTML = '';
        if (!Array.isArray(results) || results.length === 0) {
            container.hidden = true;
            return;
        }

        results.slice(0, 8).forEach((item) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'suggestion';
            button.dataset.symbol = item.symbol ?? '';
            button.innerHTML = `<strong>${item.symbol ?? ''}</strong><span>${item.name ?? ''}</span>`;
            button.addEventListener('click', () => {
                if (els.searchInput) {
                    els.searchInput.value = item.symbol ?? '';
                }
                hideSuggestions(container);
                fetchInsights(item.symbol ?? '');
            });
            container.appendChild(button);
        });

        container.hidden = false;
    }

    function hideSuggestions(target) {
        const container = target || els.suggestions;
        if (container) {
            container.hidden = true;
            container.innerHTML = '';
        }
    }

    function onSearchInput(event) {
        const value = event.currentTarget.value;
        if (state.debounceTimer) {
            clearTimeout(state.debounceTimer);
        }
        state.debounceTimer = window.setTimeout(() => {
            searchCompanies(value, els.suggestions);
        }, 200);
    }

    function onSearchSubmit(event) {
        event.preventDefault();
        const value = els.searchInput?.value ?? '';
        if (value.trim() === '') {
            return;
        }
        fetchInsights(value.trim());
    }

    function bindPopular() {
        if (!els.popular) {
            return;
        }

        els.popular.querySelectorAll('button[data-symbol]').forEach((button) => {
            button.addEventListener('click', () => {
                const symbol = button.dataset.symbol ?? '';
                if (els.searchInput) {
                    els.searchInput.value = symbol;
                }
                fetchInsights(symbol);
            });
        });
    }

    function initializePopularFromState() {
        if (!els.popular || !state.popular || state.popular.length === 0) {
            return;
        }

        if (els.popular.children.length === 0) {
            state.popular.slice(0, 6).forEach((symbol) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'pill';
                button.dataset.symbol = symbol;
                button.textContent = symbol;
                button.addEventListener('click', () => {
                    if (els.searchInput) {
                        els.searchInput.value = symbol;
                    }
                    fetchInsights(symbol);
                });
                els.popular.appendChild(button);
            });
        }
    }

    function bootstrap() {
        if (!els.root) {
            return;
        }

        const defaultSymbol = els.root.dataset.defaultSymbol || window.SignalLedger?.defaultSymbol || 'AAPL';

        if (els.searchInput) {
            els.searchInput.addEventListener('input', onSearchInput);
            els.searchInput.addEventListener('focus', (event) => {
                const target = event.currentTarget;
                if (target.value.trim().length >= 2) {
                    searchCompanies(target.value, els.suggestions);
                }
            });
        }

        if (els.searchForm) {
            els.searchForm.addEventListener('submit', onSearchSubmit);
        }

        if (els.suggestions) {
            document.addEventListener('click', (event) => {
                if (!els.suggestions.contains(event.target) && event.target !== els.searchInput) {
                    hideSuggestions(els.suggestions);
                }
            });
        }

        initializePopularFromState();
        bindPopular();
        fetchInsights(defaultSymbol);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootstrap);
    } else {
        bootstrap();
    }
})();
