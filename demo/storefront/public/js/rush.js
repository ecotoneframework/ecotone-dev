(function () {
    document.querySelectorAll('[data-rush-panel]').forEach(function (panel) {
        const form = panel.querySelector('[data-rush-form]');
        const status = panel.querySelector('[data-rush-status]');
        const storageKey = 'rush:' + panel.dataset.concertId;
        const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
        let stateUrl = null;

        function remember(url) {
            try {
                sessionStorage.setItem(storageKey, url);
            } catch (error) {
            }
        }

        function remembered() {
            try {
                return sessionStorage.getItem(storageKey);
            } catch (error) {
                return null;
            }
        }

        function show(text, failed) {
            status.hidden = false;
            status.textContent = text;
            status.classList.toggle('rush-panel__status--failed', failed);
        }

        async function poll() {
            if (stateUrl === null) {
                return;
            }
            const response = await fetch(stateUrl, { headers: { Accept: 'application/json' } });
            if (!response.ok) {
                return;
            }
            const state = await response.json();
            show(
                'Rush of ' + state.buyersRequested + ' buyers: ' + state.created + ' created · ' + state.held + ' held · '
                    + state.paying + ' paying · ' + state.paid + ' paid · ' + state.failed + ' failed · ' + state.gaveUp + ' gave up'
                    + (state.finishedAt === null ? '' : ' · every buyer has had a go'),
                false,
            );
        }

        form.addEventListener('submit', async function (event) {
            event.preventDefault();
            const settings = {};
            new FormData(form).forEach(function (value, name) { settings[name] = Number(value); });
            const button = form.querySelector('button');
            button.disabled = true;
            try {
                const response = await fetch(panel.dataset.startRushUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken },
                    body: JSON.stringify(settings),
                });
                const json = await response.json().catch(function () { return {}; });
                if (!response.ok) {
                    show(json.error || json.message || 'The storefront answered ' + response.status + '.', true);
                    return;
                }
                stateUrl = json.stateUrl;
                remember(stateUrl);
                show('Rush started, waiting for the first buyers.', false);
            } finally {
                button.disabled = false;
            }
        });

        stateUrl = remembered();
        poll();
        setInterval(poll, 1000);
    });
})();
