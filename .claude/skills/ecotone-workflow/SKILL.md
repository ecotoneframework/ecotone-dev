---
name: ecotone-workflow
description: >-
  Implements workflows in Ecotone: Sagas (stateful process managers),
  stateless workflows with InternalHandler and outputChannelName chaining,
  and Orchestrators (Enterprise) with routing slip pattern.
  Use when building Sagas, process managers, multi-step workflows,
  InternalHandlers, Orchestrators, or channel-based handler chaining.
---

# Ecotone Workflows

## 1. Sagas (Stateful Process Managers)

A Saga coordinates long-running processes by reacting to events and maintaining state. `#[Saga]` extends the aggregate concept — sagas have `#[Identifier]` and are stored like aggregates.

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

### Saga with outputChannelName

Use `outputChannelName` to trigger commands from saga event handlers:

```php
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

### Identifier Mapping

When the event property name doesn't match the saga identifier, use `identifierMetadataMapping` or `identifierMapping`:

```php
#[Saga]
class ShippingProcess
{
    #[Identifier]
    private string $shipmentId;

    #[EventHandler(identifierMapping: ['shipmentId' => 'orderId'])]
    public static function start(OrderWasPaid $event): self
    {
        $saga = new self();
        $saga->shipmentId = $event->orderId;
        return $saga;
    }

    #[EventHandler(identifierMetadataMapping: ['shipmentId' => 'aggregate.id'])]
    public function onItemShipped(ItemWasShipped $event): void
    {
        // correlates via metadata header 'aggregate.id'
    }
}
```

### Event-Sourced Saga

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

## 2. Stateless Workflows (InternalHandler Chaining)

Chain handlers using `outputChannelName` and `#[InternalHandler]` for multi-step stateless processing:

```php
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Messaging\Attribute\InternalHandler;

final readonly class ImageProcessingWorkflow
{
    #[CommandHandler(outputChannelName: 'image.resize')]
    public function validateImage(ProcessImage $command): ProcessImage
    {
        Assert::isTrue(
            in_array(pathinfo($command->path)['extension'], ['jpg', 'png', 'gif']),
            "Unsupported format"
        );
        return $command;
    }

    #[InternalHandler(inputChannelName: 'image.resize', outputChannelName: 'image.upload')]
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

### Asynchronous Steps

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

### InternalHandler API

`#[InternalHandler]` extends `#[ServiceActivator]`:
- `inputChannelName` (string, required) — internal channel to listen on
- `outputChannelName` (string, optional) — channel to send result to (chains to next step)
- `endpointId` (string, optional) — required when used with `#[Asynchronous]`
- If handler returns `null`, the chain stops (no message sent to outputChannel)

## 3. Orchestrators (Enterprise)

Orchestrators define a routing slip — an ordered list of steps to execute. Each step is an `#[InternalHandler]`. Data flows through steps sequentially: output of one becomes input to the next.

> Requires Enterprise licence.

```php
use Ecotone\Messaging\Attribute\Orchestrator;
use Ecotone\Messaging\Attribute\InternalHandler;

class AuthorizationOrchestrator
{
    #[Orchestrator(inputChannelName: 'start.authorization', endpointId: 'auth-orchestrator')]
    public function startAuthorization(): array
    {
        return ['validate', 'process', 'sendEmail'];
    }

    #[InternalHandler(inputChannelName: 'validate')]
    public function validate(string $data): string
    {
        return 'validated: ' . $data;
    }

    #[InternalHandler(inputChannelName: 'process')]
    public function process(string $data): string
    {
        return 'processed: ' . $data;
    }

    #[InternalHandler(inputChannelName: 'sendEmail')]
    public function sendEmail(string $data): string
    {
        return 'email sent for: ' . $data;
    }
}
```

### OrchestratorGateway

Provide a business interface for invoking orchestrators:

```php
use Ecotone\Messaging\Attribute\OrchestratorGateway;

interface AuthorizationProcess
{
    #[OrchestratorGateway('start.authorization')]
    public function start(string $data): string;
}
```

### Asynchronous Orchestrator

```php
class AsyncWorkflow
{
    #[Asynchronous('async')]
    #[Orchestrator(inputChannelName: 'async.workflow', endpointId: 'async-workflow')]
    public function start(): array
    {
        return ['stepA', 'stepB', 'stepC'];
    }

    #[InternalHandler(inputChannelName: 'stepA')]
    public function stepA(mixed $data): mixed { return $data; }

    #[InternalHandler(inputChannelName: 'stepB')]
    public function stepB(mixed $data): mixed { return $data; }

    #[InternalHandler(inputChannelName: 'stepC')]
    public function stepC(mixed $data): mixed { return $data; }
}
```

## 4. Testing Sagas

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

### Testing Saga with Async and Delayed Messages

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

### Testing Saga with outputChannelName

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

## 5. Testing Stateless Workflows

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

### Testing Async Stateless Workflow

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

## 6. Testing Orchestrators

```php
use Ecotone\Dbal\Configuration\DbalConfiguration;
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

## Key Rules

- `#[Saga]` extends aggregate — use `#[Identifier]`, factory methods, and instance methods
- Use `WithEvents` trait + `recordThat()` to publish domain events from sagas
- `outputChannelName` on handlers routes the return value to the named channel
- Returning `null` from a handler with `outputChannelName` stops the chain
- `#[InternalHandler]` is for internal routing — not exposed via CommandBus/EventBus
- Orchestrators require Enterprise licence and return arrays of step channel names
- Always provide `endpointId` when combining with `#[Asynchronous]`
- See `references/workflow-patterns.md` for detailed API reference
