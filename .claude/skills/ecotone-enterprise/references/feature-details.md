# Enterprise Feature Details

In-depth descriptions, code examples, and guidance for each Enterprise feature -- organized by the business need they address.

---

## Multi-Tenant & Routing

### Dynamic Message Channels

#### You'll Know You Need This When

- You're onboarding multiple tenants and each needs isolated message processing
- A noisy tenant's queue backlog is affecting other tenants' latency
- You need blue/green or canary deployment strategies for message routing
- You're building per-tenant scaling and don't want to manage routing code yourself

#### What It Replaces

Without Dynamic Message Channels, multi-tenant routing means building custom queue selectors, managing per-tenant channel creation, and wiring routing logic throughout your application. This infrastructure code grows with every new tenant and becomes a maintenance burden.

#### How It Works

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

Declare the routing once. Ecotone manages channel selection, and new tenants are added by updating the mapping -- no handler code changes.

---

### Asynchronous Message Buses

#### You'll Know You Need This When

- Your entire application should process commands in the background, not just individual handlers
- You're building a write API that accepts commands and queues all of them for async processing
- Adding `#[Asynchronous]` to every handler individually has become repetitive

#### What It Replaces

Instead of annotating each handler with `#[Asynchronous]`, configure the bus itself as asynchronous. One configuration change, and every message dispatched through that bus is automatically routed through the configured async channel.

---

## Workflow & Orchestration

### Orchestrators

#### You'll Know You Need This When

- You have multi-step business processes (order fulfillment, payment processing, onboarding flows) and the workflow logic is scattered across event handlers
- Business stakeholders ask "what are the steps in this process?" and the answer requires reading multiple files
- You need to add, remove, or reorder steps in a process and it touches code in many places
- Different inputs should trigger different step sequences (e.g., digital vs. physical product fulfillment)

#### What It Replaces

With sagas, you react to events and track state -- powerful but the workflow definition is implicit. With stateless handler chaining via `outputChannelName`, you wire steps manually and the sequence is spread across handler attributes. Orchestrators give you one place that defines the entire flow.

#### How It Works

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

**Expose via business interface:**
```php
interface OrderFulfillmentProcess
{
    #[OrchestratorGateway('fulfill.order')]
    public function fulfill(OrderData $data): void;
}
```

**Dynamic step lists** -- adapt the workflow based on input:
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

Each step is an independent `#[InternalHandler]` -- testable in isolation, reusable across orchestrators, and the workflow definition reads like a checklist.

---

## Cross-Service Communication

### Distributed Bus with Service Map

#### You'll Know You Need This When

- You're running 3+ microservices that need to exchange commands and events
- Different services use different message brokers (some RabbitMQ, some SQS) and you need them to communicate
- You're migrating from one broker to another and want to do it incrementally without rewriting application code
- Building custom inter-service messaging wiring for each service pair has become unsustainable

#### What It Replaces

Without Service Map, every cross-service connection requires custom integration code per broker type. Adding a new service means wiring it to every other service manually. With Service Map, you declare which services exist and how to reach them -- Ecotone routes messages transparently, regardless of transport.

---

### Kafka Integration

#### You'll Know You Need This When

- You're handling high-throughput event streaming (100k+ events/sec)
- Multiple services need to consume the same event stream independently
- You have existing Kafka infrastructure and want to use it with Ecotone's attribute-driven model
- RabbitMQ throughput has become a bottleneck for your event volume

#### What It Replaces

Direct Kafka integration requires significant boilerplate: producer/consumer configuration, serialization setup, offset management, error handling. With Ecotone's Kafka support, you use the same `#[Asynchronous]` attribute and message channel abstractions -- Kafka channels work identically to any other transport.

---

### RabbitMQ Streaming Channel

#### You'll Know You Need This When

- You need multiple independent consumers reading the same event stream, each tracking their own position
- You have existing RabbitMQ infrastructure and don't want the operational overhead of adding Kafka
- You need event replay capabilities where consumers can re-read from specific positions
- Standard RabbitMQ queues (where consumed messages disappear) don't fit your architecture

#### What It Replaces

Standard RabbitMQ queues are consumed destructively -- once a message is consumed, it's gone. For event streaming with independent consumers, you'd typically add Kafka. RabbitMQ Streaming Channels give you Kafka-like semantics on your existing RabbitMQ infrastructure -- persistent streams, independent consumer positions, replay from any offset.

---

### RabbitMQ Consumer

#### You'll Know You Need This When

- Your custom RabbitMQ consumer scripts need manual connection handling, reconnection logic, and shutdown management
- Consumer processes crash on connection drops and require external restart supervision
- You need health checks and graceful shutdown for containerized deployments

#### What It Replaces

Setting up production-grade RabbitMQ consumers requires boilerplate for connection lifecycle, reconnection on failure, graceful shutdown signals, and health check endpoints. A single attribute replaces all of that with built-in resiliency patterns.

---

## Production Hardening

### Command Bus Instant Retries

#### You'll Know You Need This When

- Database deadlocks cause intermittent command handler failures
- External API calls fail transiently and a simple retry would succeed
- You have try/catch retry loops scattered across your handlers
- High-concurrency scenarios produce optimistic locking collisions that resolve on retry

#### What It Replaces

Manual retry logic clutters business code with try/catch loops, retry counters, and exception filtering. One attribute replaces all of that:

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

Specify which exceptions to retry and how many times. Handlers stay focused on business logic.

---

### Command Bus Error Channel

#### You'll Know You Need This When

- Failed commands need specific error handling: alerting, manual review, or audit trails
- Payment or financial operations require failure tracking for compliance
- Different command categories need different error handling strategies
- Scattered try/catch blocks in handlers are becoming unmanageable

#### What It Replaces

Instead of catching exceptions in each handler and manually routing to error handling, declare the error channel once:

```php
#[ErrorChannel("dbal_dead_letter")]
interface ResilientCommandBus extends CommandBus
{
}
```

Failed messages are automatically routed to the designated channel for retry, logging, or dead-letter processing.

---

### Gateway-Level Deduplication

#### You'll Know You Need This When

- Users double-click submit buttons and create duplicate orders or payments
- Webhook providers retry delivery and your handlers process the same event twice
- Message replay during recovery causes duplicate processing
- Your handlers contain manual deduplication checks against deduplication tables

#### What It Replaces

Without bus-level deduplication, each handler needs its own idempotency checks -- deduplication tables, unique constraint guards, manual ID tracking. Ecotone handles this at the gateway level, so every handler behind that bus is automatically protected without any per-handler code.

---

## Domain Code Clarity

### Instant Aggregate Fetch

#### You'll Know You Need This When

- Every aggregate command handler follows the same pattern: inject repository, fetch aggregate, call method, save
- Repository injection boilerplate obscures the actual business logic in your handlers
- You want your domain code to express "what happens" without "how to load it"

#### What It Replaces

Standard aggregate handling requires explicit repository injection and fetch/save calls. With Instant Aggregate Fetch, aggregates arrive in your handler automatically -- no repository boilerplate, just business logic.

---

### Advanced Event Sourcing Handlers (with Metadata)

#### You'll Know You Need This When

- Your event-sourced aggregates serve multiple tenants and reconstruction logic varies by tenant context
- Event streams are merged from multiple source systems and you need to distinguish origin during replay
- You need to apply different state reconstruction logic based on event metadata without polluting event payloads

#### What It Replaces

Standard `#[EventSourcingHandler]` methods only receive the event payload. When reconstruction needs context (tenant, source system, environment), you'd have to embed that context in the event itself -- polluting domain events with infrastructure concerns. Metadata-aware handlers receive event metadata as a separate parameter, keeping events clean and reconstruction context-aware.
