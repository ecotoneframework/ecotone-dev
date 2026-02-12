---
name: ecotone-asynchronous
description: >-
  Implements asynchronous message processing in Ecotone: message channels,
  #[Asynchronous] attribute, polling consumers, Sagas, delayed messages,
  error handling with retry and dead letter queues, and the outbox pattern.
  Use when working with async processing, message channels, Sagas,
  delayed delivery, retries, or the outbox pattern.
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

## 4. Sagas (Process Managers)

```php
use Ecotone\Modelling\Attribute\Saga;
use Ecotone\Modelling\Attribute\Identifier;
use Ecotone\Modelling\Attribute\EventHandler;

#[Saga]
class OrderFulfillmentSaga
{
    #[Identifier]
    private string $orderId;
    private bool $paymentReceived = false;
    private bool $itemsShipped = false;

    #[EventHandler]
    public static function start(OrderWasPlaced $event): self
    {
        $saga = new self();
        $saga->orderId = $event->orderId;
        return $saga;
    }

    #[EventHandler]
    public function onPaymentReceived(PaymentWasReceived $event): void
    {
        $this->paymentReceived = true;
        $this->checkCompletion();
    }

    #[EventHandler]
    public function onItemsShipped(ItemsWereShipped $event): void
    {
        $this->itemsShipped = true;
        $this->checkCompletion();
    }

    private function checkCompletion(): void
    {
        if ($this->paymentReceived && $this->itemsShipped) {
            // Saga complete — could publish event or send command
        }
    }
}
```

`#[Saga]` extends the aggregate concept — sagas have `#[Identifier]` and are stored like aggregates.

## 5. Delayed Messages

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

## 6. Error Handling and Retry

### RetryTemplateBuilder

```php
use Ecotone\Messaging\Handler\Recoverability\RetryTemplateBuilder;

// Fixed backoff
$retry = RetryTemplateBuilder::fixedBackOff(1000)  // 1s between retries
    ->maxRetryAttempts(3);

// Exponential backoff
$retry = RetryTemplateBuilder::exponentialBackoff(1000, 10)  // start 1s, multiplier 10
    ->maxRetryAttempts(5);

// Exponential with max delay cap
$retry = RetryTemplateBuilder::exponentialBackoffWithMaxDelay(1000, 2, 60000);
```

### ErrorHandlerConfiguration

```php
use Ecotone\Messaging\Handler\Recoverability\ErrorHandlerConfiguration;

class ErrorConfig
{
    #[ServiceContext]
    public function errorHandler(): ErrorHandlerConfiguration
    {
        return ErrorHandlerConfiguration::createWithDeadLetterChannel(
            'errorChannel',
            RetryTemplateBuilder::fixedBackOff(1000)->maxRetryAttempts(3),
            'dead_letter'
        );
    }
}
```

### Per-Endpoint Error Channel

```php
PollingMetadata::create('ordersEndpoint')
    ->setErrorChannelName('orders_error');
```

## 7. Outbox Pattern

Use `DbalBackedMessageChannelBuilder` — events stored in DB transaction with business data:

```php
use Ecotone\Dbal\DbalBackedMessageChannelBuilder;

class OutboxConfig
{
    #[ServiceContext]
    public function outboxChannel(): DbalBackedMessageChannelBuilder
    {
        return DbalBackedMessageChannelBuilder::create('orders');
    }
}
```

Events are atomically stored with business data, then consumed by a worker process.

## 8. Testing Async

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
- Use `SimpleMessageChannelBuilder` for testing, DBAL for outbox pattern
- Test async by providing channels in `enableAsynchronousProcessing` and calling `run()`
- See `references/channel-patterns.md` for channel configuration
- See `references/error-handling.md` for retry and dead letter patterns
