(function () {
  const config = window.AISearch || {};
  const endpoints = config.endpoints || {};
  const searchEndpoint = endpoints.search || '';
  const reportEndpoint = endpoints.report || searchEndpoint;
  const initial = config.initial || {};
  const initialReport = initial && typeof initial.report === 'object' ? initial.report : null;
  const initialEntities = Array.isArray(initial.entities) ? initial.entities : [];
  const initialTop = Array.isArray(initial.top) ? initial.top : [];
  const initialSources = Array.isArray(initial.sources) ? initial.sources : [];

  const form = document.querySelector('[data-search-form]');
  const input = document.querySelector('[data-search-input]');
  const statusEl = document.querySelector('[data-search-status]');
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

  const defaultTrending = collectNames(initialEntities.concat(initialTop));

  let debounceTimer = null;
  let currentRequestId = 0;

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

  async function fetchSearch(query) {
    if (!searchEndpoint) {
      return null;
    }
    const url = buildUrl(searchEndpoint, { action: 'search', limit: 24, q: query });
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

  async function fetchReport(query) {
    if (!reportEndpoint) {
      return null;
    }
    const url = buildUrl(reportEndpoint, { action: 'report', limit: 6, q: query });
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

  async function performSearch(query) {
    if (!searchEndpoint && !reportEndpoint) {
      setStatus('Search is not configured.', 'error');
      return;
    }

    const trimmed = typeof query === 'string' ? query.trim() : '';
    const requestId = ++currentRequestId;

    setLoading(true);
    setStatus(trimmed ? `Searching for “${trimmed}”…` : 'Loading the latest coverage…', 'info');

    try {
      const searchPromise = searchEndpoint ? fetchSearch(trimmed) : Promise.resolve(null);
      const reportPromise = reportEndpoint ? fetchReport(trimmed) : Promise.resolve(null);
      const [searchOutcome, reportOutcome] = await Promise.allSettled([searchPromise, reportPromise]);

      if (requestId !== currentRequestId) {
        return;
      }

      setLoading(false);

      let searchData = null;
      let reportData = null;
      let fallbackSources = initialSources.slice();
      const errors = [];

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

      if (searchData && Array.isArray(searchData.entities)) {
        renderTrending(searchData.entities);
      } else if (!searchData && trimmed === '') {
        renderTrending(initialEntities);
      } else if (!searchData) {
        renderTrending([]);
      }

      renderBrief(reportData);
      renderResults(reportData, fallbackSources);

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

  function bootstrap() {
    renderBrief(initialReport);
    renderResults(initialReport, initialSources);
    renderTrending(initialEntities);
    bindEvents();
  }

  bootstrap();
})();
