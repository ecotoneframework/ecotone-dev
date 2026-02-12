# Resiliency API Reference

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
// 1s -> 10s -> 100s -> 1000s...
$retry = RetryTemplateBuilder::exponentialBackoff(
    initialDelay: 1000,   // starting delay in ms
    multiplier: 10        // multiplier for each retry
)->maxRetryAttempts(5);
```

### Exponential Backoff with Max Delay

```php
// Like exponential, but capped at a maximum delay
// 1s -> 2s -> 4s -> 8s -> 16s -> 32s -> 60s -> 60s...
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
use Ecotone\Modelling\Attribute\InstantRetry;

// Retry on any exception
#[InstantRetry(retryTimes: 3)]

// Retry on specific exceptions only
#[InstantRetry(retryTimes: 3, exceptions: [ConnectionException::class, TimeoutException::class])]
```

- Can be applied at `TARGET_CLASS` or `TARGET_METHOD` level
- Requires Enterprise licence

## #[ErrorChannel] Attribute (Enterprise)

```php
use Ecotone\Messaging\Attribute\ErrorChannel;

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
