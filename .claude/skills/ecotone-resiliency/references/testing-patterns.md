# Resiliency Testing Patterns

## Testing Retry Behavior

`ErrorHandlerConfiguration`'s retry runs inside a single `run()` call -- `RetryTemplateBuilder::fixedBackOff(0)`
means no delay between attempts, so `ExecutionPollingMetadata`'s polling loop drains every retry without a second
`run()` call. `stopOnError: false` keeps that loop going after a failed delivery.

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

    $errorConfig = new class {
        #[ServiceContext]
        public function errorHandler(): ErrorHandlerConfiguration
        {
            return ErrorHandlerConfiguration::create('errorChannel', RetryTemplateBuilder::fixedBackOff(0)->maxRetries(3));
        }
    };

    $ecotone = EcotoneLite::bootstrapFlowTesting(
        classesToResolve: [$handler::class, $errorConfig::class],
        containerOrAvailableServices: [$handler, $errorConfig],
        configuration: ServiceConfiguration::createWithDefaults()
            ->withDefaultErrorChannel('errorChannel')
            ->withExtensionObjects([
                SimpleMessageChannelBuilder::createQueueChannel('orders'),
            ]),
    );

    $ecotone->sendCommand(new PlaceOrder('123'));
    $ecotone->run('orders', ExecutionPollingMetadata::createWithTestingSetup(stopOnError: false));

    $this->assertEquals(3, $handler->attempts);
}
```

## Testing with Error Handler Configuration

An endpoint only reaches `ErrorHandlerConfiguration` once something routes its failures to the matching error
channel name -- either `ServiceConfiguration::withDefaultErrorChannel()` for every endpoint, or
`PollingMetadata::setErrorChannelName()` per endpoint.

```php
public function test_error_handler_routes_to_dead_letter(): void
{
    $handler = new class {
        #[Asynchronous('orders')]
        #[CommandHandler(endpointId: 'placeOrder')]
        public function handle(PlaceOrder $command): void
        {
            throw new \RuntimeException('Always fails');
        }
    };

    $errorConfig = new class {
        #[ServiceContext]
        public function errorHandler(): ErrorHandlerConfiguration
        {
            return ErrorHandlerConfiguration::createWithDeadLetterChannel(
                'errorChannel',
                RetryTemplateBuilder::fixedBackOff(0)->maxRetries(1),
                'dead_letter'
            );
        }
    };

    $ecotone = EcotoneLite::bootstrapFlowTesting(
        classesToResolve: [$handler::class, $errorConfig::class],
        containerOrAvailableServices: [$handler, $errorConfig],
        configuration: ServiceConfiguration::createWithDefaults()
            ->withDefaultErrorChannel('errorChannel')
            ->withExtensionObjects([
                SimpleMessageChannelBuilder::createQueueChannel('orders'),
                SimpleMessageChannelBuilder::createQueueChannel('dead_letter'),
            ]),
    );

    $ecotone->sendCommand(new PlaceOrder('123'));
    $ecotone->run('orders', ExecutionPollingMetadata::createWithTestingSetup(stopOnError: false));

    $this->assertNotNull($ecotone->receiveMessageFrom('dead_letter'));
}
```
