(function () {
    document.querySelectorAll('[data-rush-panel]').forEach(function (panel) {
        const form = panel.querySelector('[data-rush-form]');
        const status = panel.querySelector('[data-rush-status]');
        const storageKey = 'rush:' + panel.dataset.concertId;
        const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
        const pollEveryMilliseconds = 1000;
        const maxFailedPolls = 10;
        let stateUrl = null;
        let stateSeen = false;
        let failedPolls = 0;
        let nextPoll = null;
        let pollInFlight = false;

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

        function forget() {
            try {
                sessionStorage.removeItem(storageKey);
            } catch (error) {
            }
        }

        function follow(url, seen) {
            stateUrl = url;
            stateSeen = seen;
            failedPolls = 0;
            if (!pollInFlight) {
                clearTimeout(nextPoll);
                poll();
            }
        }

        function stopFollowing() {
            stateUrl = null;
            clearTimeout(nextPoll);
            nextPoll = null;
        }

        function show(text, failed) {
            status.hidden = false;
            status.textContent = text;
            status.classList.toggle('rush-panel__status--failed', failed);
        }

        async function poll() {
            nextPoll = null;
            if (stateUrl === null) {
                return;
            }
            const polledUrl = stateUrl;
            pollInFlight = true;
            try {
                const response = await fetch(polledUrl, { headers: { Accept: 'application/json' } });
                if (response.status === 404 && stateSeen) {
                    forget();
                    stopFollowing();
                    return;
                }
                if (!response.ok) {
                    throw new Error('The storefront answered ' + response.status + '.');
                }
                const state = await response.json();
                if (polledUrl !== stateUrl) {
                    return;
                }
                stateSeen = true;
                failedPolls = 0;
                show(
                    'Rush of ' + state.buyersRequested + ' buyers: ' + state.created + ' created · ' + state.held + ' held · '
                        + state.paying + ' paying · ' + state.paid + ' paid · ' + state.issued + ' issued · ' + state.failed + ' failed · ' + state.gaveUp + ' gave up'
                        + (state.finishedAt === null ? '' : ' · every buyer has had a go'),
                    false,
                );
                if (state.finishedAt !== null) {
                    stopFollowing();
                }
            } catch (error) {
                failedPolls++;
                if (failedPolls >= maxFailedPolls) {
                    stopFollowing();
                    show('Stopped following the rush: its last ' + failedPolls + ' polls failed (' + error.message + '). Reload the page to follow it again.', true);
                }
            } finally {
                pollInFlight = false;
                if (stateUrl !== null && nextPoll === null) {
                    nextPoll = setTimeout(poll, pollEveryMilliseconds);
                }
            }
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
                remember(json.stateUrl);
                show('Rush started, waiting for the first buyers.', false);
                follow(json.stateUrl, false);
            } finally {
                button.disabled = false;
            }
        });

        const rememberedUrl = remembered();
        if (rememberedUrl !== null) {
            follow(rememberedUrl, true);
        }
    });
})();
