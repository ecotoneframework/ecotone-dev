# Splitter on ordinary handlers

Approved 2026-11-20. This feature removes the identity splitter method and its application channel when a
Command, Event or Internal service handler already returns the iterable to split. It also covers Command/Event
aggregate and saga action results that already flow to an output channel after saving. Standalone splitters keep
their existing behaviour. Query handlers, aggregate/saga factories and Internal handlers on aggregates/sagas are
excluded. No flag, new attribute, new handler parameter or storage is introduced.

## Decisions

The existing `#[Splitter]` is stacked on the ordinary handler. Each handler attribute owns its input, endpoint id,
output and required interceptors; only `gatherChannelName` may be set on the stacked splitter. This includes every
repeated Command, Event and Internal handler attribute on a method. Every handler needs an output channel.

The result is wired as handler -> hidden direct channel -> payload splitter -> original output. Splitting occurs
after the existing handler processor and, for aggregate/saga actions, after saving. The hidden splitter's endpoint
id is `<handler endpointId>.splitter`; it receives no method interceptors and is not an asynchronous endpoint.
Before, around, after and named interceptors apply to the ordinary handler once.

The handler and split share the consumer run and the existing boundary transaction. No transaction is opened by
the splitter. The original command caller receives no direct reply because the configured output takes priority.
The hidden splitter removes the input transport type-id header before splitting, so asynchronous owning handlers
can gather their independently typed results. Standalone splitters and transport conversion retain their existing
behaviour. Ordinary returned values inherit other metadata as with a standalone splitter; returned Message values keep the
standalone rules for their own headers. Empty output sends no parts.

Service handlers and aggregate/saga actions must declare an iterable return type. Factories are refused even
when their identifier output happens to be iterable: that output is produced by the framework. Pure event-sourced
actions are refused when their return is recorded as events. Recorder-based event-sourced actions may return an
independent iterable. Existing refusal of Internal handlers on aggregates/sagas is preserved.

Splitting stays open core. Gathering uses the existing Enterprise licence selection, group store, completion,
retry, timeout, release, retention and dead-letter paths. It needs an explicit stable endpoint id on every owning
handler. Group identity is derived from the handled message id and hidden splitter endpoint id, preserving replay
identity. The group's original payload is the handler result at the split. Empty gathering behaves like the
existing standalone splitter: one asynchronous gathered invocation with no parts. No new table or index is needed;
existing group-id and endpoint/state read paths suffice.

The flow graph discovers the application handler once, marks its `splitting` capability and adds `(splitting)` to
its label. It retains the original output edge and omits the hidden splitter/channel. Pulse consumes that label
through its existing graph renderer; its channel listing is sourced from asynchronous application channels.
Default tracing selects the ordinary handler and pollable application channels, so no internal hop name appears.

## Bootstrap refusals

Every refusal names `Class::method` and concrete ways out:

- Missing output: set outputChannelName on every handler attribute, or remove Splitter.
- Input, endpoint id or output on Splitter: move the setting to the handler, or use a separate splitter method.
- Required interceptor names on Splitter: move the names to the handler, or use a separate splitter method.
- Non-iterable declared return: return an iterable, use a separate iterable handler, or remove Splitter.
- Factory: split from an action method or a handler receiving factory output, or remove Splitter.
- Pure event-sourced action: record events separately through AggregateEvents and return an independent iterable,
  use a separate splitter handler, or remove Splitter.
- Query: use a Command/Event/Internal output handler, a separate splitter, or remove Splitter.
- Gathering without explicit handler endpoint id: set endpointId on every handler, or remove gatherChannelName.

## Acceptance evidence

`HandlerSplitterTest` exercises the named guarantees for Command/Event/Internal service results, repeated
attributes, handled metadata, empty output, aggregate and saga post-save output, recorder-based event-sourced
action output, asynchronous consumer runs, pointcut and required-name interceptors, gathering and same-input
replay, empty gathering, every refusal and graph discovery. `standalone_splitter_is_unchanged` holds the
standalone path. Tests use EcotoneLite flow testing and application handlers.

The DBAL gathering conformance suite includes
`failed_part_of_a_gathering_split_handler_is_replayed_from_the_dead_letter` and
`asynchronous_split_handler_and_its_parts_share_the_consumer_transaction`. The latter deliberately fails a later
part and checks that the handler and earlier part documents both rolled back, with that failure in dead letter.
Gathering conformance runs on PostgreSQL, MySQL and MariaDB. Existing splitter, gathering and flow suites cover
shared registration paths. The broad repository suite remains CI's responsibility.

## Round-one review decisions

The hidden splitter normalizes arrays and traversable results to a list in iteration order before splitting.
Standalone splitter behavior is unchanged. A factory/action pair sharing a route, either header-changing form,
and methods on projection classes are refused at bootstrap; projection rebuild/backfill would otherwise resend parts.
Static gathered-flow validation follows the ordinary handler input and output for stacked splitters and names
Class::method for a nested stacked handler. Split-handler validation runs once in GatheringModule::prepare(),
before gathering wiring and licence checks, including applications that do not use gathering.
