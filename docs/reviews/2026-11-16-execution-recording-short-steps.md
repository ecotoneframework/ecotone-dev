# Review findings: execution recording steps, S1 to S3 (head e927fae43)

R1 to R6 (head e927fae43) put the right facts into the recording, but as whole sentences inside the step. The
maintainer cannot read the flow any more. Their words: "this is unreadable, it is basically a list of headers",
and "we can't use whole sentences in the diagram of steps. We can give a hint box explaining what was the result
and what was chosen, if it is critical to the flow, like choosing the next channel."

Examples of what must go:

```text
passes on the message with headers removed: ecotone_messaging_consumer_acknowledge_header, ecotone_in_memory_queue_consumer_acknowledge, polledChannelName, ecotone_messaging_consumer_polling_metadata
RoutingSlipPrepender::transform put ecotone.projection.backfill.completion:course_seats.execute in front of the routing slip, which is now ecotone.projection.backfill.completion:course_seats.execute
forward string by the routing slip, taking ecotone.projection.partition_batch.executor from it; the slip held ecotone.projection.partition_batch.executor and is now removed
record part 2 of gathering group {generated#1} as arrived, in InMemoryGatheredGroupStore::completePart: with it all 2 parts are in, so the store releases the group and calls the gatherer back right here
process 5: receive backfill, handled by the gatherer only, recording part 1 of 2 reported by process 3, whose work ran there
```

No fact recorded by R1 to R6 is dropped; it moves.

## S1. A step has a label and, separately, hints

- **Label:** what ran, in the shape the oldest steps already have: a kind and a name, for example
  `around TracingInterceptor::traceProjectionBatch`, `handler GatheringMessageHandler::handle`,
  `before send OutboundSerializationChannelInterceptor::preSend`, `put on channel backfill`,
  `forward to ecotone.projection.partition_batch.executor`, `record part 2 of 2`, `release group`.
  No sentence, no "so", "which is now", "here without", no list of headers, no explanation of why. A label names
  at most one class::method or one channel. Enforce a length limit in a test over every baseline (pick the
  number from the data; around 90 characters) so this cannot grow back.
- **Hints:** named facts attached to the step, each a short key and a value, stored as structured data on the
  step (not parsed back out of text). Use one fixed, small vocabulary of keys across all steps, for example:
  `chosen by` (the mechanism: output channel of X, routing slip, reply channel of the request, part report,
  header name, licence), `result` (what came out: returns nothing, int, a message of string, 2 of 2 parts in),
  `routing slip` (before → after), `headers added`, `headers removed`, `headers changed`, `sent by` / `taken by`
  (the linked process), `stopped` (the collector holding the message), `runs` (same consumer run, no queue).
  Values are names and lists, not sentences.
- **Critical hints:** a hint that decides where the message goes next or whether it goes anywhere is marked
  critical: the mechanism and target of every hand-over, a route choice, a send that was stopped, a group
  released or not, the link between processes, an acknowledgement outcome. Header lists and conversions are not
  critical. A reader of labels plus critical hints must still be able to answer the six questions of R1 to R6.

## S2. Where each form shows them

- **Stored snapshot (`execution` in the baselines):** label, depth, hints with their critical flag, as data.
- **Text recording and the Markdown comparison:** one line per step with the label only. Critical hints follow
  on their own short lines directly under the step, visibly secondary (indented, with a marker such as `↳`),
  one hint per line: `↳ chosen by: routing slip`. Non-critical hints are not printed in the default text; give
  the recording command an option that prints them all.
- **Comparison:** a step whose label is the same and whose hints differ is a changed step, shown once with the
  hint that changed (before → now), not as a removed and an added step. Moved-steps handling from 93d7ada97 and
  the pairing fixes from the last round keep working.
- **Process titles:** short too: what was received or called and the handler, plus an ordinal when titles would
  collide (`receive backfill, GatheringMessageHandler::handle (part 1 of 2)`). The link to the sending process
  and "no projection runs here" are hints on the first step, not title text.

## S3. A machine-readable export for the page

The coordinator renders the #771 recording as a page with hint boxes. Add a JSON export of the recording before and
after next to the text files: per process its title and steps, per step depth, label and hints with the critical
flag. Derive it with the same script as the text files, from the snapshot data, so the page does not parse text.

