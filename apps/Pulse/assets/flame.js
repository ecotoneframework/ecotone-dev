const MIN_SPAN = 50;

const niceStep = (range) => {
    const rough = range / 5;
    const magnitude = 10 ** Math.floor(Math.log10(rough));
    return Math.max(1, Math.round([1, 2, 5, 10].map((multiple) => multiple * magnitude).find((step) => step >= rough)));
};

const format = (micro, range) => {
    const text = (value) => String(+value.toFixed(2));
    return range >= 2000000 ? text(micro / 1000000) + ' s' : text(micro / 1000) + ' ms';
};

const ready = (fn) => (document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', fn) : fn());

ready(() => {
    const flame = document.querySelector('[data-flame]');
    if (!flame) {
        return;
    }
    const total = Math.max(1, Number(flame.dataset.timelineTotal));
    const plot = flame.querySelector('[data-flame-plot]');
    const lanes = plot.querySelector('.flame-lanes');
    const axis = plot.querySelector('.flame-axis');
    const bars = [...flame.querySelectorAll('[data-flame-span]')];
    const waits = [...flame.querySelectorAll('[data-flame-wait]')];
    const panels = flame.querySelector('[data-flame-panels]');
    const range = flame.querySelector('[data-flame-range]');
    const zoomToSpan = flame.querySelector('[data-zoom="span"]');
    const tip = Object.assign(document.createElement('div'), {className: 'flame-tip', hidden: true});
    tip.setAttribute('role', 'tooltip');
    flame.append(tip);
    let view = [0, total];
    let selected = null;

    bars.forEach((bar) => {
        bar.dataset.tip = bar.title;
        bar.removeAttribute('title');
    });
    waits.forEach((wait) => {
        wait.dataset.tip = wait.title;
        wait.removeAttribute('title');
    });

    const place = (element, minimum) => {
        const start = Number(element.dataset.offset);
        const end = start + Number(element.dataset.duration);
        const span = view[1] - view[0];
        element.hidden = end < view[0] || start > view[1];
        element.style.left = ((start - view[0]) / span * 100) + '%';
        element.style.width = Math.max(minimum, Number(element.dataset.duration) / span * 100) + '%';
    };

    const draw = () => {
        bars.forEach((bar) => place(bar, 0.5));
        waits.forEach((wait) => place(wait, 0));
        const span = view[1] - view[0];
        const step = niceStep(span);
        axis.replaceChildren();
        for (let at = Math.ceil(view[0] / step) * step; at <= view[1]; at += step) {
            const tick = Object.assign(document.createElement('span'), {className: 'flame-tick', textContent: format(at, total)});
            tick.dataset.flameTick = '';
            tick.style.left = ((at - view[0]) / span * 100) + '%';
            axis.append(tick);
        }
        range.textContent = 'Showing ' + format(view[0], total) + ' – ' + format(view[1], total) + '. Drag across the graph to zoom to a range.';
    };

    const zoomTo = (from, to) => {
        const start = Math.max(0, Math.min(from, to));
        const end = Math.min(total, Math.max(from, to));
        view = end - start < MIN_SPAN ? view : [start, end];
        draw();
    };

    const select = (bar) => {
        panels.querySelectorAll('.span-panel.open').forEach((panel) => panel.classList.remove('open'));
        bars.forEach((other) => {
            other.classList.toggle('selected', other === bar);
            other.removeAttribute('aria-current');
        });
        selected = bar;
        zoomToSpan.disabled = bar === null;
        panels.classList.toggle('has-selection', bar !== null);
        if (bar !== null) {
            bar.setAttribute('aria-current', 'true');
            const panel = document.getElementById(bar.hash.slice(1));
            panel.classList.add('open');
        }
    };

    bars.forEach((bar) => bar.addEventListener('click', (event) => {
        event.preventDefault();
        select(bar);
        history.replaceState(null, '', bar.hash);
    }));

    const showTip = (event) => {
        const target = event.target.closest('[data-tip]');
        if (!target) {
            return;
        }
        const box = target.getBoundingClientRect();
        tip.textContent = target.dataset.tip;
        tip.hidden = false;
        let left = Math.max(4, Math.min(box.left, window.innerWidth - tip.offsetWidth - 4));
        let top = box.bottom + 6;
        const selected = document.querySelector('[data-flame-panels] .span-panel.open');
        const panel = selected === null ? null : selected.getBoundingClientRect();
        if (panel !== null && left + tip.offsetWidth > panel.left && left < panel.right && top + tip.offsetHeight > panel.top && top < panel.bottom) {
            left = Math.max(4, panel.left - tip.offsetWidth - 4);
            if (left + tip.offsetWidth > panel.left) {
                top = Math.max(4, box.top - tip.offsetHeight - 6);
            }
        }
        tip.style.left = left + 'px';
        tip.style.top = top + 'px';
    };
    const hideTip = () => { tip.hidden = true; };
    lanes.addEventListener('mouseover', showTip);
    lanes.addEventListener('focusin', showTip);
    lanes.addEventListener('mouseout', hideTip);
    lanes.addEventListener('focusout', hideTip);
    document.addEventListener('keydown', (event) => { if (event.key === 'Escape') { hideTip(); } });

    lanes.addEventListener('keydown', (event) => {
        const current = event.target.closest('[data-flame-span]');
        if (!current || !['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(event.key)) {
            return;
        }
        const lane = Number(current.dataset.lane);
        const offset = Number(current.dataset.offset);
        const visible = bars.filter((bar) => !bar.hidden && bar !== current);
        const pick = (candidates, distance) => candidates.sort((first, second) => distance(first) - distance(second))[0];
        const next = {
            ArrowRight: () => pick(visible.filter((bar) => bar.dataset.lane === current.dataset.lane && Number(bar.dataset.offset) >= offset), (bar) => Number(bar.dataset.offset) - offset),
            ArrowLeft: () => pick(visible.filter((bar) => bar.dataset.lane === current.dataset.lane && Number(bar.dataset.offset) <= offset), (bar) => offset - Number(bar.dataset.offset)),
            ArrowDown: () => pick(visible.filter((bar) => Number(bar.dataset.lane) > lane), (bar) => (Number(bar.dataset.lane) - lane) * total + Math.abs(Number(bar.dataset.offset) - offset)),
            ArrowUp: () => pick(visible.filter((bar) => Number(bar.dataset.lane) < lane), (bar) => (lane - Number(bar.dataset.lane)) * total + Math.abs(Number(bar.dataset.offset) - offset)),
        }[event.key]();
        if (next) {
            event.preventDefault();
            next.focus();
        }
    });

    flame.querySelector('[data-flame-controls]').addEventListener('click', (event) => {
        const action = event.target.closest('[data-zoom]')?.dataset.zoom;
        const middle = (view[0] + view[1]) / 2;
        const half = (view[1] - view[0]) / 2;
        if (action === 'in') {
            zoomTo(middle - half / 2, middle + half / 2);
        } else if (action === 'out') {
            zoomTo(middle - half * 2, middle + half * 2);
        } else if (action === 'reset') {
            view = [0, total];
            draw();
        } else if (action === 'span' && selected) {
            const start = Number(selected.dataset.offset);
            const padding = Math.max(Number(selected.dataset.duration) * 0.1, MIN_SPAN);
            zoomTo(start - padding, start + Number(selected.dataset.duration) + padding);
        }
    });

    let brush = null;
    let origin = null;
    const microAt = (event) => {
        const box = lanes.getBoundingClientRect();
        return view[0] + Math.min(1, Math.max(0, (event.clientX - box.left) / box.width)) * (view[1] - view[0]);
    };
    plot.addEventListener('pointerdown', (event) => {
        if (event.button !== 0 || event.target.closest('[data-flame-span]')) {
            return;
        }
        origin = {micro: microAt(event), x: event.clientX};
        plot.setPointerCapture(event.pointerId);
    });
    plot.addEventListener('pointermove', (event) => {
        if (origin === null) {
            return;
        }
        brush ??= plot.appendChild(Object.assign(document.createElement('div'), {className: 'flame-brush'}));
        const box = plot.getBoundingClientRect();
        const [left, right] = [origin.x, event.clientX].sort((first, second) => first - second);
        brush.style.left = (left - box.left) + 'px';
        brush.style.width = (right - left) + 'px';
    });
    plot.addEventListener('pointerup', (event) => {
        if (origin === null) {
            return;
        }
        const moved = Math.abs(event.clientX - origin.x) > 4;
        brush?.remove();
        brush = null;
        if (moved) {
            zoomTo(origin.micro, microAt(event));
        }
        origin = null;
    });

    const fromHash = bars.find((bar) => bar.hash === location.hash);
    if (fromHash) {
        select(fromHash);
    }
    draw();
});
