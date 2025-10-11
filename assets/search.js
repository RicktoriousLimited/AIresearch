(function () {
  const config = window.AISearch || {};
  const endpoints = config.endpoints || {};
  const initial = typeof config.initial === 'object' && config.initial !== null ? config.initial : {};

  const form = document.querySelector('[data-search-form]');
  const input = document.querySelector('[data-search-input]');
  const statusEl = document.querySelector('[data-search-status]');
  const resultsList = document.querySelector('[data-results]');
  const resultsEmpty = document.querySelector('[data-results-empty]');
  const resultsMeta = document.querySelector('[data-results-meta]');
  const factList = document.querySelector('[data-fact-list]');
  const factEmpty = document.querySelector('[data-fact-empty]');
  const insightTitle = document.querySelector('[data-insight-title]');
  const insightMeta = document.querySelector('[data-insight-meta]');
  const insightBody = document.querySelector('[data-insight-body]');
  const insightEmpty = document.querySelector('[data-insight-empty]');
  const entityList = document.querySelector('[data-entity-list]');
  const entityEmpty = document.querySelector('[data-entity-empty]');
  const sourceList = document.querySelector('[data-source-list]');
  const sourceEmpty = document.querySelector('[data-source-empty]');
  const trendingContainer = document.querySelector('[data-trending]');
  const trendingList = document.querySelector('[data-trending-list]');

  let currentRequestId = 0;

  function setStatus(message, tone) {
    if (!statusEl) {
      return;
    }

    statusEl.textContent = message || '';
    if (!tone) {
      statusEl.dataset.tone = '';
      return;
    }
    statusEl.dataset.tone = tone;
  }

  function setLoading(isLoading) {
    if (!form) {
      return;
    }
    form.classList.toggle('is-loading', Boolean(isLoading));
    if (input) {
      input.setAttribute('aria-busy', isLoading ? 'true' : 'false');
    }
  }

  function buildUrl(endpoint, params) {
    if (!endpoint) {
      return '';
    }
    try {
      const url = new URL(endpoint, window.location.href);
      Object.entries(params || {}).forEach(([key, value]) => {
        if (typeof value === 'undefined' || value === null || value === '') {
          return;
        }
        url.searchParams.set(key, String(value));
      });
      return url.toString();
    } catch (error) {
      const query = new URLSearchParams(params || {}).toString();
      return query ? `${endpoint}${endpoint.includes('?') ? '&' : '?'}${query}` : endpoint;
    }
  }

  function request(endpoint, params) {
    const url = buildUrl(endpoint, params);
    if (!url) {
      return Promise.resolve(null);
    }
    return fetch(url, {
      headers: { 'Accept': 'application/json' },
    }).then((response) => {
      if (!response.ok) {
        throw new Error(`Request failed with status ${response.status}`);
      }
      return response.json();
    }).catch((error) => {
      console.error('Search request failed:', error);
      throw error;
    });
  }

  function normaliseArray(value) {
    return Array.isArray(value) ? value : [];
  }

  function collectEntityNames(entities) {
    const seen = new Set();
    const names = [];
    normaliseArray(entities).forEach((entity) => {
      let name = '';
      if (entity && typeof entity === 'object' && typeof entity.entity === 'string') {
        name = entity.entity;
      } else if (typeof entity === 'string') {
        name = entity;
      }
      const trimmed = name.trim();
      if (trimmed && !seen.has(trimmed.toLowerCase())) {
        seen.add(trimmed.toLowerCase());
        names.push(trimmed);
      }
    });
    return names;
  }

  function formatPercent(value) {
    if (typeof value === 'number' && Number.isFinite(value)) {
      return `${Math.round(value * 100)}%`;
    }
    const numeric = Number(value);
    if (Number.isFinite(numeric)) {
      return `${Math.round(numeric * 100)}%`;
    }
    return '';
  }

  function formatDate(value) {
    if (!value || typeof value !== 'string') {
      return '';
    }
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
      return value;
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

  function renderTrending(items) {
    if (!trendingContainer || !trendingList) {
      return;
    }

    const names = collectEntityNames(items);
    trendingList.innerHTML = '';

    const suggestions = names.length > 0
      ? names
      : normaliseArray(initial.trending);

    if (!suggestions || suggestions.length === 0) {
      trendingContainer.hidden = true;
      return;
    }

    trendingContainer.hidden = false;

    suggestions.slice(0, 12).forEach((label) => {
      const chip = document.createElement('button');
      chip.type = 'button';
      chip.className = 'chip';
      chip.textContent = label;
      chip.addEventListener('click', () => {
        if (input) {
          input.value = label;
          input.focus();
        }
        performSearch(label);
      });
      trendingList.appendChild(chip);
    });
  }

  function renderResults(search) {
    if (!resultsList) {
      return;
    }

    const entities = search && Array.isArray(search.entities) ? search.entities : [];
    resultsList.innerHTML = '';

    if (entities.length === 0) {
      if (resultsEmpty) {
        resultsEmpty.hidden = false;
      }
      if (resultsMeta) {
        resultsMeta.textContent = 'No graph entities matched yet. Add more data or try a different query.';
      }
      return;
    }

    if (resultsEmpty) {
      resultsEmpty.hidden = true;
    }

    if (resultsMeta) {
      const total = typeof search.total === 'number' ? search.total : entities.length;
      const query = typeof search.query === 'string' && search.query.trim() !== ''
        ? `“${search.query.trim()}”`
        : 'your corpus';
      resultsMeta.textContent = `Showing ${total} graph-backed matches for ${query}.`;
    }

    entities.forEach((entity) => {
      if (!entity || typeof entity !== 'object') {
        return;
      }
      const name = typeof entity.entity === 'string' ? entity.entity.trim() : '';
      if (!name) {
        return;
      }
      const facts = Array.isArray(entity.facts)
        ? entity.facts.filter((fact) => typeof fact === 'string' && fact.trim() !== '').slice(0, 6)
        : [];
      const synonyms = Array.isArray(entity.synonyms)
        ? entity.synonyms.filter((syn) => typeof syn === 'string' && syn.trim() !== '').slice(0, 3)
        : [];
      const matchedSynonym = typeof entity.matched_synonym === 'string' ? entity.matched_synonym.trim() : '';
      const matchedFact = typeof entity.matched_fact === 'string' ? entity.matched_fact.trim() : '';
      const score = typeof entity.score === 'number' ? entity.score : null;

      const item = document.createElement('li');
      item.className = 'result-card';

      const heading = document.createElement('h2');
      heading.className = 'result-card__headline';
      heading.textContent = name;
      item.appendChild(heading);

      const metaParts = [];
      if (matchedSynonym) {
        metaParts.push(`Matched synonym: ${matchedSynonym}`);
      }
      if (matchedFact) {
        metaParts.push(`Context: ${matchedFact}`);
      }
      if (score !== null && Number.isFinite(score)) {
        metaParts.push(`Relevance ${formatPercent(score)}`);
      }
      if (synonyms.length > 0 && !matchedSynonym) {
        metaParts.push(`Related terms: ${synonyms.join(', ')}`);
      }

      if (metaParts.length > 0) {
        const meta = document.createElement('p');
        meta.className = 'result-card__meta';
        meta.textContent = metaParts.join(' · ');
        item.appendChild(meta);
      }

      if (facts.length > 0) {
        const list = document.createElement('ul');
        list.className = 'result-card__facts';
        facts.forEach((fact) => {
          const li = document.createElement('li');
          li.textContent = fact;
          list.appendChild(li);
        });
        item.appendChild(list);
      }

      resultsList.appendChild(item);
    });
  }

  function renderFacts(search) {
    if (!factList) {
      return;
    }
    factList.innerHTML = '';

    const triples = search && Array.isArray(search.triples) ? search.triples : [];
    if (triples.length === 0) {
      if (factEmpty) {
        factEmpty.hidden = false;
      }
      return;
    }

    if (factEmpty) {
      factEmpty.hidden = true;
    }

    triples.slice(0, 12).forEach((triple) => {
      if (!triple || typeof triple !== 'object') {
        return;
      }
      const subject = typeof triple.subject === 'string' ? triple.subject : '';
      const relation = typeof triple.relation === 'string' ? triple.relation : '';
      const object = typeof triple.object === 'string' ? triple.object : '';
      if (!subject && !relation && !object) {
        return;
      }

      const item = document.createElement('li');
      item.className = 'fact-panel__item';

      const relationLine = document.createElement('span');
      relationLine.className = 'fact-panel__relation';
      relationLine.textContent = relation || 'Relationship';
      item.appendChild(relationLine);

      const detail = document.createElement('span');
      detail.textContent = `${subject || 'Unknown'} → ${object || 'Unknown'}`;
      item.appendChild(detail);

      const score = typeof triple.score === 'number' ? triple.score : null;
      if (score !== null && Number.isFinite(score)) {
        const meta = document.createElement('span');
        meta.className = 'fact-panel__meta';
        meta.textContent = `Match confidence ${formatPercent(score)}`;
        item.appendChild(meta);
      }

      factList.appendChild(item);
    });
  }

  function renderInsight(insight, report) {
    if (!insightBody) {
      return;
    }

    const documentData = insight && typeof insight === 'object' && insight.document && typeof insight.document === 'object'
      ? insight.document
      : null;
    const sections = documentData && Array.isArray(documentData.sections) ? documentData.sections : [];
    const title = documentData && typeof documentData.title === 'string' ? documentData.title.trim() : '';
    const query = insight && typeof insight.query === 'string' ? insight.query.trim() : '';
    const generatedAt = insight && typeof insight.generated_at === 'string' ? insight.generated_at : '';
    const docCount = report && typeof report.document_count === 'number' ? report.document_count : 0;

    if (insightTitle) {
      insightTitle.textContent = title || 'Insight briefing';
    }

    if (insightMeta) {
      const parts = [];
      if (query) {
        parts.push(`Focus: “${query}”`);
      }
      if (Number.isFinite(docCount) && docCount > 0) {
        parts.push(`${docCount} sources analysed`);
      }
      const formatted = formatDate(generatedAt);
      if (formatted) {
        parts.push(`Generated ${formatted}`);
      }
      insightMeta.textContent = parts.join(' · ');
    }

    insightBody.innerHTML = '';

    const hasSections = sections.length > 0;
    sections.forEach((section) => {
      if (!section || typeof section !== 'object') {
        return;
      }
      const heading = typeof section.heading === 'string' ? section.heading.trim() : '';
      const items = Array.isArray(section.items) ? section.items : [];
      if (items.length === 0) {
        return;
      }
      const wrapper = document.createElement('div');
      wrapper.className = 'insight-card__section';
      if (heading) {
        const headerEl = document.createElement('h3');
        headerEl.className = 'insight-card__section-title';
        headerEl.textContent = heading;
        wrapper.appendChild(headerEl);
      }
      const list = document.createElement('ul');
      list.className = 'insight-card__list';
      items.slice(0, 6).forEach((itemText) => {
        if (typeof itemText !== 'string' || itemText.trim() === '') {
          return;
        }
        const li = document.createElement('li');
        li.textContent = itemText.trim();
        list.appendChild(li);
      });
      if (list.children.length > 0) {
        wrapper.appendChild(list);
        insightBody.appendChild(wrapper);
      }
    });

    if (!hasSections || insightBody.children.length === 0) {
      if (insightEmpty) {
        insightEmpty.hidden = false;
      }
    } else if (insightEmpty) {
      insightEmpty.hidden = true;
    }
  }

  function renderEntitiesSidebar(search) {
    if (!entityList) {
      return;
    }
    entityList.innerHTML = '';
    const entities = search && Array.isArray(search.entities) ? search.entities.slice(0, 8) : [];

    if (entities.length === 0) {
      if (entityEmpty) {
        entityEmpty.hidden = false;
      }
      return;
    }

    if (entityEmpty) {
      entityEmpty.hidden = true;
    }

    entities.forEach((entity) => {
      if (!entity || typeof entity !== 'object') {
        return;
      }
      const name = typeof entity.entity === 'string' ? entity.entity.trim() : '';
      if (!name) {
        return;
      }
      const li = document.createElement('li');
      const title = document.createElement('span');
      title.textContent = name;
      title.style.fontWeight = '600';
      li.appendChild(title);
      if (Array.isArray(entity.synonyms) && entity.synonyms.length > 0) {
        const meta = document.createElement('span');
        meta.textContent = `Also known as ${entity.synonyms.slice(0, 3).join(', ')}`;
        li.appendChild(meta);
      } else if (typeof entity.fact_count === 'number' && entity.fact_count > 0) {
        const meta = document.createElement('span');
        meta.textContent = `${entity.fact_count} related facts`;
        li.appendChild(meta);
      }
      entityList.appendChild(li);
    });
  }

  function renderSources(sources) {
    if (!sourceList) {
      return;
    }
    sourceList.innerHTML = '';
    const list = normaliseArray(sources).slice(0, 6);

    if (list.length === 0) {
      if (sourceEmpty) {
        sourceEmpty.hidden = false;
      }
      return;
    }

    if (sourceEmpty) {
      sourceEmpty.hidden = true;
    }

    list.forEach((source) => {
      if (!source || typeof source !== 'object') {
        return;
      }
      const title = typeof source.title === 'string' ? source.title.trim() : '';
      const url = typeof source.url === 'string' ? source.url.trim() : '';
      const preview = typeof source.preview === 'string' ? source.preview.trim() : '';
      const li = document.createElement('li');
      if (url) {
        const link = document.createElement('a');
        link.href = url;
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
        link.textContent = title || url;
        li.appendChild(link);
      } else if (title) {
        const span = document.createElement('span');
        span.textContent = title;
        li.appendChild(span);
      }
      if (preview) {
        const meta = document.createElement('span');
        meta.textContent = preview;
        li.appendChild(meta);
      }
      sourceList.appendChild(li);
    });
  }

  function updateView(payload) {
    if (!payload) {
      return;
    }
    const search = payload.search || {};
    const insight = payload.insight || {};
    const report = payload.report || {};

    renderResults(search);
    renderFacts(search);
    renderInsight(insight, report);
    renderEntitiesSidebar(search);

    const sources = payload.sources
      || (insight && insight.references && Array.isArray(insight.references.sources)
        ? insight.references.sources
        : (search && Array.isArray(search.sources) ? search.sources : []));
    renderSources(sources);

    const trendingCandidates = collectEntityNames(search.entities)
      .concat(collectEntityNames(report.entities))
      .concat(normaliseArray(payload.trending));
    renderTrending(trendingCandidates);
  }

  function performSearch(value) {
    const query = typeof value === 'string' ? value.trim() : (input ? input.value.trim() : '');
    if (query === '' && input) {
      input.value = '';
    }

    if (!endpoints.search) {
      setStatus('Search endpoint is not configured.', 'error');
      return;
    }

    const requestId = ++currentRequestId;
    setLoading(true);
    setStatus(query ? `Searching for “${query}”…` : 'Retrieving the latest intelligence…', 'info');

    const params = { q: query, limit: query ? 12 : 18 };

    Promise.all([
      request(endpoints.search, params),
      request(endpoints.insight, { q: query, limit: 6 }),
      request(endpoints.report, { q: query, limit: 6 }),
    ]).then((responses) => {
      if (requestId !== currentRequestId) {
        return;
      }
      const searchData = responses[0] && responses[0].data && responses[0].data.search ? responses[0].data.search : {};
      const insightData = responses[1] && responses[1].data && responses[1].data.insight ? responses[1].data.insight : {};
      const reportData = responses[2] && responses[2].data && responses[2].data.report ? responses[2].data.report : {};

      const combined = {
        search: searchData,
        insight: insightData,
        report: reportData,
        sources: insightData && insightData.references && Array.isArray(insightData.references.sources)
          ? insightData.references.sources
          : (searchData && Array.isArray(searchData.sources) ? searchData.sources : []),
      };

      updateView(combined);

      const entityCount = Array.isArray(searchData.entities) ? searchData.entities.length : 0;
      const factCount = Array.isArray(searchData.triples) ? searchData.triples.length : 0;
      if (entityCount + factCount === 0) {
        setStatus('No graph matches yet. Try a broader phrase or connect additional sources.', 'warning');
      } else {
        setStatus(`Updated results for ${query ? `“${query}”` : 'your corpus'}.`, 'success');
      }
    }).catch(() => {
      if (requestId !== currentRequestId) {
        return;
      }
      setStatus('We couldn’t complete that search. Please try again in a moment.', 'error');
    }).finally(() => {
      if (requestId === currentRequestId) {
        setLoading(false);
      }
    });
  }

  if (form) {
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      performSearch();
    });
  }

  updateView({
    search: initial.search || {},
    insight: initial.insight || {},
    report: initial.report || {},
    sources: initial.sources || [],
    trending: initial.trending || [],
  });
  renderTrending(initial.trending || []);

  setStatus('Ready to search your knowledge graph.', 'info');
})();
