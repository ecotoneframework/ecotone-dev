---
name: ecotone-laravel-setup
description: >-
  Sets up Ecotone in Laravel: composer installation, auto-discovery,
  config/ecotone.php, Eloquent ORM integration, LaravelConnectionReference
  for DBAL, Laravel Queue channels, artisan consumer commands, and
  ServiceContext configuration. Use when installing, configuring, or
  integrating Ecotone with Laravel.
---

# Ecotone Laravel Setup

## 1. Installation

```bash
composer require ecotone/laravel
```

Optional packages:

```bash
# Database support (DBAL, outbox, dead letter, event sourcing)
composer require ecotone/dbal

# RabbitMQ support
composer require ecotone/amqp

# Redis support
composer require ecotone/redis

# SQS support
composer require ecotone/sqs

# Kafka support
composer require ecotone/kafka
```

The service provider `Ecotone\Laravel\EcotoneProvider` is auto-discovered by Laravel.

## 2. Publishing Configuration

```bash
php artisan vendor:publish --tag=ecotone-config
```

This creates `config/ecotone.php`.

## 3. Configuration

### All Configuration Options

| Option | Default | Description |
|--------|---------|-------------|
| `serviceName` | `null` | Service identifier for distributed messaging |
| `loadAppNamespaces` | `true` | Auto-scan `app/` for handlers |
| `namespaces` | `[]` | Additional namespaces to scan |
| `cacheConfiguration` | `false` | Cache messaging config (auto in prod) |
| `defaultSerializationMediaType` | `null` | Media type for async serialization |
| `defaultErrorChannel` | `null` | Error channel name |
| `defaultConnectionExceptionRetry` | `null` | Retry config for connection failures |
| `skippedModulePackageNames` | `[]` | Module packages to skip |
| `test` | `false` | Enable test mode |
| `licenceKey` | `null` | Enterprise licence key |

## 4. Eloquent ORM Integration

Ecotone automatically registers `EloquentRepository` — Eloquent models that extend `Model` are auto-detected as aggregates. No additional configuration is needed.

### Eloquent Aggregate

```php
use Ecotone\Modelling\Attribute\Aggregate;
use Ecotone\Modelling\Attribute\Identifier;
use Ecotone\Modelling\Attribute\AggregateIdentifierMethod;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Ecotone\Modelling\WithEvents;
use Illuminate\Database\Eloquent\Model;

#[Aggregate]
class Order extends Model
{
    use WithEvents;

    public $fillable = ['id', 'user_id', 'product_ids', 'total_price', 'is_cancelled'];

    #[CommandHandler]
    public static function place(PlaceOrder $command): self
    {
        $order = self::create([
            'user_id' => $command->userId,
            'product_ids' => $command->productIds,
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

    #[QueryHandler('order.isCancelled')]
    public function isCancelled(): bool
    {
        return $this->is_cancelled;
    }
}
```

Key differences from regular aggregates:
- Extends `Illuminate\Database\Eloquent\Model`
- Use `#[AggregateIdentifierMethod('id')]` instead of `#[Identifier]` on properties (Eloquent manages properties dynamically)
- Call `$this->save()` in action handlers (Eloquent persistence)
- Factory methods use `self::create([...])` (Eloquent pattern)
- Use `WithEvents` trait for recording domain events

## 5. Database Connection (DBAL)

### Using Laravel Database Connection

```php
#[ServiceContext]
public function databaseConnection(): LaravelConnectionReference
{
    return LaravelConnectionReference::defaultConnection('mysql');
}
```

The connection name matches the key in `config/database.php` `connections` array.

### LaravelConnectionReference API

| Method | Description |
|--------|-------------|
| `defaultConnection(connectionName)` | Default connection using Laravel DB config |
| `create(connectionName, referenceName)` | Named connection with custom reference |

### Multiple Connections

```php
#[ServiceContext]
public function connections(): array
{
    return [
        LaravelConnectionReference::defaultConnection('mysql'),
        LaravelConnectionReference::create('reporting', 'reporting_connection'),
    ];
}
```

## 7. Async Messaging with Laravel Queue

Use Laravel Queue drivers as Ecotone message channels:

```php
#[ServiceContext]
public function asyncChannel(): LaravelQueueMessageChannelBuilder
{
    return LaravelQueueMessageChannelBuilder::create('notifications');
}

// With a specific queue connection
#[ServiceContext]
public function redisChannel(): LaravelQueueMessageChannelBuilder
{
    return LaravelQueueMessageChannelBuilder::create('orders', 'redis');
}
```

### Using DBAL Channels Directly

```php
#[ServiceContext]
public function ordersChannel(): DbalBackedMessageChannelBuilder
{
    return DbalBackedMessageChannelBuilder::create('orders');
}
```

## 8. Running Async Consumers

Ecotone auto-registers Artisan commands:

```bash
# Run a consumer
php artisan ecotone:run <channel_name>

# With message limit
php artisan ecotone:run orders --handledMessageLimit=100

# With memory limit
php artisan ecotone:run orders --memoryLimit=256

# With time limit (milliseconds)
php artisan ecotone:run orders --executionTimeLimit=60000

# List available consumers
php artisan ecotone:list
```

## 9. Multi-Tenant Configuration

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

## Additional resources

- [Laravel integration patterns](references/laravel-patterns.md) — Complete code examples for Laravel integration. Load when you need: full `config/ecotone.php` file with all options, full Eloquent aggregate class with imports, full DBAL connection class, full Laravel Queue channel class, `config/queue.php` example, DBAL channel class, multi-tenant config class, or multi-tenant `config/database.php`.