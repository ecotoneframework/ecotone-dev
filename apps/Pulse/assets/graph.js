import mermaid from 'mermaid';

mermaid.initialize({startOnLoad: false, securityLevel: 'strict', flowchart: {htmlLabels: false}});
mermaid.run({querySelector: 'pre.mermaid'});
