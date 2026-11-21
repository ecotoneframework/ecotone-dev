# Review findings: execution recording, commits e263fef37..96b0ca7bf

One fresh read-only review of the code and of the recorded output. Every item is reproduced with a failing test
before it is fixed; an item that does not reproduce is reported as such. Round 1 is correctness, round 2 is
coverage: steps that run but are not recorded.

## Round 1: correctness
- C1. A send that succeeded on retry is recorded as failed: the send step ends when the failure reaches the first
  interceptor, before `SendRetryChannelInterceptor::recover` runs. Record the outcome after recovery, and the
  recovery itself.
- C2. Recording may change behaviour: `RecordedGatheringPart` reads the part number and size headers before
  delegating, so a message without a gathering group fails with a missing header instead of
  `GatheringMessageHandler`'s own message. Recording must never change what a flow does or throws.
- C3. A process that fails before its first span vanishes: an entry whose before interceptor or argument
  conversion throws leaves nothing. Keep a process that ends in an exception; empty polls and flow-testing
  housekeeping still leave nothing.
- C4. Entries with no span at all leave nothing: a direct send to a transformer, router or splitter, a console
  command that sends nothing, a framework gateway without `#[MessageGateway]`. Record those a flow test can reach.
- C5. The flow-testing exclusion is inconsistent: the spy channel interceptor appears and turns a plain hand-over
  into a send production does not have, while `AllowMissingDestination` is excluded although it changes the
  outcome. Nothing that exists only in flow testing appears, and nothing excluded hides an outcome.
- C6. Same-named processes cannot be told apart and may be paired wrongly. Each process gets a title that says what
  it handled and its position among same-named ones, and before is matched to after on that.
- C7. One event is written as two lines: a presend interceptor is always followed by
  `channel interceptor BeforeSendChannelInterceptor`.
- C8. `reply X to the reply channel of the request` is decided by header presence; a null reply channel reads as
  delivered.
- C9. "Collected" is keyed by message id and never cleared when a failed handler drops its messages, so a later
  ordinary send of the same id could read as a release.
- C10. Role labels are inconsistent: an aggregate handler behind the factory/action router is `step`, elsewhere
  `handler`.
- C11. Names lose identity: generated endpoint ids become `{generated}.target` for different handlers in one
  scenario, and a channel interceptor registered by service id is named by that id, not its class.
- C12. `around CollectorSenderInterceptor::send` repeats once per collected channel with no channel name.

## Round 2: coverage
- V1. Aggregates and sagas: identifier resolution, load (found, not found, dropped), handler, save, repository
  call, `#[Apply]`, events published by the save.
- V2. Acknowledgement outcome of `AcknowledgeConfirmationInterceptor` and the final failure strategy that decided it.
- V3. Channel interceptor phases besides pre-send: post-send, after-send-completion, pre-receive, post-receive,
  after-receive-completion.
- V4. The receive itself: the poll that returned a message and what the channel applied (delay, priority, time to
  live); the process starts at the receive.
- V5. Command and query bus routing: the route selected and why.
- V6. Projection internals in `ProjectingManager` (transaction, stream load, flush, partition save) and
  `GatheredPartitionBatchDispatcher` opening the gathered group with its size.
- V7. Gathering store calls under `store part N of M`.
- V8. Conversion as its own steps: parameter, payload and media type conversion, gateway argument and reply
  conversion, the serialization media type on a send.
- V9. Splitters, transformers, routers, orchestrators, bridges get their own role label; a splitter's sends and a
  router's chosen channels are steps.
- V10. Retry decisions of `DelayedRetryErrorHandler`: retry number, delay, dead letter.
- V11. `MessagingEntrypointService` entries: an entry line and the header propagation it performs.
- V12. Licence-dependent services: which one ran.
- V13. A pollable channel without interceptors gets no adapter, so a send to it leaves no step.
