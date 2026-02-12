# Workflow Testing Patterns

All workflow tests use `EcotoneLite::bootstrapFlowTesting()` to bootstrap the framework.

## Testing Saga Start and State

```php
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;

public function test_saga_starts_on_event(): void
{
    $ecotone = EcotoneLite::bootstrapFlowTesting(
        [OrderFulfillmentProcess::class],
    );

    $orderId = '123';
    $ecotone->publishEvent(new OrderWasPlaced($orderId));

    $saga = $ecotone->getSaga(OrderFulfillmentProcess::class, $orderId);
    $this->assertFalse($saga->isCompleted());
}
```

## Testing Saga Completion via Multiple Events

```php
public function test_saga_completes_when_all_events_received(): void
{
    $ecotone = EcotoneLite::bootstrapFlowTesting(
        [OrderFulfillmentProcess::class],
    );

    $orderId = '123';
    $events = $ecotone
        ->publishEvent(new OrderWasPlaced($orderId))
        ->publishEvent(new PaymentWasReceived($orderId))
        ->publishEvent(new ItemsWereShipped($orderId))
        ->getRecordedEvents();

    $this->assertContainsEquals(new OrderWasFulfilled($orderId), $events);
}
```

## Testing Saga State via getSaga()

```php
$ecotone = EcotoneLite::bootstrapFlowTesting([OrderProcess::class]);

$ecotone->publishEvent(new OrderWasPlaced('123'));

$saga = $ecotone->getSaga(OrderProcess::class, '123');
$this->assertEquals(OrderStatus::PLACED, $saga->getStatus());
```

## Testing Saga Events via getRecordedEvents()

```php
$ecotone = EcotoneLite::bootstrapFlowTesting([OrderProcess::class]);

$events = $ecotone
    ->publishEvent(new OrderWasPlaced('123'))
    ->getRecordedEvents();

$this->assertEquals([new OrderProcessWasStarted('123')], $events);
```

## Testing Saga with Query Handler

```php
$ecotone = EcotoneLite::bootstrapFlowTesting([OrderProcess::class]);

$status = $ecotone
    ->publishEvent(new OrderWasPlaced('123'))
    ->sendQueryWithRouting('orderProcess.getStatus', metadata: ['aggregate.id' => '123']);

$this->assertEquals(OrderProcessStatus::PLACED, $status);
```

## Testing Saga with Async and Delayed Messages

```php
use Ecotone\Messaging\Scheduling\TimeSpan;

public function test_saga_retries_payment_after_delay(): void
{
    $ecotone = EcotoneLite::bootstrapFlowTesting(
        [OrderProcess::class, PaymentService::class],
        [
            OrderService::class => new StubOrderService(Money::EUR(100)),
            PaymentService::class => new PaymentService(new FailingPaymentProcessor())
        ],
        enableAsynchronousProcessing: [
            SimpleMessageChannelBuilder::createQueueChannel('async', delayable: true),
        ],
    );

    $ecotone
        ->publishEvent(new OrderWasPlaced('123'))
        ->releaseAwaitingMessagesAndRunConsumer('async', new TimeSpan(hours: 1));

    $saga = $ecotone->getSaga(OrderProcess::class, '123');
    $this->assertEquals(2, $saga->getPaymentAttempt());
}
```

## Testing Saga with outputChannelName

```php
public function test_saga_triggers_command_via_output_channel(): void
{
    $ecotone = EcotoneLite::bootstrapFlowTesting(
        [OrderProcess::class, PaymentService::class],
        [
            OrderService::class => new StubOrderService(Money::EUR(100)),
            PaymentService::class => new PaymentService(new PaymentProcessor())
        ],
        enableAsynchronousProcessing: [
            SimpleMessageChannelBuilder::createQueueChannel('async'),
        ],
    );

    $this->assertEquals(
        [new PaymentWasSuccessful('123')],
        $ecotone
            ->publishEvent(new OrderWasPlaced('123'))
            ->run('async')
            ->getRecordedEventsByType(PaymentWasSuccessful::class)
    );
}
```

## Testing Delayed Messages

```php
$ecotone = EcotoneLite::bootstrapFlowTesting(
    [OrderProcess::class, PaymentService::class],
    [PaymentService::class => new PaymentService(new FailingProcessor())],
    enableAsynchronousProcessing: [
        SimpleMessageChannelBuilder::createQueueChannel('async', delayable: true),
    ],
);

$ecotone
    ->publishEvent(new OrderWasPlaced('123'))
    ->releaseAwaitingMessagesAndRunConsumer('async', new TimeSpan(hours: 1));
```

## Testing Stateless Workflow Chains

```php
public function test_workflow_chains_through_all_steps(): void
{
    $ecotone = EcotoneLite::bootstrapFlowTesting(
        [ImageProcessingWorkflow::class],
        [
            ImageProcessingWorkflow::class => new ImageProcessingWorkflow(),
            ImageResizer::class => new ImageResizer(),
            ImageUploader::class => $uploader = new InMemoryImageUploader(),
        ],
    );

    $ecotone->sendCommand(new ProcessImage('/images/photo.png'));

    $this->assertTrue($uploader->wasUploaded('/images/photo_resized.png'));
}
```

## Testing Async Stateless Workflow

```php
public function test_async_workflow(): void
{
    $ecotone = EcotoneLite::bootstrapFlowTesting(
        [ImageProcessingWorkflow::class],
        [
            ImageProcessingWorkflow::class => new ImageProcessingWorkflow(),
            ImageResizer::class => new ImageResizer(),
            ImageUploader::class => $uploader = new InMemoryImageUploader(),
        ],
        enableAsynchronousProcessing: [
            SimpleMessageChannelBuilder::createQueueChannel('async'),
        ],
    );

    $ecotone
        ->sendCommand(new ProcessImage('/images/photo.png'))
        ->run('async');

    $this->assertTrue($uploader->wasUploaded('/images/photo_resized.png'));
}
```

## Testing InternalHandler Chains

```php
$ecotone = EcotoneLite::bootstrapFlowTesting(
    [MyWorkflow::class],
    [
        MyWorkflow::class => new MyWorkflow(),
        SomeDependency::class => new SomeDependency(),
    ],
);

$ecotone->sendCommand(new StartWorkflow('data'));
// Assert on side effects of final step
```

## Testing Orchestrator (Enterprise)

Orchestrator tests require Enterprise licence configuration.

### Basic Orchestrator Test

```php
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Testing\LicenceTesting;

public function test_orchestrator_executes_steps_in_order(): void
{
    $ecotone = EcotoneLite::bootstrapFlowTesting(
        [AuthorizationOrchestrator::class],
        [$orchestrator = new AuthorizationOrchestrator()],
        ServiceConfiguration::createWithDefaults()
            ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::CORE_PACKAGE]))
            ->withLicenceKey(LicenceTesting::VALID_LICENCE),
    );

    $result = $ecotone->sendDirectToChannel('start.authorization', 'test-data');

    $this->assertEquals('email sent for: processed: validated: test-data', $result);
}
```

### Testing Orchestrator via Business Interface (OrchestratorGateway)

```php
public function test_orchestrator_via_business_interface(): void
{
    $ecotone = EcotoneLite::bootstrapFlowTesting(
        [AuthorizationOrchestrator::class, AuthorizationProcess::class],
        [new AuthorizationOrchestrator()],
        ServiceConfiguration::createWithDefaults()
            ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::CORE_PACKAGE]))
            ->withLicenceKey(LicenceTesting::VALID_LICENCE),
    );

    /** @var AuthorizationProcess $gateway */
    $gateway = $ecotone->getGateway(AuthorizationProcess::class);
    $result = $gateway->start('test-data');

    $this->assertEquals('email sent for: processed: validated: test-data', $result);
}
```

### Testing Async Orchestrator

```php
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;

public function test_async_orchestrator(): void
{
    $ecotone = EcotoneLite::bootstrapFlowTesting(
        [AsyncWorkflow::class],
        [$service = new AsyncWorkflow()],
        ServiceConfiguration::createWithDefaults()
            ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([
                ModulePackageList::CORE_PACKAGE,
                ModulePackageList::ASYNCHRONOUS_PACKAGE,
            ]))
            ->withLicenceKey(LicenceTesting::VALID_LICENCE),
        enableAsynchronousProcessing: [
            SimpleMessageChannelBuilder::createQueueChannel('async'),
        ],
    );

    $ecotone->sendDirectToChannel('async.workflow', []);
    $this->assertEquals([], $service->getExecutedSteps());

    $ecotone->run('async', ExecutionPollingMetadata::createWithTestingSetup());
    $this->assertEquals(['stepA', 'stepB', 'stepC'], $service->getExecutedSteps());
}
```
