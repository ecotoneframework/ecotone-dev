import './styles/app.css';

const ready = (fn) => (document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', fn) : fn());

ready(() => {
    document.documentElement.classList.add('js');
    document.querySelectorAll('[data-toggle-run]').forEach((button) => {
        button.addEventListener('click', () => {
            const open = button.getAttribute('aria-expanded') !== 'true';
            button.setAttribute('aria-expanded', open ? 'true' : 'false');
            document.querySelectorAll('tr[data-run="' + button.dataset.toggleRun + '"]').forEach((row) => row.classList.toggle('open', open));
        });
    });
    document.querySelectorAll('[data-copy]').forEach((button) => {
        button.hidden = false;
        button.addEventListener('click', async () => {
            const source = document.querySelector(button.dataset.copy);
            if (source && navigator.clipboard) {
                await navigator.clipboard.writeText(source.textContent);
                const label = button.textContent;
                button.textContent = 'Copied';
                setTimeout(() => { button.textContent = label; }, 1500);
            }
        });
    });

    const bar = document.querySelector('[data-selection-bar]');
    if (bar) {
        const boxes = [...document.querySelectorAll('[data-dead-letter] input[type="checkbox"]')];
        const count = bar.querySelector('[data-selected-count]');
        const update = () => {
            const selected = boxes.filter((box) => box.checked).length;
            count.textContent = selected;
            bar.querySelectorAll('button').forEach((button) => { button.disabled = selected === 0; });
        };
        boxes.forEach((box) => box.addEventListener('change', update));
        update();
    }

    document.addEventListener('submit', (event) => {
        const pressed = event.submitter;
        if (pressed && pressed.hasAttribute('data-await')) {
            pressed.setAttribute('aria-busy', 'true');
            setTimeout(() => { pressed.disabled = true; }, 0);
        }
    });

    const toast = document.querySelector('.toast[data-notice]');
    if (toast) {
        setTimeout(() => { toast.hidden = true; }, 10000);
    }
});
