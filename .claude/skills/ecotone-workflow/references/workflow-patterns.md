# Workflow Patterns Reference

## Saga Attribute API

### #[Saga]

Source: `Ecotone\Modelling\Attribute\Saga`

Class-level attribute. Extends `Aggregate` — sagas are stored and loaded like aggregates.

```php
#[Saga]
class MyProcess
{
    #[Identifier]
    private string $processId;
}
```

### #[EventSourcingSaga]

Source: `Ecotone\Modelling\Attribute\EventSourcingSaga`

Class-level attribute. Extends `EventSourcingAggregate` — saga state rebuilt from events.

```php
#[EventSourcingSaga]
class MyProcess
{
    use WithEvents;

    #[Identifier]
    private string $processId;
}
```

### WithEvents Trait

Source: `Ecotone\Modelling\WithEvents`

```php
use Ecotone\Modelling\WithEvents;

#[Saga]
class OrderProcess
{
    use WithEvents;

    public function handle(SomeEvent $event): void
    {
        $this->recordThat(new SomethingHappened($this->id));
    }
}
```

Methods:
- `recordThat(object $event)` — records a domain event to be published after handler completes
- Events are auto-cleared after publishing

## InternalHandler Attribute API

Source: `Ecotone\Messaging\Attribute\InternalHandler`

Extends `ServiceActivator`. For internal message routing not exposed via bus.

```php
#[InternalHandler(
    inputChannelName: 'step.name',      // required — channel to listen on
    outputChannelName: 'next.step',     // optional — chain to next handler
    endpointId: 'step.endpoint',        // optional — required with #[Asynchronous]
    requiredInterceptorNames: [],       // optional — interceptors to apply
    changingHeaders: false,             // optional — whether handler modifies headers
)]
public function handle(mixed $payload): mixed { }
```

## Orchestrator Attribute API (Enterprise)

Source: `Ecotone\Messaging\Attribute\Orchestrator`

Method-level attribute. Returns array of channel names (routing slip).

```php
#[Orchestrator(
    inputChannelName: 'workflow.start',  // required — trigger channel
    endpointId: 'my-orchestrator',       // optional — required with #[Asynchronous]
)]
public function start(): array
{
    return ['step1', 'step2', 'step3'];
}
```

## OrchestratorGateway Attribute API (Enterprise)

Source: `Ecotone\Messaging\Attribute\OrchestratorGateway`

Method-level attribute on interface methods. Creates business interface gateway.

```php
use Ecotone\Messaging\Attribute\OrchestratorGateway;

interface MyWorkflowProcess
{
    #[OrchestratorGateway('workflow.start')]
    public function start(mixed $data): mixed;
}
```

## Saga Patterns

### Basic Saga (Event-Driven)

```php
#[Saga]
class OrderFulfillment
{
    #[Identifier]
    private string $orderId;

    // Factory — creates saga instance
    #[EventHandler]
    public static function start(OrderWasPlaced $event): self
    {
        $saga = new self();
        $saga->orderId = $event->orderId;
        return $saga;
    }

    // Action — modifies saga state
    #[EventHandler]
    public function onPayment(PaymentReceived $event): void
    {
        // update state
    }
}
```

### Saga Starting from Command

```php
#[Saga]
class ImportProcess
{
    #[Identifier]
    private string $importId;

    #[CommandHandler]
    public static function start(StartImport $command): self
    {
        $saga = new self();
        $saga->importId = $command->importId;
        return $saga;
    }
}
```

### Saga with Identifier Mapping

When event properties don't match saga identifier name:

```php
#[Saga]
class ShippingProcess
{
    #[Identifier]
    private string $shipmentId;

    // Map event property to saga identifier
    #[EventHandler(identifierMapping: ['shipmentId' => 'orderId'])]
    public static function start(OrderWasPaid $event): self
    {
        $saga = new self();
        $saga->shipmentId = $event->orderId;
        return $saga;
    }

    // Map metadata header to saga identifier
    #[EventHandler(identifierMetadataMapping: ['shipmentId' => 'aggregate.id'])]
    public function onShipped(ItemShipped $event): void { }
}
```

### Saga with Command Triggering via outputChannelName

```php
#[Saga]
class OrderProcess
{
    use WithEvents;

    #[Identifier]
    private string $orderId;

    // outputChannelName routes the return value as a command
    #[EventHandler(outputChannelName: 'takePayment')]
    public static function start(OrderWasPlaced $event): TakePayment
    {
        return new TakePayment($event->orderId, $event->totalAmount);
    }

    // Returning null stops the chain
    #[EventHandler(outputChannelName: 'takePayment')]
    public function retryPayment(PaymentFailed $event): ?TakePayment
    {
        if ($this->attempts >= 3) {
            return null;
        }
        return new TakePayment($this->orderId, $this->amount);
    }
}
```

### Saga with dropMessageOnNotFound

When events may arrive before saga exists or after it completes:

```php
#[Saga]
class OrderProcess
{
    #[Identifier]
    private string $orderId;

    #[EventHandler(dropMessageOnNotFound: true)]
    public function onLateEvent(ShipmentDelayed $event): void
    {
        // silently dropped if saga doesn't exist
    }
}
```

## Stateless Workflow Patterns

### Handler Chain with outputChannelName

```php
class AuditWorkflow
{
    #[CommandHandler(outputChannelName: 'audit.validate')]
    public function startAudit(StartAudit $command): AuditData
    {
        return new AuditData($command->targetId);
    }

    #[InternalHandler(inputChannelName: 'audit.validate', outputChannelName: 'audit.conduct')]
    public function validate(AuditData $data): AuditData
    {
        $data->markValidated();
        return $data;
    }

    #[InternalHandler(inputChannelName: 'audit.conduct', outputChannelName: 'audit.report')]
    public function conduct(AuditData $data): AuditData
    {
        $data->markConducted();
        return $data;
    }

    #[InternalHandler(inputChannelName: 'audit.report')]
    public function generateReport(AuditData $data): void
    {
        // final step — no outputChannelName
    }
}
```

### Mixed Sync/Async Steps

```php
class ProcessingWorkflow
{
    // Synchronous entry point
    #[CommandHandler(outputChannelName: 'process.enrich')]
    public function start(ProcessData $command): ProcessData
    {
        return $command;
    }

    // Async step
    #[Asynchronous('async')]
    #[InternalHandler(inputChannelName: 'process.enrich', outputChannelName: 'process.store', endpointId: 'process.enrich')]
    public function enrich(ProcessData $data, ExternalApi $api): ProcessData
    {
        return $data->withExternalData($api->fetch($data->id));
    }

    // Synchronous final step (runs after async step completes)
    #[InternalHandler(inputChannelName: 'process.store')]
    public function store(ProcessData $data, Repository $repo): void
    {
        $repo->save($data);
    }
}
```

## Orchestrator Patterns (Enterprise)

### Simple Orchestrator

```php
class SimpleOrchestrator
{
    #[Orchestrator(inputChannelName: 'start')]
    public function orchestrate(): array
    {
        return ['step1', 'step2', 'step3'];
    }

    #[InternalHandler(inputChannelName: 'step1')]
    public function step1(mixed $data): mixed { return $data; }

    #[InternalHandler(inputChannelName: 'step2')]
    public function step2(mixed $data): mixed { return $data; }

    #[InternalHandler(inputChannelName: 'step3')]
    public function step3(mixed $data): mixed { return $data; }
}
```

### Orchestrator with Business Interface

```php
interface OrderProcess
{
    #[OrchestratorGateway('process.order')]
    public function process(OrderData $data): OrderResult;
}

class OrderOrchestrator
{
    #[Orchestrator(inputChannelName: 'process.order')]
    public function orchestrate(): array
    {
        return ['order.validate', 'order.charge', 'order.fulfill'];
    }

    #[InternalHandler(inputChannelName: 'order.validate')]
    public function validate(OrderData $data): OrderData { return $data; }

    #[InternalHandler(inputChannelName: 'order.charge')]
    public function charge(OrderData $data): OrderData { return $data; }

    #[InternalHandler(inputChannelName: 'order.fulfill')]
    public function fulfill(OrderData $data): OrderResult
    {
        return new OrderResult($data->orderId, 'fulfilled');
    }
}
```

### Asynchronous Orchestrator

```php
class AsyncOrchestrator
{
    #[Asynchronous('async')]
    #[Orchestrator(inputChannelName: 'async.process', endpointId: 'async-process')]
    public function orchestrate(): array
    {
        return ['async.step1', 'async.step2'];
    }

    #[InternalHandler(inputChannelName: 'async.step1')]
    public function step1(mixed $data): mixed { return $data; }

    #[InternalHandler(inputChannelName: 'async.step2')]
    public function step2(mixed $data): mixed { return $data; }
}
```

## Testing Patterns

### Testing Saga State via getSaga()

```php
$ecotone = EcotoneLite::bootstrapFlowTesting([OrderProcess::class]);

$ecotone->publishEvent(new OrderWasPlaced('123'));

$saga = $ecotone->getSaga(OrderProcess::class, '123');
$this->assertEquals(OrderStatus::PLACED, $saga->getStatus());
```

### Testing Saga Events via getRecordedEvents()

```php
$ecotone = EcotoneLite::bootstrapFlowTesting([OrderProcess::class]);

$events = $ecotone
    ->publishEvent(new OrderWasPlaced('123'))
    ->getRecordedEvents();

$this->assertEquals([new OrderProcessWasStarted('123')], $events);
```

### Testing Saga with Query Handler

```php
$ecotone = EcotoneLite::bootstrapFlowTesting([OrderProcess::class]);

$status = $ecotone
    ->publishEvent(new OrderWasPlaced('123'))
    ->sendQueryWithRouting('orderProcess.getStatus', metadata: ['aggregate.id' => '123']);

$this->assertEquals(OrderProcessStatus::PLACED, $status);
```

### Testing Delayed Messages

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

### Testing InternalHandler Chains

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

### Testing Orchestrator

```php
$ecotone = EcotoneLite::bootstrapFlowTesting(
    [MyOrchestrator::class],
    [new MyOrchestrator()],
    ServiceConfiguration::createWithDefaults()
        ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::CORE_PACKAGE]))
        ->withLicenceKey(LicenceTesting::VALID_LICENCE),
);

$result = $ecotone->sendDirectToChannel('orchestrator.start', $inputData);
$this->assertEquals($expectedResult, $result);
```
