# Asynchronous Processing Testing Patterns

## Basic Async Testing

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

## ExecutionPollingMetadata

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

## Testing Delayed Messages

```php
use Ecotone\Messaging\Scheduling\TimeSpan;

$ecotone->run('reminders', null, TimeSpan::withSeconds(60));
```

## Key Testing Methods

- `enableAsynchronousProcessing` -- provide in-memory channels to `bootstrapFlowTesting`
- `$ecotone->run('channelName')` -- consume messages from a channel
- `ExecutionPollingMetadata::createWithTestingSetup()` -- default test polling config
- `$ecotone->sendDirectToChannel('channel', $payload)` -- inject messages directly into a channel
