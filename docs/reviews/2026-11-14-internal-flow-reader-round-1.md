# Reader round 1 on the #771 renderings

Seven fresh readers (Sonnet, Opus, Fable), each given one rendering and nothing else, scored every presentation 2/5.
A and B readers found the completion handler from the wiring; C readers did not find it at all. Nobody could say what
happens after a batch, in order, without guessing.

What #771 really does (the answer a reader should be able to give back): an asynchronous rebuild/backfill run is now one
gathered group; after each batch is projected, the batch's consumer sends a part report to the run's channel; that
report is routed (routing slip -> bridge -> `.execute`) to ProjectionRunCompletion::complete, which records the part
and closes the run once.

Findings shared by all readers, to fix in the comparison and all three presentations:

R1. No opening sentence. Generate one or two plain sentences per changed group from the data, e.g. "After
    `process whenPlaced`, the consumer now sends a message to `backfill`; it is routed to
    `ProjectionRunCompletion::complete`." Before first, then now.
R2. Identical changes are not grouped (the brief's step 4 asked for it). Backfill and rebuild differ only by the
    name; render once ("applies to: projection backfilled asynchronously, projection rebuilt asynchronously") with the
    varying name shown as `backfill|rebuild` or one example plus "same for rebuild".
R3. 8 of the 12 added nodes (gathering console commands, cleanup) connect to nothing in the changed flow and drowned
    the 4 that matter. Diagram only the parts connected to a traced step or to a pre-existing node; list the rest in
    one line ("also registered now, not on this path: gathering list/discard/release commands, closed-group cleanup").
R4. The trace stops at `receive backfill`: no reader could tell that the completion handler runs, nor who sends.
    Join trace and wiring: for an added receive on a channel, follow the wiring from that channel to the handler it
    reaches (bridge -> .execute -> ProjectionRunCompletion::complete) and show it as the continuation, marked as
    derived from wiring if no span exists. Show the sender (the step under which the send span sits). If the handler
    produces no span because the tracer skips it, say so in the report rather than inventing a step.
R5. `… 4 steps unchanged` hides the context. Show the unchanged steps in full when they are few (<= 8), dimmed.
R6. C draws send/receive as self-arrows on one participant. Draw sender -> channel -> receiving handler as distinct
    participants; put Before/After labels over the whole width, not on the first participant.
R7. Names: `class@anonymous::whenPlaced` (say "projection handler whenPlaced" or the projection name when the class is
    anonymous), long `ecotone.channel.ecotone:gathering:*` prefixes, `Bridge::handle on backfill`, `n0..n13` order
    differing between the two After diagrams, nothing feeding `...completion:orders` (say what sends to it, or mark it
    as an entry point).
R8. No legend: dashed = registered by Ecotone, green = added, and what the arrow labels `output` and `routes` mean
    (prefer words a reviewer knows: "then sends to", "routed to").
R9. C's wiring line ("12 nodes added (... and 8 more), 8 arrows added") and the "Traced steps" bullet repeat or hide
    information; replace with R1's sentences and R3's one-liner.
