(function () {
  const apiEndpoint = document.body.dataset.api || 'api/analyse.php';

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
    documentSpellingList: document.getElementById('document-spelling-list')
  };

  let lastResult = null;
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

    const sorted = entries.sort((a, b) => b[1] - a[1]).slice(0, 25);
    const items = sorted.map(([label, value]) => {
      return `<li><span>${escapeHtml(label)}</span><span>${escapeHtml(String(value))}</span></li>`;
    });

    container.innerHTML = `<ul>${items.join('')}</ul>`;
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

      const data = payload.data || {};
      lastResult = data;
      if (data.state && elements.continueState && elements.continueState.checked) {
        persistedState = data.state;
      } else {
        persistedState = null;
      }

      renderSummary(data.summary);
      renderList(elements.relations, data.relations);
      renderList(elements.entities, data.entities);
      renderTriples(data.triples);
      renderSynonyms(data.synonyms);
      renderInsights(data);
      renderDocumentInsights(data.documents);

      if (elements.results) {
        elements.results.hidden = false;
      }

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

  function handleDownload() {
    if (!lastResult) {
      return;
    }

    const blob = new Blob([JSON.stringify(lastResult, null, 2)], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = 'semantic-workbench.json';
    document.body.appendChild(anchor);
    anchor.click();
    document.body.removeChild(anchor);
    URL.revokeObjectURL(url);
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
