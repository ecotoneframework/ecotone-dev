---
name: ecotone-asynchronous
description: >-
  Implements asynchronous message processing in Ecotone: message channels,
  #[Asynchronous] attribute, polling consumers, delayed messages,
  priority, time to live, scheduling, and dynamic channels.
  Use when working with async processing, message channels,
  delayed delivery, scheduling, priority, TTL, or dynamic channel routing.
---

# Ecotone Asynchronous Processing

## 1. #[Asynchronous] Attribute

Routes handler execution through a message channel:

```php
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Modelling\Attribute\EventHandler;

class NotificationService
{
    #[Asynchronous('notifications')]
    #[EventHandler(endpointId: 'sendEmailNotification')]
    public function sendEmail(OrderWasPlaced $event): void
    {
        // Processed asynchronously via 'notifications' channel
    }
}
```

- Requires a corresponding channel to be configured
- `endpointId` is required when using `#[Asynchronous]`
- Can be applied to `#[CommandHandler]`, `#[EventHandler]`, or at class level

## 2. Message Channels

Channels are registered via `#[ServiceContext]` methods:

```php
use Ecotone\Messaging\Attribute\ServiceContext;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;

class ChannelConfiguration
{
    #[ServiceContext]
    public function notificationChannel(): SimpleMessageChannelBuilder
    {
        return SimpleMessageChannelBuilder::createQueueChannel('notifications');
    }
}
```

### Channel Types

| Type | Class | Use Case |
|------|-------|----------|
| In-memory queue | `SimpleMessageChannelBuilder::createQueueChannel()` | Testing, dev |
| DBAL (database) | `DbalBackedMessageChannelBuilder::create()` | Outbox, durability |
| RabbitMQ | `AmqpBackedMessageChannelBuilder::create()` | Production messaging |
| SQS | `SqsBackedMessageChannelBuilder::create()` | AWS messaging |
| Redis | `RedisBackedMessageChannelBuilder::create()` | Fast messaging |

## 3. Polling Configuration

```php
use Ecotone\Messaging\Endpoint\PollingMetadata;

class ConsumerConfiguration
{
    #[ServiceContext]
    public function ordersConsumer(): PollingMetadata
    {
        return PollingMetadata::create('orders')
            ->setHandledMessageLimit(100)
            ->setExecutionTimeLimitInMilliseconds(60000)
            ->setMemoryLimitInMegabytes(256)
            ->setFixedRateInMilliseconds(200)
            ->setStopOnError(false)
            ->setFinishWhenNoMessages(false);
    }
}
```

Running consumers:
```bash
bin/console ecotone:run notifications --handledMessageLimit=100
```

## 4. Delayed Messages

```php
use Ecotone\Messaging\Attribute\Delayed;

class ReminderService
{
    // Fixed delay in milliseconds
    #[Delayed(5000)]
    #[Asynchronous('reminders')]
    #[EventHandler(endpointId: 'sendReminder')]
    public function sendReminder(OrderWasPlaced $event): void { }
}
```

Testing delayed messages:
```php
use Ecotone\Messaging\Scheduling\TimeSpan;

$ecotone->run('reminders', null, TimeSpan::withSeconds(60));
```

## 5. Priority

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

- Sets `MessageHeaders::PRIORITY` header on the message
- Higher number = higher priority (processed first when multiple messages are queued)
- Can be applied at `TARGET_CLASS` or `TARGET_METHOD` level
- Default priority is `1`

## 6. Time to Live

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

- Message is discarded if not consumed within the TTL period
- Accepts integer (milliseconds), `TimeSpan` object, or an expression string
- Can be applied at `TARGET_CLASS` or `TARGET_METHOD` level

## 7. Scheduling

```php
use Ecotone\Messaging\Attribute\Scheduled;
use Ecotone\Messaging\Attribute\Poller;

class ReportGenerator
{
    #[Scheduled(requestChannelName: 'generateReport', endpointId: 'reportScheduler')]
    #[Poller(cron: '0 8 * * *')]
    public function schedule(): string
    {
        return 'daily-report';
    }
}
```

`#[Scheduled]` triggers a method on a schedule defined by `#[Poller]`:
- `cron` — cron expression (e.g. `'*/5 * * * *'` for every 5 minutes)
- `fixedRateInMilliseconds` — periodic execution interval
- `initialDelayInMilliseconds` — delay before first execution

Running scheduled consumers:
```bash
bin/console ecotone:run reportScheduler
```

## 8. Dynamic Channel

```php
use Ecotone\Messaging\Channel\DynamicChannel\DynamicMessageChannelBuilder;

class ChannelConfig
{
    // Round-robin across multiple channels
    #[ServiceContext]
    public function dynamicChannel(): DynamicMessageChannelBuilder
    {
        return DynamicMessageChannelBuilder::createRoundRobin(
            'orders',
            ['orders_1', 'orders_2', 'orders_3']
        );
    }
}
```

### Factory Methods

| Method | Description |
|--------|-------------|
| `createRoundRobin(name, channelNames)` | Distributes messages across channels evenly |
| `createRoundRobinWithDifferentChannels(name, sendChannels, receiveChannels)` | Different channels for send/receive |
| `createWithHeaderBasedStrategy(name, headerName, headerMapping, ?defaultChannel)` | Routes based on message header value |
| `createThrottlingStrategy(name, requestChannelName, channelNames)` | Throttling-based consumption |
| `createNoStrategy(name)` | No-op channel for custom strategy attachment |

### Customization

```php
$channel = DynamicMessageChannelBuilder::createRoundRobin('orders', ['ch1', 'ch2'])
    ->withCustomSendingStrategy('customSendChannel')
    ->withCustomReceivingStrategy('customReceiveChannel')
    ->withHeaderSendingStrategy('routeHeader', ['value1' => 'ch1'], 'defaultCh')
    ->withInternalChannels([...]);
```

## 9. Testing Async

```php
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;

public function test_async_processing(): void
{
    $ecotone = EcotoneLite::bootstrapFlowTesting(
        classesToResolve: [NotificationHandler::class],
        containerOrAvailableServices: [new NotificationHandler()],
        enableAsynchronousProcessing: [
            SimpleMessageChannelBuilder::createQueueChannel('notifications'),
        ],
    );

    $ecotone->publishEvent(new OrderWasPlaced('order-1'));

    // Run the consumer
    $ecotone->run('notifications', ExecutionPollingMetadata::createWithTestingSetup());

    // Assert results
    $this->assertTrue($handler->wasProcessed);
}
```

Key testing methods:
- `enableAsynchronousProcessing` — provide in-memory channels
- `$ecotone->run('channelName')` — consume messages
- `ExecutionPollingMetadata::createWithTestingSetup()` — default test polling config
- `$ecotone->sendDirectToChannel('channel', $payload)` — inject messages directly

## Key Rules

- Always provide `endpointId` with `#[Asynchronous]`
- Register channels via `#[ServiceContext]` methods
- Use `SimpleMessageChannelBuilder` for testing
- Test async by providing channels in `enableAsynchronousProcessing` and calling `run()`
- Use `#[Priority]` for message ordering within a channel
- Use `#[TimeToLive]` to expire unprocessed messages
- Use `#[Scheduled]` + `#[Poller]` for periodic tasks
- See `references/channel-patterns.md` for channel configuration
- See `references/scheduling-patterns.md` for scheduling and dynamic channel details
