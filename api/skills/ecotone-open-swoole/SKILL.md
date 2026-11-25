---
name: ecotone-open-swoole
description: >-
  Runs Ecotone on OpenSwoole or Swoole: an HTTP server that keeps the
  application booted, coroutine message channels whose handlers run in the
  sending HTTP worker, message consumers on coroutines (ecotone:run --coroutines), a database connection per coroutine from a
  connection pool, #[CurrentConnection], RabbitMQ and OpenTelemetry under
  coroutines. Use when running an Ecotone application or consumer on
  OpenSwoole, Swoole or Laravel Octane with Swoole, handling several
  messages at once in one process with coroutines, sizing the connection
  pool, or fixing ConnectionPoolExhausted or a PostgreSQL connection refused
  in a coroutine.
---

# Ecotone on OpenSwoole and Swoole

## Overview

On OpenSwoole or Swoole one PHP process stays booted and can handle several messages at once, each on its own
coroutine. Ecotone keeps the state of every message (propagated headers, the transaction, the database connection,
the active tenant) per coroutine, so messages handled at once do not see each other's state. A coroutine only helps
while it waits on something the runtime can wait on: MySQL, MariaDB, `php-amqplib` and Redis are, and SQS on Swoole;
the `amqp` extension is not, and PostgreSQL only on Swoole built with `--enable-swoole-pgsql` (never on OpenSwoole). A Kafka topic or a
RabbitMQ stream is consumed one message at a time: Ecotone refuses its consumer on coroutines.

## Applies when

- Running message consumers on coroutines instead of one process per message at a time
- Running asynchronous handlers in the sending HTTP worker without a broker
- Serving HTTP with an OpenSwoole or Swoole server, or Laravel Octane on Swoole
- Choosing the database, the RabbitMQ client and the pool size for a coroutine runtime
- A handler needs the database connection of the message it handles
- Tracing with OpenTelemetry inside coroutines

## 1. Consumers on coroutines

```bash
bin/console ecotone:run notifications --coroutines=8      # Symfony
php artisan ecotone:run notifications --coroutines=8      # Laravel
```

One process then handles up to eight messages of the `notifications` channel at once. The same number can be set
for the consumer in configuration, and the command option replaces it:

```php
use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Api\ExtensionObject\PollingMetadata;

final class ConsumerConfiguration
{
    #[ServiceContext]
    public function notificationsConsumer(): PollingMetadata
    {
        return PollingMetadata::create('notifications')->withCoroutines(8);
    }
}
```

- Without the `openswoole` or `swoole` extension the consumer refuses to start with a `ConfigurationException`
- Coroutines need a connection registered from a DSN (§2). A Laravel, Tempest or Doctrine `ManagerRegistry`
  connection is one per process, so with it run the consumer without `--coroutines`
- Each coroutine handles up to `--handledMessageLimit` messages, so the process handles up to that many times the
  coroutines; `--executionTimeLimit` and `--memoryLimit` are checked by every coroutine
- A failed message goes to the error channel or dead letter as usual and the other coroutines go on. When one
  coroutine's consumer stops with an exception (`--stopOnFailure`, a lost connection), the others stop and the
  consumer exits with that failure, so a supervisor restarts it
- A `#[Scheduled]` endpoint is refused on coroutines: each coroutine would run every scheduled execution again
- A consumer of a Kafka topic or a RabbitMQ stream is refused on coroutines: a message finished out of turn would
  commit its position past one still being handled, also when it is read through a dynamic channel. For Kafka start
  more processes (with at least as many partitions); for a RabbitMQ stream, which one consumer reads in order, let
  the handler send the work to a queue channel and consume that channel on coroutines
- A consumer of a Laravel queue channel (`LaravelQueueMessageChannelBuilder`) or a Symfony Messenger channel
  (`SymfonyMessengerMessageChannelBuilder`) is refused on coroutines, also behind a dynamic channel: the framework
  keeps one connection for the process. Start more processes, or move the handlers to a channel Ecotone connects to
  itself (DBAL from a DSN, RabbitMQ, Redis, SQS)
- Outbox (`OutboxForwardingMessageChannel`): the consumer forwarding the outbox is refused on coroutines, which would
  forward batches out of order; run it without `--coroutines`, raise `withMaxForwardingBatchSize()` to move more in a
  run, and run the consumer of the target channel on coroutines
- Multi-tenancy: tenants on `DbalConnectionFactory` DSNs are handled at once, each message on its tenant's
  connection. Tenants on `LaravelConnectionReference` or `TempestConnectionReference` are refused when two messages
  are handled at once, because the framework's default connection is one for the process: run those consumers
  without `--coroutines` and set `enable_coroutine` to false on the server
- Projections: an `#[Asynchronous]` projection is consumed on coroutines like any handler. A `#[Partitioned]` one
  projects different partitions at once; a global one is projected by one coroutine at a time, the others lose the
  optimistic write, are retried and find nothing left, so coroutines add nothing to it. A `#[Polling]` and a
  `#[Streaming]` projection are refused on coroutines
- Projection rebuild and backfill: the batches of `asyncChannelName` run at once on coroutines. The batch reports
  are handled one at a time: give them `batchCompletionChannelName` (a channel of their own, consumed without
  `--coroutines`) and run `asyncChannelName` with `--coroutines`. Left on one channel, that consumer refuses the option
- Aggregates and sagas keep their optimistic locking: commands of one aggregate handled at once are retried, and
  go to the error channel when the retries run out. `#[Deduplicated]`, `#[Delayed]`, dead letter and
  `#[RabbitConsumer]` work on coroutines as they do in one process per message

## 2. A database connection per coroutine

Register the connection from a DSN and it becomes a pool: every coroutine takes its own connection.

```php
use Ecotone\Api\Dbal\DbalConnectionFactory;

new DbalConnectionFactory(['dsn' => 'mysql://user:secret@database/app', 'connection_pool_size' => 20]);
```

| Registration | Under coroutines |
|---|---|
| `new DbalConnectionFactory($dsn)` | A pool, 10 connections by default (`connection_pool_size`) |
| `SymfonyConnectionReference::defaultConnection('default')` | Copies of Symfony's connection, 10 by default (`->withConnectionPoolSize(20)`) |
| `SymfonyConnectionReference::defaultManagerRegistry('default')`, Laravel, Tempest | One connection for the process: handle one message at a time. A `ManagerRegistry` connection used by two coroutines at once is refused, with or without a transaction |

- A consumer coroutine keeps its connection while it consumes, so the pool is at least `--coroutines` large
- Pool size times processes stays below what the database accepts
- A coroutine waits five seconds for a free connection, then fails with `ConnectionPoolExhausted`. The wait is set
  beside the size: `'connection_pool_wait_seconds' => 2` (`->withConnectionPoolWaitSeconds(2)` for Symfony); `0` fails
  at once

## 3. What a coroutine can wait on

| | OpenSwoole | Swoole 5.1+ |
|---|---|---|
| MySQL, MariaDB (`pdo_mysql`) | waits | waits |
| PostgreSQL (`pdo_pgsql`) | refused by Ecotone inside a coroutine | waits when Swoole is built with `--enable-swoole-pgsql` (`SWOOLE_HOOK_PDO_PGSQL`, part of `SWOOLE_HOOK_ALL`); refused otherwise |
| RabbitMQ with `Ecotone\Api\Amqp\AmqpLibConnectionFactory` (`php-amqplib`) | waits | waits |
| RabbitMQ with `Ecotone\Api\Amqp\AmqpExtConnectionFactory` (the `amqp` extension) | stops every coroutine during the call | same |
| SQLite | stops every coroutine during the call; a second writer fails with `database is locked` | waits when Swoole is built with `--enable-swoole-sqlite` (`SWOOLE_HOOK_PDO_SQLITE`): writers take turns; like OpenSwoole otherwise |
| Kafka topic, RabbitMQ stream (consuming) | consumer refused on coroutines | same |
| Kafka (`rdkafka`), sending from a handler | stops every coroutine during the call | same |
| Redis with `new RedisConnectionFactory($dsn)` (predis, or `redis+phpredis:`) | waits, a connection per coroutine | same |
| Redis with a client the application connected (`new RedisConnectionFactory($redis)`) | refused by Ecotone inside a coroutine | same |
| SQS with `new SqsConnectionFactory($dsn)` | refused by Ecotone while the curl hook is on (it rejects options the AWS SDK sets); with the hook off each request stops every coroutine | waits when Swoole is built with `--enable-swoole-curl`, a client per coroutine |
| SQS with a client the application built (`new SqsConnectionFactory($awsClient)`) | refused by Ecotone inside a coroutine | same |
| Laravel queue channel, Symfony Messenger channel (consuming) | consumer refused on coroutines | same |

With SQS on OpenSwoole switch the curl hook off before the coroutines start,
`OpenSwoole\Runtime::enableCoroutine(OpenSwoole\Runtime::HOOK_ALL & ~(OpenSwoole\Runtime::HOOK_CURL | OpenSwoole\Runtime::HOOK_NATIVE_CURL))`
or the same value as the server's `hook_flags`: SQS then works, and each request stops the other coroutines while it
waits. Every coroutine opens its own Redis connection and builds its own SQS client, closed when the coroutine ends,
so register both from their settings and not from a client object.

With PostgreSQL on OpenSwoole, or on a Swoole built without `--enable-swoole-pgsql`, set `enable_coroutine` to
`false` on the server and run consumers without `--coroutines`: the process stays booted, and handles one message at
a time. SQLite lets one connection write at a time: on Swoole built with `--enable-swoole-sqlite` a coroutine waits for the
one writing, elsewhere let one coroutine write at a time. `sqlite:///:memory:` is a separate empty database for every
connection of the pool.

## 4. The connection of the handled message

```php
use Doctrine\DBAL\Connection;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Dbal\Attribute\CurrentConnection;

final class RegisterCustomerHandler
{
    #[CommandHandler]
    public function register(RegisterCustomer $command, #[CurrentConnection] Connection $connection): void
    {
        $connection->insert('customers', ['id' => $command->customerId, 'name' => $command->name]);
    }
}
```

`#[CurrentConnection]` injects the connection the message's transaction runs on. A `Connection` kept in a property
is one fixed connection, shared by every coroutine.

## 5. HTTP server

```php
use OpenSwoole\Http\Server;
use OpenSwoole\Runtime;

$server = new Server('0.0.0.0', 9501);
$server->set(['worker_num' => 4, 'enable_coroutine' => true, 'hook_flags' => Runtime::HOOK_ALL]);
$server->on('workerStart', static function () use (&$kernel): void {
    $kernel = new App\Kernel('prod', false);
    $kernel->boot();
});
```

On Swoole the classes are `Swoole\Http\Server` and `Swoole\Runtime`, and the flags are `SWOOLE_HOOK_ALL`; these
names work on OpenSwoole too.

Boot the application in `workerStart`, once per worker, and handle each request with it. Laravel Octane on Swoole
handles one request at a time per worker and needs no change.

## 6. OpenTelemetry

```bash
composer require open-telemetry/context-swoole
```

```php
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextStorage;
use OpenTelemetry\Contrib\Context\Swoole\SwooleContextStorage;

Context::setStorage(new SwooleContextStorage(new ContextStorage()));
```

Set it once when the worker or the consumer process starts. Without it the SDK tracks the current span per
process, and spans of messages handled at once are attached to each other's traces.

## 7. Asynchronous handlers in the sending process

For best-effort work in the sending HTTP worker, register an Enterprise coroutine message channel:

```php
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Api\ExtensionObject\CoroutineMessageChannelBuilder;

final class NotificationChannels
{
    #[ServiceContext]
    public function notificationsChannel(): CoroutineMessageChannelBuilder
    {
        return CoroutineMessageChannelBuilder::create('notifications')->withCoroutines(4);
    }
}

final class Notifications
{
    public function __construct(private NotificationProvider $provider)
    {
    }

    #[Asynchronous('notifications')]
    #[CommandHandler(endpointId: 'notifications.send')]
    public function send(SendNotification $command): void
    {
        $this->provider->send($command);
    }
}
```

The sender writes through the connection of Ecotone's transaction and sends the notification through the public bus:

```php
use Doctrine\DBAL\Connection;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Reference;
use Ecotone\Api\Dbal\Attribute\CurrentConnection;
use Ecotone\Api\Gateway\CommandBus;

final class Orders
{
    #[CommandHandler]
    public function place(PlaceOrder $command, #[CurrentConnection] Connection $connection, #[Reference] CommandBus $bus): void
    {
        $connection->insert('orders', ['id' => $command->orderId]);
        $bus->send(new SendNotification($command->orderId));
    }
}
```

Messages are held until the outermost command bus, asynchronous endpoint, gateway or console command succeeds and
Ecotone's transaction commits. A failed sender releases no work. A transaction opened manually around the bus call
is not waited for; keep the order's writes inside Ecotone's transaction. Once released, handlers start when the
sending coroutine next yields to the event loop or ends. The caller does not wait for them to finish.

A `#[ConsoleCommand]` holds messages just like a bus call: it releases them only after the console command returns
successfully and Ecotone commits. A long-running console command keeps its messages held for its whole execution.
Run a sending console command inside a Swoole or OpenSwoole coroutine; otherwise the send is refused. Ending an
inner bus call does not release the console command's held messages.

A channel runs up to 10 handlers at once by default. `withCoroutines(4)` sets its limit to four; the rest wait in
order in memory. Objects pass as they are, without serialization. Set concurrency on the channel builder. Its
handlers start in the sending process, so there is no separate `ecotone:run` consumer and `PollingMetadata` consumer
limits and schedule settings are refused; its error channel is honoured. `#[Priority]` and `#[TimeToLive]` are
refused for this channel, and so is listing it with another channel in one `#[Asynchronous]`. `#[OnConsumerStop]`
handlers are not called for it: it has no consumer process. It never handles process signals either: they stay
with the server that owns the worker.

A `#[Splitter]` stacked on a handler sends each part to handlers on the channel, which work them at once. To gather
their results, give the `#[Gathered]` handler a channel of its own, `CoroutineMessageChannelBuilder::create('summaries')`,
and leave `withCoroutines()` out. The channel of a `#[Gathered]` handler handles one message at a time, also for any
other handler on it, because parts finishing together would lose the group's optimistic write until the retries run
out; bootstrap refuses `withCoroutines()` with more than one coroutine there. A `#[Collected]` handler follows the
same rule: its own channel without `withCoroutines()`, and messages sent at once by several requests are recorded
into one batch. The channel named as `batchCompletionChannelName` of a `#[ProjectionRebuild]` or
`#[ProjectionBackfill]` follows it too: leave `withCoroutines()` out there and state it on the `asyncChannelName`
channel. On a channel consumed with `ecotone:run`, run the consumer of a `#[Gathered]` or `#[Collected]`
handler without `--coroutines`: the consumer refuses the option, and `PollingMetadata::withCoroutines()`, above one.

```php
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Gathered;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Api\Attribute\Splitter;
use Ecotone\Api\ExtensionObject\CoroutineMessageChannelBuilder;
use Ecotone\Api\Messaging\GatheredParts;

final class ImportRows
{
    #[CommandHandler('import', outputChannelName: 'import.row')]
    #[Splitter(gatherChannelName: 'import.summary')]
    public function import(array $rows): array
    {
        return $rows;
    }

    #[Asynchronous('rows')]
    #[InternalHandler('import.row', endpointId: 'import.row.handler')]
    public function row(int $row): int
    {
        return $row * 2;
    }

    #[Gathered]
    #[Asynchronous('summaries')]
    #[InternalHandler('import.summary', endpointId: 'import.summary.handler')]
    public function summarize(GatheredParts $parts): void
    {
        $results = $parts->results;
    }

    #[ServiceContext]
    public function channels(): array
    {
        return [
            CoroutineMessageChannelBuilder::create('rows'),
            CoroutineMessageChannelBuilder::create('summaries'),
        ];
    }
}
```

Each element the splitting handler returns becomes one message for `import.row`; what each row handler returns is
its result, and the gathered handler runs once with all of them in `GatheredParts::$results`.

Size each worker's connection pool for simultaneous requests plus the limit of every coroutine message channel in
that process, and any polling consumer coroutines. For 12 requests and this channel's limit of 4, use at least 16:

```php
use Ecotone\Api\Dbal\DbalConnectionFactory;

new DbalConnectionFactory([
    'dsn' => 'mysql://user:secret@database/app',
    'connection_pool_size' => 16,
]);
```

The default pool of 10 cannot cover that workload. Multiply the pool size by worker processes when checking the
database's connection budget. For an exhausted pool, lower `CoroutineMessageChannelBuilder::withCoroutines()`, raise
the pool size, or adjust `connection_pool_wait_seconds` as in §2.

This requires Ecotone Enterprise and the `swoole` or `openswoole` extension with coroutines enabled. Send from a
coroutine: enable `enable_coroutine=true` for HTTP requests (§5), start a sending polling consumer with
`ecotone:run <source-channel> --coroutines=N`, or execute a sending console command inside a coroutine. A send
outside a coroutine is refused; an uncaught failure rolls back the sender's Ecotone transaction. A dead-letter
replay does not go through the channel: the handler runs in the code that replays, inside or outside a coroutine,
before the replay call returns, with the endpoint's transaction and instant retries. A replay does not use delayed
retries: one that fails again goes straight back to the dead letter with the handler's exception, as a new entry
with no retry count, and `replayAll` goes on to the next message.

Error channels, instant retries, delayed retries and dead letter use the ordinary asynchronous consumer pipeline.
Without an error channel, the channel and endpoint are logged with the exception and message headers, then the
message is dropped. A message a handler rejects with `RejectMessageException` is such a failure too: it is not
handled again and is logged as dropped. Configure an error channel connected to durable storage to retain failures. `#[Delayed]` and
delayed retries wait on native coroutine timers, including when sleep hooks are disabled. Delays wait real time
unless the flow test clock is pinned (`bootstrapFlowTesting(initialTime: ...)`); replacing the application's clock
service alone does not shorten them.

For application tests on PHP without the extension, list every environment that runs on Swoole or OpenSwoole on
the `#[ServiceContext]` method returning the coroutine message channel and leave out only the test environment:
`#[Ecotone\Api\Attribute\Environment(['prod', 'dev'])]`, or with Laravel's names `['production', 'local']`. Limiting
it to `['prod']` would also remove the channel from an application run on Swoole locally. Bootstrap the test in
the `test` environment and register only the queue for that name:

```php
$flow = Ecotone\Api\Lite\EcotoneLite::bootstrapFlowTesting(
    [$handler::class, NotificationChannels::class],
    [$handler],
    extensionObjects: [Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder::createQueueChannel('notifications')],
    environment: 'test',
);
$flow->sendCommand($notification);
$flow->run('notifications');
```

Call `run('notifications')` on the flow test support after sending. The environment excludes the production
registration, so the channel is not registered twice. This covers application routing and handlers;
use Swoole/OpenSwoole integration tests for the in-process scheduling guarantee.

Messages and timers are lost on a crash or restart. Worker shutdown has no draining or completion guarantee.
Use a durable broker or database channel for work that must survive the worker.

## Rules

### 1. A standalone polling consumer uses `--coroutines`
`ecotone:run <channel> --coroutines=N` or `PollingMetadata::create('<channel>')->withCoroutines(N)` starts the
coroutines, enables the runtime hooks, stops every coroutine on a failure or a termination signal, and refuses to
start without the extension.
- **Expected:** the consumer is started with `ecotone:run <channel> --coroutines=<N>` or configured with `PollingMetadata::withCoroutines()`; the answer contains no own script calling `Coroutine::create()` around `run()`.
- **Verified by:** `eval:consume-on-coroutines`

### 2. Under coroutines the connection is registered from a DSN, with a pool at least as large as the coroutines
Only a connection Ecotone opens itself can be given to each coroutine. A consumer coroutine keeps its connection for
as long as it consumes.
- **Expected:** the connection is `new DbalConnectionFactory(...)` (or `SymfonyConnectionReference::defaultConnection()`), and `connection_pool_size` (or `withConnectionPoolSize()`) is stated to be at least the number of coroutines whenever that number exceeds 10.
- **Verified by:** `eval:consume-on-coroutines`

### 3. PostgreSQL waits only on Swoole built for it; on OpenSwoole the database is MySQL or MariaDB
Ecotone refuses a PostgreSQL connection inside an OpenSwoole coroutine, and inside a Swoole coroutine unless Swoole
is built with `--enable-swoole-pgsql` (a stock `pecl install swoole` is not), because a PostgreSQL query there stops
the whole process. The ways out are MySQL or MariaDB, Swoole 5.1+ built with `--enable-swoole-pgsql`
(`php --ri swoole` shows `coroutine_pgsql => enabled`), or one message at a time.
- **Expected:** for a PostgreSQL application on OpenSwoole the answer says coroutines cannot be used with it as is and names at least one of the ways out; it does not present PostgreSQL with `--coroutines` on OpenSwoole as working.
- **Verified by:** `eval:consume-on-coroutines`

### 4. A handler takes the connection with `#[CurrentConnection]`
Bootstrap refuses a `Doctrine\DBAL\Connection` handler parameter without it, and a connection held in a property
is not the one the message's transaction runs on.
- **Expected:** handler code that queries the database takes `#[CurrentConnection] Connection $connection` as a handler parameter; no `Connection` is injected through the constructor for the message's own writes.
- **Verified by:** `eval:consume-on-coroutines`

### 5. RabbitMQ under coroutines is read with `php-amqplib`
The `amqp` extension stops every coroutine while it waits for the broker.
- **Expected:** a RabbitMQ channel consumed on coroutines uses `Ecotone\Api\Amqp\AmqpLibConnectionFactory`, registered as a service and passed to the channel as `amqpConnectionReferenceName: AmqpLibConnectionFactory::class`, not `Ecotone\Api\Amqp\AmqpExtConnectionFactory`.
- **Verified by:** `eval:consume-on-coroutines`

### 6. Handlers in the sending worker use CoroutineMessageChannelBuilder
Register the channel through `#[ServiceContext]`, mark the handler `#[Asynchronous]`, and send through the public command bus.
- **Expected:** the answer registers `Ecotone\Api\ExtensionObject\CoroutineMessageChannelBuilder::create()` through `#[ServiceContext]`, uses `#[Asynchronous]` with the same channel, sets concurrency on the builder, and shows a sender using `Ecotone\Api\Gateway\CommandBus`; the coroutine message channel starts its handlers without a separate consumer or handwritten dispatcher.
- **Verified by:** `eval:send-in-process`

### 7. Release waits for Ecotone's transaction and execution boundary
Work is released after the outermost successful command bus, asynchronous endpoint, gateway or console command and Ecotone's transaction. It starts when the sending coroutine next yields or ends. A transaction opened manually around the bus is outside that guarantee.
- **Expected:** the answer names Ecotone's transaction, says a failed sender starts no handler, explains the next-yield-or-end start time, and explicitly says a transaction opened manually around the bus is not waited for.
- **Verified by:** `eval:send-in-process`

### 8. Pools cover requests plus all coroutine message channel limits
Every request and running handler can need its own connection. Sum simultaneous requests, each channel's limit and any polling consumer coroutines in the same worker.
- **Expected:** the answer sizes `connection_pool_size` (or `withConnectionPoolSize()`) to at least that sum and explains that additional channels add their limits; it then checks workers times pool size against the database budget.
- **Verified by:** `eval:send-in-process`

### 9. State the runtime and licence prerequisites
The channel needs Enterprise and a coroutine runtime in the PHP process sending the message.
- **Expected:** the answer explicitly states both Ecotone Enterprise and the `swoole` or `openswoole` extension with coroutines enabled, including when the question already provides them.
- **Verified by:** `eval:send-in-process`

### 10. The sending worker's memory is the delivery guarantee
Pending messages and timers are lost when the process stops. Worker shutdown is not coordinated with this work.
- **Expected:** the answer states in-process memory storage, loss on crash or restart, and no draining or completion guarantee at shutdown; durable work is assigned a durable channel.
- **Verified by:** `eval:send-in-process`

### 11. Extension-free tests exclude the production channel registration
List every environment that runs on Swoole or OpenSwoole on the `#[ServiceContext]` method returning the builder,
for example `#[Environment(['prod', 'dev'])]` or Laravel's `#[Environment(['production', 'local'])]`, and leave out
only the test environment. Bootstrap tests in `test` and register a queue channel under the same name.
- **Expected:** the answer lists the runtime environments on the production builder's method and leaves out only the test environment, registers `SimpleMessageChannelBuilder::createQueueChannel()` once in the test bootstrap, and calls `run('<channel>')` on the flow test support after sending.
- **Verified by:** `eval:send-in-process`

### 12. Console commands release only when they return
A console command is a release boundary and its send still needs an active coroutine.
- **Expected:** the answer says to run a sending console command inside a Swoole/OpenSwoole coroutine, explains that it releases messages only when the command returns successfully, and warns that a long-running command holds them until then.
- **Verified by:** `eval:send-in-process`

### 13. A gathered or collected handler gets a coroutine message channel of its own
Its channel handles one message at a time; the handlers doing the work stay on another channel.
- **Expected:** the answer registers one `CoroutineMessageChannelBuilder` for the handlers working the parts and a separate one for the `#[Gathered]` (or `#[Collected]`) handler, leaves `withCoroutines()` out of the latter or passes 1, and says that channel handles one message at a time while the parts still run at once.
- **Verified by:** `eval:gather-in-process`
