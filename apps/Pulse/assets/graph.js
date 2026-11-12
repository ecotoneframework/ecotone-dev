import dagre from '@dagrejs/dagre';

const SVG = 'http://www.w3.org/2000/svg';
const MIN_START_SCALE = 0.9;
const MIN_FIT_SCALE = 0.6;
const HUB_KINDS = ['channel', 'distributedBus'];
const HUB_ABOVE_ARROWS = 4;
const HUBS_ABOVE_NODES = 40;
const ONLY_TRACE_ABOVE_NODES = 40;

const pathThrough = (points) => {
    if (points.length < 3) {
        return 'M' + points.map((point) => `${point.x} ${point.y}`).join('L');
    }
    let path = `M${points[0].x} ${points[0].y}`;
    for (let index = 1; index < points.length - 1; index++) {
        const end = index === points.length - 2 ? points[index + 1] : {x: (points[index].x + points[index + 1].x) / 2, y: (points[index].y + points[index + 1].y) / 2};
        path += `Q${points[index].x} ${points[index].y} ${end.x} ${end.y}`;
    }

    return path;
};

const element = (name, attributes = {}, text = null) => {
    const created = document.createElementNS(SVG, name);
    Object.entries(attributes).forEach(([key, value]) => created.setAttribute(key, value));
    if (text !== null) {
        created.textContent = text;
    }

    return created;
};

const layout = (diagram, onlyTrace = false) => {
    const stage = diagram.querySelector('[data-diagram-stage]');
    stage.querySelectorAll('.dg-frame, .diagram-arrows').forEach((drawn) => drawn.remove());
    const every = [...stage.querySelectorAll('[data-diagram-node]')];
    every.forEach((node) => { node.hidden = onlyTrace && !node.hasAttribute('data-trace-state'); });
    const nodes = every.filter((node) => !node.hidden);
    if (nodes.length === 0) {
        return null;
    }
    diagram.classList.add('diagram-ready');
    const labelled = nodes.length <= HUBS_ABOVE_NODES;
    const graph = new dagre.graphlib.Graph({compound: true, multigraph: true});
    graph.setGraph({rankdir: 'LR', nodesep: 16, edgesep: 10, ranksep: 96, marginx: 28, marginy: 34, acyclicer: 'greedy'});
    graph.setDefaultEdgeLabel(() => ({}));
    const groups = [...stage.querySelectorAll('[data-diagram-group]')];
    groups.forEach((group) => {
        graph.setNode('group:' + group.dataset.diagramGroup, {});
        group.querySelectorAll('[data-diagram-node]:not([hidden])').forEach((node) => graph.setParent(node.dataset.diagramNode, 'group:' + group.dataset.diagramGroup));
    });
    nodes.forEach((node) => graph.setNode(node.dataset.diagramNode, {width: node.offsetWidth, height: node.offsetHeight}));
    groups.forEach((group) => { if (graph.children('group:' + group.dataset.diagramGroup).length === 0) { graph.removeNode('group:' + group.dataset.diagramGroup); } });
    const edges = [...diagram.querySelectorAll('[data-diagram-edge]')];
    const degree = {};
    edges.filter((edge) => graph.hasNode(edge.dataset.from) && graph.hasNode(edge.dataset.to)).forEach((edge) => [edge.dataset.from, edge.dataset.to].forEach((id) => { degree[id] = (degree[id] || 0) + 1; }));
    const hubs = new Set(nodes.length <= HUBS_ABOVE_NODES ? [] : nodes.filter((node) => HUB_KINDS.includes(node.dataset.kind) && (degree[node.dataset.diagramNode] || 0) > HUB_ABOVE_ARROWS).map((node) => node.dataset.diagramNode));
    const sideEdges = [];
    edges.forEach((edge, index) => {
        if (!graph.hasNode(edge.dataset.from) || !graph.hasNode(edge.dataset.to)) {
            return;
        }
        if (hubs.has(edge.dataset.from) || hubs.has(edge.dataset.to)) {
            sideEdges.push(edge);
        } else {
            graph.setEdge(edge.dataset.from, edge.dataset.to, labelled && edge.dataset.label !== '' ? {index, width: edge.dataset.label.length * 6.6 + 12, height: 18, labelpos: 'c'} : {index}, 'edge' + index);
        }
    });
    try {
        dagre.layout(graph);
    } catch (error) {
        diagram.classList.remove('diagram-ready');
        every.forEach((node) => { node.hidden = false; });
        return null;
    }
    if (nodes.some((node) => !Number.isFinite(graph.node(node.dataset.diagramNode).x) || !Number.isFinite(graph.node(node.dataset.diagramNode).y))) {
        diagram.classList.remove('diagram-ready');
        every.forEach((node) => { node.hidden = false; });
        return null;
    }

    const size = graph.graph();
    stage.style.width = size.width + 'px';
    stage.style.height = size.height + 'px';
    nodes.forEach((node) => {
        const placed = graph.node(node.dataset.diagramNode);
        node.style.left = (placed.x - placed.width / 2) + 'px';
        node.style.top = (placed.y - placed.height / 2) + 'px';
    });
    groups.forEach((group) => {
        const placed = graph.node('group:' + group.dataset.diagramGroup);
        if (!placed || !Number.isFinite(placed.x)) {
            return;
        }
        const frame = document.createElement('div');
        frame.className = 'dg-frame ' + [...group.classList].filter((name) => name.startsWith('svc-')).join(' ');
        frame.style.cssText = `left:${placed.x - placed.width / 2 - 6}px;top:${placed.y - placed.height / 2 - 12}px;width:${placed.width + 12}px;height:${placed.height + 18}px`;
        const title = document.createElement('span');
        title.textContent = group.dataset.service;
        title.setAttribute('aria-hidden', 'true');
        frame.append(title);
        stage.prepend(frame);
    });

    const svg = element('svg', {class: 'diagram-arrows', width: size.width, height: size.height, 'aria-hidden': 'true', focusable: 'false'});
    const defs = element('defs');
    const marker = element('marker', {id: 'diagram-arrow-' + Math.random().toString(36).slice(2), viewBox: '0 0 8 8', refX: '7', refY: '4', markerWidth: '7', markerHeight: '7', orient: 'auto-start-reverse'});
    marker.append(element('path', {d: 'M0 0L8 4L0 8z', fill: 'context-stroke'}));
    defs.append(marker);
    svg.append(defs);
    const draw = (edge, points, extra = '', labelAt = null) => {
        const highlighted = edge.hasAttribute('data-highlighted');
        const path = element('path', {class: `da da-${edge.dataset.arrow}${highlighted ? ' da-crossed' : ''}${extra}`, d: pathThrough(points), 'marker-end': `url(#${marker.id})`, 'data-from': edge.dataset.from, 'data-to': edge.dataset.to});
        svg.append(path);
        if (edge.dataset.label !== '') {
            const middle = points[Math.floor(points.length / 2)];
            const before = points[Math.max(0, Math.floor(points.length / 2) - 1)];
            const at = labelAt || (points.length % 2 === 0 ? {x: (middle.x + before.x) / 2, y: (middle.y + before.y) / 2} : middle);
            svg.append(element('text', {class: 'da-label' + (highlighted ? ' da-crossed' : '') + extra, x: at.x, y: labelAt ? at.y + 4 : at.y - 5, 'data-from': edge.dataset.from, 'data-to': edge.dataset.to}, edge.dataset.label));
        }
    };
    graph.edges().forEach((key) => { const placed = graph.edge(key); draw(edges[placed.index], placed.points, '', Number.isFinite(placed.x) && Number.isFinite(placed.y) ? {x: placed.x, y: placed.y} : null); });
    sideEdges.forEach((edge) => {
        const from = graph.node(edge.dataset.from);
        const to = graph.node(edge.dataset.to);
        const forward = to.x >= from.x;
        const start = {x: from.x + (forward ? 1 : -1) * from.width / 2, y: from.y};
        const end = {x: to.x + (forward ? -1 : 1) * to.width / 2, y: to.y};
        const bend = Math.max(40, Math.abs(end.x - start.x) / 3) * (forward ? 1 : -1);
        draw(edge, [start, {x: start.x + bend, y: start.y}, {x: end.x - bend, y: end.y}, end], ' da-hub');
    });
    stage.prepend(svg);

    return size;
};

const zoomable = (diagram, firstSize) => {
    const view = diagram.querySelector('[data-diagram-view]');
    const stage = diagram.querySelector('[data-diagram-stage]');
    let size = firstSize;
    let scale = 1;
    let x = 0;
    let y = 0;
    const apply = () => { stage.style.transform = `translate(${x}px,${y}px) scale(${scale})`; };
    const zoom = (factor, cx = view.clientWidth / 2, cy = view.clientHeight / 2) => {
        const next = Math.min(2.5, Math.max(0.15, scale * factor));
        x = cx - (cx - x) * (next / scale);
        y = cy - (cy - y) * (next / scale);
        scale = next;
        apply();
    };
    const centreOn = (node) => {
        x = view.clientWidth / 2 - (node.offsetLeft + node.offsetWidth / 2) * scale;
        y = view.clientHeight / 2 - (node.offsetTop + node.offsetHeight / 2) * scale;
    };
    const reset = (next) => {
        size = next;
        const whole = Math.min(1, view.clientWidth / size.width);
        scale = whole >= MIN_FIT_SCALE ? whole : MIN_START_SCALE;
        view.style.height = Math.min(Math.ceil(size.height * scale) + 8, Math.round(window.innerHeight * 0.72)) + 'px';
        x = scale * size.width < view.clientWidth ? (view.clientWidth - scale * size.width) / 2 : 0;
        y = 0;
        const anchor = stage.querySelector('[data-focused]:not([hidden])') || stage.querySelector('[aria-current]:not([hidden])') || stage.querySelector('[data-trace-state="traceFailed"]:not([hidden])') || stage.querySelector('[data-step="1"]:not([hidden])');
        if (anchor && (scale * size.width > view.clientWidth || scale * size.height > view.clientHeight)) {
            centreOn(anchor);
            x = Math.min(0, x);
            y = Math.min(0, y);
        }
        apply();
    };
    reset(size);

    const toolbar = document.createElement('div');
    toolbar.className = 'graph-tools';
    [['+', 'Zoom in', () => zoom(1.25)], ['−', 'Zoom out', () => zoom(0.8)], ['Fit', 'Fit the whole graph', () => { scale = Math.min(1, view.clientWidth / size.width, view.clientHeight / size.height); x = (view.clientWidth - scale * size.width) / 2; y = Math.max(0, (view.clientHeight - scale * size.height) / 2); apply(); }], ['1:1', 'Actual size', () => { zoom(1 / scale); }]].forEach(([text, label, action]) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.textContent = text;
        button.setAttribute('aria-label', label);
        button.addEventListener('click', action);
        toolbar.append(button);
    });
    const traced = stage.querySelectorAll('[data-trace-state]').length;
    const all = stage.querySelectorAll('[data-diagram-node]').length;
    if (traced > 0 && traced < all) {
        const toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.dataset.onlyTrace = '';
        const show = (only) => {
            toggle.setAttribute('aria-pressed', only ? 'true' : 'false');
            toggle.textContent = only ? `Show all ${all} nodes` : `Show only the ${traced} nodes the trace passed`;
            const next = layout(diagram, only);
            if (next) { reset(next); }
        };
        toggle.addEventListener('click', () => show(toggle.getAttribute('aria-pressed') !== 'true'));
        toolbar.append(toggle);
        show(all > ONLY_TRACE_ABOVE_NODES);
    }
    diagram.before(toolbar);

    view.tabIndex = 0;
    view.setAttribute('role', 'group');
    view.setAttribute('aria-label', `${view.getAttribute('aria-label')}, arrow keys pan, plus and minus zoom`);
    view.addEventListener('wheel', (event) => {
        if (!event.ctrlKey && !event.metaKey && document.activeElement !== view) { return; }
        event.preventDefault();
        const bounds = view.getBoundingClientRect();
        zoom(event.deltaY < 0 ? 1.15 : 0.87, event.clientX - bounds.left, event.clientY - bounds.top);
    }, {passive: false});
    let drag = null;
    let dragged = false;
    view.addEventListener('pointerdown', (event) => { drag = {x: event.clientX, y: event.clientY, moved: false}; dragged = false; });
    view.addEventListener('pointermove', (event) => {
        if (!drag) { return; }
        if (Math.abs(event.clientX - drag.x) + Math.abs(event.clientY - drag.y) > 3) { drag.moved = true; view.setPointerCapture(event.pointerId); }
        if (drag.moved) { x += event.clientX - drag.x; y += event.clientY - drag.y; drag.x = event.clientX; drag.y = event.clientY; apply(); }
    });
    view.addEventListener('pointerup', () => { dragged = drag?.moved === true; drag = null; });
    view.addEventListener('pointercancel', () => { drag = null; dragged = false; });
    view.addEventListener('dragstart', (event) => event.preventDefault());
    diagram.addEventListener('pulse:show-step', (event) => {
        const node = stage.querySelector(`[data-diagram-node][data-step="${Number(event.detail)}"]:not([hidden])`);
        if (!node) { return; }
        centreOn(node);
        apply();
        node.classList.add('dn-pointed');
        setTimeout(() => node.classList.remove('dn-pointed'), 2500);
        view.scrollIntoView({block: 'nearest'});
    });
    view.addEventListener('click', (event) => {
        if (dragged) {
            event.preventDefault();
            event.stopPropagation();
        }
        dragged = false;
    }, true);
    view.addEventListener('keydown', (event) => {
        if (event.target !== view) { return; }
        const keys = {ArrowLeft: [60, 0], ArrowRight: [-60, 0], ArrowUp: [0, 60], ArrowDown: [0, -60]};
        if (keys[event.key]) { event.preventDefault(); x += keys[event.key][0]; y += keys[event.key][1]; apply(); }
        if (event.key === '+' || event.key === '=') { zoom(1.25); }
        if (event.key === '-') { zoom(0.8); }
    });
    view.addEventListener('focusin', (event) => {
        const node = event.target.closest('[data-diagram-node]');
        if (!node) { return; }
        const left = node.offsetLeft * scale + x;
        const top = node.offsetTop * scale + y;
        if (left < 0 || top < 0 || left + node.offsetWidth * scale > view.clientWidth || top + node.offsetHeight * scale > view.clientHeight) {
            centreOn(node);
            apply();
        }
        view.scrollLeft = 0;
        view.scrollTop = 0;
    });
};

document.querySelectorAll('[data-diagram]').forEach((diagram) => {
    const size = layout(diagram);
    if (size) {
        zoomable(diagram, size);
    }
});

document.querySelectorAll('[data-diagram]').forEach((diagram) => {
    const light = (node, on) => diagram.querySelectorAll(`.da-hub[data-from="${node.dataset.diagramNode}"],.da-hub[data-to="${node.dataset.diagramNode}"]`).forEach((arrow) => arrow.classList.toggle('da-lit', on));
    diagram.querySelectorAll('[data-diagram-node]').forEach((node) => {
        node.addEventListener('mouseenter', () => light(node, true));
        node.addEventListener('mouseleave', () => light(node, false));
        node.addEventListener('focus', () => light(node, true));
        node.addEventListener('blur', () => light(node, false));
    });
});

document.querySelectorAll('[data-trace-step-button]').forEach((button) => {
    button.addEventListener('click', () => document.querySelectorAll('[data-diagram]').forEach((diagram) => diagram.dispatchEvent(new CustomEvent('pulse:show-step', {detail: button.closest('[data-trace-step]').dataset.traceStep}))));
});
document.querySelectorAll('[data-trace-step]').forEach((row) => {
    const mark = (on) => document.querySelectorAll(`[data-diagram-node][data-step="${row.dataset.traceStep}"]`).forEach((node) => node.classList.toggle('dn-pointed', on));
    row.addEventListener('mouseenter', () => mark(true));
    row.addEventListener('mouseleave', () => mark(false));
    row.addEventListener('focusin', () => mark(true));
    row.addEventListener('focusout', () => mark(false));
});
