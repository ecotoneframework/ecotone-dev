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

A group id is derived from the split message id and splitter endpoint id. A splitter naming a gather channel must supply an explicit `endpointId`; bootstrap refuses a generated identity because replay and restart must address the same group. The group retains the original message;
part reports retain the payload representation and content/type metadata delivered by the asynchronous channel.
`StoredGatheringMessages` uses the existing outbound converter and conversion service. The gathered handler's
wrapper reconstructs results with those existing converters, preserving part numbers and split order.

The group row carries a version. Both stores follow storage rule 8 through optimistic version checks. Recording
reads the group and checks for an already-visible `(group_id, part_number)` row. A duplicate returns without
changing the group. A new part conditionally writes the next `arrived` and version values against the version
read, also closing the group when that arrival completes it. Only a winning writer inserts the part row. The
unique part key remains a final integrity constraint.

A zero-row version check makes no group or part change. The gathering handler sends exactly one replacement
report to its own channel with a fresh message id, retaining group/part identity, payload and content/type
metadata, and returns normally. Default asynchronous deduplication records the original delivery; the new id
lets the replacement run. Each replacement is a new delivery and transaction, with a fresh snapshot. No conflict
exception reaches instant retry, failure answers, an error channel or the dead letter, and no user retry
configuration is required. Timeout and manual-release triggers use the same conditional close and replacement
path. Synchronous discard is one conditional write against `state = open`, incrementing the version; it depends
on no previously read version and cannot lose a version check.

A successful version check proves no group write committed after the reader's snapshot. The completing
transaction therefore sees every committed part through a plain read; no locking read is needed. This avoids
releasing a MySQL/MariaDB repeatable-read snapshot with a committed result missing. A duplicate committed after
the snapshot also advanced the group version, so the stale delivery conflicts and its replacement sees that
part as a duplicate. The in-memory twin keeps the same version transitions and rollback behavior.

There is no inline spin or backoff: one conflicting delivery creates one replacement and finishes. A report can
lose only to a committed version-changing write of its own group, so retries are bounded by writes to that group
(parts, release or discard), rather than by consumer count. One consumer on the gathered handler's channel avoids
report-to-report conflicts. Several consumers can create retried small reports without re-running the part work.

The existing acknowledgement contract remains unchanged. On a DBAL channel using the group store's connection,
the replacement enqueue commits in the handler transaction before acknowledgement removes the source delivery.
A crash between commit and acknowledgement leaves both messages. This is safe at least once: the original is
deduplicated or conflicts again, and part identity is the pair `(group_id, part_number)`. Returning a zero-row
check normally leaves nested DBAL transactions/savepoints usable; no rollback-only state is introduced.

The completing consumer closes the group and invokes the ordinary handler inside the same transaction. Failure
rolls back its part, group version and closure; retrying that message can complete the group again. The
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
`released_message_carries_application_headers_but_not_the_split_requests_routing`; the transactional completion tests
include independent consumers and a deterministic transaction-snapshot interleaving. The independent consumers
announce readiness inside their consumption transactions before either is allowed to record its part.

## Bootstrap validation and nesting

A gathered handler must be an ordinary `#[InternalHandler]` or `#[CommandHandler]`, must be asynchronous, and
must receive `GatheredParts` as its first payload parameter. A splitter's gather channel must target such a
handler; a gathered handler must have a splitter pointing at it. Invalid wiring is refused during module
preparation with the supported alternatives in the exception message.

An optional router with no destination accounts for its part without a result, through the same completion
collaborator as the reply producer. The maintainer approved refusing a router that resolves multiple destinations for a message carrying group headers. The runtime guard runs before sending any branch and names three exits: one destination, a nested splitter with its own gather channel, or a bus hop. Existing router behaviour without group headers remains unchanged.

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
`GatheredReleaseReason::MANUAL_RELEASE`. Duplicate requests invoke the handler once. A trigger for an already
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
| Conflict redelivery without failure | `concurrent_part_reports_conflict_and_are_retried_without_reaching_the_dead_letter`, `releasing_transaction_sees_a_part_committed_after_its_snapshot_was_established`, `conflicting_group_operation_is_redelivered_before_observing_a_fresh_snapshot` |
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
| Stable splitter identity | `gathered_splitter_without_explicit_endpoint_id_is_refused_at_bootstrap` |
| Router fan-out refusal | `router_resolving_multiple_destinations_in_a_gathered_flow_is_refused` |
| Timeout channel capability | `gathered_timeout_is_refused_when_its_channel_cannot_hold_delayed_messages`, `gathered_timeout_is_refused_when_channel_builder_does_not_declare_delay_capability` |
| Sequential and combined timeout holding | `gathered_timeout_waits_on_first_delayable_channel_before_non_delayable_sequential_hop`, `gathered_timeout_resolves_combined_channel_to_its_first_delayable_hop`, `gathered_timeout_is_refused_when_first_sequential_channel_is_not_delayable`, `gathered_timeout_is_refused_when_combined_channels_first_physical_hop_is_not_delayable` |
| Unknown delay maximum | `gathered_timeout_trusts_symfony_messenger_unknown_maximum_as_unbounded`, `gathered_timeout_trusts_laravel_queue_unknown_maximum_as_unbounded`, `gathered_timeout_trusts_custom_amqp_delay_strategy_unknown_maximum_as_unbounded` |
| Broker timeout boundaries | `gathered_timeout_at_sqs_fifteen_minute_limit_is_accepted_at_bootstrap`, `gathered_timeout_exceeding_sqs_fifteen_minute_limit_is_refused_at_bootstrap`, `gathered_timeout_on_sqs_fifo_queue_is_refused_at_bootstrap`, `kafka_declares_no_delay_support_and_refuses_gathered_timeout`, `gathered_timeout_is_accepted_on_amqp_delayed_channel`, `gathered_timeout_is_accepted_on_redis_delayed_channel` |
| Optional no-match router | `optional_router_with_no_match_counts_as_done_without_result` |
| Gateway operations | `manual_release_is_requested_until_its_trigger_is_consumed`, `discard_closes_the_group_without_invoking_its_handler` |
| Console operations | `console_lists_open_group_progress`, `console_release_reports_requested_before_handler_consumption`, `console_discards_a_group_without_invoking_its_handler` |
| Pulse snapshot and actions | `pulse_snapshot_lists_open_and_closed_groups_with_late_arrivals`, `pulse_release_reports_requested_until_handler_consumes_trigger`, `pulse_discard_closes_the_group_without_invoking_handler` |
| Missing storage setup instructions | `missing_gathering_tables_name_setup_and_migration_instructions` |
| Pulse request guards | `pulse_action_refuses_invalid_group_selection`, `unconfigured_pulse_service_cannot_change_a_group` |
| Dashboard operations and failures | `page_shows_open_group_progress_and_operations`, `release_control_requests_release_before_handler_consumption`, `discard_control_closes_group_without_running_handler`, `page_displays_service_refusal_for_a_cleaned_up_group` |

## Configuration decisions

The maintainer approved three follow-ups: explicit gathered splitter endpoint ids, refusal of multi-destination
routers in gathered flows, and an optional channel-builder delay capability with bootstrap timeout validation.
The explicit-id guard and runtime router guard are implemented. The public `#[Router]` path resolves through application code, so its fan-out guard runs when the message arrives; the maintainer confirmed that no new static-routing API or testing exception is needed. The maintainer approved trusting an unknown delay maximum as unbounded; Symfony Messenger, Laravel Queue and custom AMQP delay strategies use that policy without driver inspection.

## Delayed-channel capability

The optional public `DelayedMessageChannelBuilder` interface lives in `Api/ExtensionObject`. It declares
`supportsDelayedMessages(): bool` and `maximumDelay(): ?TimeSpan`; `null` means no fixed broker maximum within
the framework's representable duration range. The timeout guard runs at bootstrap. A builder without this
interface, a channel declaring no support, or a timeout above a declared maximum is refused with the ways out.
No timeout means no new capability requirement for the channel.

- In-memory delayable queues support delayed delivery; ordinary queues and streaming channels do not.
- DBAL stores `delayed_until` as a bigint timestamp (`Database/EnqueueTableManager.php`,
  `Connection/DbalProducer.php`) and exposes no fixed broker maximum.
- AMQP's default `HeadersExchangeDelayStrategy` creates a per-delay queue with `x-message-ttl` and dead lettering.
  RabbitMQ accepts nonnegative millisecond TTL values including AMQP long-long-int, so the default strategy has
  no fixed limit below the framework's duration range ([RabbitMQ TTL](https://www.rabbitmq.com/docs/ttl)).
- Redis stores delayed entries in its sorted set with a future timestamp score (`RedisOutboundChannelAdapter.php`)
  and exposes no fixed broker maximum.
- SQS maps the delay to `DelaySeconds` (`SqsOutboundChannelAdapter.php`). Standard queues accept at most 900
  seconds; FIFO queues do not accept per-message delays and declare no support
  ([AWS SendMessage](https://docs.aws.amazon.com/AWSSimpleQueueService/latest/APIReference/API_SendMessage.html)).
- Kafka declares no delay support.

Symfony Messenger and Laravel Queue declare delay support with no known maximum. A custom AMQP delay strategy
is accepted on the same basis. The maintainer approved trusting these unknown maxima as unbounded, without
driver inspection or an additional declaration. The underlying transport's delay limit is the application's
responsibility on these channels. A builder not implementing the optional interface is still refused for a
configured gathered timeout.

The timeout guard resolves a `CombinedMessageChannel` alias exactly as asynchronous wiring does, then validates
only the first physical channel in the sequential chain. That channel holds the delayed trigger; later hops do
not need delay support. A delayable first channel followed by a non-delayable channel runs at the deadline; the
reverse is refused at bootstrap. The same guarantees cover an explicit asynchronous array and a combined alias.

## Database coverage

The shared functional gathering contracts run on SQLite as well as PostgreSQL, MySQL and MariaDB.
The four two-connection race tests run on PostgreSQL, MySQL and MariaDB only. SQLite serialises writers
with a database-wide lock, so their overlapping writer transactions cannot exercise the server-engine race.
These methods are explicitly skipped on SQLite; ordinary functional contracts remain enabled.

Review coverage also proves duplicate manual requests run once, retained timeout triggers do not invoke a completed
group again, empty and timed-out inner groups become accounted outer parts, and a failed inner summary can be
replayed before the outer group completes. Named contracts are `two_manual_release_requests_invoke_the_gathered_handler_once`,
`timeout_after_completion_does_not_invoke_a_retained_group_again`, `empty_inner_split_becomes_an_accounted_outer_part`,
`inner_timeout_result_becomes_an_accounted_outer_part`, `inner_gathered_handler_failure_replays_before_completing_the_outer_group`
and `dbal_report_channel_redelivers_the_completing_part_with_its_group_transaction`.
The `conflicting_group_operation_is_redelivered_before_observing_a_fresh_snapshot` provider covers manual and timeout
release, report conflicts on a non-transactional channel, and discard interleaved with either a part or a release.
These cases remain within the four server-engine race methods excluded on SQLite.
