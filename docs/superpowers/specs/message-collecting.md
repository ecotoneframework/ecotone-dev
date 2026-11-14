# Collect messages into a batch (Enterprise)

Approved on 2026-11-13; built on message gathering, PR #765. This spec records the approved decisions and the
implementation clarifications. The user guide is [message collecting](../../message-collecting.md).

## Scope

The opt-in `#[Collected]` marker applies to ordinary asynchronous service `#[CommandHandler]`, `#[EventHandler]`
and `#[InternalHandler]` methods. Senders use their ordinary buses or output channels. No splitter or sending-side
batch API is involved. No debounce, release expression, reducer or synchronous collection is included.
Handlers without the marker retain their existing routing, parameter conversion and request/reply behavior.

The first parameter is `Ecotone\Api\Messaging\CollectedMessages`. Subscriptions must be explicit (`routingKey`,
`listenTo`, `inputChannelName` respectively). `#[Asynchronous]` already requires an explicit endpoint id for all
three kinds, so collecting reuses that refusal. Aggregate/saga handlers, queries, missing asynchronous handling,
`#[Gathered]` combined with `#[Collected]`, a wrong first parameter and missing release limits fail at bootstrap.
An Enterprise licence is required; container selection uses the existing licence mechanism.

## Limits, grouping and release

`Collected` exposes readonly `?int $maxSize`, `?TimeSpan $maxWait` and `string|Closure|null $groupBy`.
At least one limit is required: maxSize >= 1, maxWait > 0. Size alone can wait indefinitely for manual release.
The first recorded message fixes the deadline. A size release closes that specific batch and a later message
opens another generation. Its stale delayed timeout cannot release the new generation.

Expressions reuse `AttributeExpressionExecutorCompiler` unchanged, including payload/header/service parameter
resolution. PHP 8.5 permits static closures in attributes. A closure payload parameter is first or explicitly
marked `#[Payload]`; an unannotated object later in the list is a service reference. The closure's payload type
selects per-message deserialization. String expressions use the type header, with unchanged payloads if absent.
Null and non-scalar/non-stringable keys are refused; expression failures use ordinary retry/dead-letter handling.
Keys normalize to strings. Without groupBy the public key is null and all messages for the endpoint share a batch.

The ordinary handler's parameter conversion receives the completed typed batch; it never attempts to convert
each ordinary incoming message to that type. The collecting wrapper restores each payload through the existing
conversion service and then calls the handler with a fresh batch message. That message carries the first recorded message's user, tenant and tracing context, excluding its delivery and flow headers through the shared gathering context
and bus-hop exclusions. It has a fresh identity/timestamp. The timer uses the same exclusions;
per-message headers belong to each `CollectedMessage` object. `CollectedMessages` has public readonly
`messages`, `groupKey` and `releaseReason`; `messages` is a list of `CollectedMessage` objects with readonly
`payload` and `headers`. There is no redundant size property: use count(messages). Its own `CollectedReleaseReason` enum has `SIZE_REACHED`,
`MAX_WAIT`, and `MANUAL_RELEASE` cases. Return values flow once for the whole batch.

Payload order is recording order, equal to queue order with one consumer, not necessarily send order with
several consumers. The message that fills a batch or its delayed/manual trigger invokes the handler. Handler
failure rolls back recording and release through the same message transaction and retries the releasing message.

## Storage, identities and conflict handling

The existing gathering tables, stores, versioned group row, retention, missing-table instructions and database
setup feature are reused. No new table is added. Gathering's zero-size split remains distinct from an unknown
collecting maximum; internally an unknown maximum uses -1, exposed as null everywhere in operations.

Existing read paths considered before adding indexes:

- The primary group-id lookup cannot locate the current batch for an endpoint/key. The group row gains nullable
  handler, readable group key, fixed-size key hash and generation fields. A unique index on
  `(handler, group_key_hash, generation)` supports current-generation lookup and rejects simultaneous opens of
  the same generation. Its handler prefix also supports endpoint batch listings. Each group id is random and
  never reused, so retained delayed triggers always identify their original batch.
- The existing part primary key `(group_id, part_number)` prevents duplicate part numbers but cannot detect the
  same ordinary message after a batch release. Nullable handler and message-id fields plus a unique
  `(handler, message_id)` index provide one indexed deduplication lookup across retained batches of that handler.
  Cleanup removes those identities together with retained parts.

Open/append/release use the same optimistic group version as gathering. A lost version check or lost generation
insert writes nothing and re-sends through the existing conflict-redelivery collaborator to the handler's own
asynchronous channel. A new transaction consumes it. No locking loop, new locking scheme or runtime DDL is added.
Hot-key throughput depends on conflict rate; one consumer avoids inter-consumer conflicts.

DBAL recording, release, handler execution and deferred timeout sends are protected by the message transaction.
The in-memory twin uses the same shared conformance suite. **Maintainer-approved parity exception (2026-11-14):**
the in-memory store is test-only and has no transaction. A failure raised after the store returned, such as a
deferred channel send, cannot roll back its state. DBAL rolls it back through the boundary transaction.
Gathering timeout sends have the same property. The timeout-send failure test therefore skips in-memory with
that reason and runs on PostgreSQL, MySQL and MariaDB. No collector or gathering transaction infrastructure is changed.

## Operations and deployment

The existing `GatheredGroups` gateway adds `listOpenBatches(endpointId)` and `listClosedBatches(endpointId)`;
ordinary group listings remain separate. Shared requestRelease(groupId), discard(groupId) and cleanup apply to
both. Manual release queues a trigger on the recorded pre-execution routing channel, so it enters the async
queue rather than invoking the handler synchronously. A discard never invokes it. Closed batches follow
`GatheringConfiguration` retention (seven days by default); cleanup leaves open batches intact.

`ecotone:collecting:list <endpointId> [--closed]` lists batches; existing gathering release/discard/cleanup
commands act by group id. Pulse's Gathering page adds separate batch rows and shares the existing actions.
Unknown maximum size is blank in the console and shown as no maximum size in Pulse; the internal sentinel
never appears in the API or UI.

Endpoint identity is durable: renaming an endpoint orphans its open batches under the old id. List and release
or discard them before deploying a rename. All consumers must be upgraded before enabling the marker.
Gathering tables are unreleased; existing prerelease installations must migrate the added columns/indexes or
drain and recreate the gathering tables before upgrading. Setup does not alter existing tables.

## Acceptance evidence

Tests use EcotoneLite flow testing and public APIs. The shared collecting conformance suite covers in-memory
and DBAL; the database branch and concurrency tests run on PostgreSQL, MySQL and MariaDB.

| Guarantee | Named test (test_ prefix omitted) |
|---|---|
| Allowed handler kinds, ordinary sends | collected_command_accepts_ordinary_class_routed_messages; collected_internal_handler_accepts_a_previous_handlers_output |
| Aggregate/saga/query/Gathered refusals | collected_handler_on_an_aggregate_is_refused_at_bootstrap; collected_handler_on_a_saga_is_refused_at_bootstrap; collected_query_handler_is_refused_at_bootstrap; collected_and_gathered_on_one_handler_are_refused_at_bootstrap |
| Async, licence and typed parameter | collected_handler_without_asynchronous_is_refused_at_bootstrap; collected_handler_requires_an_enterprise_licence; collected_handler_requires_the_typed_batch_as_its_first_parameter |
| Explicit subscriptions and identity | collected_event_handler_without_explicit_subscription_is_refused_at_bootstrap; collected_command_handler_without_explicit_subscription_is_refused_at_bootstrap; collected_internal_handler_without_explicit_subscription_is_refused_at_bootstrap; collected_internal_handler_requires_an_explicit_endpoint_id; collected_command_handler_requires_an_explicit_endpoint_id; collected_event_handler_requires_an_explicit_endpoint_id |
| Limits and delay support | collected_handler_without_max_size_or_max_wait_is_refused_at_bootstrap; collected_handler_refuses_zero_max_size_at_bootstrap; collected_handler_refuses_zero_max_wait_at_bootstrap; collected_handler_refuses_negative_max_size_at_bootstrap; collected_handler_refuses_negative_max_wait_at_bootstrap; collected_max_wait_requires_a_delayable_asynchronous_channel |
| Size release and no groupBy | collected_handler_runs_once_when_the_batch_reaches_max_size |
| Keys and independent handlers | messages_are_batched_separately_per_group_key; message_without_a_group_key_is_refused; non_scalar_group_key_is_refused_without_opening_a_fallback_batch; failing_group_expression_names_the_handler_and_expression_without_opening_a_batch; two_collected_handlers_of_one_event_keep_separate_batches |
| Closure conversion and explicit payload | group_key_can_be_a_closure_and_its_payload_type_deserializes_each_message; group_key_closure_resolves_an_explicit_payload_after_a_header |
| First-message deadline and size-only wait | collected_handler_runs_with_a_partial_batch_after_max_wait_from_the_first_message; later_messages_do_not_postpone_the_release; max_size_only_batch_waits_until_full_or_released_by_hand; max_wait_only_collects_a_batch_without_a_known_size |
| Generation and stale timeout | message_after_a_release_opens_a_new_batch; timeout_of_a_released_batch_does_not_release_the_next_batch |
| Per-message API | batch_contains_ordered_message_objects_with_their_own_payload_and_headers |
| Recording order and headers | batch_keeps_the_order_of_recording; batch_inherits_the_first_messages_user_headers_and_retains_each_messages_own_headers; first_messages_delivery_headers_do_not_cross_the_batch_or_output_boundary; max_wait_timer_keeps_the_first_messages_tenant_for_its_database_connection |
| Duplicate and retry | redelivered_message_is_not_collected_twice_even_after_its_batch_is_released; failing_collected_handler_is_retried_with_the_same_batch; failed_timeout_send_rolls_back_opening_the_batch |
| Return value | return_value_flows_once_per_batch |
| Concurrency | batch_is_released_once_when_last_messages_are_recorded_in_a_real_database_race; consumers_opening_the_same_generation_in_a_real_database_race_keep_one_batch_and_resend_the_loser |
| Storage and retention | collected_handler_requires_durable_storage_outside_flow_testing; closed_batches_follow_gathering_retention_and_open_batches_are_kept |
| Gateway, console and Pulse | open_batches_are_listed_by_collected_endpoint_id; released_batches_are_listed_by_collected_endpoint_id; open_batch_can_be_released_by_hand_without_collecting_the_release_message; group_listings_remain_separate_from_collected_batch_listings; batch_console_listing_uses_endpoint_id_and_hides_an_unknown_size; pulse_lists_open_batches_by_collected_endpoint_and_hides_unknown_size; pulse_requests_batch_release_on_its_asynchronous_channel; pulse_discards_a_batch_without_invoking_its_handler |

Existing gathering suites remain the regression proof for zero-size splits, late parts, timeouts, operations,
transaction behavior, delay validation and conflicts. The shared concurrent-consumer barrier is reused rather
than introducing a second process harness.
