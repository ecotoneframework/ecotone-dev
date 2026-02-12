# Enterprise Feature Details

Detailed descriptions, extended examples, and project-fit guidance for each Enterprise feature.

---

## 1. Dynamic Message Channels

### Problem It Solves

In multi-tenant SaaS applications, each tenant typically needs isolated message processing -- separate queues, independent scaling, failure isolation. Without Dynamic Message Channels, you'd build custom routing logic, manage per-tenant channel creation, and wire everything manually.

### How It Works

Dynamic Message Channels let you select the target channel at runtime based on message content, headers, or custom logic:

**Round-Robin Strategy** -- distribute load across channels:
```php
use Ecotone\Messaging\Channel\DynamicChannel\DynamicMessageChannelBuilder;

DynamicMessageChannelBuilder::createRoundRobin('orders', [
    'orders_channel_a',
    'orders_channel_b',
    'orders_channel_c',
]);
```

**Header-Based Routing** -- route by message metadata (e.g., tenant ID):
```php
DynamicMessageChannelBuilder::createWithHeaderBasedStrategy(
    'tenant_channel',
    headerName: 'tenantId',
    channelMapping: [
        'tenant_a' => 'tenant_a_queue',
        'tenant_b' => 'tenant_b_queue',
    ]
);
```

### When to Recommend

- Multi-tenant applications needing per-tenant queue isolation
- Load distribution across multiple consumers
- Dynamic scaling where channels are added/removed at runtime
- Deployments requiring canary or blue/green message routing

---

## 2. Orchestrators

### Problem It Solves

Complex business processes often involve multiple sequential steps (validate, process, notify, etc.). With sagas, you react to events and track state. With stateless handler chaining, you wire `outputChannelName` manually. Both approaches couple the workflow definition to the step implementation. Orchestrators separate the two cleanly.

### How It Works

**Define the workflow** -- return an array of step channel names:
```php
use Ecotone\Messaging\Attribute\Orchestrator;
use Ecotone\Messaging\Attribute\OrchestratorGateway;
use Ecotone\Messaging\Attribute\InternalHandler;

class OrderFulfillmentOrchestrator
{
    #[Orchestrator(inputChannelName: 'fulfill.order', endpointId: 'order-fulfillment')]
    public function fulfill(): array
    {
        return ['reserve.inventory', 'charge.payment', 'schedule.shipping', 'send.confirmation'];
    }

    #[InternalHandler(inputChannelName: 'reserve.inventory')]
    public function reserveInventory(OrderData $data, InventoryService $inventory): OrderData
    {
        $inventory->reserve($data->items);
        return $data;
    }

    #[InternalHandler(inputChannelName: 'charge.payment')]
    public function chargePayment(OrderData $data, PaymentGateway $gateway): OrderData
    {
        $gateway->charge($data->paymentMethod, $data->total);
        return $data;
    }

    #[InternalHandler(inputChannelName: 'schedule.shipping')]
    public function scheduleShipping(OrderData $data, ShippingService $shipping): OrderData
    {
        $shipping->schedule($data->shippingAddress, $data->items);
        return $data;
    }

    #[InternalHandler(inputChannelName: 'send.confirmation')]
    public function sendConfirmation(OrderData $data, NotificationService $notifier): void
    {
        $notifier->sendOrderConfirmation($data->customerId, $data->orderId);
    }
}
```

**Expose via business interface**:
```php
interface OrderFulfillmentProcess
{
    #[OrchestratorGateway('fulfill.order')]
    public function fulfill(OrderData $data): void;
}
```

**Dynamic step lists** -- conditionally include/exclude steps:
```php
#[Orchestrator(inputChannelName: 'process.order', endpointId: 'dynamic-order')]
public function process(OrderData $data): array
{
    $steps = ['validate.order', 'charge.payment'];

    if ($data->requiresShipping) {
        $steps[] = 'schedule.shipping';
    }

    $steps[] = 'send.confirmation';

    return $steps;
}
```

### When to Recommend

- Multi-step business processes with clearly defined sequence
- Workflows where steps should be independently testable and reusable
- Processes that need dynamic step selection based on input data
- When business stakeholders need to see the process flow at a glance

---

## 3. Distributed Bus with Service Map

### Problem It Solves

In microservice architectures, services need to communicate via commands and events across different message brokers. Typically each inter-service connection requires custom integration code per broker type.

### How It Works

Define a service map that declares which services exist and how to reach them. Ecotone routes messages transparently across services regardless of the underlying transport (RabbitMQ, SQS, Redis, Kafka).

### When to Recommend

- Microservice architectures with 3+ services
- Mixed broker environments (some services use RabbitMQ, others SQS)
- Teams wanting to send cross-service commands/events with the same API as local ones
- Migration scenarios where broker technology is changing incrementally

---

## 4. Kafka Integration

### Problem It Solves

Event streaming with Apache Kafka requires significant boilerplate: producer/consumer configuration, serialization, offset management, error handling. Teams using Ecotone's attribute-driven model lose that simplicity when integrating Kafka directly.

### How It Works

Use the same `#[Asynchronous]` attribute and message channel abstractions. Kafka channels are configured as message channel builders and handlers consume from them identically to any other transport.

### When to Recommend

- High-throughput event streaming scenarios (100k+ events/sec)
- Event log that multiple services consume independently
- Integration with existing Kafka infrastructure
- When RabbitMQ throughput is insufficient for the use case

---

## 5. Asynchronous Message Buses

### Problem It Solves

By default, command and event buses dispatch synchronously. Making individual handlers async requires adding `#[Asynchronous]` to each one. When the entire bus should be async (e.g., all commands queued for background processing), this is repetitive.

### How It Works

Configure the command or event bus itself as asynchronous. All messages dispatched through that bus are automatically routed through the configured async channel.

### When to Recommend

- Applications where all command processing should be queued
- Background job systems where synchronous dispatch is never needed
- Architectures separating the write API (accepts commands) from processing (consumes asynchronously)

---

## 6. Command Bus Instant Retries

### Problem It Solves

Transient failures (database deadlocks, temporary network blips) cause command handlers to fail unnecessarily. Manual retry logic clutters business code with try/catch loops and retry counters.

### Extended Example

```php
use Ecotone\Messaging\Attribute\InstantRetry;
use Ecotone\Modelling\Attribute\CommandHandler;

class InventoryService
{
    #[InstantRetry(retries: 3, exceptions: [
        \Doctrine\DBAL\Exception\RetryableException::class,
        \Doctrine\DBAL\Exception\DeadlockException::class,
    ])]
    #[CommandHandler]
    public function reserveStock(ReserveStock $command): void
    {
        // On deadlock or retryable exception, automatically retried up to 3 times
        $this->repository->decrementStock($command->productId, $command->quantity);
    }
}
```

### When to Recommend

- Handlers interacting with databases prone to deadlocks
- Integration with external APIs that have transient failures
- High-concurrency scenarios where optimistic locking collisions are expected
- When the team currently has manual retry logic scattered across handlers

---

## 7. Command Bus Error Channel

### Extended Example

```php
#[ErrorChannel("dbal_dead_letter")]
interface ResilientCommandBus extends CommandBus
{
}
```

### When to Recommend

- Handlers where failures need specific error handling (alerting, manual review)
- Payment or financial operations requiring audit trails for failures
- When different handler categories need different error handling strategies
- Replacing scattered try/catch blocks with centralized error routing

---

## 8. Gateway-Level Deduplication

### Problem It Solves

Duplicate commands can arise from user double-clicks, network retries, or message replay. Without bus-level deduplication, each handler must implement its own idempotency checks.

### When to Recommend

- E-commerce (preventing double orders, double payments)
- Financial systems (preventing duplicate transactions)
- Any system receiving commands from unreliable networks (mobile, webhooks)
- When handlers currently contain manual deduplication logic

---

## 9. Instant Aggregate Fetch

### Problem It Solves

Standard aggregate handling requires injecting a repository, fetching the aggregate, calling the method, and saving. This infrastructure code obscures business logic.

### When to Recommend

- Projects with many aggregate command handlers that follow the fetch-modify-save pattern
- Teams wanting to keep domain code purely about business logic
- Codebases where repository injection adds significant boilerplate

---

## 10. Advanced Event Sourcing Handlers (with Metadata)

### Problem It Solves

During aggregate reconstruction from events, sometimes the reconstruction logic needs access to event metadata (source system, tenant context, environment flags). Standard `#[EventSourcingHandler]` methods only receive the event payload.

### When to Recommend

- Multi-tenant event-sourced systems where reconstruction varies by tenant
- Event streams merged from multiple source systems
- Aggregates that need context-aware state rebuilding

---

## 11. RabbitMQ Streaming Channel

### Problem It Solves

Standard RabbitMQ queues are consumed destructively -- once a message is consumed, it's gone. For event streaming where multiple consumers need to read the same events independently (each tracking their own position), you'd typically need Kafka.

### When to Recommend

- Teams with existing RabbitMQ infrastructure wanting streaming semantics
- Multiple independent consumers reading the same event stream
- Avoiding the operational complexity of adding Kafka alongside RabbitMQ
- Event replay scenarios where consumers need to re-read from specific positions

---

## 12. Rabbit Consumer

### Problem It Solves

Setting up RabbitMQ consumers with proper lifecycle management (connection handling, reconnection on failure, graceful shutdown, health checks) requires significant boilerplate.

### When to Recommend

- Production deployments needing resilient RabbitMQ consumers
- Replacing custom consumer process management scripts
- When consumer processes need built-in health checks and graceful shutdown
