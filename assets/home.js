(() => {
    const form = document.querySelector('[data-home-search]');
    const input = form ? form.querySelector('[data-home-search-input]') : null;

    if (!form || !input) {
        return;
    }

    let phrases = [];
    const placeholderAttr = input.getAttribute('data-home-phrases');
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

    if (phrases.length > 1) {
        let index = 0;
        const updatePlaceholder = () => {
            const phrase = phrases[index] ?? '';
            if (phrase) {
                input.setAttribute('placeholder', `Try “${phrase}”`);
            }
        };
        updatePlaceholder();
        window.setInterval(() => {
            index = (index + 1) % phrases.length;
            updatePlaceholder();
        }, 6000);
    } else if (phrases.length === 1 && (!input.placeholder || input.placeholder.trim() === '')) {
        input.setAttribute('placeholder', `Try “${phrases[0]}”`);
    }

    form.addEventListener('submit', (event) => {
        const value = input.value.trim();
        if (value === '') {
            event.preventDefault();
            input.focus();
        }
    });

    document.querySelectorAll('[data-home-suggestion]').forEach((button) => {
        button.addEventListener('click', () => {
            const value = button.getAttribute('data-home-suggestion') || button.textContent || '';
            if (typeof value !== 'string' || value.trim() === '') {
                return;
            }
            input.value = value.trim();
            input.focus();
        });
    });
})();

