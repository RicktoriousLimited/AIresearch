(function () {
  const apiEndpoint = document.body.dataset.api || 'api/analyse.php';

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
    resetButton: document.getElementById('reset-button')
  };

  let lastResult = null;

  function setStatus(message, tone = 'info') {
    if (!elements.status) {
      return;
    }

    elements.status.textContent = message;
    elements.status.className = 'status ' + tone;
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
  }

  function renderSummary(summary) {
    if (!elements.summary) {
      return;
    }

    const entries = Object.entries(summary || {});
    if (entries.length === 0) {
      elements.summary.innerHTML = '<p>No summary data.</p>';
      return;
    }

    const fragments = entries.map(([label, value]) => {
      const safeLabel = escapeHtml(label.replace(/_/g, ' '));
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

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
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
      const response = await fetch(apiEndpoint, {
        method: 'POST',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({ text })
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
      renderSummary(data.summary);
      renderList(elements.relations, data.relations);
      renderList(elements.entities, data.entities);
      renderTriples(data.triples);
      renderSynonyms(data.synonyms);

      if (elements.results) {
        elements.results.hidden = false;
      }

      setStatus('Extraction complete.', 'success');
    } catch (error) {
      console.error(error);
      setStatus('Unable to extract entities. Please try again.', 'error');
      clearResults();
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
    });
  }
})();
