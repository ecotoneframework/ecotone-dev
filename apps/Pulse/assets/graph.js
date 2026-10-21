import mermaid from 'mermaid';

mermaid.initialize({startOnLoad: false, securityLevel: 'strict', flowchart: {htmlLabels: true}});
mermaid.run({querySelector: 'pre.mermaid'});
