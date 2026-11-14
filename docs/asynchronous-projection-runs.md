# Asynchronous rebuild and backfill as one run

An asynchronous rebuild or backfill is an Ecotone Enterprise feature. When `#[ProjectionRebuild]` or
`#[ProjectionBackfill]` names an `asyncChannelName`, Ecotone splits the projection's partitions into batches, sends
one message per batch to that channel, and tracks the whole operation as one run: a
[gathering](message-gathering.md) group whose size is the number of batches. Each batch is recorded when it has
projected all its partitions, and the run completes exactly once, when the last batch is recorded. You write
nothing new: the attributes you already use switch it on.

```php
use Ecotone\Api\Projecting\FromAggregateStream;
use Ecotone\Api\Projecting\Partitioned;
use Ecotone\Api\Projecting\Projection;
use Ecotone\Api\Projecting\ProjectionBackfill;
use Ecotone\Api\Projecting\ProjectionRebuild;

#[Projection('ticket_sales')]
#[Partitioned]
#[FromAggregateStream(Ticket::class)]
#[ProjectionRebuild(partitionBatchSize: 100, asyncChannelName: 'projection_runs')]
#[ProjectionBackfill(backfillPartitionBatchSize: 100, asyncChannelName: 'projection_runs')]
final class TicketSales
{
}
```

Run `ecotone:projection:rebuild ticket_sales` (or start it from Ecotone Pulse) and keep a consumer running on
`projection_runs`. A rebuild or backfill without `asyncChannelName` runs in the calling process as before and is
not tracked as a run.

## Where a run is visible

A run is listed under the endpoint id `<projection>.rebuild` or `<projection>.backfill`:

- `GatheredGroups::listOpenGroups('ticket_sales.rebuild')` and `listClosedGroups(...)`, and the console command
  `ecotone:gathering:list ticket_sales.rebuild`, show the batch count, the batches recorded, the batch numbers not
  done yet, and when the run opened and closed.
- Ecotone Pulse shows the run on the projection page: batches done out of the batch count, a failed batch with a
  link to its dead letter (the run waits for its replay), the batches not done yet, and done with its duration. A
  run whose group was discarded or released by hand is shown as discarded or released, with the batches not done.
  Pulse reads this from the run, so it is right also when no traces are stored. Runs started from the console are
  listed too. The run is also listed on the service's Gathering tab.

An application handler may not use `<projection>.rebuild` or `<projection>.backfill` as its `endpointId`;
the application is refused at bootstrap with the names of both.

## Storage

A run must survive restarts and be shared between consumers, so it is stored by the DBAL module in the gathering
tables. Create them before the first run with `ecotone:migration:database:setup --initialize --feature=gathering`,
or generate the SQL for your migrations with `--sql --feature=gathering`. Outside `EcotoneLite::bootstrapFlowTesting()`,
an application with an asynchronous rebuild or backfill and no DBAL module is refused at bootstrap. The channels
named in `asyncChannelName` and `batchCompletionChannelName` must be registered, or the application is refused at
bootstrap with the attribute and the parameter named. Batches carry the request's metadata, so a rebuild started
for a tenant of a multi-tenant application runs in that tenant.

## Batch completion channel

When you watch a run's progress in Ecotone Pulse, or run several consumers on the batch channel, name a completion
channel and run one consumer on it:

```php
#[ProjectionRebuild(partitionBatchSize: 100, asyncChannelName: 'projection_runs', batchCompletionChannelName: 'projection_run_completions')]
```

Each batch reports itself with a small message when it has projected its partitions. Without
`batchCompletionChannelName`, that message goes to the same `asyncChannelName` as the batches, so the consumers you
already run complete the run. The reports then queue behind the batches that are not executed yet: the run shows
no batch done until every batch has run, and then advances as the reports are consumed. Pulse shows such a run
with its running time and says so. With a separate batch completion channel and its consumer running, the run advances
as each batch finishes. `batchCompletionChannelName` needs `asyncChannelName`; naming it alone is refused.

Two reports of the same run recorded at the same moment conflict. The conflict is retried by the framework's
instant retries, and a report whose retries run out waits in the dead letter; replaying it records the batch, and
Pulse shows that batch as done with its record waiting. Measured on PostgreSQL with 40 batches and the default
retry configuration, the reports that reached the dead letter were: 0 with 1 consumer on the shared channel, 1
with 4 consumers, 6 with 8 consumers, and 0 with a separate batch completion channel consumed by one consumer (with 1,
4 or 8 consumers on the batches). One consumer on the batch completion channel records the reports one after another.
The attempts can be raised with
`InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(true, 5)`; that setting was not
part of the measurement.

## Failure and replay

A batch that fails is not recorded. It goes through your retries and error channel to the dead letter, and the
run stays open with that batch not done yet; there is no timeout. Fix the cause and replay the batch from the
dead letter (`ecotone:deadletter:replay` or Ecotone Pulse): when it succeeds, the run completes. To give up on a
run, discard its group with `ecotone:gathering:discard <groupId>`.

A consumer that is asked to stop while it handles a batch finishes that batch first and then stops; the next
batch waits on the channel for the next consumer.

## Starting a second run

Starting a rebuild while another run of the same projection is open starts a second run with its own group; both
complete independently.

## An empty projection

A projection without partitions opens a run of zero batches. It completes when the consumer of its completion
channel next runs; until then Pulse shows it as running with 0 of 0 batches.
