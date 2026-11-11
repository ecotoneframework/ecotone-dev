import './styles/app.css';

const ready = (fn) => (document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', fn) : fn());

const stored = (storage, key) => { try { return JSON.parse(storage.getItem(key)); } catch (error) { return null; } };
const store = (storage, key, value) => { try { storage.setItem(key, JSON.stringify(value)); } catch (error) {} };
const forget = (storage, key) => { try { storage.removeItem(key); } catch (error) {} };
const storageOf = (name) => { try { return window[name]; } catch (error) { return null; } };

const disclosureKey = (details, index) => details.dataset.disclosure || 'position.' + index;

const rememberDisclosures = () => {
    const local = storageOf('localStorage');
    const session = storageOf('sessionStorage');
    const all = [...document.querySelectorAll('main details')];
    all.forEach((details, index) => {
        details.dataset.openByDefault = details.open ? 'true' : 'false';
        const key = disclosureKey(details, index);
        if (local && !key.startsWith('position.')) {
            const preference = stored(local, 'pulse-disclosure.' + key);
            if (preference && preference.openByDefault === details.open) {
                details.open = preference.open;
            }
            details.addEventListener('toggle', () => {
                store(local, 'pulse-disclosure.' + key, {openByDefault: details.dataset.openByDefault === 'true', open: details.open});
            });
        }
    });

    const refreshing = document.querySelector('meta[http-equiv="refresh"]');
    if (!session) {
        return;
    }
    const before = stored(session, 'pulse-refresh-state');
    forget(session, 'pulse-refresh-state');
    if (before && before.url === location.href && Date.now() - before.savedAt < 15000) {
        all.forEach((details, index) => {
            const state = before.disclosures[disclosureKey(details, index)];
            if (state && state.openByDefault === details.open) {
                details.open = state.open;
            }
        });
        requestAnimationFrame(() => window.scrollTo(0, before.scrollY));
    }
    if (refreshing) {
        addEventListener('pagehide', () => {
            const disclosures = {};
            all.forEach((details, index) => {
                disclosures[disclosureKey(details, index)] = {openByDefault: details.dataset.openByDefault === 'true', open: details.open};
            });
            store(session, 'pulse-refresh-state', {url: location.href, savedAt: Date.now(), scrollY: window.scrollY, disclosures});
        });
    }
};

const explainConcepts = () => {
    const toggle = document.querySelector('[data-concepts-toggle]');
    if (!toggle) {
        return;
    }
    const root = document.documentElement;
    const label = () => { toggle.setAttribute('aria-pressed', root.dataset.concepts === 'off' ? 'false' : 'true'); };
    label();
    toggle.addEventListener('click', () => {
        const next = root.dataset.concepts === 'off' ? 'on' : 'off';
        if (next === 'off') {
            root.dataset.concepts = 'off';
            document.querySelectorAll('.concept-body:popover-open').forEach((body) => body.hidePopover());
        } else {
            delete root.dataset.concepts;
        }
        try { localStorage.setItem('pulse-concepts', next); } catch (error) {}
        label();
    });
};

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
        try { localStorage.setItem('ecotone-pulse.intro-seen-on', root.dataset.introDay); } catch (error) {}
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
    rememberDisclosures();
    explainConcepts();
    const themeToggle = document.querySelector('[data-theme-toggle]');
    if (themeToggle) {
        const current = () => document.documentElement.dataset.theme || (matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark');
        const label = () => { themeToggle.textContent = current() === 'dark' ? 'Light theme' : 'Dark theme'; };
        label();
        themeToggle.addEventListener('click', () => {
            const next = current() === 'dark' ? 'light' : 'dark';
            document.documentElement.dataset.theme = next;
            try { localStorage.setItem('pulse-theme', next); } catch (error) {}
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
        const counts = [...bar.querySelectorAll('[data-selected-count]')];
        const nouns = [...bar.querySelectorAll('[data-selected-noun]')];
        const update = () => {
            const selected = boxes.filter((box) => box.checked).length;
            counts.forEach((count) => { count.textContent = selected; });
            nouns.forEach((noun) => { noun.textContent = selected === 1 ? noun.dataset.one : noun.dataset.many; });
            bar.querySelectorAll('button').forEach((button) => { button.disabled = selected === 0; });
            bar.hidden = selected === 0;
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
