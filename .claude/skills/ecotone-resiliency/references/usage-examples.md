# Resiliency Usage Examples

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

## Retry-Only Configuration (Without Dead Letter)

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

## Dead Letter Queue with DBAL

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

Route errors to custom processing via `PollingMetadata::setErrorChannelName()` or `ErrorHandlerConfiguration`.

## #[InstantRetry] with Specific Exceptions (Enterprise)

```php
use Ecotone\Modelling\Attribute\InstantRetry;

#[InstantRetry(retryTimes: 3, exceptions: [ConnectionException::class, TimeoutException::class])]
#[CommandHandler('order.place')]
public function placeOrder(PlaceOrder $command): void
{
    // Only retried for ConnectionException or TimeoutException
}
```

## #[ErrorChannel] Usage (Enterprise)

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
