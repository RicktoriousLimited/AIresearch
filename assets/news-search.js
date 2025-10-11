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
    const statusEl = root.querySelector('[data-news-status]');
    const topicsEl = root.querySelector('[data-news-topics]');
    const topicsListEl = root.querySelector('[data-news-topics-list]');
    const resultsEl = root.querySelector('[data-news-results]');
    const statsEl = root.querySelector('[data-news-stats]');
    const insightsSection = root.querySelector('[data-news-insights]');
    const contextEl = root.querySelector('[data-news-context]');
    const recencySummaryEl = root.querySelector('[data-news-recency-summary]');
    const recencyListEl = root.querySelector('[data-news-recency]');
    const qualitySummaryEl = root.querySelector('[data-news-quality-summary]');
    const qualityListEl = root.querySelector('[data-news-quality]');
    const contentSummaryEl = root.querySelector('[data-news-content-summary]');
    const contentListEl = root.querySelector('[data-news-content]');
    const ingestSummaryEl = root.querySelector('[data-news-ingest-summary]');
    const ingestListEl = root.querySelector('[data-news-ingest]');
    const discoverySection = root.querySelector('[data-news-discovery]');
    const discoveryStatusEl = root.querySelector('[data-news-discovery-status]');
    const discoveryTreeEl = root.querySelector('[data-news-discovery-tree]');
    const continueButton = root.querySelector('[data-news-continue]');
    const continueStatusEl = root.querySelector('[data-news-continue-status]');

    let controller = null;
    let continueController = null;
    let lastQuery = null;

    const initialQueryAttr = root.getAttribute('data-initial-query') || '';

    function normaliseQuery(value) {
        if (typeof value !== 'string') {
            return '';
        }

        return value.trim().replace(/\s+/g, ' ');
    }

    function updateContext(query) {
        if (!contextEl) {
            return;
        }

        if (query) {
            contextEl.textContent = `Tracking coverage for “${query}”.`;
        } else {
            contextEl.textContent = 'Start with a topic, organisation, or event to see live intelligence.';
        }
    }

    function updateLocation(query) {
        if (typeof window === 'undefined' || !window.history || !window.history.replaceState) {
            return;
        }

        const url = new URL(window.location.href);
        if (query) {
            url.searchParams.set('q', query);
        } else {
            url.searchParams.delete('q');
        }

        window.history.replaceState({}, '', url.toString());
    }

    const initialQuery = normaliseQuery(initialQueryAttr);
    if (input && initialQuery && !input.value) {
        input.value = initialQuery;
    }
    updateContext(initialQuery);

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

    function formatDateTime(iso) {
        if (!iso) {
            return '';
        }

        const date = new Date(iso);
        if (Number.isNaN(date.getTime())) {
            return '';
        }

        try {
            return date.toLocaleString(undefined, {
                dateStyle: 'medium',
                timeStyle: 'short',
            });
        } catch (error) {
            return date.toISOString();
        }
    }

    function formatPercent(value) {
        if (typeof value !== 'number' || Number.isNaN(value)) {
            return '0%';
        }

        const clamped = Math.min(Math.max(value, 0), 1);
        const precision = clamped >= 0.1 ? 0 : 1;

        return `${(clamped * 100).toFixed(precision)}%`;
    }

    function clearChildren(node) {
        while (node && node.firstChild) {
            node.removeChild(node.firstChild);
        }
    }

    function createBadge(label, modifier) {
        const badge = document.createElement('span');
        badge.className = 'news-card__badge';
        if (modifier) {
            badge.classList.add(`news-card__badge--${modifier}`);
        }
        badge.textContent = label;

        return badge;
    }

    function normaliseContentType(value) {
        if (typeof value !== 'string' || !value.trim()) {
            return { label: '', modifier: '' };
        }

        const normalised = value.toLowerCase().replace(/\s+/g, '_');
        if (!normalised) {
            return { label: '', modifier: '' };
        }

        if (normalised === 'non_article') {
            return { label: 'Article', modifier: 'article' };
        }

        const label = normalised
            .split('_')
            .map((segment) => segment.charAt(0).toUpperCase() + segment.slice(1))
            .join(' ');

        return { label, modifier: normalised.replace(/[^a-z0-9-]/g, '-') };
    }

    function looksLikeHeading(line) {
        if (!line) {
            return false;
        }
        if (line.length > 48) {
            return false;
        }
        if (/[.!?]$/.test(line)) {
            return false;
        }
        if (/^[a-z]/.test(line)) {
            return false;
        }
        if (/^https?:/i.test(line)) {
            return false;
        }
        const words = line.split(/\s+/);
        if (words.length === 1) {
            return /^[A-Za-z0-9'&()\-]+$/.test(line);
        }
        const capitalised = words.filter((word) => /^[A-Z][A-Za-z0-9'&()\-]*$/.test(word)).length;
        return capitalised >= Math.ceil(words.length / 2);
    }

    function buildSummaryList(text) {
        if (typeof text !== 'string') {
            return null;
        }

        const normalised = text
            .replace(/\r\n/g, '\n')
            .replace(/\u2022/g, '\n• ');
        const lines = normalised
            .split(/\n+/)
            .map((line) => line.trim())
            .filter((line) => line);

        if (lines.length < 2) {
            return null;
        }

        const bulletLines = lines.filter((line) => /^[-*•]/.test(line));
        const colonLines = lines.filter((line) => /^[^:]{1,80}:\s+.+$/.test(line));
        const plusLines = lines.filter((line) => /^[+−-]\d/.test(line));
        const shortLines = lines.filter((line) => line.length <= 80);
        const metadataKeywords = [
            'ago',
            'updated',
            'posted',
            'published',
            'characters',
            'keywords',
            'markets',
            'technology',
            'finance',
            'climate',
            'culture',
            'sports',
            'score',
            'quality',
            'revision',
            'capture',
            'source',
            'domain',
            'minutes',
            'hours',
            'seconds',
            'price',
            'cost',
            'value',
        ];
        const metadataLines = lines.filter((line) => {
            const lower = line.toLowerCase();
            return metadataKeywords.some((keyword) => lower.includes(keyword));
        });

        const hasStructuredShape =
            bulletLines.length >= 2 ||
            colonLines.length >= 2 ||
            plusLines.length >= 2 ||
            (shortLines.length >= Math.max(3, Math.ceil(lines.length * 0.6)) &&
                lines.filter((line) => !/[.!?]$/.test(line)).length >= shortLines.length - 1) ||
            metadataLines.length >= 3;

        if (!hasStructuredShape) {
            return null;
        }

        const list = document.createElement('ul');
        list.className = 'news-card__summary-list';

        for (let index = 0; index < lines.length; index += 1) {
            const raw = lines[index];
            if (!raw) {
                continue;
            }

            const match = raw.match(/^([^:]{1,80}):\s*(.+)$/);
            if (match) {
                const li = document.createElement('li');
                const strong = document.createElement('strong');
                strong.textContent = `${match[1].trim()}:`;
                li.appendChild(strong);
                li.appendChild(document.createTextNode(` ${match[2].trim()}`));
                list.appendChild(li);
                continue;
            }

            const headingCandidate = looksLikeHeading(raw);
            if (headingCandidate) {
                const values = [];
                let cursor = index + 1;
                while (cursor < lines.length) {
                    const next = lines[cursor];
                    if (!next) {
                        cursor += 1;
                        continue;
                    }
                    if (/^[-*•]/.test(next) || /^[^:]{1,80}:\s+.+$/.test(next) || looksLikeHeading(next)) {
                        break;
                    }
                    values.push(next.replace(/^[-*•]\s*/, ''));
                    cursor += 1;
                }

                if (values.length) {
                    const li = document.createElement('li');
                    const strong = document.createElement('strong');
                    strong.textContent = `${raw.replace(/[:\s]+$/, '')}:`;
                    li.appendChild(strong);
                    li.appendChild(document.createTextNode(` ${values.join(' · ')}`));
                    list.appendChild(li);
                    index = cursor - 1;
                    continue;
                }
            }

            const li = document.createElement('li');
            li.textContent = raw.replace(/^[-*•]\s*/, '');
            list.appendChild(li);
        }

        if (list.children.length === 0) {
            return null;
        }

        if (list.children.length === 1) {
            const onlyItem = list.children[0];
            if (!onlyItem.querySelector('strong')) {
                return null;
            }
        }

        return list;
    }

    function appendChangeChips(wrapper, label, values, modifier) {
        if (!wrapper || !Array.isArray(values) || !values.length) {
            return;
        }

        const row = document.createElement('div');
        row.className = 'news-card__change-tags';
        if (modifier) {
            row.dataset.change = modifier;
        }

        const labelEl = document.createElement('span');
        labelEl.className = 'news-card__change-label';
        labelEl.textContent = label;
        row.appendChild(labelEl);

        values.forEach((value) => {
            if (typeof value !== 'string' || !value.trim()) {
                return;
            }
            const chip = document.createElement('span');
            chip.className = 'news-card__change-chip';
            chip.textContent = value;
            row.appendChild(chip);
        });

        if (row.childNodes.length > 1) {
            wrapper.appendChild(row);
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
            chip.className = 'news-search__chip';
            chip.textContent = topicName;
            chip.addEventListener('click', () => {
                fetchResults({ query: topicName, force: true });
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

    function renderFacetList(listEl, items, emptyMessage) {
        if (!listEl) {
            return;
        }

        clearChildren(listEl);

        const normalised = Array.isArray(items)
            ? items.filter((item) => item && typeof item === 'object' && Number(item.count) > 0)
            : [];

        if (!normalised.length) {
            const li = document.createElement('li');
            li.className = 'news-insight-card__empty';
            li.textContent = emptyMessage;
            listEl.appendChild(li);
            return;
        }

        normalised.forEach((item) => {
            const li = document.createElement('li');

            const label = document.createElement('span');
            label.className = 'label';
            label.textContent = typeof item.label === 'string' && item.label ? item.label : 'Unknown';
            li.appendChild(label);

            const value = document.createElement('span');
            value.className = 'value';
            const count = Number(item.count || 0);
            const share = Number.isFinite(Number(item.share)) ? Number(item.share) : 0;
            value.textContent = `${count.toLocaleString()} · ${formatPercent(share)}`;
            li.appendChild(value);

            listEl.appendChild(li);
        });
    }

    function renderInsights(meta) {
        if (!insightsSection) {
            return;
        }

        const facets = meta && meta.facets && typeof meta.facets === 'object' ? meta.facets : {};
        const recency = Array.isArray(facets.recency) ? facets.recency : [];
        const quality = Array.isArray(facets.quality) ? facets.quality : [];
        const content = Array.isArray(facets.content_types) ? facets.content_types : [];
        const ingestion = Array.isArray(facets.ingestion) ? facets.ingestion : [];

        const fallbackRecency = 'Fresh coverage metrics will appear once crawls complete.';
        const fallbackQuality = 'Awaiting quality signals from the latest crawl.';
        const fallbackContent = 'Content mix pending enrichment.';
        const fallbackIngestion = 'Ingestion progress will update as documents are processed.';

        if (recencySummaryEl) {
            if (recency.length && recency[0] && typeof recency[0] === 'object') {
                const share = Number(recency[0].share || 0);
                const label = typeof recency[0].label === 'string' ? recency[0].label.toLowerCase() : 'recent periods';
                recencySummaryEl.textContent = `${formatPercent(share)} of coverage from ${label}`;
            } else {
                recencySummaryEl.textContent = fallbackRecency;
            }
        }

        if (qualitySummaryEl) {
            if (quality.length && quality[0] && typeof quality[0] === 'object') {
                const share = Number(quality[0].share || 0);
                const label = typeof quality[0].label === 'string' ? quality[0].label.toLowerCase() : 'top buckets';
                qualitySummaryEl.textContent = `${formatPercent(share)} of stories score ${label}`;
            } else {
                qualitySummaryEl.textContent = fallbackQuality;
            }
        }

        if (contentSummaryEl) {
            if (content.length && content[0] && typeof content[0] === 'object') {
                const share = Number(content[0].share || 0);
                const label = typeof content[0].label === 'string' ? content[0].label.toLowerCase() : 'this format';
                contentSummaryEl.textContent = `${formatPercent(share)} of stories are ${label}`;
            } else {
                contentSummaryEl.textContent = fallbackContent;
            }
        }

        if (ingestSummaryEl) {
            const totals = ingestion.reduce(
                (acc, row) => {
                    const count = Number(row && typeof row === 'object' ? row.count : 0);
                    const label = typeof row.label === 'string' ? row.label.toLowerCase() : '';
                    acc.total += Number.isFinite(count) ? count : 0;
                    if (label.includes('captured') || label.includes('ingest')) {
                        acc.captured += Number.isFinite(count) ? count : 0;
                    }
                    return acc;
                },
                { total: 0, captured: 0 }
            );

            if (totals.total > 0) {
                ingestSummaryEl.textContent = `${formatPercent(totals.captured / totals.total)} of results already enriched`;
            } else {
                ingestSummaryEl.textContent = fallbackIngestion;
            }
        }

        renderFacetList(recencyListEl, recency, 'Recency distribution will populate after the next crawl.');
        renderFacetList(qualityListEl, quality, 'Quality buckets appear once headlines are scored.');
        renderFacetList(contentListEl, content, 'We will classify formats as new sources arrive.');
        renderFacetList(ingestListEl, ingestion, 'Ingestion progress will update as documents are processed.');

        if (insightsSection) {
            insightsSection.removeAttribute('hidden');
        }
    }

    function renderDiscovery(snapshot) {
        if (!discoverySection) {
            return;
        }

        const seeds = snapshot && Array.isArray(snapshot.seeds) ? snapshot.seeds : [];
        const total = snapshot && typeof snapshot.total_nodes === 'number' ? snapshot.total_nodes : seeds.length;
        const pending = snapshot && typeof snapshot.pending === 'number' ? snapshot.pending : 0;
        const recommended = snapshot && Array.isArray(snapshot.recommended) ? snapshot.recommended : [];

        if (!seeds.length && !pending && !recommended.length) {
            discoverySection.setAttribute('hidden', 'hidden');
        } else {
            discoverySection.removeAttribute('hidden');
        }

        if (discoveryStatusEl) {
            const totalLabel = total === 1 ? '1 page' : `${total.toLocaleString()} pages`;
            const pendingLabel = pending === 1 ? '1 pending' : `${pending.toLocaleString()} pending`;
            discoveryStatusEl.textContent = `Tracking ${totalLabel} · ${pendingLabel}`;
        }

        if (discoveryTreeEl) {
            clearChildren(discoveryTreeEl);
            if (!seeds.length) {
                const empty = document.createElement('p');
                empty.className = 'discovery-tree__empty';
                empty.textContent = 'Connect seed URLs to populate the discovery tree.';
                discoveryTreeEl.appendChild(empty);
            } else {
                discoveryTreeEl.appendChild(buildDiscoveryList(seeds, 0));
            }
        }

        if (continueButton) {
            const labelBase = 'Continue discovery';
            if (recommended.length) {
                continueButton.removeAttribute('disabled');
                continueButton.textContent = `${labelBase} (${recommended.length})`;
            } else {
                continueButton.setAttribute('disabled', 'disabled');
                continueButton.textContent = labelBase;
            }
        }

        if (continueStatusEl && !continueStatusEl.dataset.busy) {
            continueStatusEl.textContent = recommended.length
                ? 'Queue primed with fresh leads.'
                : 'No queued pages right now.';
        }
    }

    function buildDiscoveryList(nodes, depth) {
        const list = document.createElement('ul');
        list.className = depth === 0 ? 'discovery-tree' : 'discovery-tree__children';

        nodes.forEach((node) => {
            const normalised = node && typeof node === 'object' ? node : {};
            const url = typeof normalised.url === 'string' ? normalised.url : '';
            if (!url) {
                return;
            }

            const title = typeof normalised.title === 'string' && normalised.title.trim()
                ? normalised.title.trim()
                : url;

            const item = document.createElement('li');
            item.className = 'discovery-tree__item';
            if (normalised.seed) {
                item.classList.add('discovery-tree__item--seed');
            }

            const link = document.createElement('a');
            link.className = 'discovery-tree__link';
            link.href = url;
            link.target = '_blank';
            link.rel = 'noopener';
            link.textContent = title;
            item.appendChild(link);

            const childCount = typeof normalised.child_count === 'number' ? normalised.child_count : 0;
            const status = typeof normalised.status === 'string' ? normalised.status : '';
            const stamp = normalised.last_seen_at || normalised.first_seen_at || '';
            const metaParts = [];
            if (childCount > 0) {
                metaParts.push(`${childCount} link${childCount === 1 ? '' : 's'}`);
            }
            if (status) {
                metaParts.push(status === 'indexed' ? 'Indexed' : 'Pending');
            }
            const relative = relativeTime(stamp);
            if (relative) {
                metaParts.push(relative);
            }

            if (metaParts.length) {
                const meta = document.createElement('span');
                meta.className = 'discovery-tree__meta';
                meta.textContent = metaParts.join(' · ');
                item.appendChild(meta);
            }

            const children = Array.isArray(normalised.children) ? normalised.children : [];
            if (children.length) {
                item.appendChild(buildDiscoveryList(children, depth + 1));
            }

            list.appendChild(item);
        });

        if (!list.children.length) {
            const empty = document.createElement('li');
            empty.className = 'discovery-tree__item discovery-tree__item--empty';
            empty.textContent = 'No discovery links recorded yet.';
            list.appendChild(empty);
        }

        return list;
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
            empty.textContent = 'No stories match this search yet. Try broadening the query or checking back soon.';
            resultsEl.appendChild(empty);
            return;
        }

        results.forEach((result) => {
            const card = document.createElement('article');
            card.className = 'news-card';

            const body = document.createElement('div');
            body.className = 'news-card__content';
            card.appendChild(body);

            const normaliseImage = (value) => {
                if (typeof value !== 'string') {
                    return '';
                }
                const trimmed = value.trim();
                return trimmed;
            };

            let imageUrl = normaliseImage(result.image_url);
            if (!imageUrl) {
                imageUrl = normaliseImage(result.thumbnail);
            }
            if (imageUrl) {
                const mediaLink = document.createElement('a');
                mediaLink.className = 'news-card__media';
                mediaLink.href = result.url || '#';
                mediaLink.target = '_blank';
                mediaLink.rel = 'noopener';
                const img = document.createElement('img');
                img.src = imageUrl;
                img.alt = '';
                img.loading = 'lazy';
                mediaLink.appendChild(img);
                card.appendChild(mediaLink);
            } else {
                card.classList.add('news-card--no-image');
            }

            const qualityPill = document.createElement('span');
            qualityPill.className = 'news-card__quality';
            if (result.ingest) {
                qualityPill.dataset.ingest = 'yes';
            }
            const label = typeof result.quality_label === 'string' ? result.quality_label : 'Quality';
            const score = typeof result.quality_score === 'number' ? result.quality_score.toFixed(1) : '0.0';
            qualityPill.textContent = `${label} · ${score}`;

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
                const summaryList = buildSummaryList(summaryText);
                if (summaryList) {
                    const wrapper = document.createElement('div');
                    wrapper.className = 'news-card__summary';
                    wrapper.appendChild(summaryList);
                    body.appendChild(wrapper);
                } else {
                    const paragraph = document.createElement('p');
                    paragraph.className = 'news-card__summary';
                    paragraph.textContent = summaryText;
                    body.appendChild(paragraph);
                }
            }

            const meta = document.createElement('div');
            meta.className = 'news-card__meta';
            const metaItems = [];
            const sourceDomain = typeof result.source_domain === 'string' ? result.source_domain.trim() : '';
            const siteName = typeof result.source_site_name === 'string' ? result.source_site_name.trim() : '';
            if (sourceDomain || siteName) {
                const sameLabel = siteName && sourceDomain && siteName.toLowerCase() === sourceDomain.toLowerCase();
                const labelText = siteName
                    ? sameLabel ? siteName : `${siteName} · ${sourceDomain}`.trim().replace(/\s+·$/, '')
                    : sourceDomain;
                metaItems.push(labelText);
            }
            const publishedRelative = relativeTime(result.source_published_at);
            const publishedAbsolute = formatDateTime(result.source_published_at);
            if (publishedRelative) {
                metaItems.push(`Published ${publishedRelative}`);
            } else if (publishedAbsolute) {
                metaItems.push(`Published ${publishedAbsolute}`);
            }
            const lastChecked = typeof result.last_checked_at === 'string' ? result.last_checked_at : '';
            const fetchedAt = typeof result.fetched_at === 'string' ? result.fetched_at : '';
            let updatedRelative = '';
            if (lastChecked) {
                updatedRelative = relativeTime(lastChecked) || '';
            }
            if (!updatedRelative && fetchedAt) {
                updatedRelative = relativeTime(fetchedAt) || '';
            }
            if (updatedRelative) {
                metaItems.push(`Updated ${updatedRelative}`);
            }

            metaItems.forEach((item) => {
                const span = document.createElement('span');
                span.textContent = item;
                meta.appendChild(span);
            });

            if (meta.childNodes.length) {
                body.appendChild(meta);
            }

            const footer = document.createElement('div');
            footer.className = 'news-card__footer';
            footer.appendChild(qualityPill);
            body.appendChild(footer);

            const flags = document.createElement('div');
            flags.className = 'news-card__flags';
            let hasFlags = false;

            const revision = typeof result.revision === 'number' ? result.revision : Number(result.revision);
            if (Number.isFinite(revision) && revision > 0) {
                flags.appendChild(createBadge(`Revision ${revision}`, 'revision'));
                hasFlags = true;
            }

            const contentType = normaliseContentType(result.content_type);
            if (contentType.label) {
                const typeBadge = createBadge(contentType.label, `type-${contentType.modifier}`);
                flags.appendChild(typeBadge);
                hasFlags = true;
            }

            if (result.unchanged) {
                flags.appendChild(createBadge('Unchanged', 'unchanged'));
                hasFlags = true;
            }

            if (hasFlags) {
                body.appendChild(flags);
            }

            const changes = result.changes || {};
            const changeSummary = typeof changes.summary === 'string' ? changes.summary.trim() : '';
            const keywordsAdded = Array.isArray(changes.keywords_added) ? changes.keywords_added : [];
            const keywordsRemoved = Array.isArray(changes.keywords_removed) ? changes.keywords_removed : [];
            const entitiesAdded = Array.isArray(changes.entities_added) ? changes.entities_added : [];
            const entitiesRemoved = Array.isArray(changes.entities_removed) ? changes.entities_removed : [];
            const lengthDelta = typeof changes.length_delta === 'number' ? changes.length_delta : 0;

            if (
                changeSummary ||
                keywordsAdded.length ||
                keywordsRemoved.length ||
                entitiesAdded.length ||
                entitiesRemoved.length ||
                lengthDelta !== 0
            ) {
                const changesEl = document.createElement('div');
                changesEl.className = 'news-card__changes';

                if (changeSummary) {
                    const summaryEl = document.createElement('p');
                    summaryEl.className = 'news-card__changes-summary';
                    summaryEl.textContent = changeSummary;
                    changesEl.appendChild(summaryEl);
                }

                if (lengthDelta !== 0) {
                    const delta = document.createElement('span');
                    delta.className = 'news-card__changes-delta';
                    const direction = lengthDelta > 0 ? '+' : '−';
                    delta.textContent = `${direction}${Math.abs(lengthDelta)} characters`;
                    changesEl.appendChild(delta);
                }

                const changeList = document.createElement('div');
                changeList.className = 'news-card__change-list';
                appendChangeChips(changeList, 'Keywords added', keywordsAdded, 'added');
                appendChangeChips(changeList, 'Keywords removed', keywordsRemoved, 'removed');
                appendChangeChips(changeList, 'Entities added', entitiesAdded, 'added');
                appendChangeChips(changeList, 'Entities removed', entitiesRemoved, 'removed');

                if (changeList.childNodes.length) {
                    changesEl.appendChild(changeList);
                }

                body.appendChild(changesEl);
            }

            const versions = Array.isArray(result.versions) ? result.versions : [];
            if (versions.length) {
                const archive = document.createElement('details');
                archive.className = 'news-card__archive';

                const summary = document.createElement('summary');
                summary.textContent = `History · ${versions.length} earlier revision${versions.length === 1 ? '' : 's'}`;
                archive.appendChild(summary);

                versions.forEach((version) => {
                    if (!version) {
                        return;
                    }

                    const item = document.createElement('div');
                    item.className = 'news-card__archive-item';

                    const header = document.createElement('div');
                    header.className = 'news-card__archive-header';
                    const versionRevision = typeof version.revision === 'number' ? version.revision : Number(version.revision);
                    if (Number.isFinite(versionRevision) && versionRevision > 0) {
                        header.appendChild(createBadge(`Rev ${versionRevision}`, 'revision'));
                    }
                    if (version.fetched_at) {
                        const captured = document.createElement('span');
                        captured.className = 'news-card__archive-time';
                        const relative = relativeTime(version.fetched_at);
                        captured.textContent = relative ? `Captured ${relative}` : version.fetched_at;
                        header.appendChild(captured);
                    }
                    item.appendChild(header);

                    const versionTitle = typeof version.title === 'string' ? version.title : '';
                    if (versionTitle) {
                        const titleEl = document.createElement('p');
                        titleEl.className = 'news-card__archive-title';
                        titleEl.textContent = versionTitle;
                        item.appendChild(titleEl);
                    }

                    const versionSummary = typeof version.summary === 'string' ? version.summary : '';
                    if (versionSummary) {
                        const summaryEl = document.createElement('p');
                        summaryEl.className = 'news-card__archive-summary';
                        summaryEl.textContent = versionSummary;
                        item.appendChild(summaryEl);
                    }

                    const versionChanges = version.changes && typeof version.changes.summary === 'string'
                        ? version.changes.summary.trim()
                        : '';
                    if (versionChanges) {
                        const changeNote = document.createElement('p');
                        changeNote.className = 'news-card__archive-change';
                        changeNote.textContent = versionChanges;
                        item.appendChild(changeNote);
                    }

                    archive.appendChild(item);
                });

                body.appendChild(archive);
            }

            if (Array.isArray(result.topics) && result.topics.length) {
                const topics = result.topics
                    .map((topic) => (typeof topic === 'string' ? topic.trim() : ''))
                    .filter((topic) => topic);
                if (topics.length) {
                    const topicsWrapper = document.createElement('div');
                    topicsWrapper.className = 'news-card__topics';
                    topics.slice(0, 4).forEach((topic) => {
                        const labelEl = document.createElement('span');
                        labelEl.textContent = topic;
                        topicsWrapper.appendChild(labelEl);
                    });
                    footer.appendChild(topicsWrapper);
                }
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
            resultsEl.appendChild(card);
        });
    }

    function fetchResults(options = {}) {
        if (!input) {
            return;
        }

        const query = normaliseQuery(
            Object.prototype.hasOwnProperty.call(options, 'query') ? options.query : input.value
        );

        if (input.value !== query) {
            input.value = query;
        }

        if (options.updateLocation !== false) {
            updateLocation(query);
        }

        updateContext(query);

        if (!options.force && lastQuery !== null && query === lastQuery) {
            return;
        }

        lastQuery = query;

        const params = new URLSearchParams({
            q: query,
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
                renderInsights(payload.meta);
                renderResults(payload);
                renderDiscovery(payload.discovery || (payload.meta && payload.meta.discovery));
            })
            .catch((error) => {
                if (error.name === 'AbortError') {
                    return;
                }
                if (statusEl) {
                    statusEl.textContent = 'Unable to load news intelligence. Please retry.';
                }
                lastQuery = null;
            })
            .finally(() => {
                root.removeAttribute('data-state');
            });
    }

    if (form) {
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            fetchResults({ force: true });
        });
    }

    function continueDiscovery() {
        if (!continueButton) {
            return;
        }

        if (continueController) {
            continueController.abort();
        }

        continueController = new AbortController();
        continueButton.setAttribute('disabled', 'disabled');
        continueButton.textContent = 'Continuing…';

        if (continueStatusEl) {
            continueStatusEl.dataset.busy = 'true';
            continueStatusEl.textContent = 'Dispatching crawler to explore queued leads…';
        }

        fetch(endpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                action: 'continue',
                limit: 5,
                depth: 1,
            }),
            signal: continueController.signal,
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error(`Request failed with status ${response.status}`);
                }
                return response.json();
            })
            .then((payload) => {
                const processed = typeof payload.processed === 'number' ? payload.processed : 0;
                const targetCount = Array.isArray(payload.targets) ? payload.targets.length : 0;

                if (continueStatusEl) {
                    const processedLabel = processed === 1 ? 'Processed 1 page.' : `Processed ${processed} pages.`;
                    const queuedLabel = targetCount === 1 ? 'Queued 1 target.' : `Queued ${targetCount} targets.`;
                    continueStatusEl.textContent = processed || targetCount
                        ? `${processed ? processedLabel : ''} ${targetCount ? queuedLabel : ''}`.trim()
                        : 'No new pages discovered right now.';
                }

                renderDiscovery(payload.discovery);

                if (continueStatusEl) {
                    const statusNode = continueStatusEl;
                    window.setTimeout(() => {
                        if (statusNode.dataset.busy) {
                            statusNode.removeAttribute('data-busy');
                        }
                    }, 3000);
                }

                setTimeout(() => {
                    fetchResults({ force: true, updateLocation: false });
                }, 250);
            })
            .catch((error) => {
                if (error.name === 'AbortError') {
                    if (continueStatusEl) {
                        continueStatusEl.removeAttribute('data-busy');
                    }
                    return;
                }
                if (continueStatusEl) {
                    continueStatusEl.textContent = 'Discovery refresh failed. Try again soon.';
                    const statusNode = continueStatusEl;
                    window.setTimeout(() => {
                        if (statusNode.dataset.busy) {
                            statusNode.removeAttribute('data-busy');
                        }
                    }, 3000);
                }
            })
            .finally(() => {
                continueController = null;
            });
    }

    if (continueButton) {
        continueButton.addEventListener('click', () => {
            continueDiscovery();
        });
    }

    fetchResults({ updateLocation: false });
})();
