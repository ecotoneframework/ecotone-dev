# Workflow Usage Examples

Complete, runnable code examples for Ecotone workflow patterns.

## Full Saga: OrderFulfillmentProcess

A complete saga that coordinates an order fulfillment process by reacting to multiple events and tracking state. Demonstrates `#[Saga]`, `#[Identifier]`, `WithEvents` trait, static factory `#[EventHandler]`, and instance event handlers.

```php
use Ecotone\Modelling\Attribute\Saga;
use Ecotone\Modelling\Attribute\Identifier;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\WithEvents;

#[Saga]
class OrderFulfillmentProcess
{
    use WithEvents;

    #[Identifier]
    private string $orderId;
    private bool $paymentReceived = false;
    private bool $itemsShipped = false;

    #[EventHandler]
    public static function start(OrderWasPlaced $event): self
    {
        $saga = new self();
        $saga->orderId = $event->orderId;
        $saga->recordThat(new OrderProcessWasStarted($event->orderId));
        return $saga;
    }

    #[EventHandler]
    public function onPaymentReceived(PaymentWasReceived $event): void
    {
        $this->paymentReceived = true;
        $this->checkCompletion();
    }

    #[EventHandler]
    public function onItemsShipped(ItemsWereShipped $event): void
    {
        $this->itemsShipped = true;
        $this->checkCompletion();
    }

    private function checkCompletion(): void
    {
        if ($this->paymentReceived && $this->itemsShipped) {
            $this->recordThat(new OrderWasFulfilled($this->orderId));
        }
    }
}
```

## Full Saga with outputChannelName and Retry: OrderProcess

A complete saga demonstrating `outputChannelName` to trigger commands from event handlers, combined with `#[Asynchronous]` and `#[Delayed]` for retry logic. Shows how returning `null` stops the chain.

```php
use Ecotone\Modelling\Attribute\Saga;
use Ecotone\Modelling\Attribute\Identifier;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\WithEvents;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Attribute\Delayed;
use Ecotone\Messaging\Scheduling\TimeSpan;

#[Saga]
class OrderProcess
{
    use WithEvents;

    #[Identifier]
    private string $orderId;
    private int $paymentAttempt = 1;

    #[EventHandler]
    public static function startWhen(OrderWasPlaced $event): self
    {
        $saga = new self();
        $saga->orderId = $event->orderId;
        $saga->recordThat(new OrderProcessWasStarted($event->orderId));
        return $saga;
    }

    #[Asynchronous('async')]
    #[EventHandler(endpointId: 'takePaymentEndpoint', outputChannelName: 'takePayment')]
    public function whenOrderProcessStarted(OrderProcessWasStarted $event, OrderService $orderService): TakePayment
    {
        return new TakePayment($this->orderId, $orderService->getTotalPriceFor($this->orderId));
    }

    #[EventHandler]
    public function whenPaymentWasSuccessful(PaymentWasSuccessful $event): void
    {
        $this->recordThat(new OrderReadyToShip($this->orderId));
    }

    #[Delayed(new TimeSpan(hours: 1))]
    #[Asynchronous('async')]
    #[EventHandler(endpointId: 'whenPaymentFailedEndpoint', outputChannelName: 'takePayment')]
    public function whenPaymentFailed(PaymentFailed $event, OrderService $orderService): ?TakePayment
    {
        if ($this->paymentAttempt >= 2) {
            return null;
        }
        $this->paymentAttempt++;
        return new TakePayment($this->orderId, $orderService->getTotalPriceFor($this->orderId));
    }
}
```

## Saga Starting from Command

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

## Saga with Identifier Mapping

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

## Saga with Command Triggering via outputChannelName

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

## Saga with dropMessageOnNotFound

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

## Event-Sourced Saga

```php
use Ecotone\Modelling\Attribute\EventSourcingSaga;

#[EventSourcingSaga]
class OrderSaga
{
    use WithEvents;

    #[Identifier]
    private string $orderId;

    #[EventHandler]
    public static function start(OrderWasPlaced $event): self
    {
        $saga = new self();
        $saga->recordThat(new SagaStarted($event->orderId));
        return $saga;
    }
}
```

## Stateless Workflow: Async Steps

Make individual steps asynchronous:

```php
use Ecotone\Messaging\Attribute\Asynchronous;

final readonly class ImageProcessingWorkflow
{
    #[CommandHandler(outputChannelName: 'image.resize')]
    public function validateImage(ProcessImage $command): ProcessImage
    {
        return $command;
    }

    #[Asynchronous('async')]
    #[InternalHandler(inputChannelName: 'image.resize', outputChannelName: 'image.upload', endpointId: 'image.resize')]
    public function resizeImage(ProcessImage $command, ImageResizer $resizer): ProcessImage
    {
        return new ProcessImage($resizer->resizeImage($command->path));
    }

    #[InternalHandler(inputChannelName: 'image.upload')]
    public function uploadImage(ProcessImage $command, ImageUploader $uploader): void
    {
        $uploader->uploadImage($command->path);
    }
}
```

## Stateless Workflow: Handler Chain

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
        // final step -- no outputChannelName
    }
}
```

## Stateless Workflow: Mixed Sync/Async Steps

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

## Orchestrator with Business Interface

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

## Asynchronous Orchestrator

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
