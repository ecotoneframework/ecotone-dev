(function () {
    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

    document.querySelectorAll('[data-contention-panel]').forEach(function (panel) {
        const form = panel.querySelector('[data-contention-form]');
        const status = panel.querySelector('[data-contention-status]');
        const buyersCount = panel.querySelector('[data-contention-buyers-count]');

        form.elements.buyers.addEventListener('input', function () {
            buyersCount.textContent = form.elements.buyers.value;
        });

        form.addEventListener('submit', async function (event) {
            event.preventDefault();
            const button = form.querySelector('button');
            button.disabled = true;
            try {
                const response = await fetch(panel.dataset.startContentionUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken },
                    body: JSON.stringify({ seatId: form.elements.seatId.value, buyers: form.elements.buyers.value }),
                });
                const json = await response.json().catch(function () { return {}; });
                if (!response.ok) {
                    status.hidden = false;
                    status.textContent = json.error || json.message || 'The storefront answered ' + response.status + '.';
                    return;
                }
                window.location.href = json.pageUrl;
            } finally {
                button.disabled = false;
            }
        });
    });

    document.querySelectorAll('[data-contention]').forEach(function (page) {
        const list = page.querySelector('[data-contention-buyers]');
        const headline = page.querySelector('[data-contention-headline]');
        const outcomeReason = page.querySelector('[data-contention-outcome-reason]');
        const pollEveryMilliseconds = 1000;
        const maxFailedPolls = 10;
        let failedPolls = 0;

        function buyerItem(buyer) {
            const item = document.createElement('li');
            item.className = 'contention__buyer contention__buyer--' + buyer.state;
            const name = document.createElement('span');
            name.className = 'contention__name';
            name.textContent = buyer.name;
            const state = document.createElement('span');
            state.className = 'contention__state';
            state.textContent = buyer.state;
            item.append(name, ' ', state);
            if (buyer.refusal !== null) {
                const refusal = document.createElement('span');
                refusal.className = 'contention__refusal contention__refusal--' + buyer.refusal;
                refusal.textContent = buyer.refusalExplanation;
                item.append(' ', refusal);
            }
            if (buyer.reason !== null) {
                const reason = document.createElement('span');
                reason.className = 'contention__reason';
                reason.textContent = buyer.reason;
                item.append(' ', reason);
            }
            if (buyer.state === 'held') {
                const continueAs = document.createElement('form');
                continueAs.method = 'post';
                continueAs.action = '/whoami';
                continueAs.className = 'contention__continue';
                [['_token', csrfToken], ['customerId', buyer.customerId]].forEach(function (field) {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = field[0];
                    input.value = field[1];
                    continueAs.append(input);
                });
                const button = document.createElement('button');
                button.type = 'submit';
                button.className = 'button';
                button.textContent = 'Continue as ' + buyer.name;
                continueAs.append(button);
                item.append(' ', continueAs);
            }

            return item;
        }

        async function poll() {
            let decided = false;
            try {
                const response = await fetch(page.dataset.stateUrl, { headers: { Accept: 'application/json' } });
                if (!response.ok) {
                    throw new Error('The storefront answered ' + response.status + '.');
                }
                const contention = await response.json();
                failedPolls = 0;
                headline.textContent = contention.outcome.headline;
                outcomeReason.hidden = contention.outcome.reason === null;
                outcomeReason.textContent = contention.outcome.reason === null ? '' : 'Ticketing said: ' + contention.outcome.reason;
                list.replaceChildren.apply(list, contention.buyers.map(buyerItem));
                decided = contention.buyers.every(function (buyer) { return buyer.state !== 'pending'; });
            } catch (error) {
                failedPolls++;
            }
            if (!decided && failedPolls < maxFailedPolls) {
                setTimeout(poll, pollEveryMilliseconds);
            }
        }

        poll();
    });
})();
