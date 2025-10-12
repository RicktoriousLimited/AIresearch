(() => {
    const forms = Array.from(document.querySelectorAll('[data-home-search]'));
    const inputs = forms
        .map((form) => form.querySelector('[data-home-search-input]'))
        .filter((input) => input instanceof HTMLInputElement);

    if (inputs.length === 0) {
        return;
    }

    let phrases = [];
    for (const input of inputs) {
        const placeholderAttr = input.getAttribute('data-home-phrases');
        if (placeholderAttr) {
            try {
                const parsed = JSON.parse(placeholderAttr);
                if (Array.isArray(parsed)) {
                    phrases = parsed.filter((value) => typeof value === 'string' && value.trim() !== '');
                    break;
                }
            } catch (error) {
                phrases = [];
            }
        }
    }

    if (phrases.length > 1) {
        let index = 0;
        const updatePlaceholder = () => {
            const phrase = phrases[index] ?? '';
            if (phrase) {
                inputs.forEach((input) => {
                    input.setAttribute('placeholder', `Try “${phrase}”`);
                });
            }
        };
        updatePlaceholder();
        window.setInterval(() => {
            index = (index + 1) % phrases.length;
            updatePlaceholder();
        }, 6000);
    } else if (phrases.length === 1) {
        inputs.forEach((input) => {
            if (!input.placeholder || input.placeholder.trim() === '') {
                input.setAttribute('placeholder', `Try “${phrases[0]}”`);
            }
        });
    }

    forms.forEach((form, index) => {
        const input = inputs[index] ?? inputs[0];
        if (!input) {
            return;
        }
        form.addEventListener('submit', (event) => {
            const value = input.value.trim();
            if (value === '') {
                event.preventDefault();
                input.focus();
            }
        });
    });

    document.querySelectorAll('[data-home-suggestion]').forEach((button) => {
        button.addEventListener('click', () => {
            const value = button.getAttribute('data-home-suggestion') || button.textContent || '';
            if (typeof value !== 'string' || value.trim() === '') {
                return;
            }
            const target = inputs[0];
            if (target) {
                target.value = value.trim();
                target.focus();
            }
        });
    });
})();

