import mermaid from 'mermaid';

mermaid.initialize({startOnLoad: false, securityLevel: 'strict', maxTextSize: 400000, maxEdges: 2000, flowchart: {htmlLabels: false}});
mermaid.run({querySelector: 'pre.mermaid'});
