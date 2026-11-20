---
name: ecotone-open-swoole
description: >-
  Runs Ecotone on OpenSwoole or Swoole: an HTTP server that keeps the
  application booted, message consumers on coroutines
  (ecotone:run --coroutines), a database connection per coroutine from a
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
while it waits on something the runtime can wait on: MySQL, MariaDB and `php-amqplib` are; the `amqp` extension is
not, and PostgreSQL only on Swoole built with `--enable-swoole-pgsql` (never on OpenSwoole). A Kafka topic or a
RabbitMQ stream is consumed one message at a time: Ecotone refuses its consumer on coroutines.

## Applies when

- Running message consumers on coroutines instead of one process per message at a time
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
| `SymfonyConnectionReference::defaultManagerRegistry('default')`, Laravel, Tempest | One connection for the process: handle one message at a time |

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

## Rules

### 1. Several messages at once in one consumer is `--coroutines`, not a handwritten coroutine loop
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
