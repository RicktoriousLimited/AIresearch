(() => {
    const form = document.querySelector('[data-home-search]');
    const input = form ? form.querySelector('[data-home-search-input]') : null;
    const placeholderAttr = input ? input.getAttribute('data-home-phrases') : null;
    let phrases = [];

    if (placeholderAttr) {
        try {
            const parsed = JSON.parse(placeholderAttr);
            if (Array.isArray(parsed)) {
                phrases = parsed.filter((value) => typeof value === 'string' && value.trim() !== '');
            }
        } catch (error) {
            phrases = [];
        }
    }

    if (input && phrases.length > 1) {
        let index = 0;
        const basePlaceholder = input.getAttribute('placeholder');

        window.setInterval(() => {
            index = (index + 1) % phrases.length;
            const nextPhrase = phrases[index];
            if (typeof nextPhrase === 'string' && nextPhrase.trim() !== '') {
                input.setAttribute('placeholder', `Try “${nextPhrase}”`);
            }
        }, 6000);

        if (!basePlaceholder || basePlaceholder.trim() === '') {
            input.setAttribute('placeholder', `Try “${phrases[0]}”`);
        }
    }

    if (form && input) {
        form.addEventListener('submit', (event) => {
            const value = input.value.trim();
            if (value === '') {
                event.preventDefault();
                input.focus();
            }
        });
    }

    const attachQueryHandler = (element, valueSource) => {
        if (!element || !input) {
            return;
        }

        element.addEventListener('click', () => {
            const value = typeof valueSource === 'function' ? valueSource() : valueSource;
            if (typeof value !== 'string' || value.trim() === '') {
                return;
            }

            input.value = value;
            input.focus();
        });
    };

    const chips = document.querySelectorAll('[data-home-chip]');
    chips.forEach((chip) => {
        const value = chip.getAttribute('data-home-chip') || chip.textContent || '';
        attachQueryHandler(chip, value);
    });

    const tags = document.querySelectorAll('[data-home-suggestion]');
    tags.forEach((tag) => {
        const value = tag.getAttribute('data-home-suggestion') || tag.textContent || '';
        attachQueryHandler(tag, value);
    });
})();
