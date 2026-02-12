# Laravel Integration Patterns

## Eloquent Aggregate (Full Example)

Ecotone automatically registers `EloquentRepository` -- Eloquent models that extend `Model` are auto-detected as aggregates. No additional configuration is needed.

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

## Database Connection (DBAL) -- Full Examples

### Default Connection

```php
use Ecotone\Messaging\Attribute\ServiceContext;
use Ecotone\Laravel\Config\LaravelConnectionReference;

class EcotoneConfiguration
{
    #[ServiceContext]
    public function databaseConnection(): LaravelConnectionReference
    {
        return LaravelConnectionReference::defaultConnection('mysql');
    }
}
```

The connection name matches the key in `config/database.php` `connections` array.

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

## Laravel Queue Channel -- Full Examples

```php
use Ecotone\Laravel\Queue\LaravelQueueMessageChannelBuilder;

class ChannelConfiguration
{
    #[ServiceContext]
    public function asyncChannel(): LaravelQueueMessageChannelBuilder
    {
        return LaravelQueueMessageChannelBuilder::create('notifications');
    }

    // Use a specific queue connection
    #[ServiceContext]
    public function redisChannel(): LaravelQueueMessageChannelBuilder
    {
        return LaravelQueueMessageChannelBuilder::create('orders', 'redis');
    }
}
```

Configure queue connections in `config/queue.php`:

```php
return [
    'default' => env('QUEUE_CONNECTION', 'database'),
    'connections' => [
        'database' => [
            'driver' => 'database',
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
        ],
        'redis' => [
            'driver' => 'redis',
            'connection' => 'default',
            'queue' => env('REDIS_QUEUE', 'default'),
        ],
    ],
];
```

### Using DBAL Channels Directly

```php
use Ecotone\Dbal\DbalBackedMessageChannelBuilder;

class ChannelConfiguration
{
    #[ServiceContext]
    public function ordersChannel(): DbalBackedMessageChannelBuilder
    {
        return DbalBackedMessageChannelBuilder::create('orders');
    }
}
```

## Multi-Tenant Configuration -- Full Example

```php
use Ecotone\Dbal\MultiTenant\MultiTenantConfiguration;

class EcotoneConfiguration
{
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
}
```

Configure connections in `config/database.php`:

```php
'connections' => [
    'tenant_a_connection' => [
        'driver' => 'pgsql',
        'url' => env('TENANT_A_DATABASE_URL'),
    ],
    'tenant_b_connection' => [
        'driver' => 'pgsql',
        'url' => env('TENANT_B_DATABASE_URL'),
    ],
],
```
