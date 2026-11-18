# State leak review: execution recording (L1 to L8)

Reviewed at 07880ad4d. The maintainer accepted per-message state in tracing services on the condition that it cannot
leak. A read-only session traced every mutable field; two leaks are real, both in `ExecutionRecordingTracer`. This
comes before M3: the level is meant to work outside flow testing (OpenTelemetry), so the recorder has to be bounded
in a long-running consumer.

- L1 (blocking). `ExecutionRecordingTracer.php:76-79,89-96`, `ChannelSendRecording.php:170`, `RecordedGateway.php:34`:
  `$awaitedReceive` is set by every receive and taken by ANY next top-level gateway call. Scenario: asynchronous
  handler on `orders`, send a command, receive the message by hand (`getMessageChannelByName('orders')->receive()`),
  then send an unrelated command: it is recorded as process "receive orders". Other triggers: a handler draining a
  pollable channel itself, the last message of a consumer run. Clearing in `closeEntry` is not a fix (the polling
  housekeeping send opens and closes a top entry between the receive and the gateway call). Suggested: keep the
  received message's id, continue a receive only for the gateway call handling that message, and drop a slot that
  does not match.
- L2 (blocking). `ExecutionRecordingTracer.php:86-87,139,287-289`, `ConsoleCommandRunner.php:58`: a consumer started
  through a console command (`runConsoleCommand('ecotone:run', …)`, `bin/console ecotone:run`) keeps the command's top
  entry open for its lifetime, so every message is a nested step of one process, every finished step is buffered in
  `$stepsOfOpenEntry` and `$stepsWithChildren` until the consumer stops, and nothing reaches the span recorder before
  that. Memory grows without bound. Each consumed message has to be its own process (a sub-flow of its sender), with
  its steps handed to the recorder when it ends. Prove with a consumer run through the console path over several
  messages: one process per message, spans recorded after each, and the next call after the run is its own process.
- L3 (should fix). Row 3h calls the recording "a flow-testing option"; nothing enforces it, and the maintainer now
  wants the level usable with other integrations. State the production bound truthfully: after L2 the tracer holds
  state for one message only; `InMemorySpanRecorder::$spans` grows until `clear()` and is a test recorder. If the
  reader's requirement of an `InMemorySpanRecorder` makes a long-running application unbounded by configuration
  alone, say so in the report with the options; do not decide it.
- L4 (should fix, defence). `ExecutionRecordingTracer.php:270-273`: the only clean-up is the end of the top step.
  When `$openSteps` becomes empty also clear `$stepsWithChildren` and `$hintsOfOpenSteps`; and when an entry starts
  with no current span while the oldest open step belongs to a span, the state is stale: reset it.
- L5 (nit). Outcome computed after the `try`, so a throw there leaves the step or span open: `RecordedStep.php:31`,
  `RecordedAggregateRepository.php:61`, `RecordedAggregateStep.php:39`, and `TracedDecisionModelBatchLoader.php:45-51`.
  Compute it inside the `try`.
- L6 (nit). `ReplyRecording.php:130,135,161`, `ChannelSendRecording.php:86,107,113`: index `count - 1` on a possibly
  empty stack writes key -1. Return early when the stack is empty.
- L7 (nit). `$order` counts per application; two applications sharing one `InMemorySpanRecorder` interleave wrongly.
  Check whether the sub-flow tests with two applications depend on it; fix if they can be shown wrong, otherwise
  state the scope in row 3h.
- L8 (nit). Row 3h says `$openSends` and `$openRequests` close "in its `finally`"; the code uses catch and rethrow.
  Correct the row, and its claims for `$awaitedReceive` and `$stepsWithChildren` after L1/L4.

Leak tests for the paths found uncovered, each failing (or finishing) one process, then running an unrelated one
and asserting it is recorded as its own process with nothing inherited: a failing asynchronous handler then the next
consumed message; a failing around interceptor; a failing argument conversion; a collector flush failure; an error
channel send failure; instant retry final failure; a failing channel send inside an inline delivery. One is skipped
only with the reason it cannot be reached from userland.
