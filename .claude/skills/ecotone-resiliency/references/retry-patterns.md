# Retry and Error Handling Patterns Reference

## RetryTemplateBuilder API

```php
use Ecotone\Messaging\Handler\Recoverability\RetryTemplateBuilder;
```

### Fixed Backoff

```php
// Fixed delay between retries (in milliseconds)
$retry = RetryTemplateBuilder::fixedBackOff(1000)  // 1s between retries
    ->maxRetryAttempts(3);
```

### Exponential Backoff

```php
// Initial delay * multiplier^attempt
// 1s → 10s → 100s → 1000s...
$retry = RetryTemplateBuilder::exponentialBackoff(
    initialDelay: 1000,   // starting delay in ms
    multiplier: 10        // multiplier for each retry
)->maxRetryAttempts(5);
```

### Exponential Backoff with Max Delay

```php
// Like exponential, but capped at a maximum delay
// 1s → 2s → 4s → 8s → 16s → 32s → 60s → 60s...
$retry = RetryTemplateBuilder::exponentialBackoffWithMaxDelay(
    initialDelay: 1000,   // starting delay in ms
    multiplier: 2,        // multiplier for each retry
    maxDelay: 60000       // cap delay at 60s
)->maxRetryAttempts(10);
```

## ErrorHandlerConfiguration API

```php
use Ecotone\Messaging\Handler\Recoverability\ErrorHandlerConfiguration;
```

### With Dead Letter Channel

After retries are exhausted, messages go to a dead letter channel:

```php
ErrorHandlerConfiguration::createWithDeadLetterChannel(
    errorChannelName: 'errorChannel',
    retryTemplate: RetryTemplateBuilder::fixedBackOff(1000)->maxRetryAttempts(3),
    deadLetterChannelName: 'dead_letter'
);
```

### Without Dead Letter (Retry Only)

Messages that exhaust retries are dropped:

```php
ErrorHandlerConfiguration::create(
    errorChannelName: 'errorChannel',
    retryTemplate: RetryTemplateBuilder::exponentialBackoff(1000, 2)->maxRetryAttempts(5)
);
```

## FinalFailureStrategy Enum

```php
use Ecotone\Messaging\Endpoint\FinalFailureStrategy;
```

| Value | Constant | Behavior |
|-------|----------|----------|
| `'ignore'` | `IGNORE` | Drops the failed message — no redelivery |
| `'resend'` | `RESEND` | Resends to the end of the channel (loses order) |
| `'release'` | `RELEASE` | Releases for transport-specific redelivery |
| `'stop'` | `STOP` | Stops consumer by rethrowing exception |

### Transport-Specific `RELEASE` Behavior

| Transport | Behavior |
|-----------|----------|
| AMQP (RabbitMQ) | Rejects with `requeue=true` (goes to beginning of queue, preserves order) |
| Kafka | Resets consumer offset to redeliver same message (preserves order) |
| DBAL | Requeues the message |
| SQS | Message returns to queue after visibility timeout |

### Usage

```php
// On channel builder
AmqpBackedMessageChannelBuilder::create('orders')
    ->withFinalFailureStrategy(FinalFailureStrategy::RESEND);
```

## Dead Letter Channel Setup

### Full Configuration

```php
class ResiliencyConfig
{
    #[ServiceContext]
    public function errorHandler(): ErrorHandlerConfiguration
    {
        return ErrorHandlerConfiguration::createWithDeadLetterChannel(
            'errorChannel',
            RetryTemplateBuilder::exponentialBackoffWithMaxDelay(1000, 2, 30000)
                ->maxRetryAttempts(5),
            'dead_letter'
        );
    }

    #[ServiceContext]
    public function deadLetterChannel(): DbalBackedMessageChannelBuilder
    {
        return DbalBackedMessageChannelBuilder::create('dead_letter');
    }
}
```

### Consuming Dead Letters

```bash
# Process dead letter messages manually
bin/console ecotone:run dead_letter --handledMessageLimit=10
```

## Outbox Pattern with DBAL

Events are stored in the same database transaction as business data, ensuring atomicity:

```php
class OutboxConfiguration
{
    #[ServiceContext]
    public function ordersChannel(): DbalBackedMessageChannelBuilder
    {
        return DbalBackedMessageChannelBuilder::create('orders');
    }
}
```

The handler marks its channel as `#[Asynchronous('orders')]`. When the command handler executes:
1. Business data is saved to the database
2. Events are stored in the same transaction (via DBAL channel)
3. A worker process (`ecotone:run orders`) consumes and processes the events

This guarantees no events are lost even if the application crashes after saving business data.

## Per-Endpoint Error Routing

```php
#[ServiceContext]
public function ordersPolling(): PollingMetadata
{
    return PollingMetadata::create('ordersEndpoint')
        ->setErrorChannelName('orders_error');
}
```

## Custom Error Processing with ServiceActivator

```php
use Ecotone\Messaging\Attribute\ServiceActivator;
use Ecotone\Messaging\Handler\Recoverability\ErrorMessage;

class ErrorProcessor
{
    #[ServiceActivator(inputChannelName: 'orders_error')]
    public function handleError(ErrorMessage $errorMessage): void
    {
        $exception = $errorMessage->getPayload();
        $originalMessage = $errorMessage->getOriginalMessage();

        $this->logger->error('Order processing failed', [
            'exception' => $exception->getMessage(),
            'payload' => $originalMessage->getPayload(),
        ]);
    }
}
```

## Testing Error Handling

### Testing Retry Behavior

```php
public function test_retry_on_failure(): void
{
    $handler = new class {
        public int $attempts = 0;

        #[Asynchronous('orders')]
        #[CommandHandler(endpointId: 'placeOrder')]
        public function handle(PlaceOrder $command): void
        {
            $this->attempts++;
            if ($this->attempts < 3) {
                throw new \RuntimeException('Temporary failure');
            }
        }
    };

    $ecotone = EcotoneLite::bootstrapFlowTesting(
        classesToResolve: [$handler::class],
        containerOrAvailableServices: [$handler],
        enableAsynchronousProcessing: [
            SimpleMessageChannelBuilder::createQueueChannel('orders'),
        ],
    );

    $ecotone->sendCommand(new PlaceOrder('123'));

    for ($i = 0; $i < 3; $i++) {
        try {
            $ecotone->run('orders', ExecutionPollingMetadata::createWithTestingSetup());
        } catch (\Throwable) {
            // Expected failures on first attempts
        }
    }

    $this->assertEquals(3, $handler->attempts);
}
```

### Testing with Error Handler Configuration

```php
public function test_error_handler_routes_to_dead_letter(): void
{
    $errorConfig = new class {
        #[ServiceContext]
        public function errorHandler(): ErrorHandlerConfiguration
        {
            return ErrorHandlerConfiguration::createWithDeadLetterChannel(
                'errorChannel',
                RetryTemplateBuilder::fixedBackOff(0)->maxRetryAttempts(1),
                'dead_letter'
            );
        }
    };

    $ecotone = EcotoneLite::bootstrapFlowTesting(
        classesToResolve: [$handler::class, $errorConfig::class],
        containerOrAvailableServices: [$handler, $errorConfig],
        enableAsynchronousProcessing: [
            SimpleMessageChannelBuilder::createQueueChannel('orders'),
            SimpleMessageChannelBuilder::createQueueChannel('dead_letter'),
        ],
    );

    $ecotone->sendCommand(new PlaceOrder('123'));
    $ecotone->run('orders', ExecutionPollingMetadata::createWithTestingSetup());

    // Verify message ended up in dead letter
    $ecotone->run('dead_letter', ExecutionPollingMetadata::createWithTestingSetup());
}
```
