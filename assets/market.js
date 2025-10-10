(function () {
    function parseDataset(id) {
        const element = document.getElementById(id);
        if (!element || !element.textContent) {
            return null;
        }

        try {
            const parsed = JSON.parse(element.textContent);
            return parsed;
        } catch (error) {
            console.warn('Unable to parse dataset', id, error);
            return null;
        }
    }

    function toNumber(value) {
        const number = Number(value);
        return Number.isFinite(number) ? number : 0;
    }

    const billionsFormatter = new Intl.NumberFormat(undefined, {
        minimumFractionDigits: 1,
        maximumFractionDigits: 1,
    });
    const integerFormatter = new Intl.NumberFormat(undefined, {
        maximumFractionDigits: 0,
    });
    const percentFormatter = new Intl.NumberFormat(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
    const shortTimeFormatter = new Intl.DateTimeFormat(undefined, {
        hour: '2-digit',
        minute: '2-digit',
    });
    const longDateFormatter = new Intl.DateTimeFormat(undefined, {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });

    function formatPercent(value) {
        const number = toNumber(value);
        if (number === 0) {
            return '0.00%';
        }

        const formatted = percentFormatter.format(Math.abs(number));
        return `${number > 0 ? '+' : '-'}${formatted}%`;
    }

    function changeBadge(value) {
        const number = toNumber(value);
        if (number > 0) {
            return 'change-up';
        }

        if (number < 0) {
            return 'change-down';
        }

        return 'change-flat';
    }

    const indicator = document.querySelector('[data-live-indicator]');
    const statusElement = indicator ? indicator.querySelector('[data-pulse-text="status"]') : null;
    const countdownElement = document.querySelector('[data-pulse-countdown]');
    const refreshButton = document.querySelector('[data-action="refresh-pulse"]');

    const refreshInterval = 60_000;
    let nextRefreshAt = Date.now() + refreshInterval;
    let isFetching = false;

    function setIndicatorState(state, message) {
        if (indicator) {
            indicator.setAttribute('data-state', state);
        }

        if (statusElement && message) {
            statusElement.textContent = message;
        }
    }

    function updateCountdown() {
        if (!countdownElement) {
            return;
        }

        if (isFetching) {
            countdownElement.textContent = 'Refreshing…';
            return;
        }

        const delta = Math.max(0, Math.round((nextRefreshAt - Date.now()) / 1000));
        if (delta <= 0) {
            countdownElement.textContent = 'Refreshing…';
            return;
        }

        countdownElement.textContent = `Next refresh in ${delta}s`;
    }

    function updateTimestamp(iso, label) {
        document.querySelectorAll('[data-pulse-timestamp]').forEach((element) => {
            if (iso) {
                element.setAttribute('data-initial-iso', iso);
            } else {
                element.removeAttribute('data-initial-iso');
            }

            element.textContent = label;
        });
    }

    function updateMetric(name, value) {
        const element = document.querySelector(`[data-pulse-metric="${name}"]`);
        if (!element) {
            return;
        }

        const format = element.getAttribute('data-format') ?? '';
        let display = '';

        switch (format) {
            case 'currency-billions': {
                const number = toNumber(value);
                display = `$${billionsFormatter.format(number / 1_000_000_000)}B`;
                break;
            }
            case 'percent':
                display = formatPercent(value);
                break;
            case 'advancers': {
                const advancers = typeof value === 'object' && value !== null ? toNumber(value.advancers) : toNumber(value);
                const decliners = typeof value === 'object' && value !== null ? toNumber(value.decliners) : 0;
                display = `${integerFormatter.format(advancers)} / ${integerFormatter.format(decliners)}`;
                break;
            }
            case 'integer':
                display = integerFormatter.format(Math.max(0, Math.round(toNumber(value))));
                break;
            default:
                display = typeof value === 'string' ? value : `${value ?? ''}`;
        }

        element.textContent = display;

        if (element.hasAttribute('data-change-badge')) {
            element.classList.remove('change-up', 'change-down', 'change-flat');
            element.classList.add(changeBadge(value));
        }
    }

    function updateWatchlist(list) {
        const container = document.querySelector('[data-pulse-list="watchlist"]');
        if (!container) {
            return;
        }

        container.innerHTML = '';
        if (!Array.isArray(list) || list.length === 0) {
            const empty = document.createElement('li');
            empty.textContent = 'No movers detected';
            container.appendChild(empty);
            return;
        }

        list.slice(0, 5).forEach((item) => {
            const li = document.createElement('li');
            const badge = document.createElement('span');
            badge.className = `badge ${changeBadge(item.change_percent)}`;
            badge.textContent = item.symbol ?? '';

            const strong = document.createElement('strong');
            strong.textContent = item.name ?? '';

            const em = document.createElement('em');
            em.textContent = formatPercent(item.change_percent);

            li.append(badge, strong, em);
            container.appendChild(li);
        });
    }

    function formatTime(value, formatter) {
        if (!value) {
            return '';
        }

        const date = new Date(value);
        if (Number.isNaN(date.getTime())) {
            return '';
        }

        return formatter.format(date);
    }

    function updateHeadlines(news) {
        const container = document.querySelector('[data-pulse-list="headlines"]');
        if (!container) {
            return;
        }

        container.innerHTML = '';
        if (!Array.isArray(news) || news.length === 0) {
            const empty = document.createElement('li');
            empty.textContent = 'No headlines cached yet';
            container.appendChild(empty);
            return;
        }

        news.slice(0, 3).forEach((item) => {
            const li = document.createElement('li');
            const strong = document.createElement('strong');
            strong.textContent = item.news?.title ?? '';
            const em = document.createElement('em');
            const source = item.news?.source ?? '';
            const time = formatTime(item.news?.published_at, shortTimeFormatter);
            em.textContent = time ? `${source} · ${time}` : source;
            li.append(strong, em);
            container.appendChild(li);
        });
    }

    function updateSectors(sectors) {
        const container = document.querySelector('[data-pulse-list="sectors"]');
        if (!container) {
            return;
        }

        container.innerHTML = '';
        if (!Array.isArray(sectors) || sectors.length === 0) {
            return;
        }

        sectors.slice(0, 6).forEach((sector) => {
            const article = document.createElement('article');
            article.className = 'sector-card';

            const title = document.createElement('h3');
            title.textContent = sector.sector ?? '';

            const change = document.createElement('p');
            change.className = `sector-change ${changeBadge(sector.avg_change)}`;
            change.textContent = formatPercent(sector.avg_change);

            const meta = document.createElement('p');
            meta.className = 'muted';
            meta.textContent = `Constituents: ${integerFormatter.format(toNumber(sector.count))}`;

            article.append(title, change, meta);
            container.appendChild(article);
        });
    }

    function updateNews(news) {
        const region = document.querySelector('[data-pulse-region="news"]');
        if (!region) {
            return;
        }

        region.innerHTML = '';
        if (!Array.isArray(news) || news.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'empty-state';
            empty.innerHTML = '<p>No articles available in the cache yet. Add more companies to expand coverage.</p>';
            region.appendChild(empty);
            return;
        }

        news.slice(0, 12).forEach((item) => {
            const card = document.createElement('article');
            card.className = 'news-card';

            const header = document.createElement('header');

            const badge = document.createElement('span');
            badge.className = `badge ${changeBadge(item.sentiment_score)}`;
            badge.textContent = item.company?.symbol ?? '';

            const source = document.createElement('p');
            source.className = 'news-source';
            const time = formatTime(item.news?.published_at, longDateFormatter);
            source.textContent = `${item.news?.source ?? ''}${time ? ` · ${time}` : ''}`;

            const heading = document.createElement('h3');
            const link = document.createElement('a');
            link.href = item.news?.url ?? '#';
            link.target = '_blank';
            link.rel = 'noopener';
            link.textContent = item.news?.title ?? '';
            heading.appendChild(link);

            header.append(badge, source, heading);

            const summary = document.createElement('p');
            summary.textContent = item.news?.summary ?? '';

            const footer = document.createElement('footer');
            const action = document.createElement('a');
            action.className = 'button ghost';
            const symbol = item.company?.symbol ?? '';
            action.href = `/company.php?q=${encodeURIComponent(symbol)}`;
            action.textContent = 'View company brief';
            footer.appendChild(action);

            card.append(header, summary, footer);
            region.appendChild(card);
        });
    }

    function updateAutonomy(overview) {
        const target = document.querySelector('[data-pulse-text="autonomy"]');
        if (!target) {
            return;
        }

        const age = typeof overview.cache_age_minutes === 'number' ? overview.cache_age_minutes : null;
        let message = 'All systems nominal';

        if (age !== null) {
            if (age <= 5) {
                message = 'Offline cache fresh';
            } else if (age <= 30) {
                message = `Cache ${age} min old`;
            } else {
                message = 'Cache due for refresh';
            }
        }

        target.textContent = message;
    }

    function applyPulse(pulse) {
        if (!pulse || typeof pulse !== 'object') {
            return;
        }

        const overview = pulse.overview ?? {};
        updateMetric('total_market_cap', overview.total_market_cap);
        updateMetric('average_change_percent', overview.average_change_percent);
        updateMetric('advancers_decliners', {
            advancers: overview.advancers,
            decliners: overview.decliners,
        });
        updateMetric('company_count', overview.company_count);
        updateMetric('news_count', overview.news_count);

        updateWatchlist(pulse.watchlist ?? []);
        updateHeadlines(pulse.latest_news ?? []);
        updateSectors(pulse.sectors ?? overview.sectors ?? []);
        updateNews(pulse.latest_news ?? []);
        updateAutonomy(overview);

        const label = overview.last_updated_relative ?? 'recently';
        updateTimestamp(overview.last_updated_iso ?? null, label);
        setIndicatorState('ready', `Cache synced ${label}`);
    }

    async function fetchPulse(manual) {
        if (isFetching) {
            return;
        }

        isFetching = true;
        setIndicatorState('syncing', manual ? 'Refreshing now…' : 'Syncing with offline cache…');
        updateCountdown();

        try {
            const response = await fetch('/api/market-pulse.php', {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store',
            });

            if (!response.ok) {
                throw new Error(`Request failed with status ${response.status}`);
            }

            const payload = await response.json();
            applyPulse(payload);
            nextRefreshAt = Date.now() + refreshInterval;
        } catch (error) {
            console.warn('Unable to refresh market pulse', error);
            setIndicatorState('error', 'Refresh failed – retrying soon');
            nextRefreshAt = Date.now() + refreshInterval;
        } finally {
            isFetching = false;
            updateCountdown();
        }
    }

    function setupPulse() {
        const initialPulse = parseDataset('market-pulse');
        if (initialPulse) {
            applyPulse(initialPulse);
        }

        updateCountdown();
        window.setInterval(() => {
            fetchPulse(false);
        }, refreshInterval);
        window.setInterval(updateCountdown, 1000);

        if (refreshButton) {
            refreshButton.addEventListener('click', () => {
                fetchPulse(true);
            });
        }
    }

    function setupSearchSuggestions() {
        const dataset = parseDataset('company-dataset');
        const input = document.getElementById('company-query');
        if (!input || !Array.isArray(dataset) || dataset.length === 0) {
            return;
        }

        const companies = dataset;
        const wrapper = document.createElement('div');
        wrapper.className = 'search-suggestions';
        input.parentElement?.appendChild(wrapper);

        function renderSuggestions(value) {
            const normalized = value.trim().toLowerCase();
            if (normalized === '') {
                wrapper.innerHTML = '';
                wrapper.classList.remove('visible');
                return;
            }

            const results = companies.filter((company) => {
                return (
                    company.symbol.toLowerCase().includes(normalized) ||
                    company.name.toLowerCase().includes(normalized) ||
                    company.sector.toLowerCase().includes(normalized)
                );
            }).slice(0, 5);

            if (results.length === 0) {
                wrapper.innerHTML = '';
                wrapper.classList.remove('visible');
                return;
            }

            wrapper.innerHTML = '';
            results.forEach((company) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'suggestion-item';
                button.innerHTML = `
                    <strong>${company.symbol}</strong>
                    <span>${company.name}</span>
                    <em>${company.sector}</em>
                `;
                button.addEventListener('click', () => {
                    input.value = company.symbol;
                    wrapper.classList.remove('visible');
                    input.form?.submit();
                });
                wrapper.appendChild(button);
            });

            wrapper.classList.add('visible');
        }

        input.addEventListener('input', (event) => {
            renderSuggestions(event.target.value);
        });

        input.addEventListener('focus', (event) => {
            if (event.target.value) {
                renderSuggestions(event.target.value);
            }
        });

        document.addEventListener('click', (event) => {
            if (event.target === input || wrapper.contains(event.target)) {
                return;
            }

            wrapper.classList.remove('visible');
        });
    }

    setupPulse();
    setupSearchSuggestions();
})();
