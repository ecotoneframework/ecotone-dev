# Review findings: execution recording, a consumer run is a sub-flow of the flow that sent its message (F1 to F4)

The maintainer, reading the #771 recording: "why receive backfill is a separate flow. It comes from messages
created during backfill. Tracing does know correlation so should be able to show related flows and sub flows."

Today the recording is a flat list of processes. The relation exists only as text in two hints (`sent by: process
2, …` on the first step of the receiving process, `taken by: process 3, …` on the send). Make the relation part of
the recording's structure.

- F1. Structure. Each process records which process and which step of it sent the message it handles, taken from
  what tracing already correlates (the trace context carried on the message), not from matching labels or channel
  names. A process with no sender is a flow; a process with one is a sub-flow of it. Report how the link is
  derived, and what happens for: a message sent by a process outside the recording, a delayed or retried
  delivery (received more than once), a message published to several consumers, a distributed bus hop between two
  applications in one test. A link the recorder cannot observe is left out, never guessed.
- F2. Stored snapshot and JSON export. The link is data (`sent by` process and step), not parsed back out of hint
  text. The existing `sent by` / `taken by` hints stay as the readable form and are derived from that data.
- F3. Default text. Flows print in run order; each sub-flow prints under the flow that sent its message, indented
  as a sub-flow, in the order its messages were sent, so #771 reads: process 1; process 2 with sub-flows 3 (with
  sub-flow 5) and 4 (with sub-flow 6). Process numbers keep saying the order in which they ran. Titles stay short.
- F4. Comparison. A sub-flow that is new, gone, or attached to a different sender is a change the Markdown
  comparison states ("2 new sub-flows of `receive backfill, …executeBatch`"), and the before/after presentation
  keeps the nesting.
