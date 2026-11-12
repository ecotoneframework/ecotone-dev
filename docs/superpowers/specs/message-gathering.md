# Message gathering

Gathering is an Enterprise extension of an ordinary asynchronous message handler. A splitter opts a flow in by
naming `gatherChannelName`; splitters without that setting retain their existing behaviour. `#[Gathered]` marks
the ordinary handler that receives the combined `GatheredParts` payload.

## Storage and completion

The feature owns `ecotone_gathering_groups` and `ecotone_gathering_parts`. Existing storage read paths were checked:

- `DocumentStore::findDocument(collectionName, documentId)` uses the document table's `(collection, document_id)`
  primary key. `getAllDocuments(collectionName)` could scan a collection and filter groups in application code,
  but has no indexed channel/state lookup. Recording each arrival would rewrite the entire group document.
- `StateStoredRepository::findBy(aggregateClassName, identifiers)` addresses one aggregate or saga; it has no read
  path for open groups by gather channel and requires the same whole-state rewrite.
- `DbalHandledMessages::wasHandled()` uses the deduplication primary key `(message_id, consumer_endpoint_id,
  routing_slip)`. Its `handled_at` index supports expiry cleanup. Neither read path carries group progress, part
  payloads, the original split message or a group state.

The gathering group table therefore has its own group-id primary key and `(gather_channel_name, state)` listing
index. The parts table's `(group_id, part_number)` primary key both deduplicates arrivals and reads a group's
results in split order.

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
include independent consumers and a deterministic transaction-snapshot interleaving. The independent consumers
announce readiness inside their consumption transactions before either is allowed to record its part.

## Bootstrap validation and nesting

A gathered handler must be an ordinary `#[InternalHandler]` or `#[CommandHandler]`, must be asynchronous, and
must receive `GatheredParts` as its first payload parameter. A splitter's gather channel must target such a
handler; a gathered handler must have a splitter pointing at it. Invalid wiring is refused during module
preparation with the supported alternatives in the exception message.

An optional router with no destination accounts for its part without a result, through the same completion
collaborator as the reply producer. Existing router behaviour without group headers remains unchanged.

A splitter without its own gather channel cannot split a tracked part. Attribute-defined static output chains
are checked at bootstrap; the Enterprise splitter processor checks dynamic routing slips when the part arrives.
Both refusals name the choices: give that splitter its own gather channel, or send the sub-work through a bus so
it leaves the gathered flow.

An inner splitter with its own gather channel opens an inner group. Its original message retains the outer
part's headers; its sub-parts carry the inner headers. Restoring the original application and parent-group
headers at inner release lets the inner gathered handler's result become the outer part's result. The shared
suite covers this through `inner_gathered_result_becomes_outer_part_result`.

`GatheredParts::releaseReason` is a typed `GatheredReleaseReason`; ordinary completion is
`ALL_PARTS_ACCOUNTED_FOR`. Results remain keyed by their split part numbers, including `null` for a part that
completed without returning a result.

## Timeout and retention

`#[Gathered(timeout: new TimeSpan(minutes: 30))]` schedules a delayed trigger at the split on the gathered
handler's own asynchronous channel. Consuming that trigger closes the group and invokes the ordinary handler in
that consumption's transaction. The payload includes only accounted results, the missing part numbers, the
original split payload and `GatheredReleaseReason::TIMEOUT`. The deadline is stored when the group opens;
replaying the same split does not open another group or schedule another timeout. A timeout trigger that arrives
after ordinary completion, including after closed-group cleanup, has no effect.

A failed part does not count as an arrival. Error-channel and dead-letter handlers receive the original group
headers for replay, but their successful handling of an error message does not report completion. The test
`replayed_failed_part_completes_group_without_timeout` leaves the group missing for 30 pinned days, repairs the
worker and replays the failed message through `DeadLetterGateway` before asserting complete results.

Closed groups are retained for seven days from release or discard by default. This matches
[`DeduplicationModule::REMOVE_MESSAGE_AFTER_7_DAYS`](../../../packages/Dbal/src/Deduplication/DeduplicationModule.php)
and its default use in
[`DbalConfiguration`](../../../packages/Dbal/Api/ExtensionObject/DbalConfiguration.php).
An application may return `GatheringConfiguration::createWithDefaults()->withRetention(new TimeSpan(days: 14))`
from a `#[ServiceContext]` method. `GatheredGroups::cleanupClosedGroups()` and `ecotone:gathering:cleanup` remove
expired closed groups together with their parts; cleanup never removes an open group. A part referencing a
removed group is refused with a message naming that retention setting and the cleanup command.

Parts reported after release are acknowledged without invoking the handler again. The retained closed row's
`latePartCount` records these arrivals, visible through `GatheredGroups::listClosedGroups()`. Ordinary progress
and missing-part numbers stay unchanged by a late arrival.

## Operations and Pulse

`GatheredGroups` lists open groups with accounted and missing part numbers, lists retained closed groups with late
arrival counts, requests release, discards and cleans up. `releaseNow(groupId)` returns `release requested` after
queuing a distinct manual trigger on the gathered handler's own asynchronous channel. The group remains open
until consumption closes it and invokes the handler in that consumption's transaction. Its result carries
`GatheredReleaseReason::RELEASED_BY_HAND`. Duplicate requests invoke the handler once. A trigger for an already
closed group does nothing. Handler failure rolls closure back and uses ordinary retry/dead-letter handling.

Discard closes the group transactionally without invoking the handler. Repeating discard does not move the
closure time or extend retention. `ecotone:gathering:list`, `ecotone:gathering:release`,
`ecotone:gathering:discard` and `ecotone:gathering:cleanup` expose the same operations. Listing with `--closed`
shows retained groups and late-arrival counts. Release output says `release requested`, since the handler has
not run when the command returns.

Pulse adds a tenant-scoped `gathering.groups` snapshot section through the existing snapshot-section extension.
Release and discard use authorized Distributed Bus requests, accepting only a JSON object with one non-empty
string `groupId`. A service other than the configured Pulse service cannot mutate a group. The service's
ordinary failure reply carries malformed-selection and unknown-group errors back to Pulse.

The dashboard's Gathering tab displays group progress, missing parts, deadline, state and late arrivals. Open
groups have release/discard controls; closed groups have none. The page says `Release requested` before trigger
consumption and displays service refusals, including a group removed by retention cleanup after the page was
loaded. Ordinary bus hops still do not carry gathering context.

The in-memory store retains a payload snapshot at split time, using the existing in-memory document store's
copy approach. A caller mutating its command after sending it cannot change the group's original payload.

## Named acceptance coverage

The shared `GatheringConformanceTest` runs through `EcotoneLite::bootstrapFlowTesting()` against core in-memory
and DBAL storage. `GatheringPulseTest` drives two applications through their Distributed Buses. Dashboard tests
boot Pulse beside a real split/gather application.

| Guarantee | Named test (without the `test_` prefix) |
|---|---|
| Durable opening | `open_group_survives_application_restart` |
| Split progress | `split_opens_a_group_until_all_parts_are_accounted_for`, `open_group_lists_accounted_and_missing_parts`, `progress_lists_accounted_parts_in_split_order` |
| Ordered results and no-result completion | `gathered_handler_receives_all_part_results_in_split_order`, `part_returning_nothing_counts_as_done` |
| Typed payload conversion and snapshot | `gathered_parts_restore_typed_results_and_original_payload`, `original_payload_is_a_snapshot_of_the_message_at_the_split` |
| Duplicate parts and concurrent completion | `duplicate_part_is_not_counted_twice`, `gathered_handler_runs_once_when_last_two_parts_are_recorded_concurrently` |
| Transaction rollback | `gathered_handler_failure_rolls_back_the_completing_part`, `failed_release_handler_keeps_group_open_until_normal_retry` |
| Original application headers | `released_message_carries_application_headers_but_not_the_split_requests_routing` |
| Bus boundary | `group_headers_do_not_cross_a_command_bus_hop`, `group_headers_do_not_cross_an_event_bus_hop` |
| Empty split | `empty_split_runs_gathered_handler_with_zero_parts` |
| Failed-part replay | `replayed_failed_part_completes_group_without_timeout` |
| Timeout and original deadline | `timeout_releases_group_with_missing_parts_reported`, `timeout_deadline_is_measured_from_the_split` |
| Split replay and overdue timeout | `replayed_split_schedules_only_one_timeout`, `timeout_after_retention_cleanup_does_not_recreate_the_group` |
| Late arrivals | `late_part_is_dropped_and_noted_on_closed_group` |
| Retention and unknown groups | `cleanup_retains_closed_groups_for_seven_days_from_release_and_never_removes_open_groups`, `configured_retention_applies_to_gateway_cleanup`, `part_for_cleaned_up_group_is_refused_with_the_retention_setting` |
| Nested groups | `inner_gathered_result_becomes_outer_part_result` |
| Ungathered nested splitter refusal | `splitter_without_gather_channel_inside_static_gathered_flow_is_refused_at_bootstrap`, `splitter_without_gather_channel_inside_dynamic_gathered_flow_is_refused` |
| Handler wiring and licence | `gather_channel_without_gathered_handler_is_refused_at_bootstrap`, `gathered_handler_without_a_splitter_is_refused_at_bootstrap`, `gathered_handler_without_asynchronous_is_refused_at_bootstrap`, `gathered_handler_with_plain_array_payload_is_refused_at_bootstrap`, `gathered_marker_without_an_ordinary_handler_is_refused_at_bootstrap`, `splitter_with_gather_channel_requires_enterprise_licence_at_bootstrap` |
| Ordinary handler attributes | `gathered_command_handler_receives_all_part_results` |
| Optional no-match router | `optional_router_with_no_match_counts_as_done_without_result` |
| Gateway operations | `manual_release_is_requested_until_its_trigger_is_consumed`, `discard_closes_the_group_without_invoking_its_handler` |
| Console operations | `console_lists_open_group_progress`, `console_release_reports_requested_before_handler_consumption`, `console_discards_a_group_without_invoking_its_handler` |
| Pulse snapshot and actions | `pulse_snapshot_lists_open_and_closed_groups_with_late_arrivals`, `pulse_release_reports_requested_until_handler_consumes_trigger`, `pulse_discard_closes_the_group_without_invoking_handler` |
| Pulse request guards | `pulse_action_refuses_invalid_group_selection`, `unconfigured_pulse_service_cannot_change_a_group` |
| Dashboard operations and failures | `page_shows_open_group_progress_and_operations`, `release_control_requests_release_before_handler_consumption`, `discard_control_closes_group_without_running_handler`, `page_displays_service_refusal_for_a_cleaned_up_group` |

## Maintainer decisions still required before publication

Four decisions remain outside this implementation's authority: the channel delay-capability seam and broker
limits, multi-destination routers in a gathered flow, stable splitter endpoint identity when `endpointId` is
omitted, and the provisional database current-read exception. No channel-capability guard, router policy or
new endpoint-id default/requirement has been added while these decisions are pending.

The provisional `loadPartsForRelease()` current read is confined to the completing transaction. The deterministic
`releasing_transaction_sees_a_part_committed_after_its_snapshot_was_established` test reproduces a MySQL/MariaDB
repeatable-read snapshot that otherwise misses a committed part. This mechanism is not recorded here as an
approved departure from storage rule 8; publication remains gated on the maintainer's decision.
