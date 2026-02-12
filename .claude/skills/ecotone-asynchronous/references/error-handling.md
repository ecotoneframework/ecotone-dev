# Error Handling Reference

## RetryTemplateBuilder

Source: `Ecotone\Messaging\Handler\Recoverability\RetryTemplateBuilder`

### Fixed Backoff

```php
use Ecotone\Messaging\Handler\Recoverability\RetryTemplateBuilder;

// 1 second between retries, max 3 attempts
$retry = RetryTemplateBuilder::fixedBackOff(1000)
    ->maxRetryAttempts(3);
```

### Exponential Backoff

```php
// Start at 1s, multiply by 10 each retry
// 1s → 10s → 100s → 1000s...
$retry = RetryTemplateBuilder::exponentialBackoff(1000, 10)
    ->maxRetryAttempts(5);
```

### Exponential with Max Delay

```php
// Start at 1s, multiply by 2, cap at 60s
// 1s → 2s → 4s → 8s → 16s → 32s → 60s → 60s...
$retry = RetryTemplateBuilder::exponentialBackoffWithMaxDelay(1000, 2, 60000)
    ->maxRetryAttempts(10);
```

## ErrorHandlerConfiguration

Source: `Ecotone\Messaging\Handler\Recoverability\ErrorHandlerConfiguration`

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

## Per-Endpoint Error Channel

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

## Dead Letter Queue

Messages that exhaust all retries go to the dead letter channel:

```php
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

## Handling Patterns

### Global Error Handler

```php
class GlobalErrorConfig
{
    #[ServiceContext]
    public function errorConfig(): ErrorHandlerConfiguration
    {
        return ErrorHandlerConfiguration::createWithDeadLetterChannel(
            'errorChannel',
            RetryTemplateBuilder::exponentialBackoffWithMaxDelay(1000, 2, 30000)
                ->maxRetryAttempts(5),
            'dead_letter'
        );
    }
}
```

### Custom Error Processing

```php
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

## Testing Error Handling

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
