import './styles/app.css';

const ready = (fn) => (document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', fn) : fn());

const showIntro = () => {
    const root = document.documentElement;
    const intro = document.querySelector('[data-intro]');
    if (!intro || !root.classList.contains('intro-pending')) {
        return;
    }
    const reduced = matchMedia('(prefers-reduced-motion: reduce)').matches;
    const timers = [];
    let ended = false;
    const end = () => {
        if (ended) {
            return;
        }
        ended = true;
        timers.forEach(clearTimeout);
        try { localStorage.setItem('ecotone-pulse.intro-seen', root.dataset.pulseVersion); } catch (error) {}
        intro.classList.add('intro-leaving');
        setTimeout(() => { root.classList.remove('intro-pending'); intro.remove(); }, reduced ? 0 : 500);
    };
    const later = (action, milliseconds) => timers.push(setTimeout(action, milliseconds));
    intro.addEventListener('click', end);
    document.addEventListener('keydown', end, {once: true});
    if (reduced) {
        intro.classList.add('intro-beating');
        later(end, 500);
        return;
    }
    later(() => intro.classList.add('intro-beating'), 500);
    later(end, 2600);
};

ready(() => {
    document.documentElement.classList.add('js');
    showIntro();
    const themeToggle = document.querySelector('[data-theme-toggle]');
    if (themeToggle) {
        const current = () => document.documentElement.dataset.theme || (matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark');
        const label = () => { themeToggle.textContent = current() === 'dark' ? 'Light theme' : 'Dark theme'; };
        label();
        themeToggle.addEventListener('click', () => {
            const next = current() === 'dark' ? 'light' : 'dark';
            document.documentElement.dataset.theme = next;
            try { localStorage.setItem('pulse-theme', next); } catch (error) {}
            if (document.querySelector('pre.mermaid.graph-ready')) {
                location.reload();
            }
            label();
        });
    }
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
