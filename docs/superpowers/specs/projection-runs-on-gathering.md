# Asynchronous projection runs on gathering

An asynchronous rebuild or backfill (`#[ProjectionRebuild(asyncChannelName: ...)]`,
`#[ProjectionBackfill(asyncChannelName: ...)]`) runs as a gathered split. Before this, nothing knew when the last
batch of a run had finished; Ecotone Pulse guessed it from trace spans. Gathering already stores what a run is: a
group with a known size, the parts that arrived, the parts that are missing, and one completion. Both features are
Enterprise: an asynchronous rebuild or backfill was already refused without a licence at bootstrap
(`ProjectingModule::prepare`), so every gathered run is licensed. The synchronous rebuild and backfill are unchanged.

## The run

The prepare step (the handler of `ecotone:projection:rebuild|backfill`, `ProjectionOperations` and Pulse's
request) counts the partitions as before, builds the batches, and hands them to a `PartitionBatchDispatcher`. The
open-core `UngatheredPartitionBatchDispatcher` sends them as before; the Enterprise
`GatheredPartitionBatchDispatcher`, chosen by `LicenceDecider`, opens one gathering group for an asynchronous run
and stamps every batch with the gathering headers. No new table, column or index: the existing gathering store,
tables and operations hold a run (design rule D1).

- The group's `endpointId` is `<projection>.rebuild` or `<projection>.backfill`. An application handler with the
  same `endpointId` is refused at bootstrap, naming the projection, the handler and the ways out.
- The group id is derived from the prepare message id and that `endpointId`, as the gathering splitter derives
  it. The prepare step runs in the process that starts the run (a direct channel), inside the caller's
  transaction, so a failed prepare rolls back with its group; there is no queued prepare message to redeliver.
- The group key is the prepare message's tracing id. Pulse matches a run it started by that key. The group key
  hash stays empty, so collecting's open-slot uniqueness never applies to a run and two runs never merge.
- The size is the number of batches. An empty projection opens a zero-size group; it closes when its completion
  is consumed on the batch completion channel, like gathering's empty split (not at prepare time).
- A second rebuild while a run is open opens its own group; both complete independently, as two runs did before.
- Batches sent to the asynchronous channel, and the completion of an empty run, carry the request's headers that
  the framework propagates to following messages (the tenant of a multi-tenant application, user metadata);
  framework headers such as the id, routing, reply channel and delivery delay are not copied. Before, batches lost
  the tenant and a multi-tenant asynchronous rebuild failed on its first batch.

Each batch message is consumed by the existing `partition_batch_executor_handler`, which reports its part through
the existing `PartCompletion` when `executeBatch` returns: the report is a message on the run's gather channel
(`ecotone.projection.<operation>.completion:<projection>`), recorded by an internal gathered handler
(`ProjectionRunCompletion`, no user hook: the closed group is the record). The per-partition messages a batch
sends carry no gathering headers, so a batch is counted only when all its partitions are projected
(`batch_failing_after_its_first_partition_is_not_counted_as_done`).

## Batch completion channel

The internal gathered handler is asynchronous on the run's `asyncChannelName` by default (maintainer decision
M3). `batchCompletionChannelName` on `#[ProjectionRebuild]` and `#[ProjectionBackfill]` names a separate channel for
it. Naming `batchCompletionChannelName` without `asyncChannelName` is refused when the attribute is read, naming both
ways out. An `asyncChannelName` or `batchCompletionChannelName` that is not registered as a message channel is refused at
bootstrap, naming the projection, the attribute, the parameter and the ways out.

## Failure and conflicts

A failed batch is not reported: it goes through the endpoint's retries, error channel and dead letter, and stays
missing until it is replayed; its replay completes the run. There is no timeout. Discard and release stay
available through the existing gathering operations. Two part reports recorded at once lose a version check and
throw `ConcurrencyException`; the default instant retries handle it (rule 8a), and an exhausted report waits in
the dead letter for replay. Step 7 of the unit measured this; the numbers are in the pull request.

## Graceful shutdown

A batch is handled to its end: `PartitionBatchExecutorHandler` no longer stops between partitions on a
termination signal, and a backfill batch runs each partition until it is caught up
(`ProjectingManager::executeUntilCaughtUp`). The consumer stops after that message, and the next batch waits on
the channel. Before, the early return acknowledged a batch whose partitions were not all projected. Ordinary
`execute()` outside the batch path keeps stopping on the signal. Maintainer decision (option A, 2026-11-14): no
mechanism that gives a message back on termination.

## Storage

Gathering storage follows the gathering module: `DbalGatheringModule` switches to the DBAL store also when a
projection runs asynchronously. Outside flow testing, an asynchronous rebuild or backfill without the DBAL module
is refused at bootstrap with a projection-specific message (install and enable DBAL, set up the tables with
`--feature=gathering`, or remove `asyncChannelName`).

## Pulse

Ecotone Pulse reads a run from its group, also when no traces are stored: `PulseTrackings` lists the runs Pulse
started (matched by group key) and the asynchronous runs started from the console (listed from their groups,
`startedFrom: console`). Each entry carries `batchesDone` (recorded parts), `batchCount` (group size),
`missingBatches`, `failedBatches` (the batch number and dead-letter message id of each batch whose message is in
the dead letter, found by its gathering group header), `groupId`, the start and, when closed, the duration. A part report that exhausted its retries is listed in
`batchesWaitingToBeRecorded`: its batch was projected and its record waits in the dead letter, so it is not a failed
batch. The state is `done` when the group closed with every part, `discarded` or `released` when an operator
discarded or released it by hand (with the batches not done), `failed` when a batch is in the dead letter (Pulse
labels it as waiting for the replay), otherwise `running`. A run whose group was removed by retention is `done`.
The service reports each projection's effective batch completion channel (`rebuildBatchCompletionChannel`,
`backfillBatchCompletionChannel`); while a run's reports queue behind its batches on a shared channel with nothing
recorded yet, Pulse shows the running time and, once in the Operation cell, how `batchCompletionChannelName` shows
progress instead of a 0% bar; after the first record the count reads "batches recorded". A discarded or released run
says its missing batches were not projected; it is not raised in the attention banner, which shows running and
failed runs only. A synchronous run is `done` once its tracking exists: the
tracking is written in the run's transaction, so a failed synchronous run leaves none
(`test_a_failed_synchronous_rebuild_leaves_no_tracking`). The span-derived progress and the
`ecotone_projecting_rebuild_batch_number|batch_count|partition_count` headers are removed. The run's group is
also listed on the Gathering tab under its `endpointId`.

Progress counts part reports. On the shared channel the reports queue behind the batches not yet executed, so the
run records no batch until every batch has been executed, then advances as the reports are consumed; Pulse shows it
as described above (the running time while nothing is recorded, then "batches recorded"). A separate
`batchCompletionChannelName` with its own consumer moves the page as batches finish.

## Named acceptance coverage

| Guarantee | Named test (without the `test_` prefix) |
|---|---|
| A run completes once | `asynchronous_rebuild_completes_once_when_every_batch_was_executed`, `asynchronous_backfill_completes_once_when_every_batch_was_executed` |
| Concurrent completion on real databases | `batches_executed_concurrently_complete_the_rebuild_once_in_a_real_database_race` |
| Empty projection | `empty_projection_rebuild_completes_once_its_completion_is_consumed` |
| Second run | `rebuild_of_a_projection_with_an_open_run_opens_its_own_group` |
| Synchronous path | `synchronous_rebuild_is_unchanged`, `a_failed_synchronous_rebuild_leaves_no_tracking` |
| Batch completion channel | `batch_completion_can_be_consumed_from_a_separate_channel`, `backfill_batch_completion_can_be_consumed_from_a_separate_channel`, `rebuild_batch_completion_channel_without_asynchronous_rebuild_is_refused_at_bootstrap`, `backfill_batch_completion_channel_without_asynchronous_backfill_is_refused_at_bootstrap` |
| Endpoint id clash | `application_endpoint_id_equal_to_a_run_endpoint_id_is_refused_at_bootstrap` |
| Failure and replay | `failed_batch_stays_missing_until_it_is_replayed_from_the_dead_letter`, `replayed_batch_completes_the_rebuild` |
| No header leak into partitions | `batch_failing_after_its_first_partition_is_not_counted_as_done` |
| Graceful shutdown | `stop_signal_during_a_rebuild_batch_lets_the_batch_finish_and_leaves_the_next_batch_on_the_channel`, `stop_signal_during_a_backfill_batch_lets_each_partition_of_the_batch_finish` |
| Durable storage | `run_group_survives_application_restart`, `asynchronous_rebuild_with_dbal_is_accepted_without_test_configuration`, `asynchronous_rebuild_without_durable_storage_is_refused_outside_flow_testing_with_the_ways_out`, `asynchronous_backfill_without_durable_storage_is_refused_outside_flow_testing_with_the_ways_out` |
| Both stores | `asynchronous_projection_rebuild_is_one_group_under_its_endpoint_id_that_closes_once` (in-memory and DBAL) |
| Unregistered channels | `an_unregistered_rebuild_channel_is_refused_naming_the_attribute_and_the_ways_out`, `an_unregistered_backfill_channel_is_refused_naming_the_attribute_and_the_ways_out`, `an_unregistered_batch_completion_channel_is_refused_naming_the_attribute_and_the_ways_out` |
| Two stream filters | `rebuild_of_a_projection_reading_two_streams_counts_the_batches_of_both_and_completes_once` |
| Multi-tenancy and metadata | `asynchronous_rebuild_of_a_tenant_completes_in_that_tenants_group_and_is_listed_for_that_tenant_only`, `asynchronous_rebuild_of_a_tenant_without_partitions_completes_in_that_tenants_group`, `metadata_of_a_rebuild_request_reaches_its_batches_while_framework_headers_do_not` |
| Pulse progress without traces | `rebuild_progress_counts_executed_batches_without_stored_traces` |
| Pulse states | `a_rebuild_through_the_projection_rebuild_channel_is_running_until_its_batch_is_handled`, `a_rebuild_batch_in_the_dead_letter_is_failed_and_links_its_dead_letter`, `a_replayed_rebuild_batch_completes_the_tracked_rebuild`, `a_rebuild_started_from_the_console_is_listed_from_its_group`, `a_synchronous_rebuild_started_from_the_console_is_not_listed`, `the_group_of_a_rebuild_is_listed_in_the_gathering_snapshot_under_its_endpoint_id`, `a_discarded_rebuild_is_not_shown_as_done`, `a_rebuild_released_by_hand_with_batches_missing_is_not_shown_as_done`, `a_batch_whose_record_waits_in_the_dead_letter_is_executed_and_not_failed`, `a_pulse_started_rebuild_beyond_the_listing_limit_is_not_listed_as_started_from_the_console`, `a_rebuild_whose_group_was_removed_by_retention_is_done` |
| Pulse pages | `a_running_rebuild_of_a_projection_without_partitions_shows_zero_batches_and_is_not_failed`, `a_rebuild_whose_failed_batch_was_replayed_shows_done_with_every_batch`, `a_rebuild_started_from_the_console_is_shown_without_a_trace`, `group_opened_between_two_seconds_shows_the_second_it_opened_in`, `a_rebuild_with_a_failed_batch_waits_for_its_replay_and_links_its_dead_letter`, `a_rebuild_with_a_failed_batch_and_a_batch_still_queued_names_only_the_queued_one_as_not_done`, `a_running_rebuild_whose_records_queue_behind_its_batches_shows_its_running_time_and_how_to_see_progress`, `a_running_rebuild_with_a_separate_batch_completion_channel_shows_its_batches_from_the_first_one`, `a_discarded_rebuild_is_shown_as_discarded_with_the_batches_not_done`, `a_rebuild_released_by_hand_is_shown_as_released_with_the_batches_not_done`, `a_batch_whose_record_waits_in_the_dead_letter_is_shown_done_and_links_the_record`, `a_failed_console_rebuild_of_one_batch_links_its_failed_batch`, `recent_operations_show_the_state_as_a_pill_and_a_short_start_time_with_the_full_one_in_its_datetime`, `a_running_rebuild_whose_records_queue_behind_its_batches_counts_its_recorded_batches_after_the_first_one`, `a_running_rebuild_shows_a_running_time_under_a_second_in_seconds` |

The real-database race runs on PostgreSQL, MySQL and MariaDB and is skipped on SQLite, like gathering's races.
`apps/Pulse/tests/Accessibility/projection-runs.mjs` drives the same run through Pulse in a real browser against
the demo application.
