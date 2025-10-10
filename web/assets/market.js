(function () {
    const datasetEl = document.getElementById('company-dataset');
    const input = document.getElementById('company-query');

    if (!datasetEl || !input) {
        return;
    }

    let companies = [];
    try {
        const payload = datasetEl.textContent ? JSON.parse(datasetEl.textContent) : [];
        if (Array.isArray(payload)) {
            companies = payload;
        }
    } catch (error) {
        console.warn('Unable to parse market dataset', error);
    }

    if (companies.length === 0) {
        return;
    }

    const wrapper = document.createElement('div');
    wrapper.className = 'search-suggestions';
    input.parentElement?.appendChild(wrapper);

    function renderSuggestions(value) {
        const normalized = value.trim().toLowerCase();
        if (normalized === '') {
            wrapper.innerHTML = '';
            wrapper.classList.remove('visible');
            return;
        }

        const results = companies.filter((company) => {
            return (
                company.symbol.toLowerCase().includes(normalized) ||
                company.name.toLowerCase().includes(normalized) ||
                company.sector.toLowerCase().includes(normalized)
            );
        }).slice(0, 5);

        if (results.length === 0) {
            wrapper.innerHTML = '';
            wrapper.classList.remove('visible');
            return;
        }

        wrapper.innerHTML = '';
        for (const company of results) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'suggestion-item';
            button.innerHTML = `
                <strong>${company.symbol}</strong>
                <span>${company.name}</span>
                <em>${company.sector}</em>
            `;
            button.addEventListener('click', () => {
                input.value = company.symbol;
                wrapper.classList.remove('visible');
                input.form?.submit();
            });
            wrapper.appendChild(button);
        }

        wrapper.classList.add('visible');
    }

    input.addEventListener('input', (event) => {
        renderSuggestions(event.target.value);
    });

    input.addEventListener('focus', (event) => {
        if (event.target.value) {
            renderSuggestions(event.target.value);
        }
    });

    document.addEventListener('click', (event) => {
        if (event.target === input || wrapper.contains(event.target)) {
            return;
        }

        wrapper.classList.remove('visible');
    });
})();
