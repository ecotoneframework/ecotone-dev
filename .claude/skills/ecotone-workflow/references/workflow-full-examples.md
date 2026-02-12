# Workflow Full Examples

Complete, runnable code examples for Ecotone workflow patterns. These are full implementations that complement the compact snippets in SKILL.md.

## Full Saga Example: OrderFulfillmentProcess

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

## Full Orchestrator Testing Examples

Complete tests for orchestrators. All orchestrator tests require Enterprise licence configuration.

### Testing Orchestrator Steps Execute in Order

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
