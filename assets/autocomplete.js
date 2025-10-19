(() => {
    const AUTOCOMPLETE_ATTR = 'data-autocomplete';
    const SOURCE_ATTR = 'data-autocomplete-source';
    const PANEL_SELECTOR = '[data-autocomplete-panel]';
    const CONTAINER_ATTR = 'data-autocomplete-container';

    const parseSuggestions = (input) => {
        const sourceAttr = input.getAttribute(SOURCE_ATTR);
        if (!sourceAttr) {
            return [];
        }

        try {
            const parsed = JSON.parse(sourceAttr);
            if (Array.isArray(parsed)) {
                return parsed
                    .map((value) => (typeof value === 'string' ? value.trim() : ''))
                    .filter((value, index, array) => value !== '' && array.indexOf(value) === index);
            }
        } catch (error) {
            return [];
        }

        return [];
    };

    const normalise = (value) => value.normalize('NFKD').replace(/[\u0300-\u036f]/g, '').toLowerCase();

    const highlightMatch = (text, query) => {
        if (!query) {
            return text;
        }

        const escaped = query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        const regex = new RegExp(`(${escaped})`, 'ig');
        return text.replace(regex, '<strong>$1</strong>');
    };

    const createPanel = (container) => {
        let panel = container.querySelector(PANEL_SELECTOR);
        if (!panel) {
            panel = document.createElement('div');
            panel.setAttribute('data-autocomplete-panel', '');
            panel.setAttribute('role', 'listbox');
            panel.id = `${container.id || 'autocomplete'}-panel-${Math.random().toString(16).slice(2)}`;
            container.appendChild(panel);
        }
        return panel;
    };

    const bindAutocomplete = (input) => {
        const container = input.closest(`[${CONTAINER_ATTR}]`) || input.parentElement;
        if (!container) {
            return;
        }

        const suggestions = parseSuggestions(input);
        if (suggestions.length === 0) {
            return;
        }

        const panel = createPanel(container);
        input.setAttribute('aria-haspopup', 'listbox');
        input.setAttribute('aria-expanded', 'false');
        input.setAttribute('autocomplete', 'off');
        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-controls', panel.id);

        let activeIndex = -1;
        let visibleSuggestions = [];

        const closePanel = () => {
            activeIndex = -1;
            visibleSuggestions = [];
            panel.dataset.open = 'false';
            panel.innerHTML = '';
            input.setAttribute('aria-expanded', 'false');
        };

        const openPanel = () => {
            if (visibleSuggestions.length === 0) {
                closePanel();
                return;
            }

            panel.dataset.open = 'true';
            input.setAttribute('aria-expanded', 'true');
        };

        const selectSuggestion = (index) => {
            if (index < 0 || index >= visibleSuggestions.length) {
                return;
            }

            input.value = visibleSuggestions[index];
            input.dispatchEvent(new Event('input', { bubbles: true }));
            closePanel();
            input.focus();
        };

        const renderPanel = () => {
            panel.innerHTML = '';
            panel.dataset.open = 'false';

            if (visibleSuggestions.length === 0) {
                closePanel();
                return;
            }

            visibleSuggestions.forEach((suggestion, index) => {
                const option = document.createElement('button');
                option.type = 'button';
                option.className = 'autocomplete-option';
                option.setAttribute('role', 'option');
                option.dataset.index = String(index);
                option.dataset.active = index === activeIndex ? 'true' : 'false';
                option.innerHTML = highlightMatch(suggestion, input.value.trim());
                option.addEventListener('mousedown', (event) => {
                    event.preventDefault();
                });
                option.addEventListener('click', () => {
                    selectSuggestion(index);
                });
                panel.appendChild(option);
            });

            openPanel();
        };

        const scoreSuggestion = (value, query) => {
            if (!value || !query) {
                return 0;
            }

            let score = 0;
            if (value.startsWith(query)) {
                score += 3;
            }
            if (value.includes(query)) {
                score += 1.5;
            }

            const tokens = value.split(/\s+/);
            if (tokens.some((token) => token.startsWith(query))) {
                score += 1.2;
            }

            const delta = Math.abs(value.length - query.length);
            score += Math.max(0, 1 - (delta / 40));

            return score;
        };

        const updateSuggestions = () => {
            const rawQuery = normalise(input.value.trim());
            if (rawQuery === '') {
                visibleSuggestions = suggestions.slice(0, 8);
            } else {
                const ranked = suggestions
                    .map((suggestion) => {
                        const normalised = normalise(suggestion);
                        const score = scoreSuggestion(normalised, rawQuery);
                        if (score <= 0 && !normalised.includes(rawQuery)) {
                            return null;
                        }
                        return {
                            value: suggestion,
                            score: score > 0 ? score : 0.1,
                        };
                    })
                    .filter((entry) => entry !== null)
                    .sort((left, right) => {
                        if (left.score === right.score) {
                            return left.value.localeCompare(right.value);
                        }

                        return right.score - left.score;
                    });

                visibleSuggestions = ranked.slice(0, 8).map((entry) => entry.value);
            }

            if (rawQuery !== '' && visibleSuggestions.length === 0) {
                visibleSuggestions = suggestions.slice(0, 6);
            }

            activeIndex = visibleSuggestions.length > 0 ? 0 : -1;
            renderPanel();
        };

        input.addEventListener('input', updateSuggestions);
        input.addEventListener('focus', updateSuggestions);

        input.addEventListener('keydown', (event) => {
            if (panel.dataset.open !== 'true') {
                return;
            }

            if (event.key === 'ArrowDown') {
                event.preventDefault();
                activeIndex = (activeIndex + 1) % visibleSuggestions.length;
                Array.from(panel.children).forEach((child, index) => {
                    child.dataset.active = index === activeIndex ? 'true' : 'false';
                });
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                activeIndex = (activeIndex - 1 + visibleSuggestions.length) % visibleSuggestions.length;
                Array.from(panel.children).forEach((child, index) => {
                    child.dataset.active = index === activeIndex ? 'true' : 'false';
                });
            } else if (event.key === 'Enter') {
                if (activeIndex >= 0 && activeIndex < visibleSuggestions.length) {
                    event.preventDefault();
                    selectSuggestion(activeIndex);
                }
            } else if (event.key === 'Escape') {
                closePanel();
            }
        });

        document.addEventListener('click', (event) => {
            if (!container.contains(event.target)) {
                closePanel();
            }
        });
    };

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll(`[${AUTOCOMPLETE_ATTR}]`).forEach((input) => {
            bindAutocomplete(input);
        });
    });
})();
