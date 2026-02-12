# Asynchronous Processing Usage Examples

## Channel Registration Patterns

### Single Channel per Method

```php
use Ecotone\Messaging\Attribute\ServiceContext;
use Ecotone\Dbal\DbalBackedMessageChannelBuilder;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;

class MessagingConfiguration
{
    #[ServiceContext]
    public function ordersChannel(): DbalBackedMessageChannelBuilder
    {
        return DbalBackedMessageChannelBuilder::create('orders');
    }

    #[ServiceContext]
    public function notificationsChannel(): SimpleMessageChannelBuilder
    {
        return SimpleMessageChannelBuilder::createQueueChannel('notifications');
    }
}
```

### Multiple Channels from One Method

```php
#[ServiceContext]
public function channels(): array
{
    return [
        SimpleMessageChannelBuilder::createQueueChannel('orders'),
        SimpleMessageChannelBuilder::createQueueChannel('notifications'),
        SimpleMessageChannelBuilder::createQueueChannel('reports'),
    ];
}
```

## Polling Configuration

### Registering PollingMetadata via ServiceContext

```php
use Ecotone\Messaging\Endpoint\PollingMetadata;

#[ServiceContext]
public function ordersPolling(): PollingMetadata
{
    return PollingMetadata::create('orders')
        ->setHandledMessageLimit(50)
        ->setStopOnError(true);
}
```

### Running Consumers

```bash
bin/console ecotone:run notifications --handledMessageLimit=100
```

## Channel Usage with Handlers

```php
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Modelling\Attribute\EventHandler;

class NotificationService
{
    #[Asynchronous('notifications')]
    #[EventHandler(endpointId: 'emailNotification')]
    public function onOrderPlaced(OrderWasPlaced $event): void
    {
        // Processed via 'notifications' channel
    }
}
```

## Priority Handling

```php
use Ecotone\Messaging\Attribute\Endpoint\Priority;

class OrderService
{
    #[Priority(10)]
    #[Asynchronous('orders')]
    #[CommandHandler(endpointId: 'urgentOrders')]
    public function handleUrgent(UrgentOrder $command): void { }

    #[Priority(1)]
    #[Asynchronous('orders')]
    #[CommandHandler(endpointId: 'regularOrders')]
    public function handleRegular(RegularOrder $command): void { }
}
```

## Time to Live Patterns

```php
use Ecotone\Messaging\Attribute\Endpoint\TimeToLive;
use Ecotone\Messaging\Scheduling\TimeSpan;

class NotificationService
{
    // TTL in milliseconds
    #[TimeToLive(60000)]
    #[Asynchronous('notifications')]
    #[EventHandler(endpointId: 'sendNotification')]
    public function send(OrderWasPlaced $event): void { }

    // TTL with TimeSpan
    #[TimeToLive(time: TimeSpan::withMinutes(5))]
    #[Asynchronous('notifications')]
    #[EventHandler(endpointId: 'sendUrgentNotification')]
    public function sendUrgent(UrgentEvent $event): void { }
}
```

## Scheduling Variations

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

## Dynamic Channel Strategies

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
