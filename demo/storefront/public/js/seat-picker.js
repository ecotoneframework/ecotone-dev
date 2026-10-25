(function () {
    const picker = document.querySelector('[data-seat-picker]');
    if (!picker) {
        return;
    }
    const basket = document.querySelector('[data-basket]');
    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
    const ordersUrl = picker.dataset.ordersUrl;
    const pendingSeatIds = new Set();
    let orderId = picker.dataset.orderId || null;
    let state = null;
    let message = null;

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

    function render() {
        const yours = new Map((state ? state.heldSeats : []).map(function (seat) { return [seat.seatId, seat]; }));
        const taken = new Set(state ? state.takenSeatIds : JSON.parse(picker.dataset.takenSeatIds || '[]'));
        const refusal = state ? state.lastRefusal : null;
        const open = state === null || state.status === 'started' || state.status === 'seats_held';

        seatButtons().forEach(function (button) {
            const seatId = button.dataset.seatId;
            const refused = refusal !== null && refusal.seatId === seatId && !yours.has(seatId) && !pendingSeatIds.has(seatId);
            button.classList.toggle('seat--yours', yours.has(seatId));
            button.classList.toggle('seat--taken', taken.has(seatId) && !yours.has(seatId));
            button.classList.toggle('seat--pending', pendingSeatIds.has(seatId));
            button.classList.toggle('seat--refused', refused);
            button.disabled = !open || (taken.has(seatId) && !yours.has(seatId)) || pendingSeatIds.has(seatId);
            button.title = refused ? refusal.reason : taken.has(seatId) ? 'held by someone' : seatId;
        });

        const seatList = basket.querySelector('[data-basket-seats]');
        seatList.replaceChildren(...Array.from(yours.values()).map(function (seat) {
            const item = document.createElement('li');
            const countdown = document.createElement('span');
            countdown.className = 'countdown';
            countdown.dataset.expiresAt = seat.expiresAt;
            item.append(seat.seatCode + ' ', countdown);
            return item;
        }));
        basket.querySelector('[data-basket-empty]').hidden = yours.size > 0 || pendingSeatIds.size > 0;

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
        checkout.hidden = !(state && state.status === 'seats_held');
        if (orderId !== null) {
            checkout.href = ordersUrl + '/' + orderId + '/checkout';
        }
        window.demoCountdown.tick();
    }

    async function refresh() {
        if (orderId === null) {
            const taken = await fetch(picker.dataset.takenSeatsUrl, { headers: { Accept: 'application/json' } });
            if (taken.ok) {
                picker.dataset.takenSeatIds = JSON.stringify((await taken.json()).takenSeatIds);
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
