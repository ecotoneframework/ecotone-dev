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

Closure parameters follow the existing expression rules: put the payload first, or annotate a later parameter
with `#[Payload]`. Header parameters use `#[Header]`; an unannotated object after the first parameter is resolved
as a service. A typed payload parameter specifies the conversion type for each message. With a string expression,
the message's type header specifies that type; without a type header the payload is exposed as it arrived.

A key must be a non-null scalar or stringable value and is normalized to a string. A missing or invalid key,
or a failed expression, refuses the message through ordinary retry and dead-letter handling. It never enters a
fallback batch. Two collected handlers subscribing to the same event keep independent batches by endpoint id.

## Read the batch and return a result

`Ecotone\Api\Messaging\CollectedMessages` exposes public readonly properties:

| Property | Value |
|---|---|
| `messages` | `CollectedMessage` objects in recording order; each has readonly `payload` and `headers` |
| `groupKey` | Normalized key, or null when `groupBy` is omitted |
| `releaseReason` | `CollectedReleaseReason::SIZE_REACHED`, `MAX_WAIT` or `MANUAL_RELEASE` |

`count($batch->messages)` gives the number of recorded messages. For example, `$batch->messages[0]->payload`
and `$batch->messages[0]->headers` describe the first recorded message.

The outer batch message has framework headers; user headers remain inside `$batch->messages[0]->headers`. Use those per-message
headers for tenant or business metadata. The handler's return value flows once per batch; an `outputChannelName`
receives one result for the whole batch.

Recording order equals queue order with one consumer. With several consumers it follows successful recording,
which can differ from send order. A batch never exceeds `maxSize`. Redelivery of the same message id is ignored
for that endpoint while its recorded batch remains retained, including after release.

The message that fills the batch or its timeout trigger runs the handler. With DBAL, recording, release, handler
work and deferred timeout publication participate in the message transaction. A failure rolls back and retries
the releasing message with the same batch. Keep transactional application work and gathering storage on the same
connection, and use an outbox / combined channel for external publication that must follow the commit.

## Storage and channel setup

Install `ecotone/dbal`, configure its connection and enable `ModulePackageList::DBAL_PACKAGE` (or `dbal` in your
integration's `enabledModulePackageNames`). Provide an Enterprise licence. Collecting reuses gathering storage:

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

Concurrent writes use gathering's versioned group row and conflict re-send. A lost race writes nothing and queues
a replacement message for a fresh transaction. Hot keys can cause frequent conflicts; one consumer avoids those
conflicts. Measure your workload before increasing consumer count.

Flow tests without DBAL use the test-only in-memory store. It has no transaction: failures raised after a store
call returns, such as a deferred channel send, do not roll back its recorded state. DBAL does roll back those
failures through the message transaction. Gathering timeout sends have the same in-memory limitation.

## Inspect, release and discard

Use the existing `Ecotone\Api\Gateway\GatheredGroups` gateway:

```php
$open = $groups->listOpenBatches('search.index');
$closed = $groups->listClosedBatches('search.index');
$groups->requestRelease($open[0]['groupId']);
$groups->discard($anotherGroupId);
$groups->cleanupClosedGroups();
```

Listings use the collected endpoint id and include the group id, readable key, generation, state, configured
maximum size (null if omitted), recorded message numbers and deadline. Ordinary gathered-group listings stay
separate. `requestRelease()` queues a trigger: consume the handler's asynchronous channel to invoke it.
Discard closes a batch without running the handler. Duplicate release triggers do not run it again.

```text
ecotone:collecting:list search.index
ecotone:collecting:list search.index --closed
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

Keep endpoint ids stable. Renaming an endpoint leaves its open batches under the old identity: list them and
release or discard them before deploying the rename. Upgrade every consumer before adding the marker to a flow.
