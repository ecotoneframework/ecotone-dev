# Pre-push review findings: flow versions and their comparison, commit b787ba82c

Four independent read-only reviews (guidelines, breaking changes, behaviour, developer experience). One BLOCKING
finding (G1). Fix every item test-first where it changes behaviour.

## Guidelines
- G1 (BLOCKING). Five exception messages of the flow version storage are asserted by no test: a file that is not
  JSON, a file that is not a stored version, a wrong format version, a location that cannot be created, a file that
  cannot be written.
- G2 (should fix). One concept, two names: the console options and JSON say `from` / `to`, the API says
  `earlier` / `later`.
- G3 (should fix). Calls with three or more arguments written positionally (`FlowVersion`, the console command
  preparation, the Pulse responder registration, `ComparedFlowDiagram`).
- G4 (should fix). `MessageFlowComparisonTest` builds graphs by hand instead of booting an application.
- G5 (should fix). A version graph Pulse cannot read renders "Waiting" forever, with no refresh.
- G6 (should fix). The upgrade entry's "Before" describes `ecotone:flow:dump`, which no 1.x release had.
- G7 (nit). A bad `--format` is thrown as a `ConfigurationException`.
- G8 (nit). Pulse's `TraceQuery` now also carries flow version queries.
- G9 (nit). `MessageFlowGraph::toMermaidMarking()` is a second public way to the comparison's Mermaid output.
- G10 (nit). `FlowVersions::of()` reads every file twice when the label is unknown.
- G11 (nit). Three-level routing keys; consistent with the trace queries.

## Breaking changes
- B1 (should fix). The format version is compared strictly, so the first format bump makes every committed file
  unreadable, and the message tells the user to upgrade for a file that is older.
- B2 (should fix). A stored version freezes ids and attributes the framework derives; an Ecotone upgrade can report
  changes the application never made. Say so in the upgrade entry.
- B3 (should fix). A service on an Ecotone without flow versions shows as "not answering", which reads as an outage.
- B4 (nit). Same as G6.
- B5 (nit). An unknown node or edge kind in a stored file surfaces as a raw `ValueError` on the console.

## Behaviour
- V1 (should fix). Mermaid: a handler that became synchronous keeps its routed arrow drawn through the removed
  channel, and the direct arrow that now exists is never drawn; the reverse case draws the new channel arrow unmarked.
- V2 (should fix). "Latest" compares `storedAt` as a string, which is wrong across timezone offsets.
- V3 (should fix). A `scheme://` location is accepted but cannot be listed, and neither can a path holding `[`.
- V4 (should fix). A multi-tenant service is listed once per tenant on the Pulse compare page.
- V5 (should fix). No test reads a malformed file, a file with missing keys, another format version, an unreadable
  graph, or a file whose label differs from its name.
- V6 (should fix). Same as G5.
- V7 (nit). The label `0` is treated as no `from` on the Pulse page.
- V8 (nit). Version answers are cached for 60 s, so a refusal outlives the deploy that fixes it.
- V9 (nit). Untested page paths: a version answer still waiting, a refused `to`, `from` equal to `to`.
- V10 (nit). List attributes are compared in order.

## Developer experience
- D1 (should fix). "Latest" is the most recently stored version, not the highest label, and nothing says so.
- D2 (should fix). `store` refuses an existing label, so a rolling baseline needs the file deleted by hand.
- D3 (nit). Same as G9.
- D4 (nit). Same as V3.
- D5 (nit). "The same code always stores the same file" ignores `storedAt`.
- D6 (nit). The setup snippet Pulse shows has no import and names only `bin/console`.
- D7 (nit). `ecotone:flow:store` and `ecotone:flow:versions` sit next to `ecotone:flow:dump`.
