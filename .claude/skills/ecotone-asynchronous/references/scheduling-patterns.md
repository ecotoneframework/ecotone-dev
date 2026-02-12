# Scheduling and Dynamic Channel Patterns Reference

## Scheduled Attribute

```php
use Ecotone\Messaging\Attribute\Scheduled;

#[Scheduled(
    requestChannelName: 'channelName',  // Channel to send the return value to
    endpointId: 'myScheduler',          // Unique endpoint identifier
    requiredInterceptorNames: []        // Optional interceptor names
)]
```

The method's return value is sent as a message to `requestChannelName`.

## Poller Attribute

```php
use Ecotone\Messaging\Attribute\Poller;

#[Poller(
    cron: '',                           // Cron expression (e.g. '*/5 * * * *')
    errorChannelName: '',               // Error channel for failures
    fixedRateInMilliseconds: 1000,      // Poll interval (default 1000ms)
    initialDelayInMilliseconds: 0,      // Delay before first execution
    memoryLimitInMegabytes: 0,          // Memory limit (0 = unlimited)
    handledMessageLimit: 0,             // Message limit (0 = unlimited)
    executionTimeLimitInMilliseconds: 0, // Time limit (0 = unlimited)
    fixedRateExpression: null,          // Runtime expression for fixed rate
    cronExpression: null                // Runtime expression for cron
)]
```

## Scheduled + Poller Examples

### Cron-Based Scheduling

```php
class ReportGenerator
{
    #[Scheduled(requestChannelName: 'generateReport', endpointId: 'dailyReport')]
    #[Poller(cron: '0 8 * * *')]
    public function schedule(): string
    {
        return 'daily-report';
    }
}
```

### Fixed-Rate Scheduling

```php
class HealthChecker
{
    #[Scheduled(requestChannelName: 'healthCheck', endpointId: 'healthMonitor')]
    #[Poller(fixedRateInMilliseconds: 30000)]
    public function check(): string
    {
        return 'ping';
    }
}
```

### With Initial Delay

```php
class WarmupTask
{
    #[Scheduled(requestChannelName: 'warmup', endpointId: 'cacheWarmer')]
    #[Poller(fixedRateInMilliseconds: 60000, initialDelayInMilliseconds: 5000)]
    public function warmCache(): string
    {
        return 'warm';
    }
}
```

### Common Cron Expressions

| Expression | Meaning |
|-----------|---------|
| `*/5 * * * *` | Every 5 minutes |
| `0 * * * *` | Every hour |
| `0 8 * * *` | Daily at 8 AM |
| `0 0 * * 1` | Every Monday at midnight |
| `0 0 1 * *` | First day of month at midnight |

## Priority Attribute

```php
use Ecotone\Messaging\Attribute\Endpoint\Priority;

// Default priority is 1
#[Priority(10)]
#[Asynchronous('orders')]
#[CommandHandler(endpointId: 'urgentOrders')]
public function handleUrgent(UrgentOrder $command): void { }
```

- Sets `MessageHeaders::PRIORITY` header
- Higher number = higher priority
- Can target `Attribute::TARGET_CLASS | Attribute::TARGET_METHOD`

## Time to Live Attribute

```php
use Ecotone\Messaging\Attribute\Endpoint\TimeToLive;
use Ecotone\Messaging\Scheduling\TimeSpan;

// Integer (milliseconds)
#[TimeToLive(60000)]

// TimeSpan object
#[TimeToLive(time: TimeSpan::withMinutes(5))]

// Expression-based
#[TimeToLive(expression: "reference('config').getTtl()")]
```

- Sets `MessageHeaders::TIME_TO_LIVE` header
- Message discarded if not consumed within TTL
- Can target `Attribute::TARGET_CLASS | Attribute::TARGET_METHOD`

### TimeSpan Factory Methods

```php
TimeSpan::withMilliseconds(500)
TimeSpan::withSeconds(30)
TimeSpan::withMinutes(5)
```

## Dynamic Channel Builder

### Round-Robin

Distributes messages evenly across channels:

```php
use Ecotone\Messaging\Channel\DynamicChannel\DynamicMessageChannelBuilder;

#[ServiceContext]
public function dynamicChannel(): DynamicMessageChannelBuilder
{
    return DynamicMessageChannelBuilder::createRoundRobin(
        'orders',
        ['orders_shard_1', 'orders_shard_2', 'orders_shard_3']
    );
}
```

### Round-Robin with Different Send/Receive Channels

```php
DynamicMessageChannelBuilder::createRoundRobinWithDifferentChannels(
    'orders',
    sendingChannelNames: ['outbox_1', 'outbox_2'],
    receivingChannelNames: ['inbox_1', 'inbox_2'],
);
```

### Header-Based Routing

Routes messages based on a header value:

```php
DynamicMessageChannelBuilder::createWithHeaderBasedStrategy(
    'orders',
    headerName: 'region',
    headerMapping: ['eu' => 'orders_eu', 'us' => 'orders_us'],
    defaultChannelName: 'orders_default'  // optional fallback
);
```

### Throttling Strategy

Throttling-based consumption with a request channel for decisions:

```php
DynamicMessageChannelBuilder::createThrottlingStrategy(
    'orders',
    requestChannelName: 'shouldProcess',
    channelNames: ['orders_1', 'orders_2']
);
```

### Custom Strategies

```php
$channel = DynamicMessageChannelBuilder::createNoStrategy('orders')
    ->withCustomSendingStrategy('mySendDecisionChannel')
    ->withCustomReceivingStrategy('myReceiveDecisionChannel');
```

### Internal Channels

Embed channel builders directly within a dynamic channel:

```php
$channel = DynamicMessageChannelBuilder::createRoundRobin('orders', ['ch1', 'ch2'])
    ->withInternalChannels([
        DbalBackedMessageChannelBuilder::create('ch1'),
        DbalBackedMessageChannelBuilder::create('ch2'),
    ]);
```
