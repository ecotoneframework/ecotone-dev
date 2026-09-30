# Asynchronous Processing Testing Patterns

## Basic Async Testing

```php
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;

public function test_async_processing(): void
{
    $handler = new NotificationHandler();

    $ecotone = EcotoneLite::bootstrapFlowTesting(
        classesToResolve: [NotificationHandler::class],
        containerOrAvailableServices: [$handler],
    );

    $ecotone->publishEvent(new OrderWasPlaced('order-1'));

    // Flow tests provide an in-memory delayable queue for 'notifications'; consume it explicitly
    $ecotone->run('notifications');

    $this->assertTrue($handler->wasProcessed);
}
```

## ExecutionPollingMetadata

```php
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;

// Default test setup
$ecotone->run('orders', ExecutionPollingMetadata::createWithTestingSetup());

// Custom test setup
$ecotone->run('orders', ExecutionPollingMetadata::createWithTestingSetup(
    handledMessageLimit: 1,
    executionTimeLimitInMilliseconds: 100
));
```

## Testing Delayed Messages

`run()` takes a channel name and an optional `ExecutionPollingMetadata` -- move time forward with
`advanceTimeBy()` first, then run:

```php
use Ecotone\Messaging\Scheduling\TimeSpan;

$ecotone
    ->advanceTimeBy(TimeSpan::withSeconds(60))
    ->run('reminders');
```

## Key Testing Methods

- `ServiceConfiguration::withExtensionObjects([...])` -- register in-memory channels for `bootstrapFlowTesting`
- `$ecotone->run('channelName')` -- consume messages from a channel
- `ExecutionPollingMetadata::createWithTestingSetup()` -- default test polling config
- `$ecotone->sendDirectToChannel('channel', $payload)` -- inject messages directly into a channel
