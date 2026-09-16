# Resiliency API Reference

## RetryTemplateBuilder API

```php
use Ecotone\Messaging\Handler\Recoverability\RetryTemplateBuilder;
```

### Fixed Backoff

```php
// Fixed delay between retries (in milliseconds)
$retry = RetryTemplateBuilder::fixedBackOff(1000)  // 1s between retries
    ->maxRetries(3);            // 1 initial delivery + 3 retries = up to 4 handler calls
```

### Exponential Backoff

```php
// Initial delay * multiplier^attempt
// 1s -> 10s -> 100s -> 1000s...
$retry = RetryTemplateBuilder::exponentialBackOff(
    initialDelayInMilliseconds: 1000,
    multiplier: 10
)->maxRetries(5);
```

### Exponential Backoff with Max Delay

```php
// Like exponential, but capped at a maximum delay
// 1s -> 2s -> 4s -> 8s -> 16s -> 32s -> 60s -> 60s...
$retry = RetryTemplateBuilder::exponentialBackOffWithMaxDelay(
    initialDelayInMilliseconds: 1000,
    multiplier: 2,
    maxDelayInMilliseconds: 60000
)->maxRetries(10);
```

## ErrorHandlerConfiguration API

```php
use Ecotone\Api\ErrorHandlerConfiguration;
```

### With Dead Letter Channel

After retries are exhausted, messages go to a dead letter channel:

```php
ErrorHandlerConfiguration::createWithDeadLetterChannel(
    errorChannelName: 'errorChannel',
    retryTemplate: RetryTemplateBuilder::fixedBackOff(1000)->maxRetries(3),
    deadLetterChannelName: 'dead_letter'
);
```

### Without Dead Letter (Retry Only)

Messages that exhaust retries are dropped:

```php
ErrorHandlerConfiguration::create(
    errorChannelName: 'errorChannel',
    retryTemplate: RetryTemplateBuilder::exponentialBackOff(1000, 2)->maxRetries(5)
);
```

## FinalFailureStrategy Enum

```php
use Ecotone\Messaging\Endpoint\FinalFailureStrategy;
```

| Value | Constant | Behavior |
|-------|----------|----------|
| `'ignore'` | `IGNORE` | Drops the failed message -- no redelivery |
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

## #[InstantRetry] Attribute (Enterprise)

```php
use Ecotone\Api\InstantRetry;

// Retry on any exception
#[InstantRetry(retryTimes: 3)]

// Retry on specific exceptions only
#[InstantRetry(retryTimes: 3, exceptions: [ConnectionException::class, TimeoutException::class])]
```

- Can be applied at `TARGET_CLASS` or `TARGET_METHOD` level
- Requires Enterprise licence

## #[ErrorChannel] Attribute (Enterprise)

```php
use Ecotone\Api\ErrorChannel;

#[ErrorChannel('orders_error')]
```

- Routes messages to a specific error channel on handler failure
- Can be applied at class or method level
- Requires Enterprise licence

## ErrorMessage API

```php
use Ecotone\Messaging\Handler\Recoverability\ErrorMessage;

$errorMessage->getPayload();         // Returns the exception
$errorMessage->getOriginalMessage(); // Returns the original message
```
