(function () {
    const picker = document.querySelector('[data-seat-picker]');
    if (!picker) {
        return;
    }
    const basket = document.querySelector('[data-basket]');
    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
    const ordersUrl = picker.dataset.ordersUrl;
    const currency = picker.dataset.currency;
    const priceCents = { vip: Number(picker.dataset.vipPriceCents), standard: Number(picker.dataset.standardPriceCents) };
    const tierLabels = { vip: 'VIP', standard: 'Standard' };
    const pendingSeatIds = new Set();
    let orderId = picker.dataset.orderId || null;
    let state = null;
    let message = null;

    function price(cents) {
        return currency + ' ' + (cents / 100).toFixed(2);
    }

    async function post(url, body) {
        const response = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken },
            body: JSON.stringify(body || {}),
        });
        const json = await response.json().catch(function () { return {}; });
        if (!response.ok) {
            throw new Error(json.error || json.message || 'The storefront answered ' + response.status + '.');
        }
        return json;
    }

    async function openOrder() {
        if (orderId === null) {
            orderId = (await post(picker.dataset.startOrderUrl)).orderId;
            picker.dataset.orderId = orderId;
        }
        return orderId;
    }

    function seatButtons() {
        return picker.querySelectorAll('[data-seat-id]');
    }

    function seatStateOf(seatId, yours, taken, sold, refusal) {
        if (pendingSeatIds.has(seatId)) {
            return 'pending';
        }
        if (yours.has(seatId)) {
            return 'yours';
        }
        if (sold.has(seatId)) {
            return 'sold';
        }
        if (taken.has(seatId)) {
            return 'held';
        }
        if (refusal !== null && refusal.seatId === seatId) {
            return 'refused';
        }
        return 'free';
    }

    function render() {
        const yours = new Map((state ? state.heldSeats : []).map(function (seat) { return [seat.seatId, seat]; }));
        const taken = new Set(state ? state.takenSeatIds : JSON.parse(picker.dataset.takenSeatIds || '[]'));
        const sold = new Set(state ? state.soldSeatIds : JSON.parse(picker.dataset.soldSeatIds || '[]'));
        const refusal = state ? state.lastRefusal : null;
        const open = state === null || state.status === 'started' || state.status === 'seats_held';
        const counts = { free: 0, yours: 0, pending: 0, held: 0, sold: 0, refused: 0 };
        const labels = { free: 'free', yours: 'yours', pending: 'asking ticketing', held: 'held by someone', sold: 'sold' };
        const tiers = new Map();

        seatButtons().forEach(function (button) {
            const seatId = button.dataset.seatId;
            const seatState = seatStateOf(seatId, yours, taken, sold, refusal);
            counts[seatState]++;
            tiers.set(seatId, button.dataset.tier);
            ['yours', 'pending', 'held', 'sold', 'refused'].forEach(function (name) {
                button.classList.toggle('seat--' + name, seatState === name);
            });
            button.disabled = !open || seatState === 'pending' || seatState === 'held' || seatState === 'sold';
            const label = button.dataset.seatCode + ', ' + tierLabels[button.dataset.tier] + ', ' + (seatState === 'refused' ? 'refused: ' + refusal.reason : labels[seatState]);
            button.setAttribute('aria-label', label);
            button.title = label;
        });
        Object.keys(counts).forEach(function (name) {
            const count = picker.querySelector('[data-seat-count="' + name + '"]');
            if (count) {
                count.textContent = name === 'free' ? counts.free + counts.refused : counts[name];
            }
        });

        const seatList = basket.querySelector('[data-basket-seats]');
        let totalCents = 0;
        seatList.replaceChildren(...Array.from(yours.values()).map(function (seat) {
            const tier = tiers.get(seat.seatId) || 'standard';
            totalCents += priceCents[tier];
            const item = document.createElement('li');
            item.className = 'basket__seat';
            const code = document.createElement('strong');
            code.textContent = seat.seatCode;
            const details = document.createElement('span');
            details.textContent = tierLabels[tier] + ' · ' + price(priceCents[tier]);
            const countdown = document.createElement('span');
            countdown.className = 'countdown';
            countdown.dataset.expiresAt = seat.expiresAt;
            item.append(code, details, countdown);
            return item;
        }));
        basket.querySelector('[data-basket-empty]').hidden = yours.size > 0 || pendingSeatIds.size > 0;
        basket.querySelector('[data-basket-total]').hidden = yours.size === 0;
        basket.querySelector('[data-basket-total-amount]').textContent = price(totalCents);

        const expiry = basket.querySelector('[data-basket-expiry]');
        expiry.hidden = !state || state.earliestExpiresAt === null;
        if (state && state.earliestExpiresAt !== null) {
            basket.querySelector('[data-basket-countdown]').dataset.expiresAt = state.earliestExpiresAt;
        }

        const refusalLine = basket.querySelector('[data-basket-refusal]');
        const shownRefusal = message || (refusal !== null ? 'Seat ' + refusal.seatId.split('/').pop() + ' was refused: ' + refusal.reason : null);
        refusalLine.hidden = shownRefusal === null;
        refusalLine.textContent = shownRefusal || '';

        const checkout = basket.querySelector('[data-basket-checkout]');
        checkout.hidden = !(state && state.status === 'seats_held') || pendingSeatIds.size > 0 || orderId === null;
        if (checkout.hidden) {
            checkout.removeAttribute('href');
        } else {
            checkout.href = ordersUrl + '/' + orderId + '/checkout';
        }
        window.demoCountdown.tick();
    }

    async function refresh() {
        if (orderId === null) {
            const seats = await fetch(picker.dataset.takenSeatsUrl, { headers: { Accept: 'application/json' } });
            if (seats.ok) {
                const json = await seats.json();
                picker.dataset.takenSeatIds = JSON.stringify(json.takenSeatIds);
                picker.dataset.soldSeatIds = JSON.stringify(json.soldSeatIds);
                render();
            }
            return;
        }
        const response = await fetch(ordersUrl + '/' + orderId + '/state.json', { headers: { Accept: 'application/json' } });
        if (!response.ok) {
            return;
        }
        state = await response.json();
        state.heldSeats.forEach(function (seat) { pendingSeatIds.delete(seat.seatId); });
        if (state.lastRefusal !== null) {
            pendingSeatIds.delete(state.lastRefusal.seatId);
        }
        if (state.status !== 'started' && state.status !== 'seats_held') {
            pendingSeatIds.clear();
        }
        render();
    }

    picker.addEventListener('click', async function (event) {
        const button = event.target.closest('[data-seat-id]');
        if (!button || button.disabled) {
            return;
        }
        const seatId = button.dataset.seatId;
        message = null;
        try {
            if (button.classList.contains('seat--yours')) {
                await post(ordersUrl + '/' + orderId + '/seats/release', { seatId: seatId });
            } else {
                pendingSeatIds.add(seatId);
                render();
                await post(ordersUrl + '/' + (await openOrder()) + '/seats', { seatId: seatId });
            }
        } catch (error) {
            pendingSeatIds.delete(seatId);
            message = error.message;
        }
        await refresh();
        render();
    });

    render();
    refresh();
    setInterval(refresh, 1000);
})();
