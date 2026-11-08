---
name: ecotone-laravel-setup
description: >-
  Sets up Ecotone in a Laravel project: composer installation, auto-discovery,
  config/ecotone.php, Eloquent ORM integration, LaravelConnectionReference
  for DBAL, Laravel Queue channels, artisan consumer commands, and
  ServiceContext. Use when installing Ecotone in Laravel, configuring
  Laravel-specific connections, or setting up Laravel async consumers.
---

# Ecotone Laravel Setup

## Overview

This skill covers setting up and configuring Ecotone within a Laravel application. Use it when installing Ecotone, configuring database connections, setting up async messaging with Laravel Queue, integrating Eloquent aggregates, or configuring multi-tenancy.

## Applies when

- Installing Ecotone into a Laravel application or publishing `config/ecotone.php`
- Pointing Ecotone's DBAL features (outbox, dead letter, event store, DBAL channels) at a `config/database.php` connection
- Running asynchronous handlers on a Laravel Queue connection and starting the consumer with artisan
- Turning an Eloquent model into an Ecotone aggregate
- Setting the service name, error channel or licence key for a Laravel application

## 1. Installation

```bash
composer require ecotone/laravel
```

Optional packages:

```bash
composer require ecotone/dbal   # Database support (DBAL, outbox, dead letter, event sourcing)
composer require ecotone/amqp   # RabbitMQ support
composer require ecotone/redis  # Redis support
composer require ecotone/sqs    # SQS support
composer require ecotone/kafka  # Kafka support
```

The service provider `Ecotone\Laravel\EcotoneProvider` is auto-discovered by Laravel.

Publish configuration:

```bash
php artisan vendor:publish --tag=ecotone-config
```

This creates `config/ecotone.php`.

## 2. Eloquent Aggregate

Ecotone automatically registers `EloquentRepository` -- Eloquent models extending `Model` are auto-detected as aggregates.

```php
use Ecotone\Api\Attribute\Aggregate;
use Ecotone\Api\Attribute\IdentifierMethod;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Modelling\WithEvents;
use Illuminate\Database\Eloquent\Model;

#[Aggregate]
class Order extends Model
{
    use WithEvents;

    public $fillable = ['id', 'user_id', 'total_price', 'is_cancelled'];

    #[CommandHandler]
    public static function place(PlaceOrder $command): self
    {
        $order = self::create([
            'user_id' => $command->userId,
            'total_price' => $command->totalPrice,
            'is_cancelled' => false,
        ]);
        $order->recordThat(new OrderWasPlaced($order->id));
        return $order;
    }

    #[CommandHandler]
    public function cancel(CancelOrder $command): void
    {
        $this->is_cancelled = true;
    }

    #[IdentifierMethod('id')]
    public function getId(): int
    {
        return $this->id;
    }
}
```

Key differences from regular aggregates:
- Extends `Illuminate\Database\Eloquent\Model`
- Use `#[IdentifierMethod('id')]` instead of `#[Identifier]` on properties
- Do not call `$this->save()` in action handlers: Ecotone's Eloquent repository saves the model after the handler
- Factory methods use `self::create([...])`
- Use `WithEvents` trait for recording domain events

## 3. Database Connection (DBAL)

`#[ServiceContext]` methods live in any class under `app/` (or a namespace listed in `config/ecotone.php`). Ecotone
instantiates that class itself, so it takes no constructor parameters and is not bound in a service provider.

```php
#[ServiceContext]
public function databaseConnection(): LaravelConnectionReference
{
    return LaravelConnectionReference::defaultConnection('mysql');
}
```

The connection name matches the key in `config/database.php` `connections` array.

## 4. Async Messaging with Laravel Queue

```php
#[ServiceContext]
public function asyncChannel(): LaravelQueueMessageChannelBuilder
{
    return LaravelQueueMessageChannelBuilder::create('notifications');
}
```

## 5. Running Async Consumers

```bash
php artisan ecotone:run <channel_name>
php artisan ecotone:run orders --handledMessageLimit=100
php artisan ecotone:run orders --memoryLimit=256
php artisan ecotone:run orders --executionTimeLimit=60000
php artisan ecotone:list
```

## 6. Multi-Tenant Configuration

Multi-tenancy is an Ecotone Enterprise feature: without `licenceKey` the application refuses to boot with a
`LicensingException`.

```php
#[ServiceContext]
public function multiTenant(): MultiTenantConfiguration
{
    return MultiTenantConfiguration::create(
        tenantHeaderName: 'tenant',
        tenantToConnectionMapping: [
            'tenant_a' => LaravelConnectionReference::create('tenant_a_connection'),
            'tenant_b' => LaravelConnectionReference::create('tenant_b_connection'),
        ],
    );
}
```

## Rules

### 1. Install `ecotone/laravel` and let package discovery register it
`Ecotone\Laravel\EcotoneProvider` is auto-discovered; publish the configuration with
`php artisan vendor:publish --tag=ecotone-config`.
- **Expected:** setup is `composer require ecotone/laravel` (plus `ecotone/dbal` when database features are used) and
  `php artisan vendor:publish --tag=ecotone-config`, with no manual provider registration in `config/app.php` or
  `bootstrap/providers.php`.
- **Verified by:** `eval:laravel-orders-setup`

### 2. `config/ecotone.php` holds only its five keys
The file reads `loadAppNamespaces`, `namespaces`, `enabledModulePackageNames`, `test` and `licenceKey`; any other key
is refused at bootstrap. The service name, default error channel, serialization media type and similar settings are a
`ServiceConfiguration` returned from one `#[ServiceContext]` method.
- **Expected:** `config/ecotone.php` contains no key outside those five; the service name and error channel are set
  with `ServiceConfiguration::createWithDefaults()->withServiceName(...)->withDefaultErrorChannel(...)` in a
  `#[ServiceContext]` method.
- **Verified by:** `eval:laravel-orders-setup`

### 3. Point DBAL at a Laravel connection with `LaravelConnectionReference`
`LaravelConnectionReference::defaultConnection('mysql')` takes a key of the `connections` array in
`config/database.php`, returned from a `#[ServiceContext]` method in a class with no constructor parameters.
- **Expected:** the database connection is configured as
  `LaravelConnectionReference::defaultConnection('<connection name from config/database.php>')` returned from a
  `#[ServiceContext]` method, not as a DSN string or a manually built DBAL connection.
- **Verified by:** `eval:laravel-orders-setup`

### 4. A Laravel Queue channel carries the name used in `#[Asynchronous]`
`LaravelQueueMessageChannelBuilder::create('orders')` creates channel `orders` on the default queue connection (a
second argument names another one from `config/queue.php`, which must not be the `sync` driver). Ecotone consumes it
itself: `php artisan ecotone:run orders`.
- **Expected:** the channel builder's name equals the `#[Asynchronous('...')]` name, and the consumer is started with
  `php artisan ecotone:run <that name>` (not only with `php artisan queue:work`).
- **Verified by:** `eval:laravel-orders-setup`

### 5. An Eloquent aggregate is saved by Ecotone
A model extending `Illuminate\Database\Eloquent\Model` with `#[Aggregate]` is stored by Ecotone's Eloquent repository
after each command handler. Mark the identifier getter with `#[IdentifierMethod('id')]` and record events with
`WithEvents`.
- **Expected:** the model carries `#[Aggregate]`, an `#[IdentifierMethod('id')]` method and `use WithEvents`, and its
  action command handlers change attributes without calling `$this->save()`.
- **Verified by:** `eval:laravel-orders-setup`

## Additional resources

- [Configuration reference](references/configuration-reference.md) -- Full `config/ecotone.php` file with all options and comments, all configuration option descriptions with defaults, and `LaravelConnectionReference` API table. Load when you need the complete configuration file or all available config options.
- [Integration patterns](references/integration-patterns.md) -- Complete class implementations for Laravel integration: full Eloquent aggregate with all imports, DBAL connection setup with multiple connections, Laravel Queue channel configuration with `config/queue.php`, DBAL-backed channels, and multi-tenant setup with `config/database.php`. Load when you need full working class files with imports and complete configuration examples.
