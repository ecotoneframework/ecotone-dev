# Resiliency Testing Patterns

## Testing Retry Behavior

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

## Testing with Error Handler Configuration

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
