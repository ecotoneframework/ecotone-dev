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
  it, so a redelivered prepare message finds its group through the store's existence check.
- The group key is the prepare message's tracing id. Pulse matches a run it started by that key. The group key
  hash stays empty, so collecting's open-slot uniqueness never applies to a run and two runs never merge.
- The size is the number of batches. An empty projection opens a zero-size group; it closes when its completion
  is consumed on the completion channel, like gathering's empty split (not at prepare time).
- A second rebuild while a run is open opens its own group; both complete independently, as two runs did before.

Each batch message is consumed by the existing `partition_batch_executor_handler`, which reports its part through
the existing `PartCompletion` when `executeBatch` returns: the report is a message on the run's gather channel
(`ecotone.projection.<operation>.completion:<projection>`), recorded by an internal gathered handler
(`ProjectionRunCompletion`, no user hook: the closed group is the record). The per-partition messages a batch
sends carry no gathering headers, so a batch is counted only when all its partitions are projected
(`batch_failing_after_its_first_partition_is_not_counted_as_done`).

## Completion channel

The internal gathered handler is asynchronous on the run's `asyncChannelName` by default (maintainer decision
M3). `completionChannelName` on `#[ProjectionRebuild]` and `#[ProjectionBackfill]` names a separate channel for
it. Naming `completionChannelName` without `asyncChannelName` is refused when the attribute is read, naming both
ways out. Because the completion endpoint is attached to the asynchronous channel, a rebuild or backfill channel
that is declared but not registered is now refused at bootstrap by the existing missing-channel error.

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
the dead letter, found by its gathering group header), `groupId`, the start and, when closed, the duration. The
state is `done` when the group is closed, `failed` when a batch is in the dead letter, otherwise `running`. A run
whose group was removed by retention is `done`. A synchronous run is `done` once its tracking exists: the
tracking is written in the run's transaction, so a failed synchronous run leaves none
(`test_a_failed_synchronous_rebuild_leaves_no_tracking`). The span-derived progress and the
`ecotone_projecting_rebuild_batch_number|batch_count|partition_count` headers are removed. The run's group is
also listed on the Gathering tab under its `endpointId`.

Progress counts part reports. On the shared channel the reports queue behind the batches not yet executed, so the
page stays at `0 of N` until every batch has been executed, then advances as the reports are consumed. A separate
`completionChannelName` with its own consumer moves the page as batches finish.

## Named acceptance coverage

| Guarantee | Named test (without the `test_` prefix) |
|---|---|
| A run completes once | `asynchronous_rebuild_completes_once_when_every_batch_was_executed`, `asynchronous_backfill_completes_once_when_every_batch_was_executed` |
| Concurrent completion on real databases | `batches_executed_concurrently_complete_the_rebuild_once_in_a_real_database_race` |
| Empty projection | `empty_projection_rebuild_completes_once_its_completion_is_consumed` |
| Second run | `rebuild_of_a_projection_with_an_open_run_opens_its_own_group` |
| Synchronous path | `synchronous_rebuild_is_unchanged`, `a_failed_synchronous_rebuild_leaves_no_tracking` |
| Completion channel | `completion_can_be_consumed_from_a_separate_channel`, `backfill_completion_can_be_consumed_from_a_separate_channel`, `rebuild_completion_channel_without_asynchronous_rebuild_is_refused_at_bootstrap`, `backfill_completion_channel_without_asynchronous_backfill_is_refused_at_bootstrap` |
| Endpoint id clash | `application_endpoint_id_equal_to_a_run_endpoint_id_is_refused_at_bootstrap` |
| Failure and replay | `failed_batch_stays_missing_until_it_is_replayed_from_the_dead_letter`, `replayed_batch_completes_the_rebuild` |
| No header leak into partitions | `batch_failing_after_its_first_partition_is_not_counted_as_done` |
| Graceful shutdown | `stop_signal_during_a_rebuild_batch_lets_the_batch_finish_and_leaves_the_next_batch_on_the_channel`, `stop_signal_during_a_backfill_batch_lets_each_partition_of_the_batch_finish` |
| Durable storage | `run_group_survives_application_restart`, `asynchronous_rebuild_with_dbal_is_accepted_without_test_configuration` |
| Pulse progress without traces | `rebuild_progress_counts_executed_batches_without_stored_traces` |
| Pulse states | `a_rebuild_through_the_projection_rebuild_channel_is_running_until_its_batch_is_handled`, `a_rebuild_batch_in_the_dead_letter_is_failed_and_links_its_dead_letter`, `a_replayed_rebuild_batch_completes_the_tracked_rebuild`, `a_rebuild_started_from_the_console_is_listed_from_its_group`, `a_synchronous_rebuild_started_from_the_console_is_not_listed`, `the_group_of_a_rebuild_is_listed_in_the_gathering_snapshot_under_its_endpoint_id` |
| Pulse pages | `a_rebuild_with_a_failed_batch_shows_the_batch_with_its_dead_letter_and_the_batches_not_done_yet`, `a_running_rebuild_of_a_projection_without_partitions_shows_zero_batches_and_is_not_failed`, `a_rebuild_whose_failed_batch_was_replayed_shows_done_with_every_batch`, `a_rebuild_started_from_the_console_is_shown_without_a_trace`, `group_opened_between_two_seconds_shows_the_second_it_opened_in` |

The real-database race runs on PostgreSQL, MySQL and MariaDB and is skipped on SQLite, like gathering's races.
`apps/Pulse/tests/Accessibility/projection-runs.mjs` drives the same run through Pulse in a real browser against
the demo application.
