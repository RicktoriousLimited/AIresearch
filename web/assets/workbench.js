(function () {
  const apiEndpoint = document.body.dataset.api || 'api/analyse.php';
  const scrapeEndpoint = document.body.dataset.scrape || 'api/scrape.php';

  const SAMPLE_TEXT = {
    bios: `Alice Smith leads the applied ML lab at Horizon Bio. She previously built entity linking systems at Quantum Labs.\n\nBrandon Lee is a materials scientist at Aurora Fusion and collaborates with Alice Smith on advanced plasma diagnostics.`,
    updates: `Week 18 update – Project Helios\n- Completed integration tests for the updated relation extractor.\n- Alice Smith onboarded two new annotators to extend the synonym lexicon.\n- Brandon Lee submitted spectroscopy results that revealed three new compound interactions.`,
    company: `Aurora Fusion is a deep-tech company focused on compact fusion reactors. The R&D group partners with Horizon Bio on plasma containment techniques and maintains collaborations with Quantum Labs in Cambridge.`
  };

  const SUMMARY_LABELS = {
    documents_received: 'Documents submitted',
    documents_processed: 'Documents processed',
    triples: 'Triples extracted',
    synonym_groups: 'Synonym groups',
    unique_entities: 'Unique entities',
    generated_at: 'Generated at'
  };

  const elements = {
    form: document.getElementById('workbench-form'),
    textarea: document.getElementById('input-text'),
    status: document.getElementById('status'),
    results: document.getElementById('results'),
    overview: document.getElementById('results-overview'),
    summary: document.getElementById('summary-list'),
    relations: document.getElementById('relations-chart'),
    entities: document.getElementById('entities-chart'),
    triples: document.getElementById('triples-table'),
    synonyms: document.getElementById('synonyms-list'),
    downloadJson: document.getElementById('download-json'),
    copySummary: document.getElementById('copy-summary'),
    resetButton: document.getElementById('reset-button'),
    inputMeta: document.getElementById('input-meta'),
    fileInput: document.getElementById('file-upload'),
    samplePills: document.querySelectorAll('.chip[data-sample]'),
    continueState: document.getElementById('continue-state'),
    clearSession: document.getElementById('clear-session'),
    urlInput: document.getElementById('input-url'),
    urlButton: document.getElementById('fetch-url'),
    insightsCard: document.getElementById('insights-card'),
    insightsList: document.getElementById('insights-list'),
    documentInsightsCard: document.getElementById('document-insights-card'),
    documentCleaned: document.getElementById('document-cleaned'),
    documentCleanedText: document.getElementById('document-cleaned-text'),
    documentRewrite: document.getElementById('document-rewrite'),
    documentRewriteText: document.getElementById('document-rewrite-text'),
    documentKeywords: document.getElementById('document-keywords'),
    documentKeywordsList: document.getElementById('document-keywords-list'),
    documentSpelling: document.getElementById('document-spelling'),
    documentSpellingList: document.getElementById('document-spelling-list'),
    metricDocumentsProcessed: document.querySelector('[data-metric-value="documents_processed"]'),
    metricDocumentsSubmitted: document.querySelector('[data-metric-sub="documents_received"]'),
    metricTriples: document.querySelector('[data-metric-value="triples"]'),
    metricTriplesDensity: document.querySelector('[data-metric-sub="triples-density"]'),
    metricEntities: document.querySelector('[data-metric-value="unique_entities"]'),
    metricSynonyms: document.querySelector('[data-metric-sub="synonym_groups"]'),
    datasetCard: document.getElementById('dataset-card'),
    datasetSubtitle: document.getElementById('dataset-subtitle'),
    datasetSummary: document.getElementById('dataset-summary'),
    datasetPreview: document.getElementById('dataset-preview'),
    downloadDatasetJson: document.getElementById('download-dataset-json'),
    downloadDatasetCsv: document.getElementById('download-dataset-csv')
  };

  let lastResult = null;
  let lastDataset = null;
  let persistedState = null;

  function setStatus(message, tone = 'info') {
    if (!elements.status) {
      return;
    }

    elements.status.textContent = message;
    elements.status.className = tone ? 'status ' + tone : 'status';
  }

  function clearResults() {
    lastResult = null;
    lastDataset = null;
    if (elements.results) {
      elements.results.hidden = true;
    }
    if (elements.summary) {
      elements.summary.innerHTML = '';
    }
    if (elements.relations) {
      elements.relations.innerHTML = '';
    }
    if (elements.entities) {
      elements.entities.innerHTML = '';
    }
    if (elements.triples) {
      elements.triples.innerHTML = '';
    }
    if (elements.synonyms) {
      elements.synonyms.innerHTML = '';
    }
    if (elements.overview) {
      elements.overview.hidden = true;
    }
    if (elements.metricDocumentsProcessed) {
      elements.metricDocumentsProcessed.textContent = '0';
    }
    if (elements.metricDocumentsSubmitted) {
      elements.metricDocumentsSubmitted.textContent = 'Submitted: 0';
    }
    if (elements.metricTriples) {
      elements.metricTriples.textContent = '0';
    }
    if (elements.metricTriplesDensity) {
      elements.metricTriplesDensity.textContent = '– per document';
    }
    if (elements.metricEntities) {
      elements.metricEntities.textContent = '0';
    }
    if (elements.metricSynonyms) {
      elements.metricSynonyms.textContent = 'Synonym groups: 0';
    }
    if (elements.insightsList) {
      elements.insightsList.innerHTML = '';
    }
    if (elements.insightsCard) {
      elements.insightsCard.hidden = true;
    }
    if (elements.documentInsightsCard) {
      elements.documentInsightsCard.hidden = true;
    }
    if (elements.documentCleanedText) {
      elements.documentCleanedText.textContent = '';
    }
    if (elements.documentRewriteText) {
      elements.documentRewriteText.textContent = '';
    }
    if (elements.documentKeywordsList) {
      elements.documentKeywordsList.innerHTML = '';
    }
    if (elements.documentSpellingList) {
      elements.documentSpellingList.innerHTML = '';
    }
    if (elements.documentCleaned) {
      elements.documentCleaned.hidden = true;
    }
    if (elements.documentRewrite) {
      elements.documentRewrite.hidden = true;
    }
    if (elements.documentKeywords) {
      elements.documentKeywords.hidden = true;
    }
    if (elements.documentSpelling) {
      elements.documentSpelling.hidden = true;
    }
    if (elements.datasetCard) {
      elements.datasetCard.hidden = true;
    }
    if (elements.datasetSummary) {
      elements.datasetSummary.innerHTML = '';
    }
    if (elements.datasetPreview) {
      elements.datasetPreview.innerHTML = '';
    }
    if (elements.datasetSubtitle) {
      elements.datasetSubtitle.textContent = '';
    }
  }

  function renderSummary(summary) {
    if (!elements.summary) {
      return;
    }

    if (!summary || typeof summary !== 'object') {
      elements.summary.innerHTML = '<p>No summary data.</p>';
      return;
    }

    const keys = Object.keys(SUMMARY_LABELS).filter((key) => Object.prototype.hasOwnProperty.call(summary, key));
    if (keys.length === 0) {
      elements.summary.innerHTML = '<p>No summary data.</p>';
      return;
    }

    const fragments = keys.map((key) => {
      const label = SUMMARY_LABELS[key];
      const value = summary[key];
      const safeLabel = escapeHtml(label);
      const safeValue = escapeHtml(String(value));
      return `<div><dt>${safeLabel}</dt><dd>${safeValue}</dd></div>`;
    });

    elements.summary.innerHTML = fragments.join('');

    renderOverview(summary);
  }

  function renderOverview(summary) {
    if (!elements.overview) {
      return;
    }

    if (!summary || typeof summary !== 'object') {
      elements.overview.hidden = true;
      return;
    }

    const documentsProcessed = Math.max(0, Number(summary.documents_processed ?? 0));
    const documentsReceived = Math.max(0, Number(summary.documents_received ?? documentsProcessed));
    const triples = Math.max(0, Number(summary.triples ?? 0));
    const uniqueEntities = Math.max(0, Number(summary.unique_entities ?? 0));
    const synonymGroups = Math.max(0, Number(summary.synonym_groups ?? 0));
    const density = documentsProcessed > 0 ? (triples / documentsProcessed).toFixed(1) : null;

    if (elements.metricDocumentsProcessed) {
      elements.metricDocumentsProcessed.textContent = formatNumber(documentsProcessed);
    }
    if (elements.metricDocumentsSubmitted) {
      elements.metricDocumentsSubmitted.textContent = `Submitted: ${formatNumber(documentsReceived)}`;
    }
    if (elements.metricTriples) {
      elements.metricTriples.textContent = formatNumber(triples);
    }
    if (elements.metricTriplesDensity) {
      elements.metricTriplesDensity.textContent = density !== null ? `${density} per document` : 'No documents processed yet';
    }
    if (elements.metricEntities) {
      elements.metricEntities.textContent = formatNumber(uniqueEntities);
    }
    if (elements.metricSynonyms) {
      elements.metricSynonyms.textContent = `Synonym groups: ${formatNumber(synonymGroups)}`;
    }

    const hasData =
      documentsProcessed > 0 ||
      documentsReceived > 0 ||
      triples > 0 ||
      uniqueEntities > 0 ||
      synonymGroups > 0;

    elements.overview.hidden = !hasData;
  }

  function renderList(container, data) {
    if (!container) {
      return;
    }

    const entries = Object.entries(data || {});
    if (entries.length === 0) {
      container.innerHTML = '<p class="empty">No data.</p>';
      return;
    }

    const sorted = entries
      .sort((a, b) => Number(b[1]) - Number(a[1]))
      .slice(0, 25);
    const maxValue = sorted.length > 0 ? Math.max(...sorted.map(([, value]) => Number(value) || 0)) : 0;

    const items = sorted.map(([label, value]) => {
      const numericValue = Number(value);
      const safeValue = Number.isFinite(numericValue) ? numericValue : 0;
      const width = maxValue > 0 ? Math.max(8, Math.round((safeValue / maxValue) * 100)) : 8;
      const safeLabel = escapeHtml(label);
      const displayValue = escapeHtml(formatNumber(safeValue));

      return `
        <li>
          <div class="bar-header">
            <span class="bar-label">${safeLabel}</span>
            <span class="bar-value">${displayValue}</span>
          </div>
          <div class="bar-meter"><span style="width:${width}%"></span></div>
        </li>
      `;
    });

    container.innerHTML = `<ul class="bar-list">${items.join('')}</ul>`;
  }

  function renderTriples(triples) {
    if (!elements.triples) {
      return;
    }

    if (!Array.isArray(triples) || triples.length === 0) {
      elements.triples.innerHTML = '<p class="empty">No triples extracted.</p>';
      return;
    }

    const rows = triples.map((triple) => {
      const subject = escapeHtml(triple.subject || '');
      const relation = escapeHtml(triple.relation || '');
      const object = escapeHtml(triple.object || '');
      return `<tr><td>${subject}</td><td>${relation}</td><td>${object}</td></tr>`;
    });

    elements.triples.innerHTML = `
      <table>
        <thead>
          <tr><th>Subject</th><th>Relation</th><th>Object</th></tr>
        </thead>
        <tbody>${rows.join('')}</tbody>
      </table>
    `;
  }

  function renderSynonyms(synonyms) {
    if (!elements.synonyms) {
      return;
    }

    if (!Array.isArray(synonyms) || synonyms.length === 0) {
      elements.synonyms.innerHTML = '<p class="empty">No synonyms captured.</p>';
      return;
    }

    const items = synonyms.slice(0, 40).map((pair) => {
      const entity = escapeHtml(pair.entity || '');
      const values = Array.isArray(pair.synonyms) ? pair.synonyms.map((value) => `<code>${escapeHtml(value)}</code>`) : [];
      return `<li><span>${entity}</span><span>${values.join(', ')}</span></li>`;
    });

    elements.synonyms.innerHTML = `<ul>${items.join('')}</ul>`;
  }

  function renderInsights(data) {
    if (!elements.insightsCard || !elements.insightsList) {
      return;
    }

    const insights = [];
    const relations = data.relations || {};
    const entities = data.entities || {};
    const summary = data.summary || {};
    const triples = Array.isArray(data.triples) ? data.triples : [];
    const documentsProcessed = Number(summary.documents_processed || 0);
    const synonymGroups = Number(summary.synonym_groups || 0);
    const topRelation = getTopEntry(relations);
    const topEntity = getTopEntry(entities);

    if (topRelation) {
      insights.push({
        title: capitalize(topRelation.label),
        description: `Most frequent relation with ${formatCount(topRelation.count, 'occurrence')}.`
      });
    }

    if (topEntity) {
      insights.push({
        title: capitalize(topEntity.label),
        description: `Entity mentioned ${formatCount(topEntity.count, 'time')}.`
      });
    }

    if (triples.length > 0 && documentsProcessed > 0) {
      const ratio = (triples.length / documentsProcessed).toFixed(1);
      insights.push({
        title: `${triples.length} triples`,
        description: `≈${ratio} triples per document processed.`
      });
    }

    if (synonymGroups > 0) {
      insights.push({
        title: `${synonymGroups} synonym groups`,
        description: 'Use them to consolidate author aliases and product names.'
      });
    }

    if (insights.length === 0) {
      elements.insightsList.innerHTML = '<li><span>No highlights yet. Run an extraction to surface quick insights.</span></li>';
      elements.insightsCard.hidden = false;
      return;
    }

    const items = insights.slice(0, 4).map((insight) => {
      return `<li><strong>${escapeHtml(insight.title)}</strong><span>${escapeHtml(insight.description)}</span></li>`;
    });

    elements.insightsList.innerHTML = items.join('');
    elements.insightsCard.hidden = false;
  }

  function renderDocumentInsights(documents) {
    if (!elements.documentInsightsCard) {
      return;
    }

    const card = elements.documentInsightsCard;
    const cleanedSection = elements.documentCleaned;
    const cleanedText = elements.documentCleanedText;
    const rewriteSection = elements.documentRewrite;
    const rewriteText = elements.documentRewriteText;
    const keywordsSection = elements.documentKeywords;
    const keywordsList = elements.documentKeywordsList;
    const spellingSection = elements.documentSpelling;
    const spellingList = elements.documentSpellingList;

    card.hidden = true;

    if (!Array.isArray(documents) || documents.length === 0) {
      return;
    }

    const doc = documents[0] || {};
    const cleaned = typeof doc.cleaned === 'string' ? doc.cleaned.trim() : '';
    const rewrite = typeof doc.rewritten === 'string' ? doc.rewritten.trim() : '';
    const keywords = Array.isArray(doc.keywords) ? doc.keywords : [];
    const spelling = Array.isArray(doc.spelling) ? doc.spelling : [];

    let visible = false;

    if (cleanedSection && cleanedText) {
      if (cleaned !== '') {
        cleanedText.textContent = cleaned;
        cleanedSection.hidden = false;
        visible = true;
      } else {
        cleanedSection.hidden = true;
      }
    }

    if (rewriteSection && rewriteText) {
      if (rewrite !== '') {
        rewriteText.textContent = rewrite;
        rewriteSection.hidden = false;
        visible = true;
      } else {
        rewriteSection.hidden = true;
      }
    }

    if (keywordsSection && keywordsList) {
      if (keywords.length > 0) {
        const items = keywords.slice(0, 12).map((keyword) => {
          const token = escapeHtml(keyword.token || '');
          const count = escapeHtml(String(keyword.count || 0));
          return `<li><span class="label">${token}</span><span class="meta">${count}×</span></li>`;
        });
        keywordsList.innerHTML = items.join('');
        keywordsSection.hidden = false;
        visible = true;
      } else {
        keywordsList.innerHTML = '';
        keywordsSection.hidden = true;
      }
    }

    if (spellingSection && spellingList) {
      if (spelling.length > 0) {
        const items = spelling.slice(0, 12).map((entry) => {
          const token = escapeHtml(entry.token || '');
          const count = escapeHtml(String(entry.count || 0));
          const suggestionValues = Array.isArray(entry.suggestions) ? entry.suggestions.slice(0, 5) : [];
          const suggestionText = suggestionValues.length > 0
            ? suggestionValues.map((value) => escapeHtml(value)).join(', ')
            : escapeHtml('No close matches');
          return `<li><span class="label">${token} (${count}×)</span><span class="meta">${suggestionText}</span></li>`;
        });
        spellingList.innerHTML = items.join('');
        spellingSection.hidden = false;
        visible = true;
      } else {
        spellingList.innerHTML = '';
        spellingSection.hidden = true;
      }
    }

    if (visible) {
      card.hidden = false;
    }
  }

  function renderDataset(dataset) {
    if (!elements.datasetCard || !elements.datasetSummary || !elements.datasetPreview) {
      return;
    }

    if (!dataset || typeof dataset !== 'object') {
      elements.datasetCard.hidden = true;
      lastDataset = null;
      return;
    }

    lastDataset = dataset;

    const rows = Array.isArray(dataset.rows) ? dataset.rows : [];
    const stats = dataset.statistics && typeof dataset.statistics === 'object' ? dataset.statistics : {};

    if (rows.length === 0) {
      if (elements.datasetSummary) {
        elements.datasetSummary.innerHTML = '<p class="empty">Run an extraction to generate prompt/response pairs.</p>';
      }
      if (elements.datasetPreview) {
        elements.datasetPreview.innerHTML = '';
      }
      if (elements.datasetSubtitle) {
        elements.datasetSubtitle.textContent = 'No dataset rows yet.';
      }
      elements.datasetCard.hidden = false;
      return;
    }

    const recordCount = Math.max(0, Number(stats.records ?? rows.length));
    const avgChars = Math.max(0, Number(stats.average_characters ?? 0));
    const avgWords = Math.max(0, Number(stats.average_words ?? 0));
    const tripleCount = Math.max(0, Number(stats.triple_count ?? 0));
    const synonymCount = Math.max(0, Number(stats.synonym_cluster_count ?? 0));
    const distribution = stats.task_distribution && typeof stats.task_distribution === 'object' ? stats.task_distribution : {};
    const uniqueTasks = extractUniqueTasks(rows, distribution);

    if (elements.datasetSubtitle) {
      const taskLabel = uniqueTasks.length > 0 ? `${formatNumber(uniqueTasks.length)} workflows` : 'no workflows yet';
      elements.datasetSubtitle.textContent = `${formatNumber(recordCount)} records across ${taskLabel}.`;
    }

    if (elements.datasetSummary) {
      const parts = [];
      parts.push(`<div><dt>Records</dt><dd>${formatNumber(recordCount)}</dd></div>`);
      parts.push(`<div><dt>Average length</dt><dd>${formatNumber(avgWords)} words · ${formatNumber(avgChars)} chars</dd></div>`);
      parts.push(`<div><dt>Graph coverage</dt><dd>${formatNumber(tripleCount)} triples · ${formatNumber(synonymCount)} synonym sets</dd></div>`);

      if (uniqueTasks.length > 0) {
        const chips = uniqueTasks
          .map((task) => {
            const count = Number(distribution[task] ?? 0);
            const label = task.replace(/_/g, ' ');
            const countLabel = count > 0 ? ` (${formatNumber(count)})` : '';
            return `<span class="pill">${escapeHtml(label)}${escapeHtml(countLabel)}</span>`;
          })
          .join(' ');
        parts.push(`<div><dt>Workflows</dt><dd class="pill-list">${chips}</dd></div>`);
      }

      elements.datasetSummary.innerHTML = parts.join('');
    }

    if (elements.datasetPreview) {
      const previewRows = rows.slice(0, 4);
      if (previewRows.length === 0) {
        elements.datasetPreview.innerHTML = '<p class="empty">Dataset rows will appear after your first extraction.</p>';
      } else {
        elements.datasetPreview.innerHTML = buildDatasetTable(previewRows);
      }
    }

    elements.datasetCard.hidden = false;
  }

  function extractUniqueTasks(rows, distribution) {
    const tasks = new Set();

    if (distribution && typeof distribution === 'object') {
      Object.keys(distribution).forEach((task) => tasks.add(task));
    }

    rows.forEach((row) => {
      if (!row || typeof row !== 'object') {
        return;
      }
      const values = Array.isArray(row.ai_tasks) ? row.ai_tasks : [];
      values.forEach((task) => {
        if (typeof task === 'string' && task.trim() !== '') {
          tasks.add(task.trim());
        }
      });
    });

    return Array.from(tasks);
  }

  function buildDatasetTable(rows) {
    const body = rows
      .map((row) => {
        const recordId = formatNumber(Number(row.record_id ?? 0) || 0);
        const tasks = Array.isArray(row.ai_tasks)
          ? row.ai_tasks
              .filter((task) => typeof task === 'string' && task.trim() !== '')
              .map((task) => `<span class="pill">${escapeHtml(task.replace(/_/g, ' '))}</span>`)
              .join(' ')
          : '<span class="pill muted">text cleaning</span>';
        const promptRaw = typeof row.prompt === 'string' ? row.prompt : stringifyValue(row.prompt, { pretty: true });
        const responseRaw = stringifyValue(row.ideal_response, { pretty: true });
        const prompt = escapeHtml(truncateText(promptRaw, 220));
        const response = escapeHtml(truncateText(responseRaw, 220));

        return `
          <tr>
            <td data-label="#">${recordId}</td>
            <td data-label="Workflows">${tasks}</td>
            <td data-label="Prompt"><pre class="dataset-snippet">${prompt}</pre></td>
            <td data-label="Ideal response"><pre class="dataset-snippet">${response}</pre></td>
          </tr>
        `;
      })
      .join('');

    return `
      <table class="dataset-table">
        <thead>
          <tr><th>#</th><th>Workflows</th><th>Prompt</th><th>Ideal response</th></tr>
        </thead>
        <tbody>${body}</tbody>
      </table>
    `;
  }

  function getTopEntry(map) {
    const entries = Object.entries(map || {});
    if (entries.length === 0) {
      return null;
    }
    const [label, count] = entries.sort((a, b) => b[1] - a[1])[0];
    return { label, count };
  }

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function capitalize(value) {
    if (typeof value !== 'string' || value.length === 0) {
      return '';
    }
    return value.charAt(0).toUpperCase() + value.slice(1);
  }

  function formatCount(count, unit) {
    const plural = count === 1 ? unit : unit + 's';
    return `${count} ${plural}`;
  }

  function formatNumber(value) {
    const number = Number(value);
    if (!Number.isFinite(number)) {
      return '0';
    }
    return number.toLocaleString();
  }

  function truncateText(value, limit = 220) {
    const text = typeof value === 'string' ? value : String(value ?? '');
    if (text.length <= limit) {
      return text;
    }
    return text.slice(0, Math.max(0, limit - 1)) + '…';
  }

  function stringifyValue(value, { pretty = false } = {}) {
    if (value === null || value === undefined) {
      return '';
    }
    if (typeof value === 'string') {
      return value;
    }

    try {
      return JSON.stringify(value, null, pretty ? 2 : 0);
    } catch (error) {
      return String(value);
    }
  }

  function escapeCsv(value) {
    const stringValue = String(value ?? '');
    if (/[",\n]/.test(stringValue)) {
      return '"' + stringValue.replace(/"/g, '""') + '"';
    }
    return stringValue;
  }

  function datasetToCsv(rows) {
    if (!Array.isArray(rows) || rows.length === 0) {
      return '';
    }

    const columns = [
      'record_id',
      'ai_tasks',
      'input_text',
      'cleaned_text',
      'summary',
      'key_phrases',
      'structured_entities',
      'synonym_clusters',
      'prompt',
      'ideal_response'
    ];

    const header = columns.map(escapeCsv).join(',');

    const lines = rows.map((row) => {
      return columns
        .map((column) => {
          const value = row[column];
          if (column === 'ai_tasks' || column === 'key_phrases') {
            return escapeCsv(Array.isArray(value) ? value.join('; ') : '');
          }
          if (column === 'structured_entities' || column === 'synonym_clusters' || column === 'ideal_response') {
            return escapeCsv(stringifyValue(value));
          }
          if (column === 'record_id') {
            return escapeCsv(String(Number(value ?? 0) || 0));
          }
          return escapeCsv(value ?? '');
        })
        .join(',');
    });

    return [header, ...lines].join('\n');
  }

  function triggerDownload(filename, content, mimeType) {
    const blob = new Blob([content], { type: mimeType });
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = filename;
    document.body.appendChild(anchor);
    anchor.click();
    document.body.removeChild(anchor);
    URL.revokeObjectURL(url);
  }

  function updateInputMeta() {
    if (!elements.textarea || !elements.inputMeta) {
      return;
    }

    const text = elements.textarea.value;
    const trimmed = text.trim();
    const characters = text.length;
    const words = trimmed === '' ? 0 : trimmed.split(/\s+/).length;
    const documents = trimmed === '' ? 0 : trimmed.split(/\n{2,}/).length;

    elements.inputMeta.innerHTML = `
      <span><strong>${characters}</strong> characters</span>
      <span><strong>${words}</strong> words</span>
      <span><strong>${documents}</strong> documents</span>
    `;
  }

  function injectSample(id) {
    if (!elements.textarea || !Object.prototype.hasOwnProperty.call(SAMPLE_TEXT, id)) {
      return;
    }
    elements.textarea.value = SAMPLE_TEXT[id];
    updateInputMeta();
    setStatus('Loaded sample text. Press “Run extraction” to analyse it.', 'success');
  }

  function resetSession() {
    persistedState = null;
    clearResults();
    setStatus('Knowledge graph session cleared. Future runs will start fresh.', 'info');
    updateInputMeta();
  }

  async function handleFileSelection(event) {
    if (!elements.textarea) {
      return;
    }

    const file = event.target.files && event.target.files[0];
    if (!file) {
      return;
    }

    try {
      const text = await file.text();
      elements.textarea.value = text;
      updateInputMeta();
      setStatus(`Loaded ${file.name} (${text.length} characters).`, 'success');
    } catch (error) {
      console.error(error);
      setStatus('Unable to read the selected file.', 'error');
    } finally {
      event.target.value = '';
    }
  }

  function applyGraphResult(rawData, { persistState = true, resetStateWhenDisabled = true } = {}) {
    if (!rawData || typeof rawData !== 'object') {
      throw new Error('No extraction data received.');
    }

    const graph = rawData.graph && typeof rawData.graph === 'object' ? rawData.graph : rawData;

    lastResult = graph;

    if (persistState && graph.state && elements.continueState && elements.continueState.checked) {
      persistedState = graph.state;
    } else if (resetStateWhenDisabled) {
      persistedState = null;
    }

    renderSummary(graph.summary);
    renderList(elements.relations, graph.relations);
    renderList(elements.entities, graph.entities);
    renderTriples(graph.triples);
    renderSynonyms(graph.synonyms);
    renderInsights(graph);
    renderDocumentInsights(graph.documents);
    renderDataset(graph.dataset);

    if (elements.results) {
      elements.results.hidden = false;
    }
  }

  async function submitForm(event) {
    event.preventDefault();
    if (!elements.textarea) {
      return;
    }

    const text = elements.textarea.value.trim();
    if (text === '') {
      setStatus('Paste some text before running the extraction.', 'error');
      clearResults();
      return;
    }

    setStatus('Analysing documents…', 'info');
    if (elements.form) {
      elements.form.classList.add('is-loading');
    }

    try {
      const requestPayload = { text };
      if (persistedState && elements.continueState && elements.continueState.checked) {
        requestPayload.state = persistedState;
      }

      const response = await fetch(apiEndpoint, {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json'
        },
        body: JSON.stringify(requestPayload)
      });

      if (!response.ok) {
        throw new Error('Request failed');
      }

      const payload = await response.json();
      if (!payload || typeof payload !== 'object') {
        throw new Error('Invalid response payload');
      }

      applyGraphResult(payload.data || {}, { persistState: true, resetStateWhenDisabled: true });

      setStatus('Extraction complete.', 'success');
    } catch (error) {
      console.error(error);
      setStatus('Unable to extract entities. Please try again.', 'error');
      clearResults();
      persistedState = null;
    } finally {
      if (elements.form) {
        elements.form.classList.remove('is-loading');
      }
    }
  }

  async function handleScrapeRequest() {
    if (!elements.urlInput) {
      return;
    }

    const url = elements.urlInput.value.trim();
    if (url === '') {
      setStatus('Enter a URL to scrape.', 'error');
      return;
    }

    setStatus('Fetching and analysing the requested page…', 'info');
    if (elements.form) {
      elements.form.classList.add('is-loading');
    }

    try {
      const response = await fetch(scrapeEndpoint, {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({ url })
      });

      if (!response.ok) {
        throw new Error('Request failed');
      }

      const payload = await response.json();
      if (!payload || typeof payload !== 'object') {
        throw new Error('Invalid response payload');
      }

      applyGraphResult(payload.data || {}, { persistState: false, resetStateWhenDisabled: true });

      const source = (payload.data && payload.data.source) || {};
      const label = source.title || source.url || url;
      const characters = typeof source.characters === 'number' ? formatNumber(source.characters) : null;
      const summary = characters ? `${label} • ${characters} characters analysed.` : label;

      setStatus(`${summary} Knowledge graph updated.`, 'success');
      elements.urlInput.value = '';
    } catch (error) {
      console.error(error);
      setStatus('Unable to scrape the requested URL. Please try again.', 'error');
    } finally {
      if (elements.form) {
        elements.form.classList.remove('is-loading');
      }
    }
  }

  function handleDownload() {
    if (!lastResult) {
      return;
    }

    const payload = JSON.stringify(lastResult, null, 2);
    triggerDownload('semantic-workbench.json', payload, 'application/json');
  }

  async function handleCopySummary() {
    if (!lastResult || !navigator.clipboard) {
      return;
    }

    const summary = lastResult.summary || {};
    const lines = Object.entries(summary).map(([key, value]) => `${key.replace(/_/g, ' ')}: ${value}`);
    try {
      await navigator.clipboard.writeText(lines.join('\n'));
      setStatus('Summary copied to clipboard.', 'success');
    } catch (error) {
      console.error(error);
      setStatus('Unable to copy summary.', 'error');
    }
  }

  function handleDownloadDataset(format) {
    if (!lastDataset || typeof lastDataset !== 'object') {
      return;
    }

    const rows = Array.isArray(lastDataset.rows) ? lastDataset.rows : [];
    if (rows.length === 0) {
      setStatus('Run an extraction to build the training dataset before downloading.', 'error');
      return;
    }

    if (format === 'json') {
      const payload = JSON.stringify(rows, null, 2);
      triggerDownload('ai-training-dataset.json', payload, 'application/json');
      setStatus('Dataset JSON downloaded.', 'success');
      return;
    }

    if (format === 'csv') {
      const csv = datasetToCsv(rows);
      triggerDownload('ai-training-dataset.csv', csv, 'text/csv');
      setStatus('Dataset CSV downloaded.', 'success');
    }
  }

  if (elements.form) {
    elements.form.addEventListener('submit', submitForm);
    elements.form.addEventListener('reset', () => {
      setStatus('', '');
      clearResults();
      persistedState = null;
      updateInputMeta();
    });
  }

  if (elements.downloadJson) {
    elements.downloadJson.addEventListener('click', handleDownload);
  }

  if (elements.downloadDatasetJson) {
    elements.downloadDatasetJson.addEventListener('click', () => handleDownloadDataset('json'));
  }

  if (elements.downloadDatasetCsv) {
    elements.downloadDatasetCsv.addEventListener('click', () => handleDownloadDataset('csv'));
  }

  if (elements.copySummary) {
    elements.copySummary.addEventListener('click', handleCopySummary);
  }

  if (elements.resetButton) {
    elements.resetButton.addEventListener('click', () => {
      setStatus('', '');
      clearResults();
      persistedState = null;
      updateInputMeta();
    });
  }

  if (elements.textarea) {
    elements.textarea.addEventListener('input', updateInputMeta);
    updateInputMeta();
  }

  if (elements.urlButton) {
    elements.urlButton.addEventListener('click', handleScrapeRequest);
  }

  if (elements.urlInput) {
    elements.urlInput.addEventListener('keydown', (event) => {
      if (event.key === 'Enter') {
        event.preventDefault();
        handleScrapeRequest();
      }
    });
  }

  if (elements.samplePills && elements.samplePills.length > 0) {
    elements.samplePills.forEach((button) => {
      button.addEventListener('click', () => {
        const sampleId = button.getAttribute('data-sample');
        if (sampleId) {
          injectSample(sampleId);
        }
      });
    });
  }

  if (elements.fileInput) {
    elements.fileInput.addEventListener('change', handleFileSelection);
  }

  if (elements.clearSession) {
    elements.clearSession.addEventListener('click', resetSession);
  }
})();
