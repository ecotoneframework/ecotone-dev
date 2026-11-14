# Collect ordinary messages into a batch

`#[Collected]` is an Enterprise marker for an asynchronous service handler. Send commands, events or a previous
handler's output as usual. Ecotone records each message and invokes the handler once with `CollectedMessages`
when the batch reaches its size limit or its first message reaches the waiting limit.

```php
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\Collected;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Messaging\CollectedMessages;
use Ecotone\Api\Scheduling\TimeSpan;

#[Collected(maxSize: 500, maxWait: new TimeSpan(seconds: 10))]
#[Asynchronous('search')]
#[EventHandler(listenTo: ProductChanged::class, endpointId: 'search.index')]
public function index(CollectedMessages $changes): void
{
    $this->search->bulkIndex(array_column($changes->messages, 'payload'));
}
```

Set an explicit `endpointId` and subscription: `listenTo` for `#[EventHandler]`, `routingKey` for
`#[CommandHandler]`, or `inputChannelName` for `#[InternalHandler]`. The batch parameter cannot identify the
original message subscription. Aggregates, sagas, queries and handlers also marked `#[Gathered]` are refused
at bootstrap. A handler without `#[Collected]` keeps its ordinary behavior.

Keep endpoint ids stable. Renaming an endpoint leaves its open batches under the old identity: list them and
release or discard them before deploying the rename.

At least one limit is required. `maxSize` must be at least one and `maxWait` must be greater than zero.
With only `maxSize`, an incomplete batch waits indefinitely until it fills or an operator releases it.
With only `maxWait`, the batch has no size limit. The waiting period starts with the first recorded message;
later messages do not restart it. A released or discarded batch is closed, and the next message opens a new batch.

## Group messages by a key

Without `groupBy`, a handler collects all its messages into one open batch. With it, each key has its own batch:

```php
use Ecotone\Api\Attribute\InternalHandler;

#[Collected(maxSize: 100, maxWait: new TimeSpan(minutes: 5), groupBy: 'payload.orderId')]
#[Asynchronous('shipping')]
#[InternalHandler(inputChannelName: 'shipping.pack', endpointId: 'shipping.pack.handler')]
public function pack(CollectedMessages $items): void
{
    $this->shipping->pack($items->groupKey, array_column($items->messages, 'payload'));
}
```

String expressions can use `payload` and `headers`. PHP 8.5 also supports a static closure inside the attribute:

```php
use Ecotone\Api\Attribute\Header;

#[Collected(maxWait: new TimeSpan(hours: 1), groupBy: static function (CommentAdded $event, #[Header('tenantId')] string $tenant): string {
    return $tenant . ':' . $event->userId;
})]
#[Asynchronous('notifications')]
#[EventHandler(listenTo: CommentAdded::class, endpointId: 'notifications.digest')]
public function digest(CollectedMessages $events): void
{
    $this->notifications->digest($events->groupKey, array_column($events->messages, 'payload'));
}
```

Closures in attributes require PHP 8.5. On PHP 8.2 to 8.4, PHP reports "Constant expression contains invalid
operations"; use a string expression such as `groupBy: 'payload.userId'` on those versions.

Put the message payload first in the closure's parameter list. If a header comes first, mark the later payload
parameter with `#[Payload]`. Header parameters use `#[Header]`; an unannotated object after the first parameter is resolved
as a service. A typed payload parameter specifies the conversion type for each message. With a string expression,
the message's type header specifies that type; without a type header the payload is exposed as it arrived.

A key must be a non-empty string, an integer or a stringable object and is normalized to a string.
The integer `1` and string `'1'` share a batch. Booleans, floats and empty strings are refused; letter case is preserved. A missing or invalid key,
or a failed expression, refuses the message through ordinary retry and dead-letter handling. It never enters a
fallback batch. Two collected handlers subscribing to the same event keep independent batches by endpoint id.

## Read the batch and return a result

`Ecotone\Api\Messaging\CollectedMessages` exposes public readonly properties:

| Property | Value |
|---|---|
| `messages` | `CollectedMessage` objects in recording order; each has readonly `payload` and `headers` |
| `groupKey` | Normalized key, or null when `groupBy` is omitted |
| `releaseReason` | `CollectedReleaseReason::MAX_SIZE_REACHED`, `MAX_WAIT_REACHED` or `MANUAL_RELEASE` |

`count($batch->messages)` gives the number of recorded messages. For example, `$batch->messages[0]->payload`
and `$batch->messages[0]->headers` describe the first recorded message.

The batch message inherits user, tenant and tracing context from the first recorded message. `#[Header]`
parameters can read that context. A header that differs between messages shows the first message's value;
read each value from `$batch->messages`. Delivery and flow headers (including routing slip, reply channel,
priority, delay and time to live) do not cross into the batch or its output. The batch has a new message id
and timestamp. The timeout uses the same context exclusions.

The handler's return value flows once per batch; an `outputChannelName`
receives one result for the whole batch.

Recording order equals queue order with one consumer. With several consumers it follows successful recording,
which can differ from send order. A batch never exceeds `maxSize`. Redelivery of the same message id is ignored
for that endpoint while its recorded batch remains retained, including after release.

The message that fills the batch or its timeout trigger runs the handler. With DBAL, recording, release, handler
work and sending its timeout participate in the message transaction. A failure rolls back and retries
the releasing message. Keep transactional application work and gathering storage on the same connection, and
use an outbox / combined channel for external publication that must follow the commit.

If a size-filling message reaches the dead letter, its recording was rolled back. The next message can fill that
slot and release the batch; replay of the failed message then starts or joins a later batch. With only maxWait,
a failed timeout leaves its batch open past the deadline, collecting more messages until a release succeeds.
Manual release behaves the same way. A delayed retry can therefore see more messages than the first attempt.

## Storage and channel setup

Install `ecotone/dbal`, configure its connection and enable `ModulePackageList::DBAL_PACKAGE` (or `dbal` in your
integration's `enabledModulePackageNames`). Pass the licence as `licenceKey` to `EcotoneLite::bootstrap()`.
In Symfony, set the bundle's `licenceKey`; in Laravel set `licenceKey` in `config/ecotone.php`; in Tempest pass
`licenceKey` to `EcotoneConfig`. Collecting reuses gathering storage:

```text
ecotone:migration:database:setup --initialize --feature=gathering
```

For application migrations, generate SQL with `--sql --feature=gathering`. Table setup runs before processing;
no tables are created on the message path. Connection selection and transactional channel guidance are the same
as [message gathering](message-gathering.md#deployment-and-connection-selection).

`maxWait` uses a delayed message on the handler's asynchronous channel, counted from the first message.
Bootstrap checks declared delay support and the channel's maximum delay using the gathering validation.
For a channel chain or combined channel, its first physical channel must support that delay. See
[gathering channel limits](message-gathering.md#operations) for transport-specific limits and unknown maxima.
A stale timeout for an already closed batch has no effect on a later batch of the same key.

Several consumers adding to one key can conflict. A lost optimistic write throws `ConcurrencyException`.
Asynchronous endpoints running in a DBAL transaction retry instantly by default, each retry in a new transaction.
The message keeps its own id, so redelivery cannot add it twice while its batch is retained.

With several consumers on one hot key, the default three retries can run out and messages then wait in the dead
letter until replayed, after the configured error channel and delayed retries. Raise the attempts with
`InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(true, 5)`, or run one consumer
on the collected handler's channel. Give that handler its own channel when other handlers need more consumers.
Measure your workload before increasing consumer count.

Flow tests without DBAL keep batches in memory. If sending a timeout fails after a message has been recorded,
the test store keeps that message. A DBAL-backed application rolls it back with the transaction. Gathering has
the same test-store limitation.

## Test it

Supply your Enterprise `$licenceKey`. Flow tests provide an in-memory queue and a clock you control. Sending
messages does not invoke an asynchronous handler; consuming its channel does.

```php
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\Collected;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Messaging\CollectedMessages;
use Ecotone\Api\Scheduling\TimeSpan;

$handler = new class () {
    public array $batches = [];

    #[Collected(maxSize: 2, maxWait: new TimeSpan(seconds: 10))]
    #[Asynchronous('search')]
    #[CommandHandler(routingKey: 'index', endpointId: 'index.handler')]
    public function index(CollectedMessages $batch): void
    {
        $this->batches[] = $batch;
    }
};
$ecotone = EcotoneLite::bootstrapFlowTesting(
    classesToResolve: [$handler::class],
    containerOrAvailableServices: [$handler],
    licenceKey: $licenceKey,
    enabledModules: [],
    initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
);
$ecotone->sendCommandWithRouting('index', 'first');
$ecotone->sendCommandWithRouting('index', 'second');
assert($handler->batches === []);
$ecotone->run('search');
assert(count($handler->batches) === 1);

$ecotone->sendCommandWithRouting('index', 'third');
$ecotone->run('search');
assert(count($handler->batches) === 1);
$ecotone->advanceTimeBy(new TimeSpan(seconds: 10));
$ecotone->run('search');
assert(array_column($handler->batches[1]->messages, 'payload') === ['third']);
```

## Inspect, release and discard

Obtain `Ecotone\Api\Gateway\GatheredGroups` from your application container (or the flow test):

```php
use Ecotone\Api\Gateway\GatheredGroups;

$groups = $ecotone->getGateway(GatheredGroups::class);
$open = $groups->listOpenBatches('search.index');
$closed = $groups->listClosedBatches('search.index');
$groups->requestRelease($open[0]['groupId']);
$groups->discard($anotherGroupId);
$groups->cleanupClosedGroups();
```

Listings use the collected endpoint id. Each open row contains `groupId`, `endpointId`, `groupKey`, `state`,
`maxSize` (null when omitted), `recordedMessages`, `openedAt` and `deadline` (null without maxWait). Times are Unix
milliseconds. Closed rows also contain `releaseReason`, with a release enum value or null for a discarded batch.
Ordinary gathered-group listings stay separate. `requestRelease()` queues a trigger: consume the handler's
asynchronous channel to invoke it. Discard closes a batch without running the handler. Duplicate release
triggers do not run it again.

```text
ecotone:gathering:list-batches search.index
ecotone:gathering:list-batches search.index --closed
ecotone:gathering:release <groupId>
ecotone:gathering:discard <groupId>
ecotone:gathering:cleanup
```

Pulse's Gathering tab has separate collected-batch rows with endpoint id, key, size, count, deadline and the
existing Request release / Discard actions. A batch without `maxSize` displays no maximum size.

Closed batches and their message identities follow `GatheringConfiguration` retention, seven days by default.
`GatheringConfiguration::createWithDefaults()->withRetention(new TimeSpan(days: 14))` returned from a
`#[ServiceContext]` method changes that period. Cleanup removes expired closed batches and preserves open ones.
After cleanup, replayed message ids can be collected again.

Upgrade every consumer before adding the marker. An older consumer invokes the method once per ordinary
message and cannot supply the collected batch object.
