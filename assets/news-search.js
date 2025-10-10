(function () {
    const root = document.querySelector('[data-news-app]');
    if (!root) {
        return;
    }

    const endpoint = root.getAttribute('data-news-endpoint');
    if (!endpoint) {
        return;
    }

    const form = root.querySelector('[data-news-search-form]');
    const input = root.querySelector('[data-news-query]');
    const quality = root.querySelector('[data-news-quality]');
    const statusEl = root.querySelector('[data-news-status]');
    const topicsEl = root.querySelector('[data-news-topics]');
    const topicsListEl = root.querySelector('[data-news-topics-list]');
    const resultsEl = root.querySelector('[data-news-results]');
    const statsEl = root.querySelector('[data-news-stats]');

    let controller = null;

    function relativeTime(iso) {
        if (!iso) {
            return '';
        }
        const date = new Date(iso);
        if (Number.isNaN(date.getTime())) {
            return '';
        }
        const diff = Date.now() - date.getTime();
        if (diff <= 0) {
            return 'just now';
        }
        const seconds = Math.floor(diff / 1000);
        if (seconds < 60) {
            return `${seconds}s ago`;
        }
        const minutes = Math.floor(seconds / 60);
        if (minutes < 60) {
            return `${minutes} min${minutes === 1 ? '' : 's'} ago`;
        }
        const hours = Math.floor(minutes / 60);
        if (hours < 24) {
            return `${hours}h ago`;
        }
        const days = Math.floor(hours / 24);
        if (days < 7) {
            return `${days}d ago`;
        }
        const weeks = Math.floor(days / 7);
        if (weeks < 5) {
            return `${weeks}w ago`;
        }
        const months = Math.floor(days / 30);
        if (months < 12) {
            return `${months}mo ago`;
        }
        const years = Math.floor(days / 365);
        return `${years}y ago`;
    }

    function clearChildren(node) {
        while (node && node.firstChild) {
            node.removeChild(node.firstChild);
        }
    }

    function renderStatus(meta) {
        if (!statusEl) {
            return;
        }
        if (!meta) {
            statusEl.textContent = 'Awaiting first crawl.';
            return;
        }
        const total = typeof meta.total_matches === 'number' ? meta.total_matches : 0;
        const highQuality = typeof meta.high_quality === 'number' ? meta.high_quality : 0;
        const average = typeof meta.average_quality === 'number' ? meta.average_quality : 0;
        statusEl.textContent = `Scored ${total} curated stories · ${highQuality} high-quality · Avg score ${average.toFixed(1)}`;
    }

    function renderTopics(meta) {
        if (!topicsEl || !topicsListEl) {
            return;
        }
        clearChildren(topicsListEl);
        const topics = meta && Array.isArray(meta.topics) ? meta.topics : [];
        if (!topics.length) {
            topicsEl.setAttribute('hidden', 'hidden');
            return;
        }
        topicsEl.removeAttribute('hidden');
        topics.forEach((topic) => {
            const topicName = typeof topic.topic === 'string' ? topic.topic : '';
            if (!topicName) {
                return;
            }
            const chip = document.createElement('button');
            chip.type = 'button';
            chip.className = 'news-chip';
            chip.textContent = topicName;
            chip.addEventListener('click', () => {
                if (input) {
                    input.value = topicName;
                }
                fetchResults();
            });
            topicsListEl.appendChild(chip);
        });
    }

    function renderStats(meta) {
        if (!statsEl) {
            return;
        }
        const sources = meta && Array.isArray(meta.sources) ? meta.sources : [];
        if (!sources.length) {
            statsEl.textContent = 'No trusted sources ranked yet.';
            return;
        }
        const top = sources.slice(0, 3).map((source) => {
            const domain = typeof source.domain === 'string' ? source.domain : '';
            const avg = typeof source.average_quality === 'number' ? source.average_quality : 0;
            return `${domain} (${avg.toFixed(1)})`;
        }).join(' · ');
        statsEl.textContent = `Top sources: ${top}`;
    }

    function renderResults(payload) {
        if (!resultsEl) {
            return;
        }
        clearChildren(resultsEl);
        const results = payload && Array.isArray(payload.results) ? payload.results : [];
        if (!results.length) {
            const empty = document.createElement('div');
            empty.className = 'news-empty';
            empty.textContent = 'No stories match this filter yet. Broaden the query or lower the quality threshold.';
            resultsEl.appendChild(empty);
            return;
        }

        results.forEach((result) => {
            const card = document.createElement('article');
            card.className = 'news-card';

            if (typeof result.thumbnail === 'string' && result.thumbnail.trim() !== '') {
                const media = document.createElement('div');
                media.className = 'news-card__media';
                const img = document.createElement('img');
                img.src = result.thumbnail;
                img.alt = '';
                img.loading = 'lazy';
                media.appendChild(img);
                card.appendChild(media);
            }

            const body = document.createElement('div');
            body.className = 'news-card__body';

            const qualityPill = document.createElement('span');
            qualityPill.className = 'news-card__quality';
            if (result.ingest) {
                qualityPill.dataset.ingest = 'yes';
            }
            const label = typeof result.quality_label === 'string' ? result.quality_label : 'Quality';
            const score = typeof result.quality_score === 'number' ? result.quality_score.toFixed(1) : '0.0';
            qualityPill.textContent = `${label} · ${score}`;
            body.appendChild(qualityPill);

            const title = document.createElement('h3');
            title.className = 'news-card__title';
            const link = document.createElement('a');
            link.href = result.url;
            link.target = '_blank';
            link.rel = 'noopener';
            link.textContent = result.title || result.url || 'Untitled article';
            title.appendChild(link);
            body.appendChild(title);

            const summaryText = result.summary || result.preview || '';
            if (summaryText) {
                const summary = document.createElement('p');
                summary.className = 'news-card__summary';
                summary.textContent = summaryText;
                body.appendChild(summary);
            }

            const meta = document.createElement('div');
            meta.className = 'news-card__meta';
            const sourceDomain = result.source_domain || '';
            if (sourceDomain) {
                const source = document.createElement('span');
                source.className = 'news-card__source';
                source.textContent = sourceDomain;
                meta.appendChild(source);
            }
            if (result.fetched_at) {
                const time = document.createElement('span');
                time.textContent = relativeTime(result.fetched_at);
                meta.appendChild(time);
            }
            body.appendChild(meta);

            if (Array.isArray(result.topics) && result.topics.length) {
                const topicsWrapper = document.createElement('div');
                topicsWrapper.className = 'news-card__topics';
                result.topics.forEach((topic) => {
                    const labelEl = document.createElement('span');
                    labelEl.textContent = topic;
                    topicsWrapper.appendChild(labelEl);
                });
                body.appendChild(topicsWrapper);
            }

            if (Array.isArray(result.quality_reasons) && result.quality_reasons.length) {
                const reasons = document.createElement('ul');
                reasons.className = 'news-card__reasons';
                result.quality_reasons.forEach((reason) => {
                    const li = document.createElement('li');
                    li.textContent = reason;
                    reasons.appendChild(li);
                });
                body.appendChild(reasons);
            }

            const footer = document.createElement('div');
            footer.className = 'news-card__footer';
            if (Array.isArray(result.recommended_sources) && result.recommended_sources.length) {
                const links = document.createElement('div');
                links.className = 'news-card__links';
                result.recommended_sources.forEach((row) => {
                    if (!row || !row.url) {
                        return;
                    }
                    const anchor = document.createElement('a');
                    anchor.href = row.url;
                    anchor.target = '_blank';
                    anchor.rel = 'noopener';
                    anchor.className = 'news-card__link';
                    const trust = typeof row.trust_score === 'number' ? row.trust_score.toFixed(2) : '';
                    anchor.textContent = row.domain ? `${row.domain} · ${trust}` : row.url;
                    links.appendChild(anchor);
                });
                footer.appendChild(links);
            }
            body.appendChild(footer);

            card.appendChild(body);
            resultsEl.appendChild(card);
        });
    }

    function fetchResults() {
        if (!input || !quality) {
            return;
        }
        const params = new URLSearchParams({
            q: input.value.trim(),
            min_quality: quality.value,
            limit: '24',
        });

        if (controller) {
            controller.abort();
        }
        controller = new AbortController();
        root.setAttribute('data-state', 'loading');

        fetch(`${endpoint}?${params.toString()}`, { signal: controller.signal })
            .then((response) => {
                if (!response.ok) {
                    throw new Error(`Request failed with status ${response.status}`);
                }
                return response.json();
            })
            .then((payload) => {
                renderStatus(payload.meta);
                renderTopics(payload.meta);
                renderStats(payload.meta);
                renderResults(payload);
            })
            .catch((error) => {
                if (error.name === 'AbortError') {
                    return;
                }
                if (statusEl) {
                    statusEl.textContent = 'Unable to load news intelligence. Please retry.';
                }
            })
            .finally(() => {
                root.removeAttribute('data-state');
            });
    }

    if (form) {
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            fetchResults();
        });
    }

    if (quality) {
        quality.addEventListener('change', () => {
            fetchResults();
        });
    }

    fetchResults();
})();
