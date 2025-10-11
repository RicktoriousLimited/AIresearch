(function () {
  const config = window.AISearch || {};
  const endpoints = config.endpoints || {};
  const insightEndpoint = endpoints.insight || endpoints.search || '';
  const searchEndpoint = endpoints.search || '';
  const reportEndpoint = endpoints.report || searchEndpoint;
  const initial = config.initial || {};
  const initialInsight = initial && typeof initial.insight === 'object' ? initial.insight : null;
  const initialReport = initialInsight && typeof initialInsight.report === 'object'
    ? initialInsight.report
    : (initial && typeof initial.report === 'object' ? initial.report : null);
  const initialSearchState = initialInsight && typeof initialInsight.search === 'object'
    ? initialInsight.search
    : (initial && typeof initial.search === 'object' ? initial.search : null);
  const initialEntities = initialSearchState && Array.isArray(initialSearchState.entities)
    ? initialSearchState.entities
    : (Array.isArray(initial.entities) ? initial.entities : []);
  const initialTop = Array.isArray(initial.top) ? initial.top : [];
  const initialSources = initialInsight && initialInsight.references && Array.isArray(initialInsight.references.sources)
    ? initialInsight.references.sources
    : (initialSearchState && Array.isArray(initialSearchState.sources)
        ? initialSearchState.sources
        : (Array.isArray(initial.sources) ? initial.sources : []));

  const form = document.querySelector('[data-search-form]');
  const input = document.querySelector('[data-search-input]');
  const statusEl = document.querySelector('[data-search-status]');
  const insightSection = document.querySelector('[data-insight]');
  const insightTitle = document.querySelector('[data-insight-title]');
  const insightMeta = document.querySelector('[data-insight-meta]');
  const insightBody = document.querySelector('[data-insight-body]');
  const insightEmpty = document.querySelector('[data-insight-empty]');
  const insightEntitiesList = document.querySelector('[data-insight-entities]');
  const insightEntitiesEmpty = document.querySelector('[data-insight-entities-empty]');
  const insightReferencesList = document.querySelector('[data-insight-references]');
  const insightReferencesEmpty = document.querySelector('[data-insight-references-empty]');
  const trendingContainer = document.querySelector('[data-trending]');
  const trendingList = document.querySelector('[data-trending-list]');
  const briefSection = document.querySelector('[data-brief]');
  const briefMeta = document.querySelector('[data-brief-meta]');
  const briefList = document.querySelector('[data-brief-list]');
  const briefEmpty = document.querySelector('[data-brief-empty]');
  const resultsSection = document.querySelector('[data-results-section]');
  const resultsList = document.querySelector('[data-results]');
  const resultsMeta = document.querySelector('[data-results-meta]');
  const resultsEmpty = document.querySelector('[data-results-empty]');
  const filterPanel = document.querySelector('[data-filter-panel]');
  const filterGroups = document.querySelectorAll('[data-filter-group]');
  const filterSummary = document.querySelector('[data-filter-summary]');
  const metricNodes = document.querySelectorAll('[data-metric]');
  const watchlistList = document.querySelector('[data-watchlist-list]');
  const watchlistEmpty = document.querySelector('.news-search__watchlist-empty');
  const watchlistHint = document.querySelector('.news-search__watchlist-hint');
  const sourceList = document.querySelector('[data-source-list]');
  const templateButtons = document.querySelectorAll('[data-search-template]');
  const workspaceButtons = document.querySelectorAll('[data-workspace-action]');

  const initialEntityNames = collectNames(initialEntities);
  const defaultTrending = collectNames(initialEntities.concat(initialTop));

  let debounceTimer = null;
  let currentRequestId = 0;
  const filterState = {};
  const metricsMap = {};

  metricNodes.forEach((node) => {
    const key = node.dataset.metric;
    if (key) {
      metricsMap[key] = node;
    }
  });

  const numberFormatter = new Intl.NumberFormat(undefined, { maximumFractionDigits: 0 });

  function collectNames(list) {
    const names = [];
    const seen = new Set();

    if (!Array.isArray(list)) {
      return names;
    }

    list.forEach((item) => {
      let name = '';
      if (item && typeof item === 'object') {
        if (typeof item.entity === 'string') {
          name = item.entity;
        } else if (typeof item.name === 'string') {
          name = item.name;
        }
      } else if (typeof item === 'string') {
        name = item;
      }

      const trimmed = name.trim();
      if (trimmed && !seen.has(trimmed.toLowerCase())) {
        seen.add(trimmed.toLowerCase());
        names.push(trimmed);
      }
    });

    return names;
  }

  function formatNumber(value) {
    const numeric = typeof value === 'number' ? value : Number(value || 0);
    if (!Number.isFinite(numeric)) {
      return '0';
    }
    return numberFormatter.format(numeric);
  }

  function formatPercent(value) {
    const numeric = typeof value === 'number' ? value : Number(value || 0);
    if (!Number.isFinite(numeric)) {
      return '0%';
    }
    return `${Math.round(Math.max(0, numeric) * 100)}%`;
  }

  function formatDate(value) {
    if (!value || typeof value !== 'string') {
      return '';
    }
    const date = new Date(value);
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

  function formatRelative(value) {
    if (!value || typeof value !== 'string') {
      return '';
    }
    const date = new Date(value);
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

  function extractHost(url) {
    if (!url || typeof url !== 'string') {
      return '';
    }
    try {
      const parsed = new URL(url);
      return parsed.hostname.replace(/^www\./i, '');
    } catch (error) {
      return '';
    }
  }

  function buildUrl(endpoint, params) {
    try {
      const url = new URL(endpoint, window.location.href);
      Object.entries(params).forEach(([key, val]) => {
        if (typeof val !== 'undefined' && val !== null && val !== '') {
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

  function getFilterParams() {
    return {
      timeframe: filterState.timeframe || '',
      signal: filterState.signal || '',
      sentiment: filterState.sentiment || '',
    };
  }

  function updateFilterSummary() {
    if (!filterSummary) {
      return;
    }

    const parts = [];
    filterGroups.forEach((group) => {
      const active = group.querySelector('.news-filter__chip.is-active');
      if (active) {
        parts.push(active.textContent.trim());
      }
    });

    if (parts.length > 0) {
      filterSummary.textContent = `Filtering ${parts.join(' · ')}`;
    } else {
      filterSummary.textContent = '';
    }
  }

  function renderWatchlist(entities) {
    if (!watchlistList) {
      return;
    }

    const names = collectNames(Array.isArray(entities) ? entities : []).slice(0, 8);
    watchlistList.innerHTML = '';

    if (names.length === 0) {
      if (watchlistEmpty) {
        watchlistEmpty.hidden = false;
      }
      if (watchlistHint) {
        watchlistHint.hidden = true;
      }
      return;
    }

    names.forEach((name) => {
      const li = document.createElement('li');
      const button = document.createElement('button');
      button.type = 'button';
      button.dataset.searchTemplate = name;
      button.textContent = name;
      button.addEventListener('click', () => {
        if (input) {
          input.value = name;
          input.focus();
        }
        performSearch(name);
      });
      li.appendChild(button);
      watchlistList.appendChild(li);
    });

    if (watchlistEmpty) {
      watchlistEmpty.hidden = true;
    }
    if (watchlistHint) {
      watchlistHint.hidden = false;
    }
  }

  function renderSources(sources) {
    if (!sourceList) {
      return;
    }

    sourceList.innerHTML = '';
    const list = Array.isArray(sources) ? sources.slice(0, 5) : [];

    list.forEach((item) => {
      if (!item || typeof item !== 'object') {
        return;
      }
      const title = item.title && typeof item.title === 'string' ? item.title.trim() : '';
      const url = item.url && typeof item.url === 'string' ? item.url.trim() : '';
      const host = extractHost(url);
      const label = title || host || 'Source';
      const timestamp = item.last_seen || item.fetched_at || '';
      const li = document.createElement('li');
      const name = document.createElement('span');
      name.className = 'news-sidecard__source-title';
      name.title = label;
      name.textContent = label;
      li.appendChild(name);
      if (timestamp) {
        const meta = document.createElement('span');
        meta.className = 'news-sidecard__source-meta';
        const formatted = formatDate(timestamp);
        meta.textContent = formatted || timestamp;
        li.appendChild(meta);
      }
      sourceList.appendChild(li);
    });
  }

  function updateToolbar(report, fallbackSources, entities) {
    const highlights = report && Array.isArray(report.highlights) ? report.highlights.length : 0;
    const docs = report && typeof report.document_count === 'number' ? report.document_count : 0;
    const names = collectNames(Array.isArray(entities) ? entities : []);
    const updatedAt = report && typeof report.generated_at === 'string' ? report.generated_at : '';

    if (metricsMap.highlights) {
      metricsMap.highlights.textContent = formatNumber(highlights);
    }
    if (metricsMap.sources) {
      metricsMap.sources.textContent = formatNumber(docs);
    }
    if (metricsMap.entities) {
      const count = names.length > 0 ? names.length : initialEntityNames.length;
      metricsMap.entities.textContent = formatNumber(count);
    }
    if (metricsMap.updated) {
      if (updatedAt) {
        const formatted = formatDate(updatedAt);
        const relative = formatRelative(updatedAt);
        metricsMap.updated.textContent = formatted ? (relative ? `${formatted} (${relative})` : formatted) : updatedAt;
      } else {
        metricsMap.updated.textContent = 'Not yet generated';
      }
    }

    renderSources(fallbackSources);
    renderWatchlist(names.length > 0 ? names : initialEntityNames);
  }

  function setStatus(message, tone = 'info') {
    if (!statusEl) {
      return;
    }

    const allowed = ['info', 'success', 'warning', 'error'];
    const state = allowed.includes(tone) ? tone : 'info';
    statusEl.textContent = message || '';
    statusEl.className = `news-search__status news-search__status--${state}`;
  }

  function setLoading(isLoading) {
    if (form) {
      form.classList.toggle('is-loading', Boolean(isLoading));
    }
    if (input) {
      input.setAttribute('aria-busy', isLoading ? 'true' : 'false');
    }
  }

  function renderTrending(entities) {
    if (!trendingContainer || !trendingList) {
      return;
    }

    const names = collectNames(Array.isArray(entities) ? entities : []);
    const items = names.length > 0 ? names : defaultTrending;

    trendingList.innerHTML = '';

    if (items.length === 0) {
      trendingContainer.hidden = true;
      return;
    }

    trendingContainer.hidden = false;

    items.slice(0, 8).forEach((name) => {
      const chip = document.createElement('button');
      chip.type = 'button';
      chip.className = 'news-search__chip';
      chip.dataset.entity = name;
      chip.textContent = name;
      chip.addEventListener('click', () => {
        if (input) {
          input.value = name;
          input.focus();
        }
        performSearch(name);
      });
      trendingList.appendChild(chip);
    });
  }

  function renderInsight(insight) {
    if (!insightSection) {
      return;
    }

    const hasInsight = insight && typeof insight === 'object';
    const documentData = hasInsight && insight.document && typeof insight.document === 'object' ? insight.document : null;
    const sections = documentData && Array.isArray(documentData.sections) ? documentData.sections : [];
    const entities = hasInsight && Array.isArray(insight.entities) ? insight.entities : [];
    const references = hasInsight && insight.references && typeof insight.references === 'object' ? insight.references : {};
    const title = documentData && typeof documentData.title === 'string' ? documentData.title.trim() : 'Insight briefing';
    const query = hasInsight && typeof insight.query === 'string' ? insight.query.trim() : '';
    const generatedAt = hasInsight && typeof insight.generated_at === 'string' ? insight.generated_at : '';
    const reportData = hasInsight && typeof insight.report === 'object' ? insight.report : null;
    const docCount = reportData && typeof reportData.document_count === 'number' ? reportData.document_count : 0;

    if (insightTitle) {
      insightTitle.textContent = title || 'Insight briefing';
    }

    if (insightMeta) {
      const parts = [];
      parts.push(query ? `Focus: “${query}”` : 'Focus: Latest coverage');
      if (Number.isFinite(docCount) && docCount > 0) {
        parts.push(`${formatNumber(docCount)} source${docCount === 1 ? '' : 's'}`);
      }
      if (generatedAt) {
        const formatted = formatDate(generatedAt);
        const relative = formatRelative(generatedAt);
        if (formatted) {
          parts.push(relative ? `${formatted} (${relative})` : formatted);
        }
      }
      insightMeta.textContent = parts.join(' · ');
    }

    renderInsightSections(sections);
    renderInsightEntities(entities);
    renderInsightReferences(references);
  }

  function renderInsightSections(sections) {
    if (!insightBody) {
      return;
    }

    insightBody.innerHTML = '';

    const list = Array.isArray(sections) ? sections : [];
    let hasContent = false;

    list.forEach((section) => {
      if (!section || typeof section !== 'object') {
        return;
      }

      const items = Array.isArray(section.items) ? section.items : [];
      if (items.length === 0) {
        return;
      }

      hasContent = true;

      const type = typeof section.type === 'string' ? section.type : 'bullets';
      const typeSlug = typeof type === 'string' ? type.replace(/[^a-z0-9_-]+/gi, '') : 'bullets';
      const heading = typeof section.heading === 'string' ? section.heading : '';
      const sectionEl = document.createElement('section');
      const className = `news-insight__section news-insight__section--${typeSlug}`;
      sectionEl.className = className;

      if (heading) {
        const headingEl = document.createElement('h3');
        headingEl.textContent = heading;
        sectionEl.appendChild(headingEl);
      }

      if (type === 'topics') {
        const listEl = document.createElement('ul');
        listEl.className = 'news-insight__topics';
        items.forEach((topic) => {
          if (!topic || typeof topic !== 'object') {
            return;
          }
          const label = typeof topic.label === 'string' ? topic.label.trim() : '';
          if (!label) {
            return;
          }
          const count = typeof topic.count === 'number' ? topic.count : Number(topic.count || 0);
          const citations = Array.isArray(topic.citations)
            ? topic.citations.filter(Boolean).map((value) => String(value)).slice(0, 6)
            : [];

          const itemEl = document.createElement('li');
          const labelEl = document.createElement('span');
          labelEl.className = 'news-insight__topic-label';
          labelEl.textContent = label;
          itemEl.appendChild(labelEl);

          if (Number.isFinite(count) && count > 0) {
            const countEl = document.createElement('span');
            countEl.className = 'news-insight__topic-count';
            countEl.textContent = `${formatNumber(count)} mention${count === 1 ? '' : 's'}`;
            itemEl.appendChild(countEl);
          }

          if (citations.length > 0) {
            const citeEl = document.createElement('span');
            citeEl.className = 'news-insight__topic-citations';
            citeEl.textContent = `Citations: ${citations.join(', ')}`;
            itemEl.appendChild(citeEl);
          }

          listEl.appendChild(itemEl);
        });
        if (listEl.children.length > 0) {
          sectionEl.appendChild(listEl);
        }
      } else {
        const listEl = document.createElement('ul');
        listEl.className = 'news-insight__bullet-list';
        items.forEach((entry) => {
          if (!entry || typeof entry !== 'object') {
            return;
          }
          const text = typeof entry.text === 'string' ? entry.text.trim() : '';
          if (!text) {
            return;
          }
          const citation = typeof entry.citation === 'string' ? entry.citation.trim() : '';
          const itemEl = document.createElement('li');
          itemEl.textContent = text;
          if (citation) {
            const citeEl = document.createElement('span');
            citeEl.className = 'news-insight__citation';
            citeEl.textContent = ` (${citation})`;
            itemEl.appendChild(citeEl);
          }
          listEl.appendChild(itemEl);
        });
        if (listEl.children.length > 0) {
          sectionEl.appendChild(listEl);
        }
      }

      if (sectionEl.children.length > 0) {
        insightBody.appendChild(sectionEl);
      }
    });

    if (!hasContent) {
      if (insightSection) {
        insightSection.classList.add('is-empty');
      }
      if (insightEmpty) {
        insightEmpty.hidden = false;
      }
      return;
    }

    if (insightSection) {
      insightSection.classList.remove('is-empty');
    }
    if (insightEmpty) {
      insightEmpty.hidden = true;
    }
  }

  function renderInsightEntities(entities) {
    if (!insightEntitiesList) {
      return;
    }

    const list = Array.isArray(entities) ? entities : [];
    insightEntitiesList.innerHTML = '';

    if (!insightEntitiesEmpty) {
      // no-op
    } else if (list.length === 0) {
      insightEntitiesEmpty.hidden = false;
    } else {
      insightEntitiesEmpty.hidden = true;
    }

    if (list.length === 0) {
      return;
    }

    list.slice(0, 6).forEach((entity) => {
      if (!entity || typeof entity !== 'object') {
        return;
      }
      const name = typeof entity.entity === 'string' ? entity.entity.trim() : '';
      if (!name) {
        return;
      }
      const summary = typeof entity.summary === 'string' ? entity.summary.trim() : '';
      const synonyms = Array.isArray(entity.synonyms)
        ? entity.synonyms.filter(Boolean).map((value) => String(value).trim()).filter(Boolean).slice(0, 3)
        : [];
      const facts = Array.isArray(entity.facts)
        ? entity.facts.filter(Boolean).map((value) => String(value).trim()).filter(Boolean).slice(0, 3)
        : [];

      const itemEl = document.createElement('li');

      const nameEl = document.createElement('p');
      nameEl.className = 'news-insight__entity-name';
      nameEl.textContent = name;
      itemEl.appendChild(nameEl);

      if (summary) {
        const summaryEl = document.createElement('p');
        summaryEl.className = 'news-insight__entity-summary';
        summaryEl.textContent = summary;
        itemEl.appendChild(summaryEl);
      }

      if (synonyms.length > 0) {
        const synonymEl = document.createElement('p');
        synonymEl.className = 'news-insight__entity-synonyms';
        synonymEl.textContent = `Also known as ${synonyms.join(', ')}`;
        itemEl.appendChild(synonymEl);
      }

      if (facts.length > 0) {
        const factList = document.createElement('ul');
        factList.className = 'news-insight__fact-list';
        facts.forEach((fact) => {
          const factItem = document.createElement('li');
          factItem.textContent = fact;
          factList.appendChild(factItem);
        });
        itemEl.appendChild(factList);
      }

      insightEntitiesList.appendChild(itemEl);
    });
  }

  function renderInsightReferences(refs) {
    if (!insightReferencesList) {
      return;
    }

    const citations = refs && Array.isArray(refs.citations) ? refs.citations : [];
    const sources = refs && Array.isArray(refs.sources) ? refs.sources : [];
    const seen = new Set();
    const items = [];

    citations.forEach((citation) => {
      if (!citation || typeof citation !== 'object') {
        return;
      }
      const url = citation.url && typeof citation.url === 'string' ? citation.url.trim() : '';
      const id = citation.id && typeof citation.id === 'string' ? citation.id.trim() : '';
      const key = url || (id ? `citation:${id}` : '');
      if (key && seen.has(key)) {
        return;
      }
      if (key) {
        seen.add(key);
      }
      items.push({
        type: 'citation',
        label: citation.title && typeof citation.title === 'string' && citation.title.trim()
          ? citation.title.trim()
          : (url ? extractHost(url) : 'Citation'),
        url,
        id,
        preview: citation.preview && typeof citation.preview === 'string' ? citation.preview.trim() : '',
        fetched_at: citation.fetched_at && typeof citation.fetched_at === 'string' ? citation.fetched_at : '',
      });
    });

    sources.forEach((source) => {
      if (!source || typeof source !== 'object') {
        return;
      }
      const url = source.url && typeof source.url === 'string' ? source.url.trim() : '';
      const key = url || (source.title && typeof source.title === 'string' ? `source:${source.title.trim()}` : '');
      if (key && seen.has(key)) {
        return;
      }
      if (key) {
        seen.add(key);
      }
      items.push({
        type: 'source',
        label: source.title && typeof source.title === 'string' && source.title.trim()
          ? source.title.trim()
          : (url ? extractHost(url) : 'Source'),
        url,
        preview: source.summary && typeof source.summary === 'string'
          ? source.summary.trim()
          : (source.preview && typeof source.preview === 'string' ? source.preview.trim() : ''),
        fetched_at: source.last_seen && typeof source.last_seen === 'string'
          ? source.last_seen
          : (source.fetched_at && typeof source.fetched_at === 'string' ? source.fetched_at : ''),
      });
    });

    insightReferencesList.innerHTML = '';

    if (insightReferencesEmpty) {
      insightReferencesEmpty.hidden = items.length > 0;
    }

    if (items.length === 0) {
      return;
    }

    items.slice(0, 10).forEach((item) => {
      const li = document.createElement('li');

      const titleEl = document.createElement('div');
      titleEl.className = 'news-insight__reference-title';
      const label = item.label || (item.type === 'citation' ? 'Citation' : 'Source');
      if (item.url) {
        const link = document.createElement('a');
        link.href = item.url;
        link.target = '_blank';
        link.rel = 'noopener';
        link.textContent = label || extractHost(item.url) || (item.type === 'citation' ? 'Citation' : 'Source');
        titleEl.appendChild(link);
      } else {
        titleEl.textContent = label;
      }
      li.appendChild(titleEl);

      const metaParts = [];
      if (item.type === 'citation' && item.id) {
        metaParts.push(`Citation ${item.id}`);
      } else if (item.type === 'source') {
        metaParts.push('Stored source');
      }
      if (item.fetched_at) {
        const formatted = formatDate(item.fetched_at);
        const relative = formatRelative(item.fetched_at);
        if (formatted) {
          metaParts.push(relative ? `${formatted} (${relative})` : formatted);
        }
      }
      if (metaParts.length > 0) {
        const metaEl = document.createElement('span');
        metaEl.className = 'news-insight__reference-meta';
        metaEl.textContent = metaParts.join(' · ');
        li.appendChild(metaEl);
      }

      if (item.preview) {
        const previewEl = document.createElement('p');
        previewEl.className = 'news-insight__reference-preview';
        previewEl.textContent = item.preview;
        li.appendChild(previewEl);
      }

      insightReferencesList.appendChild(li);
    });
  }

  function renderBrief(report) {
    if (!briefSection || !briefList) {
      return;
    }

    const hasReport = report && typeof report === 'object';
    const combined = hasReport && Array.isArray(report.combined_summary) ? report.combined_summary : [];
    const docCount = hasReport && typeof report.document_count === 'number' ? report.document_count : 0;
    const generatedAt = hasReport && typeof report.generated_at === 'string' ? report.generated_at : '';
    const query = hasReport && typeof report.query === 'string' ? report.query.trim() : '';

    briefList.innerHTML = '';

    const isEmpty = combined.length === 0;
    briefSection.classList.toggle('is-empty', isEmpty);
    if (briefEmpty) {
      briefEmpty.hidden = !isEmpty;
    }

    if (!isEmpty) {
      combined.slice(0, 4).forEach((entry) => {
        if (!entry || typeof entry !== 'object') {
          return;
        }
        const answer = entry.answer && typeof entry.answer === 'string' ? entry.answer.trim() : '';
        if (!answer) {
          return;
        }
        const question = entry.question && typeof entry.question === 'string' ? entry.question.trim() : '';
        const source = entry.source && typeof entry.source === 'object' ? entry.source : {};
        const sourceTitle = source.title && typeof source.title === 'string' ? source.title.trim() : '';
        const sourceUrl = source.url && typeof source.url === 'string' ? source.url.trim() : '';
        const sourceLabel = sourceTitle || extractHost(sourceUrl);

        const li = document.createElement('li');
        li.className = 'news-brief__item';

        if (question) {
          const heading = document.createElement('p');
          heading.className = 'news-brief__item-title';
          heading.textContent = question;
          li.appendChild(heading);
        }

        const text = document.createElement('p');
        text.className = 'news-brief__item-text';
        text.textContent = answer;
        li.appendChild(text);

        if (sourceLabel) {
          const sourceLine = document.createElement('p');
          sourceLine.className = 'news-brief__item-source';
          sourceLine.textContent = 'Source: ';
          if (sourceUrl) {
            const link = document.createElement('a');
            link.href = sourceUrl;
            link.target = '_blank';
            link.rel = 'noopener';
            link.textContent = sourceLabel;
            sourceLine.appendChild(link);
          } else {
            const span = document.createElement('span');
            span.textContent = sourceLabel;
            sourceLine.appendChild(span);
          }
          li.appendChild(sourceLine);
        }

        briefList.appendChild(li);
      });
    }

    if (briefMeta) {
      const focus = query ? `Focus: “${query}”` : 'Focus: Latest coverage';
      const parts = [focus];
      if (docCount > 0) {
        parts.push(`${formatNumber(docCount)} source${docCount === 1 ? '' : 's'}`);
      }
      if (generatedAt) {
        const formatted = formatDate(generatedAt);
        const relative = formatRelative(generatedAt);
        if (formatted) {
          parts.push(relative ? `${formatted} (${relative})` : formatted);
        }
      }
      briefMeta.textContent = parts.join(' · ');
    }
  }

  function renderResults(report, fallbackSources = []) {
    if (!resultsSection || !resultsList) {
      return;
    }

    const hasReport = report && typeof report === 'object';
    const highlights = hasReport && Array.isArray(report.highlights) ? report.highlights : [];
    const docCount = hasReport && typeof report.document_count === 'number' ? report.document_count : 0;
    const sources = Array.isArray(fallbackSources) ? fallbackSources : [];

    const useFallback = highlights.length === 0 && sources.length > 0;

    resultsList.innerHTML = '';

    const isEmpty = !useFallback && highlights.length === 0;
    resultsSection.classList.toggle('is-empty', isEmpty);
    if (resultsEmpty) {
      resultsEmpty.hidden = !isEmpty;
    }

    if (highlights.length > 0) {
      highlights.slice(0, 6).forEach((item) => {
        if (!item || typeof item !== 'object') {
          return;
        }
        const title = item.title && typeof item.title === 'string' ? item.title.trim() : '';
        const summary = item.summary && typeof item.summary === 'string' ? item.summary.trim() : '';
        const relevance = formatPercent(item.relevance);
        const uniqueness = formatPercent(item.uniqueness);
        const keywords = Array.isArray(item.keywords) ? item.keywords.filter(Boolean).slice(0, 6).map((keyword) => String(keyword)) : [];
        const source = item.source && typeof item.source === 'object' ? item.source : {};
        const sourceTitle = source.title && typeof source.title === 'string' ? source.title.trim() : '';
        const sourceUrl = source.url && typeof source.url === 'string' ? source.url.trim() : '';
        const fetchedAt = source.fetched_at && typeof source.fetched_at === 'string' ? source.fetched_at : '';
        const sourceLabel = sourceTitle || extractHost(sourceUrl) || 'Source';

        const card = document.createElement('article');
        card.className = 'news-card';

        const header = document.createElement('div');
        header.className = 'news-card__header';

        const metaParts = [sourceLabel];
        if (fetchedAt) {
          const formatted = formatDate(fetchedAt);
          const relative = formatRelative(fetchedAt);
          if (formatted) {
            metaParts.push(relative ? `${formatted} (${relative})` : formatted);
          }
        }

        const sourceLine = document.createElement('span');
        sourceLine.className = 'news-card__source';
        sourceLine.textContent = metaParts.join(' · ');
        header.appendChild(sourceLine);

        const titleEl = document.createElement('h3');
        titleEl.className = 'news-card__title';
        if (sourceUrl) {
          const link = document.createElement('a');
          link.href = sourceUrl;
          link.target = '_blank';
          link.rel = 'noopener';
          link.textContent = title || sourceLabel;
          titleEl.appendChild(link);
        } else {
          titleEl.textContent = title || sourceLabel;
        }
        header.appendChild(titleEl);

        const metrics = document.createElement('span');
        metrics.className = 'news-card__metrics';
        metrics.textContent = `Relevance ${relevance} · Uniqueness ${uniqueness}`;
        header.appendChild(metrics);

        card.appendChild(header);

        if (summary) {
          const body = document.createElement('p');
          body.className = 'news-card__summary';
          body.textContent = summary;
          card.appendChild(body);
        }

        if (keywords.length > 0) {
          const tagList = document.createElement('div');
          tagList.className = 'news-card__keywords';
          keywords.forEach((keyword) => {
            const span = document.createElement('span');
            span.textContent = keyword;
            tagList.appendChild(span);
          });
          card.appendChild(tagList);
        }

        resultsList.appendChild(card);
      });
    } else if (useFallback) {
      sources.slice(0, 6).forEach((item) => {
        if (!item || typeof item !== 'object') {
          return;
        }
        const title = item.title && typeof item.title === 'string' ? item.title.trim() : '';
        const url = item.url && typeof item.url === 'string' ? item.url.trim() : '';
        const preview = item.preview && typeof item.preview === 'string' ? item.preview.trim() : '';
        const summary = item.summary && typeof item.summary === 'string' ? item.summary.trim() : '';
        const snippet = summary || preview;
        const keywords = Array.isArray(item.keywords) ? item.keywords.filter(Boolean).slice(0, 6).map((keyword) => String(keyword)) : [];
        const lastSeen = item.last_seen && typeof item.last_seen === 'string' ? item.last_seen : (item.fetched_at && typeof item.fetched_at === 'string' ? item.fetched_at : '');
        const host = extractHost(url);

        const card = document.createElement('article');
        card.className = 'news-card news-card--source';

        const header = document.createElement('div');
        header.className = 'news-card__header';

        const metaParts = [host || 'Stored source'];
        if (lastSeen) {
          const formatted = formatDate(lastSeen);
          const relative = formatRelative(lastSeen);
          if (formatted) {
            metaParts.push(relative ? `${formatted} (${relative})` : formatted);
          }
        }

        const sourceLine = document.createElement('span');
        sourceLine.className = 'news-card__source';
        sourceLine.textContent = metaParts.join(' · ');
        header.appendChild(sourceLine);

        const titleEl = document.createElement('h3');
        titleEl.className = 'news-card__title';
        if (url) {
          const link = document.createElement('a');
          link.href = url;
          link.target = '_blank';
          link.rel = 'noopener';
          link.textContent = title || host || 'Source';
          titleEl.appendChild(link);
        } else {
          titleEl.textContent = title || 'Source';
        }
        header.appendChild(titleEl);

        if (snippet) {
          const meta = document.createElement('span');
          meta.className = 'news-card__metrics';
          meta.textContent = 'Snapshot from the knowledge graph';
          header.appendChild(meta);
        }

        card.appendChild(header);

        if (snippet) {
          const body = document.createElement('p');
          body.className = 'news-card__summary';
          body.textContent = snippet;
          card.appendChild(body);
        }

        if (keywords.length > 0) {
          const tagList = document.createElement('div');
          tagList.className = 'news-card__keywords';
          keywords.forEach((keyword) => {
            const span = document.createElement('span');
            span.textContent = keyword;
            tagList.appendChild(span);
          });
          card.appendChild(tagList);
        }

        resultsList.appendChild(card);
      });
    }

    if (resultsMeta) {
      const parts = [];
      if (highlights.length > 0) {
        parts.push(`${formatNumber(highlights.length)} curated highlight${highlights.length === 1 ? '' : 's'}`);
      } else if (useFallback) {
        parts.push(`${formatNumber(sources.length)} stored source${sources.length === 1 ? '' : 's'}`);
      }
      if (docCount > 0) {
        parts.push(`${formatNumber(docCount)} total source${docCount === 1 ? '' : 's'}`);
      }
      resultsMeta.textContent = parts.join(' · ');
    }
  }

  function normaliseError(reason) {
    if (!reason) {
      return 'Request failed.';
    }
    if (typeof reason === 'string') {
      return reason;
    }
    if (reason && typeof reason === 'object') {
      if (reason.name === 'AbortError') {
        return 'abort';
      }
      if (typeof reason.message === 'string') {
        return reason.message;
      }
    }
    return 'Request failed.';
  }

  async function fetchInsight(query, options = {}) {
    if (!insightEndpoint) {
      throw new Error('Insight endpoint is not configured.');
    }
    const url = buildUrl(insightEndpoint, { action: 'insight', limit: 6, q: query, ...options });
    const response = await fetch(url, { headers: { Accept: 'application/json' } });
    if (!response.ok) {
      throw new Error(`Insight request failed (${response.status})`);
    }
    const payload = await response.json();
    if (payload && payload.error) {
      throw new Error(String(payload.error));
    }
    const insight = payload && payload.data && payload.data.insight ? payload.data.insight : null;
    if (!insight) {
      throw new Error('Malformed insight response.');
    }
    return insight;
  }

  async function fetchSearch(query, options = {}) {
    if (!searchEndpoint) {
      return null;
    }
    const url = buildUrl(searchEndpoint, { action: 'search', limit: 24, q: query, ...options });
    const response = await fetch(url, { headers: { Accept: 'application/json' } });
    if (!response.ok) {
      throw new Error(`Search request failed (${response.status})`);
    }
    const payload = await response.json();
    if (payload && payload.error) {
      throw new Error(String(payload.error));
    }
    const search = payload && payload.data && payload.data.search ? payload.data.search : null;
    if (!search) {
      throw new Error('Malformed search response.');
    }
    return search;
  }

  async function fetchReport(query, options = {}) {
    if (!reportEndpoint) {
      return null;
    }
    const url = buildUrl(reportEndpoint, { action: 'report', limit: 6, q: query, ...options });
    const response = await fetch(url, { headers: { Accept: 'application/json' } });
    if (!response.ok) {
      throw new Error(`Unable to generate briefing (${response.status})`);
    }
    const payload = await response.json();
    if (payload && payload.error) {
      throw new Error(String(payload.error));
    }
    const report = payload && payload.data && payload.data.report ? payload.data.report : null;
    if (!report) {
      throw new Error('Malformed report response.');
    }
    return report;
  }

  function applyInsightResult(insight, query, errors = []) {
    const reportData = insight && typeof insight.report === 'object' ? insight.report : null;
    const searchData = insight && typeof insight.search === 'object' ? insight.search : null;
    const fallbackSources = insight && insight.references && Array.isArray(insight.references.sources)
      ? insight.references.sources
      : (searchData && Array.isArray(searchData.sources) ? searchData.sources : initialSources.slice());

    renderInsight(insight);
    renderBrief(reportData);
    renderResults(reportData, fallbackSources);

    const searchEntities = searchData && Array.isArray(searchData.entities) ? searchData.entities : [];
    const fallbackEntities = searchEntities.length > 0
      ? searchEntities
      : (insight && Array.isArray(insight.entities) ? insight.entities : initialEntities);
    updateToolbar(reportData, fallbackSources, fallbackEntities);

    if (searchEntities.length > 0) {
      renderTrending(searchEntities);
    } else if (insight && Array.isArray(insight.entities) && insight.entities.length > 0) {
      renderTrending(insight.entities);
    } else if (!query) {
      renderTrending(initialEntities);
    } else {
      renderTrending([]);
    }

    const hasHighlights = reportData && Array.isArray(reportData.highlights) && reportData.highlights.length > 0;
    const hasFallback = !hasHighlights && Array.isArray(fallbackSources) && fallbackSources.length > 0;

    let tone = hasHighlights ? 'success' : hasFallback ? 'warning' : 'warning';
    let message;

    if (!query) {
      message = hasHighlights
        ? 'Showing the latest insight briefing generated from stored sources.'
        : 'Showing stored sources from the knowledge graph. Enter a focus area to generate a briefing.';
    } else if (hasHighlights) {
      message = `Generated an insight briefing for “${query}”.`;
    } else if (hasFallback) {
      message = `No curated highlights for “${query}” yet. Showing stored sources instead.`;
    } else {
      message = `No stored coverage yet for “${query}”.`;
    }

    if (errors.length > 0) {
      const errorText = errors[0];
      if (hasHighlights || hasFallback) {
        message = `${message} ${errorText}`;
        tone = hasHighlights ? 'success' : 'warning';
      } else {
        message = errorText;
        tone = 'error';
      }
    }

    setStatus(message, tone);
  }

  async function performSearch(query) {
    if (!insightEndpoint && !searchEndpoint && !reportEndpoint) {
      setStatus('Search is not configured.', 'error');
      return;
    }

    const trimmed = typeof query === 'string' ? query.trim() : '';
    const filterParams = getFilterParams();
    const requestId = ++currentRequestId;

    setLoading(true);
    setStatus(trimmed ? `Searching for “${trimmed}”…` : 'Loading the latest coverage…', 'info');

    const errors = [];

    if (insightEndpoint) {
      try {
        const insight = await fetchInsight(trimmed, filterParams);
        if (requestId !== currentRequestId) {
          return;
        }
        setLoading(false);
        applyInsightResult(insight, trimmed);
        return;
      } catch (primaryError) {
        if (requestId !== currentRequestId) {
          return;
        }
        const reason = normaliseError(primaryError);
        if (reason !== 'abort') {
          errors.push(reason);
        }
      }
    }

    try {
      const searchPromise = searchEndpoint ? fetchSearch(trimmed, filterParams) : Promise.resolve(null);
      const reportPromise = reportEndpoint ? fetchReport(trimmed, filterParams) : Promise.resolve(null);
      const [searchOutcome, reportOutcome] = await Promise.allSettled([searchPromise, reportPromise]);

      if (requestId !== currentRequestId) {
        return;
      }

      setLoading(false);
      renderInsight(null);

      let searchData = null;
      let reportData = null;
      let fallbackSources = initialSources.slice();

      if (searchOutcome.status === 'fulfilled') {
        searchData = searchOutcome.value;
        if (searchData && typeof searchData === 'object' && Array.isArray(searchData.sources)) {
          fallbackSources = searchData.sources;
        }
      } else if (searchOutcome.status === 'rejected') {
        const reason = normaliseError(searchOutcome.reason);
        if (reason !== 'abort') {
          errors.push(reason);
        }
      }

      if (reportOutcome.status === 'fulfilled') {
        reportData = reportOutcome.value;
      } else if (reportOutcome.status === 'rejected') {
        const reason = normaliseError(reportOutcome.reason);
        if (reason !== 'abort') {
          errors.push(reason);
        }
      }

      let entityList = initialEntities;
      if (searchData && Array.isArray(searchData.entities)) {
        entityList = searchData.entities;
        renderTrending(searchData.entities);
      } else if (!searchData && trimmed === '') {
        renderTrending(initialEntities);
      } else if (!searchData) {
        renderTrending([]);
      }

      renderBrief(reportData);
      renderResults(reportData, fallbackSources);
      updateToolbar(reportData, fallbackSources, entityList);

      const hasHighlights = reportData && Array.isArray(reportData.highlights) && reportData.highlights.length > 0;
      const hasFallback = !hasHighlights && Array.isArray(fallbackSources) && fallbackSources.length > 0;

      let tone = hasHighlights ? 'success' : hasFallback ? 'warning' : 'warning';
      let message;

      if (hasHighlights) {
        message = trimmed ? `Showing coverage for “${trimmed}”.` : 'Showing the latest coverage.';
      } else if (hasFallback) {
        message = trimmed ? `Showing stored sources related to “${trimmed}”.` : 'Showing the latest stored sources.';
      } else {
        message = trimmed ? `No stored coverage yet for “${trimmed}”.` : 'No stored coverage available yet.';
      }

      if (errors.length > 0) {
        const errorText = errors[0];
        if (hasHighlights || hasFallback) {
          message = `${message} ${errorText}`;
          tone = hasHighlights ? 'success' : 'warning';
        } else {
          message = errorText;
          tone = 'error';
        }
      }

      setStatus(message, tone);
    } catch (error) {
      if (requestId !== currentRequestId) {
        return;
      }
      setLoading(false);
      renderInsight(null);
      setStatus(error instanceof Error ? error.message : 'Search failed.', 'error');
    }
  }

  function bindEvents() {
    if (form) {
      form.addEventListener('submit', (event) => {
        event.preventDefault();
        const value = input ? input.value : '';
        performSearch(value);
      });
    }

    if (input) {
      input.addEventListener('input', () => {
        const value = input.value;
        if (debounceTimer) {
          clearTimeout(debounceTimer);
        }
        debounceTimer = setTimeout(() => {
          performSearch(value);
        }, 450);
      });
    }
  }

  function attachTemplates() {
    templateButtons.forEach((button) => {
      button.addEventListener('click', () => {
        const value = button.getAttribute('data-search-template') || button.textContent || '';
        const query = value.trim();
        if (!query) {
          return;
        }
        if (input) {
          input.value = query;
          input.focus();
        }
        performSearch(query);
      });
    });
  }

  function attachWorkspaceActions() {
    workspaceButtons.forEach((button) => {
      button.addEventListener('click', () => {
        const action = button.getAttribute('data-workspace-action');
        let message = '';
        if (action === 'export') {
          message = 'Export briefing: use the CLI or API to generate decks with citations.';
        } else if (action === 'share') {
          message = 'Share briefing: copy the URL or schedule an update from the workspace.';
        }
        if (message) {
          setStatus(message, 'info');
        }
      });
    });
  }

  function initialiseFilters() {
    if (!filterPanel || filterGroups.length === 0) {
      updateFilterSummary();
      return;
    }

    filterGroups.forEach((group) => {
      const key = group.getAttribute('data-filter-group');
      if (!key) {
        return;
      }
      const chips = group.querySelectorAll('[data-filter-value]');
      if (chips.length === 0) {
        return;
      }
      let activeChip = null;
      chips.forEach((chip) => {
        const value = chip.getAttribute('data-filter-value') || '';
        if (chip.hasAttribute('data-filter-default') && !activeChip) {
          activeChip = chip;
        }
        chip.addEventListener('click', () => {
          if (chip.classList.contains('is-active')) {
            return;
          }
          chips.forEach((btn) => btn.classList.remove('is-active'));
          chip.classList.add('is-active');
          filterState[key] = value;
          updateFilterSummary();
          if (debounceTimer) {
            clearTimeout(debounceTimer);
            debounceTimer = null;
          }
          performSearch(input ? input.value : '');
        });
      });
      if (!activeChip) {
        activeChip = chips[0];
        activeChip.classList.add('is-active');
      }
      const activeValue = activeChip.getAttribute('data-filter-value') || '';
      filterState[key] = activeValue;
    });

    updateFilterSummary();
  }

  function bootstrap() {
    renderInsight(initialInsight);
    renderBrief(initialReport);
    renderResults(initialReport, initialSources);
    renderTrending(initialEntities);
    updateToolbar(initialReport, initialSources, initialEntities);
    initialiseFilters();
    attachTemplates();
    attachWorkspaceActions();
    bindEvents();
  }

  bootstrap();
})();
