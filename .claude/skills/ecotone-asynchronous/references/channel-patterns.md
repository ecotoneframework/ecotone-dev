# Channel Patterns Reference

## Channel Builder Types

### In-Memory Queue Channel

```php
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;

// Queue channel (pollable, for async processing)
SimpleMessageChannelBuilder::createQueueChannel('channel_name');

// Direct channel (point-to-point, synchronous)
SimpleMessageChannelBuilder::createDirectMessageChannel('channel_name');

// Publish-subscribe channel
SimpleMessageChannelBuilder::createPublishSubscribeChannel('channel_name');
```

### DBAL Channel (Database-Backed)

```php
use Ecotone\Dbal\DbalBackedMessageChannelBuilder;

// Basic DBAL channel
DbalBackedMessageChannelBuilder::create('orders');

// With custom connection reference
DbalBackedMessageChannelBuilder::create('orders', 'custom_connection');
```

### AMQP Channel (RabbitMQ)

```php
use Ecotone\Amqp\AmqpBackedMessageChannelBuilder;

AmqpBackedMessageChannelBuilder::create('orders');
```

### SQS Channel (AWS)

```php
use Ecotone\Sqs\SqsBackedMessageChannelBuilder;

SqsBackedMessageChannelBuilder::create('orders');
```

### Redis Channel

```php
use Ecotone\Redis\RedisBackedMessageChannelBuilder;

RedisBackedMessageChannelBuilder::create('orders');
```

## ServiceContext Registration

Channels are registered via `#[ServiceContext]` methods on any class:

```php
use Ecotone\Messaging\Attribute\ServiceContext;

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

Multiple channels from one method:

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

## PollingMetadata Configuration

```php
use Ecotone\Messaging\Endpoint\PollingMetadata;

PollingMetadata::create('endpointId')
    ->setHandledMessageLimit(100)              // Stop after N messages
    ->setExecutionTimeLimitInMilliseconds(60000) // Stop after N ms
    ->setMemoryLimitInMegabytes(256)           // Stop at memory limit
    ->setFixedRateInMilliseconds(200)          // Poll interval
    ->setStopOnError(false)                    // Continue on error
    ->setFinishWhenNoMessages(false)           // Wait for messages
    ->setErrorChannelName('custom_error')      // Custom error channel
    ->setCron('*/5 * * * *');                  // Cron schedule
```

Register via `#[ServiceContext]`:

```php
#[ServiceContext]
public function ordersPolling(): PollingMetadata
{
    return PollingMetadata::create('orders')
        ->setHandledMessageLimit(50)
        ->setStopOnError(true);
}
```

## Testing Configuration

For tests, use `ExecutionPollingMetadata`:

```php
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;

// Default test setup
$ecotone->run('orders', ExecutionPollingMetadata::createWithTestingSetup());

// Custom test setup
$ecotone->run('orders', ExecutionPollingMetadata::createWithTestingSetup(
    amountOfMessagesToHandle: 1,
    maxExecutionTimeInMilliseconds: 100
));
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
