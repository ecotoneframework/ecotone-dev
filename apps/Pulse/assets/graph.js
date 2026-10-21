import mermaid from 'mermaid';

const token = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim();
const SVG = 'http://www.w3.org/2000/svg';

mermaid.initialize({
    startOnLoad: false, securityLevel: 'strict', maxTextSize: 400000, maxEdges: 2000, flowchart: {htmlLabels: false},
    theme: 'base',
    themeVariables: {
        fontFamily: token('--font'), background: token('--bg'), primaryColor: token('--surface-2'), primaryTextColor: token('--fg'),
        primaryBorderColor: token('--accent'), secondaryColor: token('--surface'), tertiaryColor: token('--surface'), lineColor: token('--muted'),
        textColor: token('--fg'), clusterBkg: token('--surface'), clusterBorder: token('--line'), edgeLabelBackground: token('--bg'),
        titleColor: token('--fg'), nodeBorder: token('--line'),
    },
});

const link = (element, href, title) => {
    const anchor = document.createElementNS(SVG, 'a');
    anchor.setAttribute('href', href);
    const text = document.createElementNS(SVG, 'title');
    text.textContent = title;
    element.replaceWith(anchor);
    anchor.append(text, element);
};

const decorate = (graph, svg) => {
    const nodes = JSON.parse(graph.dataset.graphNodes || '{}');
    svg.querySelectorAll('g.cluster').forEach((cluster, index) => {
        const service = /^s(\d+)$/.exec(cluster.id)?.[1];
        cluster.classList.add('svc-' + ((service === undefined ? index : Number(service)) % 8));
    });
    svg.querySelectorAll('g.node, g.cluster').forEach((element) => {
        const id = /(?:flowchart-)?(s\d+(?:n\d+)?)(?:-\d+)?$/.exec(element.id)?.[1];
        if (id && nodes[id]) {
            link(element, nodes[id].href, nodes[id].title);
        }
    });
};

const zoomable = (graph, svg) => {
    const box = svg.viewBox.baseVal;
    const home = {x: box.x, y: box.y, width: box.width, height: box.height};
    let view = {...home};
    svg.removeAttribute('style');
    svg.setAttribute('width', '100%');
    svg.setAttribute('height', '100%');
    const apply = () => svg.setAttribute('viewBox', `${view.x} ${view.y} ${view.width} ${view.height}`);
    const zoom = (factor, cx = view.x + view.width / 2, cy = view.y + view.height / 2) => {
        const width = Math.min(home.width * 8, Math.max(home.width / 20, view.width * factor));
        const ratio = width / view.width;
        view = {x: cx - (cx - view.x) * ratio, y: cy - (cy - view.y) * ratio, width, height: view.height * ratio};
        apply();
    };
    const pan = (dx, dy) => { view.x += dx * view.width; view.y += dy * view.height; apply(); };
    const toolbar = document.createElement('div');
    toolbar.className = 'graph-tools';
    [['+', 'Zoom in', () => zoom(0.8)], ['−', 'Zoom out', () => zoom(1.25)], ['Fit', 'Fit to width', () => { view = {...home}; apply(); }]].forEach(([text, label, action]) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.textContent = text;
        button.setAttribute('aria-label', label);
        button.addEventListener('click', action);
        toolbar.append(button);
    });
    graph.before(toolbar);
    graph.classList.add('graph-ready');
    graph.tabIndex = 0;
    graph.setAttribute('role', 'group');
    graph.setAttribute('aria-label', 'Flow graph, arrow keys pan, plus and minus zoom');
    graph.addEventListener('wheel', (event) => {
        if (!event.ctrlKey && !event.metaKey && document.activeElement !== graph) { return; }
        event.preventDefault();
        const point = new DOMPoint(event.clientX, event.clientY).matrixTransform(svg.getScreenCTM().inverse());
        zoom(event.deltaY < 0 ? 0.85 : 1.15, point.x, point.y);
    }, {passive: false});
    let drag = null;
    let dragged = false;
    graph.addEventListener('pointerdown', (event) => { drag = {x: event.clientX, y: event.clientY, moved: false}; dragged = false; });
    graph.addEventListener('pointermove', (event) => {
        if (!drag) { return; }
        const scale = view.width / svg.getBoundingClientRect().width;
        if (Math.abs(event.clientX - drag.x) + Math.abs(event.clientY - drag.y) > 3) { drag.moved = true; graph.setPointerCapture(event.pointerId); }
        if (drag.moved) { view.x -= (event.clientX - drag.x) * scale; view.y -= (event.clientY - drag.y) * scale; drag.x = event.clientX; drag.y = event.clientY; apply(); }
    });
    graph.addEventListener('pointerup', () => { dragged = drag?.moved === true; drag = null; });
    graph.addEventListener('pointercancel', () => { drag = null; dragged = false; });
    graph.addEventListener('click', (event) => {
        if (dragged) {
            event.preventDefault();
            event.stopPropagation();
        }
        dragged = false;
    }, true);
    graph.addEventListener('keydown', (event) => {
        const keys = {ArrowLeft: [-0.1, 0], ArrowRight: [0.1, 0], ArrowUp: [0, -0.1], ArrowDown: [0, 0.1]};
        if (keys[event.key]) { event.preventDefault(); pan(...keys[event.key]); }
        if (event.key === '+' || event.key === '=') { zoom(0.8); }
        if (event.key === '-') { zoom(1.25); }
    });
};

await mermaid.run({querySelector: 'pre.mermaid'});
document.querySelectorAll('pre.mermaid').forEach((graph) => {
    const svg = graph.querySelector('svg');
    if (svg) {
        decorate(graph, svg);
        zoomable(graph, svg);
    }
});
