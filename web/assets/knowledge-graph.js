(function () {
    const config = window.AIKnowledgeGraph || {};
    const endpoints = config.endpoints || {};
    const searchEndpoint = endpoints.search || '';
    const initial = (config.initial && config.initial.search) || null;
    const hasInitialGraph = Boolean(config.initial && config.initial.hasGraph);

    const form = document.querySelector('[data-graph-search]');
    const input = form ? form.querySelector('input[name="q"]') : null;
    const metricsContainer = document.querySelector('[data-graph-metrics]');
    const entitiesContainer = document.querySelector('[data-graph-entities]');
    const entitiesEmpty = document.querySelector('[data-graph-entities-empty]');
    const relationsContainer = document.querySelector('[data-graph-relations]');
    const relationsEmpty = document.querySelector('[data-graph-relations-empty]');
    const synonymsContainer = document.querySelector('[data-graph-synonyms]');
    const synonymsEmpty = document.querySelector('[data-graph-synonyms-empty]');
    const triplesTable = document.querySelector('[data-graph-triples]');
    const triplesEmpty = document.querySelector('[data-graph-triples-empty]');
    const sourcesContainer = document.querySelector('[data-graph-sources]');
    const sourcesEmpty = document.querySelector('[data-graph-sources-empty]');
    const feedback = document.querySelector('[data-graph-feedback]');
    const entityDetail = document.querySelector('[data-graph-entity-detail]');

    const summaryCache = new Map();
    const numberFormatter = new Intl.NumberFormat(undefined, { maximumFractionDigits: 0 });

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function formatNumber(value) {
        if (typeof value !== 'number') {
            value = Number(value || 0);
        }
        if (!Number.isFinite(value)) {
            return '0';
        }
        return numberFormatter.format(value);
    }

    function formatPercent(value) {
        if (typeof value !== 'number' || !Number.isFinite(value)) {
            return '0%';
        }
        return `${Math.round(value * 100)}%`;
    }

    function parseDate(value) {
        if (!value || typeof value !== 'string') {
            return '';
        }
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) {
            return value;
        }
        return date.toLocaleString();
    }

    function toggleLoading(isLoading) {
        if (!form) {
            return;
        }
        form.classList.toggle('is-loading', Boolean(isLoading));
        const button = form.querySelector('button');
        if (button) {
            button.disabled = Boolean(isLoading);
        }
    }

    function updateFeedback(message, isError) {
        if (!feedback) {
            return;
        }

        if (!message) {
            feedback.classList.add('is-hidden');
            feedback.innerHTML = '';
            return;
        }

        feedback.classList.remove('is-hidden');
        const statusClass = isError ? 'status error' : 'status';
        feedback.innerHTML = `<p class="${statusClass}">${escapeHtml(message)}</p>`;
    }

    function clearContainer(container) {
        if (container) {
            container.innerHTML = '';
        }
    }

    function renderMetrics(summary, sources, updatedAt) {
        if (!metricsContainer) {
            return;
        }

        if (!summary || typeof summary !== 'object') {
            metricsContainer.innerHTML = '';
            return;
        }

        const documents = formatNumber(summary.documents_processed || summary.documents || 0);
        const triples = formatNumber(summary.triples || 0);
        const synonymGroups = formatNumber(summary.synonym_groups || 0);
        const entities = formatNumber(summary.unique_entities || 0);
        const sourcesCount = Array.isArray(sources) ? formatNumber(sources.length) : '0';
        const updated = updatedAt ? parseDate(updatedAt) : '';

        metricsContainer.innerHTML = `
            <article class="metric-card">
                <span class="metric-label">Documents processed</span>
                <span class="metric-value">${documents}</span>
                <span class="metric-sub">Sources tracked: ${sourcesCount}</span>
            </article>
            <article class="metric-card">
                <span class="metric-label">Triples extracted</span>
                <span class="metric-value">${triples}</span>
                <span class="metric-sub">Synonym groups: ${synonymGroups}</span>
            </article>
            <article class="metric-card">
                <span class="metric-label">Unique entities</span>
                <span class="metric-value">${entities}</span>
                ${updated ? `<span class="metric-sub">Updated ${escapeHtml(updated)}</span>` : ''}
            </article>
        `;
    }

    function renderEntities(entities) {
        if (!entitiesContainer || !entitiesEmpty) {
            return;
        }

        clearContainer(entitiesContainer);

        if (!Array.isArray(entities) || entities.length === 0) {
            entitiesEmpty.hidden = false;
            return;
        }

        entitiesEmpty.hidden = true;

        entities.slice(0, 24).forEach((entity) => {
            const name = entity && entity.entity ? String(entity.entity) : '';
            if (!name) {
                return;
            }

            if (entity.summary && entity.summary.entity) {
                summaryCache.set(name.toLowerCase(), entity.summary);
            }

            const score = typeof entity.score === 'number' ? entity.score : 0;
            const synonyms = Array.isArray(entity.synonyms) ? entity.synonyms : [];
            const hints = [];
            if (entity.matched_synonym) {
                hints.push(`Matched synonym: ${entity.matched_synonym}`);
            }
            if (entity.matched_fact) {
                hints.push(`Context: ${entity.matched_fact}`);
            }
            if (entity.signals && typeof entity.signals === 'object') {
                const labelSignals = Object.entries(entity.signals)
                    .map(([key, value]) => `${key}: ${formatPercent(Number(value))}`)
                    .join(' · ');
                if (labelSignals) {
                    hints.push(labelSignals);
                }
            }

            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'entity-chip';
            button.dataset.entity = name;
            button.innerHTML = `
                <span class="entity-chip__name">${escapeHtml(name)}</span>
                <span class="entity-chip__score">Confidence ${formatPercent(score)}</span>
                ${synonyms.length ? `<span class="entity-chip__meta">Synonyms: ${escapeHtml(synonyms.join(', '))}</span>` : ''}
                ${hints.length ? `<span class="entity-chip__signals">${escapeHtml(hints.join(' · '))}</span>` : ''}
            `;
            button.addEventListener('click', () => {
                loadEntitySummary(name, entity.summary || null, entity);
            });

            entitiesContainer.appendChild(button);
        });
    }

    function renderRelations(relations) {
        if (!relationsContainer || !relationsEmpty) {
            return;
        }

        clearContainer(relationsContainer);

        if (!Array.isArray(relations) || relations.length === 0) {
            relationsEmpty.hidden = false;
            return;
        }

        relationsEmpty.hidden = true;

        relations.slice(0, 15).forEach((relation) => {
            const label = relation && relation.relation ? String(relation.relation) : String(relation.label || '');
            if (!label) {
                return;
            }

            const count = relation.count != null ? formatNumber(relation.count) : '';
            const score = relation.score != null ? formatPercent(Number(relation.score)) : '';
            const meta = count || score ? `${count ? count : ''}${count && score ? ' · ' : ''}${score ? score : ''}` : '';

            const item = document.createElement('li');
            item.innerHTML = `
                <span class="label">${escapeHtml(label)}</span>
                ${meta ? `<span class="value">${escapeHtml(meta)}</span>` : ''}
            `;
            relationsContainer.appendChild(item);
        });
    }

    function renderSynonyms(groups) {
        if (!synonymsContainer || !synonymsEmpty) {
            return;
        }

        clearContainer(synonymsContainer);

        if (!Array.isArray(groups) || groups.length === 0) {
            synonymsEmpty.hidden = false;
            return;
        }

        synonymsEmpty.hidden = true;

        groups.slice(0, 15).forEach((group) => {
            const entity = group && group.entity ? String(group.entity) : '';
            const synonyms = Array.isArray(group.synonyms) ? group.synonyms : [];
            if (!entity || synonyms.length === 0) {
                return;
            }

            const note = group.matched_synonym ? `Matched: ${group.matched_synonym}` : '';
            const value = `${synonyms.join(', ')}${note ? ` · ${note}` : ''}`;

            const item = document.createElement('li');
            item.innerHTML = `
                <span class="label">${escapeHtml(entity)}</span>
                <span class="value">${escapeHtml(value)}</span>
            `;
            synonymsContainer.appendChild(item);
        });
    }

    function renderTriples(triples) {
        if (!triplesTable || !triplesEmpty) {
            return;
        }

        const tbody = triplesTable.tBodies[0] || triplesTable.createTBody();
        tbody.innerHTML = '';

        if (!Array.isArray(triples) || triples.length === 0) {
            triplesEmpty.hidden = false;
            return;
        }

        triplesEmpty.hidden = true;

        triples.slice(0, 25).forEach((triple) => {
            const row = document.createElement('tr');
            row.innerHTML = `
                <td>${escapeHtml(triple.subject || '')}</td>
                <td>${escapeHtml(triple.relation || '')}</td>
                <td>${escapeHtml(triple.object || '')}</td>
            `;
            tbody.appendChild(row);
        });
    }

    function renderSources(sources) {
        if (!sourcesContainer || !sourcesEmpty) {
            return;
        }

        clearContainer(sourcesContainer);

        if (!Array.isArray(sources) || sources.length === 0) {
            sourcesEmpty.hidden = false;
            return;
        }

        sourcesEmpty.hidden = true;

        sources.slice(0, 12).forEach((source) => {
            const url = source && source.url ? String(source.url) : '';
            const title = source && source.title ? String(source.title) : url;
            if (!url && !title) {
                return;
            }

            const characters = source && source.characters != null ? formatNumber(source.characters) : '';
            const fetched = source && source.fetched_at ? parseDate(source.fetched_at) : '';
            const preview = source && source.preview ? String(source.preview) : '';

            const item = document.createElement('li');
            item.innerHTML = `
                <p class="source-title">${url ? `<a href="${escapeHtml(url)}" target="_blank" rel="noopener noreferrer">${escapeHtml(title)}</a>` : escapeHtml(title)}</p>
                <p class="source-meta">${characters ? `${characters} characters` : ''}${characters && fetched ? ' • ' : ''}${fetched ? escapeHtml(fetched) : ''}</p>
                ${preview ? `<p class="source-preview">${escapeHtml(preview)}</p>` : ''}
            `;
            sourcesContainer.appendChild(item);
        });
    }

    function renderEntityDetail(summary, context) {
        if (!entityDetail) {
            return;
        }

        if (!summary || typeof summary !== 'object') {
            entityDetail.innerHTML = '<p class="empty-state">No graph facts available for this entity.</p>';
            return;
        }

        const synonyms = Array.isArray(summary.synonyms) ? summary.synonyms : [];
        const facts = Array.isArray(summary.facts) ? summary.facts : [];
        const relationCounts = summary.relation_counts && typeof summary.relation_counts === 'object'
            ? Object.entries(summary.relation_counts)
            : [];
        const counterpartCounts = summary.counterpart_counts && typeof summary.counterpart_counts === 'object'
            ? Object.entries(summary.counterpart_counts)
            : [];
        const contextInfo = summary.context && typeof summary.context === 'object' ? summary.context : {};
        const searchHighlights = [];

        if (context && context.matched_synonym) {
            searchHighlights.push({ label: 'Matched synonym', value: context.matched_synonym });
        }
        if (context && context.matched_fact) {
            searchHighlights.push({ label: 'Context match', value: context.matched_fact });
        }
        if (context && context.signals && typeof context.signals === 'object' && Object.keys(context.signals).length > 0) {
            const formatted = Object.entries(context.signals)
                .map(([key, value]) => `${key}: ${formatPercent(Number(value))}`)
                .join(' · ');
            if (formatted) {
                searchHighlights.push({ label: 'Search signals', value: formatted });
            }
        }

        const supportSignals = summary.support && typeof summary.support === 'object'
            ? Object.entries(summary.support)
            : [];
        const rankingSignals = summary.signals && typeof summary.signals === 'object'
            ? Object.entries(summary.signals)
            : [];

        const relationList = relationCounts.slice(0, 8)
            .map(([label, value]) => `<li>${escapeHtml(label)} <span>${formatNumber(value)}</span></li>`)
            .join('');
        const counterpartList = counterpartCounts.slice(0, 8)
            .map(([label, value]) => `<li>${escapeHtml(label)} <span>${formatNumber(value)}</span></li>`)
            .join('');

        const contextSubject = contextInfo.as_subject && typeof contextInfo.as_subject === 'object'
            ? Object.entries(contextInfo.as_subject).slice(0, 6)
                .map(([label, value]) => `<li>${escapeHtml(label)} <span>${formatNumber(value)}</span></li>`)
                .join('')
            : '';
        const contextObject = contextInfo.as_object && typeof contextInfo.as_object === 'object'
            ? Object.entries(contextInfo.as_object).slice(0, 6)
                .map(([label, value]) => `<li>${escapeHtml(label)} <span>${formatNumber(value)}</span></li>`)
                .join('')
            : '';

        const factsList = facts.slice(0, 12)
            .map((fact) => {
                const direction = fact.direction === 'incoming' ? '←' : '→';
                const relation = fact.relation || '';
                const counterpart = fact.counterpart || '';
                return `<li><span class="fact-direction">${escapeHtml(direction)}</span><span class="fact-relation">${escapeHtml(relation)}</span><span class="fact-counterpart">${escapeHtml(counterpart)}</span></li>`;
            })
            .join('');

        const synonymBadge = synonyms.length
            ? `<p class="entity-summary__synonyms"><strong>Synonyms:</strong> ${escapeHtml(synonyms.join(', '))}</p>`
            : '';

        const highlightList = searchHighlights.length
            ? `<ul class="entity-summary__highlights">${searchHighlights
                .map((highlight) => `<li><strong>${escapeHtml(highlight.label)}:</strong> ${escapeHtml(highlight.value)}</li>`)
                .join('')}</ul>`
            : '';

        const supportList = supportSignals.length
            ? `<ul class="entity-summary__support">${supportSignals
                .map(([label, value]) => `<li><strong>${escapeHtml(label)}:</strong> ${formatNumber(value)}</li>`)
                .join('')}</ul>`
            : '';

        const rankingList = rankingSignals.length
            ? `<ul class="entity-summary__support">${rankingSignals
                .map(([label, value]) => `<li><strong>${escapeHtml(label)}:</strong> ${formatPercent(Number(value))}</li>`)
                .join('')}</ul>`
            : '';

        entityDetail.innerHTML = `
            <article class="entity-summary">
                <header class="entity-summary__header">
                    <div>
                        <h3>${escapeHtml(summary.entity || '')}</h3>
                        <p class="entity-summary__confidence">Confidence ${formatPercent(summary.score || 0)}</p>
                    </div>
                    ${summary.eligible ? '<span class="entity-badge">Recommended</span>' : ''}
                </header>
                ${synonymBadge}
                ${highlightList}
                <section class="entity-summary__section">
                    <h4>Fact patterns</h4>
                    <p>Total facts analysed: ${formatNumber(summary.fact_count || facts.length)}</p>
                    ${factsList ? `<ul class="entity-summary__facts">${factsList}</ul>` : '<p class="empty-state">No supporting facts available.</p>'}
                </section>
                <section class="entity-summary__section entity-summary__grid">
                    <article>
                        <h4>Top relations</h4>
                        ${relationList ? `<ul>${relationList}</ul>` : '<p class="empty-state">No relation signals yet.</p>'}
                    </article>
                    <article>
                        <h4>Top counterparts</h4>
                        ${counterpartList ? `<ul>${counterpartList}</ul>` : '<p class="empty-state">No counterpart signals yet.</p>'}
                    </article>
                </section>
                <section class="entity-summary__section entity-summary__grid">
                    <article>
                        <h4>Subject context</h4>
                        ${contextSubject ? `<ul>${contextSubject}</ul>` : '<p class="empty-state">No subject-side context yet.</p>'}
                    </article>
                    <article>
                        <h4>Object context</h4>
                        ${contextObject ? `<ul>${contextObject}</ul>` : '<p class="empty-state">No object-side context yet.</p>'}
                    </article>
                </section>
                ${rankingList ? `<section class="entity-summary__section"><h4>Ranking signals</h4>${rankingList}</section>` : ''}
                ${supportList ? `<section class="entity-summary__section"><h4>Support metrics</h4>${supportList}</section>` : ''}
            </article>
        `;
    }

    async function loadEntitySummary(name, preloadedSummary, context) {
        if (!name) {
            return;
        }

        const cacheKey = name.toLowerCase();
        const cached = summaryCache.get(cacheKey);
        if (cached) {
            renderEntityDetail(cached, context);
            return;
        }

        if (preloadedSummary) {
            summaryCache.set(cacheKey, preloadedSummary);
            renderEntityDetail(preloadedSummary, context);
            return;
        }

        if (!searchEndpoint) {
            renderEntityDetail(null, context);
            return;
        }

        if (entityDetail) {
            entityDetail.innerHTML = '<p class="empty-state">Loading entity summary…</p>';
        }

        try {
            const url = new URL(searchEndpoint, window.location.origin);
            url.searchParams.set('action', 'summary');
            url.searchParams.set('entity', name);
            const response = await fetch(url.toString(), {
                headers: { Accept: 'application/json' },
            });

            if (!response.ok) {
                throw new Error(`Unable to load entity summary (${response.status})`);
            }

            const payload = await response.json();
            const summary = payload && payload.data && payload.data.entity ? payload.data.entity : null;
            if (!summary) {
                throw new Error('Entity summary not available.');
            }

            summaryCache.set(cacheKey, summary);
            renderEntityDetail(summary, context);
        } catch (error) {
            renderEntityDetail(null, context);
            updateFeedback(error instanceof Error ? error.message : 'Failed to load entity summary.', true);
        }
    }

    function renderSearchResult(search, options = {}) {
        if (!search || typeof search !== 'object') {
            return;
        }

        const summary = search.summary || {};
        const sources = Array.isArray(search.sources) ? search.sources : [];
        const updatedAt = search.updated_at || null;

        renderMetrics(summary, sources, updatedAt);
        renderEntities(search.entities || []);
        renderRelations(search.relations || []);
        renderSynonyms(search.synonyms || []);
        renderTriples(search.triples || []);
        renderSources(sources);

        const hasMatches = (Array.isArray(search.entities) && search.entities.length > 0)
            || (Array.isArray(search.relations) && search.relations.length > 0)
            || (Array.isArray(search.synonyms) && search.synonyms.length > 0)
            || (Array.isArray(search.triples) && search.triples.length > 0);

        if (!options.fromInitial) {
            if (hasMatches) {
                updateFeedback('', false);
            } else if (search.query) {
                updateFeedback(`No matches for “${search.query}”. Refine your search to explore nearby entities.`, false);
            } else {
                updateFeedback('', false);
            }
        }

        if (search.entities && search.entities[0] && !options.fromInitial) {
            loadEntitySummary(search.entities[0].entity, search.entities[0].summary || null, search.entities[0]);
        }
    }

    async function runSearch(query) {
        if (!searchEndpoint) {
            return;
        }

        toggleLoading(true);

        try {
            const url = new URL(searchEndpoint, window.location.origin);
            url.searchParams.set('action', 'search');
            url.searchParams.set('q', query);
            url.searchParams.set('limit', '18');

            const response = await fetch(url.toString(), {
                headers: { Accept: 'application/json' },
            });

            if (!response.ok) {
                throw new Error(`Search failed (${response.status})`);
            }

            const payload = await response.json();
            const searchResult = payload && payload.data && payload.data.search ? payload.data.search : null;
            if (!searchResult) {
                throw new Error('Malformed response from search endpoint.');
            }

            renderSearchResult(searchResult);
        } catch (error) {
            updateFeedback(error instanceof Error ? error.message : 'Unable to complete the search.', true);
        } finally {
            toggleLoading(false);
        }
    }

    if (form && input) {
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            const query = input.value ? input.value.trim() : '';
            runSearch(query);
        });
    }

    if (initial && hasInitialGraph) {
        renderSearchResult(initial, { fromInitial: true });
    }
})();
