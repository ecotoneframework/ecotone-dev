---
name: ecotone-resiliency
description: >-
  Implements message resiliency in Ecotone: retry strategies with
  RetryTemplateBuilder, error channels, ErrorHandlerConfiguration,
  DBAL dead letter queues, outbox pattern for guaranteed delivery,
  and FinalFailureStrategy for consumer-level failure handling.
  Use when setting up retries, error handling, dead letter queues,
  outbox pattern, or failure strategies.
---

# Ecotone Resiliency

## 1. RetryTemplateBuilder

```php
use Ecotone\Messaging\Handler\Recoverability\RetryTemplateBuilder;

// Fixed backoff: 1 second between retries, max 3 attempts
$retry = RetryTemplateBuilder::fixedBackOff(1000)
    ->maxRetryAttempts(3);

// Exponential backoff: start at 1s, multiply by 10 each retry
// 1s → 10s → 100s → 1000s...
$retry = RetryTemplateBuilder::exponentialBackoff(1000, 10)
    ->maxRetryAttempts(5);

// Exponential with max delay cap: start at 1s, multiply by 2, cap at 60s
// 1s → 2s → 4s → 8s → 16s → 32s → 60s → 60s...
$retry = RetryTemplateBuilder::exponentialBackoffWithMaxDelay(1000, 2, 60000)
    ->maxRetryAttempts(10);
```

## 2. ErrorHandlerConfiguration

### With Dead Letter Channel

```php
use Ecotone\Messaging\Handler\Recoverability\ErrorHandlerConfiguration;

class ErrorConfig
{
    #[ServiceContext]
    public function errorHandler(): ErrorHandlerConfiguration
    {
        return ErrorHandlerConfiguration::createWithDeadLetterChannel(
            'errorChannel',                                    // error channel name
            RetryTemplateBuilder::fixedBackOff(1000)
                ->maxRetryAttempts(3),                         // retry strategy
            'dead_letter'                                      // dead letter channel name
        );
    }
}
```

### Without Dead Letter (Retry Only)

```php
#[ServiceContext]
public function errorHandler(): ErrorHandlerConfiguration
{
    return ErrorHandlerConfiguration::create(
        'errorChannel',
        RetryTemplateBuilder::exponentialBackoff(1000, 2)
            ->maxRetryAttempts(5)
    );
}
```

### Per-Endpoint Error Channel

Route errors from a specific endpoint to a custom error handler:

```php
use Ecotone\Messaging\Endpoint\PollingMetadata;

#[ServiceContext]
public function ordersPolling(): PollingMetadata
{
    return PollingMetadata::create('ordersEndpoint')
        ->setErrorChannelName('orders_error');
}
```

## 3. Dead Letter Queue

Messages that exhaust all retries go to the dead letter channel:

```php
use Ecotone\Dbal\DbalBackedMessageChannelBuilder;

class DeadLetterConfig
{
    #[ServiceContext]
    public function deadLetterChannel(): DbalBackedMessageChannelBuilder
    {
        return DbalBackedMessageChannelBuilder::create('dead_letter');
    }
}
```

Consuming dead letters:
```bash
bin/console ecotone:run dead_letter --handledMessageLimit=10
```

## 4. Outbox Pattern

Use `DbalBackedMessageChannelBuilder` — events are stored atomically in the same DB transaction as business data:

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

Events committed in the same transaction as business data, then consumed by a worker process:
```bash
bin/console ecotone:run orders
```

## 5. FinalFailureStrategy

Defines behavior when all retries are exhausted and no error channel can handle the failure:

```php
use Ecotone\Messaging\Endpoint\FinalFailureStrategy;
```

| Strategy | Behavior |
|----------|----------|
| `FinalFailureStrategy::IGNORE` | Drops the failed message — it will not be redelivered |
| `FinalFailureStrategy::RESEND` | Resends message to the end of the channel (loses order, unblocks processing) |
| `FinalFailureStrategy::RELEASE` | Releases for redelivery. AMQP: rejects with `requeue=true`. Kafka: resets offset. May cause infinite loop |
| `FinalFailureStrategy::STOP` | Stops the consumer by rethrowing the exception |

Usage with channel builders:

```php
use Ecotone\Amqp\AmqpBackedMessageChannelBuilder;

#[ServiceContext]
public function ordersChannel(): AmqpBackedMessageChannelBuilder
{
    return AmqpBackedMessageChannelBuilder::create('orders')
        ->withFinalFailureStrategy(FinalFailureStrategy::RESEND);
}
```

## 6. #[InstantRetry] (Enterprise)

Automatic retry without error channels or dead letters. Applied at class or method level:

```php
use Ecotone\Modelling\Attribute\InstantRetry;

#[InstantRetry(retryTimes: 3)]
class OrderService
{
    #[CommandHandler('order.place')]
    public function placeOrder(PlaceOrder $command): void
    {
        // Retried up to 3 times on any exception
    }
}
```

### Retry on Specific Exceptions

```php
#[InstantRetry(retryTimes: 3, exceptions: [ConnectionException::class, TimeoutException::class])]
#[CommandHandler('order.place')]
public function placeOrder(PlaceOrder $command): void
{
    // Only retried for ConnectionException or TimeoutException
}
```

> Requires Enterprise licence.

## 7. #[ErrorChannel] (Enterprise)

Routes messages to a specific error channel on handler failure:

```php
use Ecotone\Messaging\Attribute\ErrorChannel;

#[ErrorChannel('orders_error')]
class OrderService
{
    #[CommandHandler('order.place')]
    public function placeOrder(PlaceOrder $command): void
    {
        // On failure, message is routed to 'orders_error' channel
    }
}
```

Can be applied at class or method level.

> Requires Enterprise licence.

## 8. Custom Error Processing

```php
use Ecotone\Messaging\Attribute\ServiceActivator;
use Ecotone\Messaging\Handler\Recoverability\ErrorMessage;

class ErrorProcessor
{
    #[ServiceActivator(inputChannelName: 'custom_error')]
    public function handleError(ErrorMessage $errorMessage): void
    {
        $this->logger->error('Processing failed', [
            'exception' => $errorMessage->getPayload(),
            'originalMessage' => $errorMessage->getOriginalMessage(),
        ]);
    }
}
```

Route errors to custom processing via `PollingMetadata::setErrorChannelName()` or `ErrorHandlerConfiguration`.

## 9. Testing Error Handling

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

    // Run multiple times to process retries
    for ($i = 0; $i < 3; $i++) {
        try {
            $ecotone->run('orders', ExecutionPollingMetadata::createWithTestingSetup());
        } catch (\Throwable) {
            // Expected failures
        }
    }

    $this->assertEquals(3, $handler->attempts);
}
```

## Key Rules

- Use `RetryTemplateBuilder` to define retry strategies (fixed, exponential, exponential with cap)
- Use `ErrorHandlerConfiguration` for global error handling with optional dead letter
- Use `PollingMetadata::setErrorChannelName()` for per-endpoint error routing
- Use `DbalBackedMessageChannelBuilder` for outbox pattern (atomic event storage)
- Use `FinalFailureStrategy` to control behavior when all recovery options are exhausted
- See `references/retry-patterns.md` for detailed API reference
