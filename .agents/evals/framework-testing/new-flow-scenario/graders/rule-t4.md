---
type: llm
---
PASS if the answer explains that no scenario of the flow scenario catalogue exercises the new header-based routing,
adds a scenario method to a `*FlowScenarios` class in packages/Ecotone/src/Test/Flow (an event published with and
without the header, built from inline anonymous handlers), lists it in that class's `all()` (and the class in
`FlowScenarioCatalogue::all()` when the class is new), gives the spans its stages expect, and leaves
packages/Ecotone/tests/BuildFlow/baseline untouched because main's CI commits the baseline after the merge. FAIL if it
edits or adds files under the baseline, adds only a unit test, or leaves the scenario out of `all()`.
