# The comment's diagram is a flow diagram (head 528c4c63a)

The maintainer, on the #771 comment's sequence diagram: "we want flow diagram on the comment, not the diagram".

- D1. "The message's path" (comment and `compare --presentation=build`) is one Mermaid `flowchart LR` per changed
  scenario instead of the sequence diagram with Before and After blocks: one node per participant (a channel as a
  cylinder, the rest boxes), one edge per distinct hand-over (from, to, label), drawn once, in the order it first
  happens now, then the gone ones.
- D2. A new edge is green with a `+ ` label, a gone one red with `- `, the rest plain; a node only new edges touch is
  green, only gone ones red; an edge from the wiring with no traced span is dashed and drops its `(build, no span)`
  suffix; colours readable on GitHub's light and dark themes; one sentence above the diagram explains the marks.
- D3. Labels and ids are Mermaid-safe (quotes, brackets, pipes, backticks, `#`), tested with the hostile labels of
  the earlier rounds; the comment's size rules still hold.
- D4. Several labels between one pair are several edges; past about 25 edges in #771 or a catalogue scenario, report
  the numbers rather than invent a rule.
- D5. Glossary, legend, the bin tests' sequence fixtures and every other mention of the sequence diagram follow; the
  sequence diagram code is removed, no second presentation kept.

Also answered from the code: the order the comment prints changed scenarios in, and which are cut first.
