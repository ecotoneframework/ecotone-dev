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
use Ecotone\Modelling\Attribute\Aggregate;
use Ecotone\Modelling\Attribute\AggregateIdentifierMethod;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\WithEvents;
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
        $this->save();
    }

    #[AggregateIdentifierMethod('id')]
    public function getId(): int
    {
        return $this->id;
    }
}
```

Key differences from regular aggregates:
- Extends `Illuminate\Database\Eloquent\Model`
- Use `#[AggregateIdentifierMethod('id')]` instead of `#[Identifier]` on properties
- Call `$this->save()` in action handlers
- Factory methods use `self::create([...])`
- Use `WithEvents` trait for recording domain events

## 3. Database Connection (DBAL)

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

## Key Rules

- `LaravelConnectionReference::defaultConnection()` takes the key from `config/database.php` `connections` array
- `LaravelQueueMessageChannelBuilder::create()` channel name must match an Ecotone async routing, optionally takes a queue connection name as second parameter
- Eloquent aggregates use `#[AggregateIdentifierMethod]` instead of `#[Identifier]` on properties
- Always use `#[ServiceContext]` methods in a class registered as a service for configuration

## Additional resources

- [Configuration reference](references/configuration-reference.md) -- Full `config/ecotone.php` file with all options and comments, all configuration option descriptions with defaults, and `LaravelConnectionReference` API table. Load when you need the complete configuration file or all available config options.
- [Integration patterns](references/integration-patterns.md) -- Complete class implementations for Laravel integration: full Eloquent aggregate with all imports, DBAL connection setup with multiple connections, Laravel Queue channel configuration with `config/queue.php`, DBAL-backed channels, and multi-tenant setup with `config/database.php`. Load when you need full working class files with imports and complete configuration examples.
