(function () {
  const config = window.AISearch || {};
  const endpoints = config.endpoints || {};
  const searchEndpoint = endpoints.search || '';
  const summaryEndpoint = endpoints.summary || searchEndpoint;
  const initial = config.initial || {};
  const initialSearch = initial.search || null;
  const initialTop = initial.top || [];

  const form = document.querySelector('[data-search-form]');
  const input = document.querySelector('[data-search-input]');
  const statusEl = document.querySelector('[data-search-status]');
  const metricsEl = document.querySelector('[data-search-metrics]');
  const resultsEl = document.querySelector('[data-search-results]');
  const entitiesContainer = document.querySelector('[data-search-entities]');
  const entitiesEmpty = document.querySelector('[data-search-entities-empty]');
  const relationsContainer = document.querySelector('[data-search-relations]');
  const relationsEmpty = document.querySelector('[data-search-relations-empty]');
  const synonymsContainer = document.querySelector('[data-search-synonyms]');
  const synonymsEmpty = document.querySelector('[data-search-synonyms-empty]');
  const triplesTable = document.querySelector('[data-search-triples]');
  const triplesEmpty = document.querySelector('[data-search-triples-empty]');
  const sourcesContainer = document.querySelector('[data-search-sources]');
  const sourcesEmpty = document.querySelector('[data-search-sources-empty]');
  const detailCard = document.querySelector('[data-entity-detail]');
  const detailName = document.querySelector('[data-entity-name]');
  const detailScore = document.querySelector('[data-entity-score]');
  const detailSynonyms = document.querySelector('[data-entity-synonyms]');
  const detailFacts = document.querySelector('[data-entity-facts]');
  const detailFactsWrap = document.querySelector('[data-entity-facts-wrap]');
  const detailRelations = document.querySelector('[data-entity-relations]');
  const detailRelationsWrap = document.querySelector('[data-entity-relations-wrap]');
  const trendingContainer = document.querySelector('[data-search-trending]');
  const trendingList = document.querySelector('[data-search-trending-list]');

  const metricDocuments = document.querySelector('[data-metric-documents]');
  const metricTriples = document.querySelector('[data-metric-triples]');
  const metricEntities = document.querySelector('[data-metric-entities]');
  const metricSynonyms = document.querySelector('[data-metric-synonyms]');
  const metricSources = document.querySelector('[data-metric-sources]');
  const metricUpdated = document.querySelector('[data-metric-updated]');

  const numberFormatter = new Intl.NumberFormat(undefined, { maximumFractionDigits: 0 });

  const summaryCache = new Map();

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

  function formatDate(value) {
    if (!value || typeof value !== 'string') {
      return '';
    }
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
      return value;
    }
    return date.toLocaleString();
  }

  function buildUrl(endpoint, params) {
    try {
      const url = new URL(endpoint, window.location.href);
      Object.entries(params).forEach(([key, val]) => {
        if (typeof val !== 'undefined' && val !== null) {
          url.searchParams.set(key, String(val));
        }
      });
      return url.toString();
    } catch (error) {
      const query = new URLSearchParams(params).toString();
      if (!endpoint) {
        return `?${query}`;
      }
      return endpoint.includes('?') ? `${endpoint}&${query}` : `${endpoint}?${query}`;
    }
  }

  function toggleLoading(isLoading) {
    if (!form) {
      return;
    }
    form.classList.toggle('is-loading', Boolean(isLoading));
    const submit = form.querySelector('button[type="submit"]');
    if (submit) {
      submit.disabled = Boolean(isLoading);
    }
  }

  function updateStatus(message, isError) {
    if (!statusEl) {
      return;
    }
    if (!message) {
      statusEl.textContent = '';
      statusEl.className = 'status';
      return;
    }
    statusEl.textContent = message;
    statusEl.className = isError ? 'status error' : 'status';
  }

  function updateMetrics(summary, sources, updatedAt) {
    if (!metricsEl) {
      return;
    }

    const hasSummary = summary && typeof summary === 'object' && Object.keys(summary).length > 0;
    const sourceCount = Array.isArray(sources) ? sources.length : 0;

    if (!hasSummary && sourceCount === 0) {
      metricsEl.hidden = true;
      return;
    }

    metricsEl.hidden = false;

    if (metricDocuments) {
      metricDocuments.textContent = formatNumber(summary && summary.documents_processed ? summary.documents_processed : 0);
    }
    if (metricTriples) {
      metricTriples.textContent = formatNumber(summary && summary.triples ? summary.triples : 0);
    }
    if (metricEntities) {
      metricEntities.textContent = formatNumber(summary && summary.unique_entities ? summary.unique_entities : 0);
    }
    if (metricSynonyms) {
      const count = summary && summary.synonym_groups ? summary.synonym_groups : 0;
      metricSynonyms.textContent = `Synonym groups: ${formatNumber(count)}`;
    }
    if (metricSources) {
      metricSources.textContent = `Sources tracked: ${formatNumber(sourceCount)}`;
    }
    if (metricUpdated) {
      const updatedText = updatedAt ? `Updated ${formatDate(updatedAt)}` : (summary && summary.generated_at ? `Generated ${formatDate(summary.generated_at)}` : '');
      metricUpdated.textContent = updatedText || '';
    }
  }

  function clearContainer(container) {
    if (container) {
      container.innerHTML = '';
    }
  }

  function renderEntities(entities) {
    if (!entitiesContainer || !entitiesEmpty) {
      return;
    }

    clearContainer(entitiesContainer);
    let hasEntities = Array.isArray(entities) && entities.length > 0;

    if (!hasEntities) {
      entitiesEmpty.hidden = false;
      return;
    }

    entitiesEmpty.hidden = true;

    entities.slice(0, 24).forEach((entity) => {
      const name = entity && entity.entity ? String(entity.entity) : '';
      if (!name) {
        return;
      }

      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'entity-chip';
      button.dataset.entity = name;

      const score = typeof entity.score === 'number' ? entity.score : 0;
      const synonyms = Array.isArray(entity.synonyms) ? entity.synonyms.filter(Boolean) : [];
      const factCount = typeof entity.fact_count === 'number' ? entity.fact_count : (entity.summary && entity.summary.fact_count) || 0;

      const nameSpan = document.createElement('span');
      nameSpan.className = 'entity-chip__name';
      nameSpan.textContent = name;
      button.appendChild(nameSpan);

      if (score > 0) {
        const scoreSpan = document.createElement('span');
        scoreSpan.className = 'entity-chip__score';
        scoreSpan.textContent = `Match confidence ${formatPercent(score)}`;
        button.appendChild(scoreSpan);
      }

      if (synonyms.length > 0) {
        const metaSpan = document.createElement('span');
        metaSpan.className = 'entity-chip__meta';
        metaSpan.textContent = `Synonyms: ${synonyms.join(', ')}`;
        button.appendChild(metaSpan);
      }

      if (factCount > 0) {
        const factsSpan = document.createElement('span');
        factsSpan.className = 'entity-chip__signals';
        factsSpan.textContent = `Facts indexed: ${formatNumber(factCount)}`;
        button.appendChild(factsSpan);
      }

      if (entity.summary) {
        summaryCache.set(name.toLowerCase(), entity.summary);
      }

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

    const rows = Array.isArray(relations) ? relations.slice(0, 16) : [];
    if (rows.length === 0) {
      relationsEmpty.hidden = false;
      return;
    }

    relationsEmpty.hidden = true;

    rows.forEach((row) => {
      const relation = row && (row.relation || row.label);
      if (!relation) {
        return;
      }
      const li = document.createElement('li');
      const label = document.createElement('span');
      label.className = 'label';
      label.textContent = String(relation);
      li.appendChild(label);

      if (typeof row.count !== 'undefined') {
        const value = document.createElement('span');
        value.className = 'value';
        value.textContent = formatNumber(row.count);
        li.appendChild(value);
      }

      relationsContainer.appendChild(li);
    });
  }

  function renderSynonyms(synonyms) {
    if (!synonymsContainer || !synonymsEmpty) {
      return;
    }
    clearContainer(synonymsContainer);

    const rows = Array.isArray(synonyms) ? synonyms.slice(0, 16) : [];
    if (rows.length === 0) {
      synonymsEmpty.hidden = false;
      return;
    }

    synonymsEmpty.hidden = true;

    rows.forEach((row) => {
      const entity = row && row.entity ? String(row.entity) : '';
      const list = Array.isArray(row.synonyms) ? row.synonyms.filter(Boolean) : [];
      if (!entity || list.length === 0) {
        return;
      }
      const li = document.createElement('li');
      const label = document.createElement('span');
      label.className = 'label';
      label.textContent = entity;
      li.appendChild(label);

      const value = document.createElement('span');
      value.className = 'value';
      value.textContent = list.join(', ');
      li.appendChild(value);

      synonymsContainer.appendChild(li);
    });
  }

  function renderTriples(triples) {
    if (!triplesTable || !triplesEmpty) {
      return;
    }
    const tbody = triplesTable.querySelector('tbody');
    if (!tbody) {
      return;
    }
    tbody.innerHTML = '';

    const rows = Array.isArray(triples) ? triples.slice(0, 30) : [];
    if (rows.length === 0) {
      triplesEmpty.hidden = false;
      return;
    }

    triplesEmpty.hidden = true;

    rows.forEach((row) => {
      const tr = document.createElement('tr');
      const subject = document.createElement('td');
      subject.textContent = row && row.subject ? String(row.subject) : '';
      tr.appendChild(subject);

      const relation = document.createElement('td');
      relation.textContent = row && row.relation ? String(row.relation) : '';
      tr.appendChild(relation);

      const object = document.createElement('td');
      object.textContent = row && row.object ? String(row.object) : '';
      tr.appendChild(object);

      tbody.appendChild(tr);
    });
  }

  function renderSources(sources) {
    if (!sourcesContainer || !sourcesEmpty) {
      return;
    }
    clearContainer(sourcesContainer);

    const rows = Array.isArray(sources) ? sources.slice(0, 16) : [];
    if (rows.length === 0) {
      sourcesEmpty.hidden = false;
      return;
    }

    sourcesEmpty.hidden = true;

    rows.forEach((row) => {
      const title = row && typeof row.title === 'string' && row.title.trim() !== '' ? row.title : (row && row.url ? row.url : '');
      const url = row && typeof row.url === 'string' ? row.url : '';
      const lastSeen = row && typeof row.last_seen === 'string' ? row.last_seen : '';

      if (!title && !url) {
        return;
      }

      const li = document.createElement('li');
      let label;
      if (url) {
        label = document.createElement('a');
        label.href = url;
        label.target = '_blank';
        label.rel = 'noopener';
        label.className = 'label';
        label.textContent = title || url;
      } else {
        label = document.createElement('span');
        label.className = 'label';
        label.textContent = title;
      }
      li.appendChild(label);

      if (lastSeen) {
        const value = document.createElement('span');
        value.className = 'value';
        value.textContent = `Seen ${formatDate(lastSeen)}`;
        li.appendChild(value);
      }

      sourcesContainer.appendChild(li);
    });
  }

  function renderEntityDetail(summary, meta) {
    if (!detailCard || !detailName || !detailFacts || !detailRelations || !detailSynonyms || !detailScore) {
      return;
    }

    if (!summary) {
      detailCard.hidden = true;
      return;
    }

    detailCard.hidden = false;
    detailName.textContent = summary.entity || (meta && meta.entity) || '';
    const score = typeof summary.score === 'number' ? summary.score : (meta && typeof meta.score === 'number' ? meta.score : 0);
    detailScore.textContent = score > 0 ? `Confidence ${formatPercent(score)}` : '';

    detailSynonyms.innerHTML = '';
    const synonyms = Array.isArray(summary.synonyms) ? summary.synonyms.filter(Boolean) : [];
    if (synonyms.length > 0) {
      detailSynonyms.hidden = false;
      synonyms.slice(0, 16).forEach((name) => {
        const pill = document.createElement('span');
        pill.className = 'pill';
        pill.textContent = name;
        detailSynonyms.appendChild(pill);
      });
    } else {
      detailSynonyms.hidden = true;
    }

    if (detailFactsWrap) {
      detailFactsWrap.hidden = false;
    }
    detailFacts.innerHTML = '';
    const facts = Array.isArray(summary.facts) ? summary.facts.slice(0, 20) : [];
    if (facts.length === 0) {
      if (detailFactsWrap) {
        detailFactsWrap.hidden = true;
      }
    } else {
      facts.forEach((fact) => {
        const li = document.createElement('li');
        const direction = fact && fact.direction ? String(fact.direction) : '';
        const relation = fact && fact.relation ? String(fact.relation) : '';
        const counterpart = fact && fact.counterpart ? String(fact.counterpart) : '';

        const headline = document.createElement('strong');
        headline.textContent = counterpart || relation || '';
        li.appendChild(headline);

        const metaSpan = document.createElement('span');
        const relationText = relation ? `${direction ? direction + ' ' : ''}${relation}` : direction;
        metaSpan.textContent = relationText ? relationText : '';
        if (metaSpan.textContent && counterpart) {
          metaSpan.textContent = `${metaSpan.textContent} · ${counterpart}`;
        }
        li.appendChild(metaSpan);

        detailFacts.appendChild(li);
      });
    }

    if (detailRelationsWrap) {
      detailRelationsWrap.hidden = false;
    }
    detailRelations.innerHTML = '';
    const relationCounts = summary.relation_counts || {};
    const entries = Object.entries(relationCounts).sort(([, a], [, b]) => Number(b) - Number(a)).slice(0, 10);
    if (entries.length === 0) {
      if (detailRelationsWrap) {
        detailRelationsWrap.hidden = true;
      }
    } else {
      entries.forEach(([relation, count]) => {
        const li = document.createElement('li');
        const title = document.createElement('strong');
        title.textContent = relation;
        li.appendChild(title);

        const value = document.createElement('span');
        value.textContent = `${formatNumber(count)} supporting facts`;
        li.appendChild(value);

        detailRelations.appendChild(li);
      });
    }
  }

  async function loadEntitySummary(name, fallbackSummary, meta) {
    if (!name) {
      return;
    }
    const cacheKey = name.toLowerCase();
    const cached = summaryCache.get(cacheKey);
    if (cached) {
      renderEntityDetail(cached, meta);
      return;
    }

    if (fallbackSummary) {
      summaryCache.set(cacheKey, fallbackSummary);
      renderEntityDetail(fallbackSummary, meta);
      return;
    }

    if (!summaryEndpoint) {
      return;
    }

    updateStatus(`Loading details for “${name}”…`);

    try {
      const url = buildUrl(summaryEndpoint, { action: 'summary', entity: name });
      const response = await fetch(url, { headers: { Accept: 'application/json' } });
      if (!response.ok) {
        throw new Error('Failed to load entity summary.');
      }
      const payload = await response.json();
      const summary = payload && payload.data && payload.data.entity ? payload.data.entity : null;
      if (!summary) {
        throw new Error('No summary available for this entity.');
      }
      summaryCache.set(cacheKey, summary);
      renderEntityDetail(summary, meta);
      updateStatus(`Showing details for “${name}”.`);
    } catch (error) {
      updateStatus(error.message || 'Failed to load entity summary.', true);
    }
  }

  function renderSearch(search) {
    if (!search) {
      return;
    }

    if (resultsEl) {
      resultsEl.hidden = false;
    }

    updateMetrics(search.summary || {}, search.sources || [], search.updated_at || '');
    renderEntities(search.entities || []);
    renderRelations(search.relations || []);
    renderSynonyms(search.synonyms || []);
    renderTriples(search.triples || []);
    renderSources(search.sources || []);
  }

  function renderTrending(trending) {
    if (!trendingContainer || !trendingList) {
      return;
    }

    const items = Array.isArray(trending) ? trending.filter((row) => row && row.entity).slice(0, 8) : [];
    trendingList.innerHTML = '';

    if (items.length === 0) {
      trendingContainer.hidden = true;
      return;
    }

    trendingContainer.hidden = false;

    items.forEach((item) => {
      const name = String(item.entity);
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'chip';
      button.textContent = name;
      button.dataset.entity = name;
      button.addEventListener('click', () => {
        if (input) {
          input.value = name;
          input.focus();
        }
        performSearch(name);
      });
      trendingList.appendChild(button);
    });
  }

  async function performSearch(query) {
    if (!searchEndpoint) {
      return;
    }

    const trimmed = (query || '').trim();
    if (!trimmed) {
      updateStatus('Enter a query to search the knowledge graph.');
      return;
    }

    toggleLoading(true);
    updateStatus(`Searching for “${trimmed}”…`);

    try {
      const url = buildUrl(searchEndpoint, { action: 'search', q: trimmed, limit: 24 });
      const response = await fetch(url, { headers: { Accept: 'application/json' } });
      if (!response.ok) {
        throw new Error('Search request failed.');
      }
      const payload = await response.json();
      if (payload.error) {
        throw new Error(String(payload.error));
      }
      const search = payload && payload.data && payload.data.search ? payload.data.search : null;
      if (!search) {
        throw new Error('Unexpected search response.');
      }

      renderSearch(search);

      const autopilot = window.AIAutopilot || null;
      if (autopilot) {
        if (typeof autopilot.generate === 'function') {
          autopilot.generate(trimmed);
        } else if (typeof autopilot.setQuery === 'function') {
          autopilot.setQuery(trimmed);
        }
      }

      const matches = Array.isArray(search.entities) ? search.entities.length : 0;
      if (matches > 0) {
        updateStatus(`Found ${matches} ${matches === 1 ? 'match' : 'matches'} for “${trimmed}”.`);
      } else {
        updateStatus(`No direct entity matches for “${trimmed}”. Showing related relations, triples, and sources.`);
      }
    } catch (error) {
      updateStatus(error.message || 'Search failed.', true);
    } finally {
      toggleLoading(false);
    }
  }

  function bindEvents() {
    if (form) {
      form.addEventListener('submit', (event) => {
        event.preventDefault();
        performSearch(input ? input.value : '');
      });
    }
  }

  function bootstrap() {
    if (initialSearch) {
      renderSearch(initialSearch);
    }
    if (initialTop) {
      renderTrending(initialTop);
    }
    bindEvents();
  }

  bootstrap();
})();
