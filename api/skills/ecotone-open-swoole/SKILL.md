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
while it waits on something the runtime can wait on: MySQL, MariaDB and `php-amqplib` are; PostgreSQL on OpenSwoole
and the `amqp` extension are not.

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
- `--handledMessageLimit`, `--executionTimeLimit` and `--memoryLimit` apply to every coroutine
- When one coroutine fails, the others stop and the consumer exits with that failure, so a supervisor restarts it
- A `#[Scheduled]` endpoint is refused on coroutines: each coroutine would run every scheduled execution again

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
- A coroutine waits five seconds for a free connection, then fails with `ConnectionPoolExhausted`

## 3. What a coroutine can wait on

| | OpenSwoole | Swoole 5.1+ |
|---|---|---|
| MySQL, MariaDB (`pdo_mysql`) | waits | waits |
| PostgreSQL (`pdo_pgsql`) | refused by Ecotone inside a coroutine | waits with `SWOOLE_HOOK_PDO_PGSQL` |
| RabbitMQ with `Enqueue\AmqpLib\AmqpConnectionFactory` | waits | waits |
| RabbitMQ with the `amqp` extension | stops every coroutine during the call | same |
| SQLite | stops every coroutine during the call | waits with `SWOOLE_HOOK_PDO_SQLITE` |

With PostgreSQL on OpenSwoole, set `enable_coroutine` to `false` on the server and run consumers without
`--coroutines`: the process stays booted, and handles one message at a time.

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

### 3. On OpenSwoole the database is MySQL or MariaDB
Ecotone refuses a PostgreSQL connection inside an OpenSwoole coroutine, because a PostgreSQL query there stops the
whole process. The ways out are MySQL or MariaDB, Swoole 5.1+ with `SWOOLE_HOOK_PDO_PGSQL`, or one message at a time.
- **Expected:** for a PostgreSQL application on OpenSwoole the answer says coroutines cannot be used with it as is and names at least one of the ways out; it does not present PostgreSQL with `--coroutines` on OpenSwoole as working.
- **Verified by:** `eval:consume-on-coroutines`

### 4. A handler takes the connection with `#[CurrentConnection]`
Bootstrap refuses a `Doctrine\DBAL\Connection` handler parameter without it, and a connection held in a property
is not the one the message's transaction runs on.
- **Expected:** handler code that queries the database takes `#[CurrentConnection] Connection $connection` as a handler parameter; no `Connection` is injected through the constructor for the message's own writes.
- **Verified by:** `eval:consume-on-coroutines`

### 5. RabbitMQ under coroutines is read with `php-amqplib`
The `amqp` extension stops every coroutine while it waits for the broker.
- **Expected:** a RabbitMQ channel consumed on coroutines uses `Enqueue\AmqpLib\AmqpConnectionFactory` (`enqueue/amqp-lib`), not `Enqueue\AmqpExt\AmqpConnectionFactory`.
- **Verified by:** `eval:consume-on-coroutines`
