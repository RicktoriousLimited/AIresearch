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
            empty.textContent = 'No stories match this search yet. Try broadening the query or checking back soon.';
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
                const summary = document.createElement('div');
                summary.className = 'news-card__summary';
                const summaryList = buildSummaryList(summaryText);
                if (summaryList) {
                    summary.appendChild(summaryList);
                } else {
                    summary.textContent = summaryText;
                }
                body.appendChild(summary);
            }

            const meta = document.createElement('div');
            meta.className = 'news-card__meta';
            const sourceDomain = result.source_domain || '';
            const siteName = result.source_site_name || '';
            if (sourceDomain || siteName) {
                const source = document.createElement('span');
                source.className = 'news-card__source';
                const label = siteName && siteName !== sourceDomain
                    ? `${siteName} · ${sourceDomain}`.trim().replace(/\s+·\s+$/, '')
                    : (siteName || sourceDomain);
                source.textContent = label;
                meta.appendChild(source);
            }
            const publishedLabel = formatDateTime(result.source_published_at);
            if (publishedLabel) {
                const published = document.createElement('span');
                published.textContent = `published ${publishedLabel}`;
                meta.appendChild(published);
            }
            if (result.fetched_at) {
                const time = document.createElement('span');
                time.textContent = relativeTime(result.fetched_at);
                meta.appendChild(time);
            }
            if (result.last_checked_at && result.last_checked_at !== result.fetched_at) {
                const checkedRelative = relativeTime(result.last_checked_at);
                if (checkedRelative) {
                    const checked = document.createElement('span');
                    checked.textContent = `checked ${checkedRelative}`;
                    meta.appendChild(checked);
                }
            }
            body.appendChild(meta);

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
        if (!input) {
            return;
        }
        const params = new URLSearchParams({
            q: input.value.trim(),
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

    fetchResults();
})();
