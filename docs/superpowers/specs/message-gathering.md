# Message gathering

Gathering is an Enterprise extension of an ordinary asynchronous message handler. A splitter opts a flow in by
naming `gatherChannelName`; splitters without that setting retain their existing behaviour. `#[Gathered]` marks
the ordinary handler that receives the combined `GatheredParts` payload.

## Storage and completion

The feature owns `ecotone_gathering_groups` and `ecotone_gathering_parts`. The document store was considered, but
rewriting a whole document for every arrival creates contention and its existing read path cannot list open
groups. Saga storage has the same rewrite problem and sagas are not addressed by channel messages. Deduplication
records message identities, without the payloads and group progress that gathering requires.

The in-memory store and DBAL store are exercised through the same split/handler/gateway contract in
`GatheringConformanceTest`. `DbalGatheringModule` supplies a store registration to the core module and registers
`GatheringTableManager` with the existing database setup commands. Message handling performs no schema work.

A group id is derived from the split message id and splitter endpoint id. The group retains the original message;
part reports retain the payload representation and content/type metadata delivered by the asynchronous channel.
`StoredGatheringMessages` uses the existing outbound converter and conversion service. The gathered handler's
wrapper reconstructs results with those existing converters, preserving part numbers and split order.

Part rows have a unique `(group_id, part_number)` key. Each newly inserted part increments `arrived` with an atomic
`UPDATE arrived = arrived + 1`. This is the maintainer-approved departure from storage rule 8's version check:
version conflicts would create retries under concurrent arrivals (the design research records 499 retries for
500 replies in one NServiceBus report). A sweeper would introduce release latency and another process. Neither is
the completion mechanism here.

The completing consumer closes the group and invokes the ordinary handler inside the same transaction. Failure
rolls back its part, the counter and group closure; retrying that message can complete the group again. The
in-memory implementation restores the previous group state when that invocation fails.

An empty split opens a zero-size group and queues a trigger on the gathered handler's asynchronous channel. It
uses the same close-and-invoke path, without creating a part row.

## Flow boundaries and original headers

Group id, part number, size and gather channel travel as dedicated framework headers. The gather channel is not
an entry on the part's routing slip. `RequestReplyProducer` reports a result when the part has no further output
or routing-slip step; a handler returning nothing reports completion without a result. These headers remain on
the part's own asynchronous flow and are excluded from bus header propagation.

A released message restores the split message's application headers and correlation/tracing context, plus parent
group headers for a nested flow. It does not restore the split request's routing slip, reply channel, sequence
headers, delivery settings or consumer state. Payload content metadata describes `GatheredParts`. Routing after
the gathered handler follows that handler's output or its own flow; there is no saved continuation to the split
request and no synchronous answer to its original caller.

The header rule is covered by
`released_message_carries_application_headers_but_not_the_split_requests_routing`; the atomic completion tests
include independent consumers and a deterministic transaction-snapshot interleaving.

## Release reason

`GatheredParts::releaseReason` is a typed `GatheredReleaseReason`; ordinary completion is
`ALL_PARTS_ACCOUNTED_FOR`. Results remain keyed by their split part numbers, including `null` for a part that
completed without returning a result.
