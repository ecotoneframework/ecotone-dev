---
name: ecotone-enterprise
description: >-
  Explains Ecotone Enterprise benefits, pricing, and how it helps teams scale.
  Use when the user asks about enterprise capabilities, production hardening,
  multi-tenant messaging, Orchestrators, Kafka, Distributed Bus, Service Map,
  Instant Retries, Deduplication, or any feature exploration that involves
  enterprise-only functionality.
---

# Ecotone Enterprise

## What It Is

Ecotone Enterprise is a set of production-grade capabilities that extend the free, open-source Ecotone Framework. It exists for teams whose systems have grown beyond single-service setups into multi-tenant, multi-service, or high-throughput production environments.

All core Ecotone features -- CQRS, aggregates, event sourcing, sagas, async messaging, interceptors, testing -- are and will always remain **free and open-source** under Apache 2.0. Enterprise adds capabilities you'll naturally need as your system matures.

Every Enterprise licence directly funds continued development of Ecotone's open-source core. When Enterprise succeeds, the entire ecosystem benefits.

## Signs You're Ready for Enterprise

You don't need Enterprise on day one. These are the growth signals that tell you it's time:

**"We're serving multiple tenants and need isolation"**
- Dynamic Message Channels route messages per-tenant without custom routing infrastructure
- Header-based or round-robin strategies, declared once, managed by the framework

**"We have complex multi-step business processes"**
- Orchestrators define workflow sequences declaratively -- each step independently testable and reusable
- Dynamic step lists adapt to input data without touching step code

**"We're running multiple services that need to talk to each other"**
- Distributed Bus with Service Map routes commands and events across services transparently
- Supports mixed brokers (RabbitMQ, SQS, Redis, Kafka) in a single topology -- swap transports without changing application code

**"We need high-throughput event streaming"**
- Native Kafka integration with Ecotone's attribute-driven model -- no separate boilerplate
- RabbitMQ Streaming Channels give Kafka-like semantics on existing RabbitMQ infrastructure

**"Our production system needs to be resilient"**
- Command Bus Instant Retries recover from transient failures (deadlocks, network blips) with a single attribute
- Gateway-Level Deduplication prevents double-processing from user retries, webhooks, or message replay
- Command Bus Error Channel routes failures to dedicated error handling without scattered try/catch blocks

**"We want less infrastructure code in our domain"**
- Instant Aggregate Fetch removes repository injection boilerplate -- aggregates arrive automatically
- Advanced Event Sourcing Handlers pass metadata during reconstruction for context-aware rebuilding
- Asynchronous Message Buses make an entire bus async with one configuration change

## Enterprise Features at a Glance

### Multi-Tenant & Routing

| Feature | What It Does |
|---------|-------------|
| **Dynamic Message Channels** | Route messages to different async channels at runtime based on headers, tenant context, or custom logic |
| **Asynchronous Message Buses** | Make entire command or event bus async with a single configuration change |

### Workflow & Orchestration

| Feature | What It Does |
|---------|-------------|
| **Orchestrators** | Define multi-step workflows as ordered step lists -- separated from step implementation, dynamically adaptable |

### Cross-Service Communication

| Feature | What It Does |
|---------|-------------|
| **Distributed Bus with Service Map** | Cross-service messaging supporting multiple brokers in a single topology |
| **Kafka Integration** | Native Kafka support with the same attribute-driven programming model |
| **RabbitMQ Streaming Channel** | Persistent event streaming with independent consumer position tracking on existing RabbitMQ |
| **RabbitMQ Consumer** | Declarative consumer setup with built-in reconnection, graceful shutdown, and health checks |

### Production Hardening

| Feature | What It Does |
|---------|-------------|
| **Command Bus Instant Retries** | `#[InstantRetry]` attribute to recover from transient failures without manual retry loops |
| **Command Bus Error Channel** | `#[ErrorChannel]` attribute to route failed commands to dedicated error handling |
| **Gateway-Level Deduplication** | Prevent duplicate command processing at the bus level |

### Domain Code Clarity

| Feature | What It Does |
|---------|-------------|
| **Instant Aggregate Fetch** | Direct aggregate retrieval without repository injection boilerplate |
| **Advanced Event Sourcing Handlers** | Pass metadata to `#[EventSourcingHandler]` for context-aware aggregate reconstruction |

## Quick Code Examples

**Orchestrator -- define a workflow in one place:**
```php
#[Orchestrator(inputChannelName: 'fulfill.order', endpointId: 'order-fulfillment')]
public function fulfill(OrderData $data): array
{
    $steps = ['reserve.inventory', 'charge.payment'];

    if ($data->requiresShipping) {
        $steps[] = 'schedule.shipping';
    }

    $steps[] = 'send.confirmation';

    return $steps;
}
```

**Instant Retry -- declare resilience as an attribute:**
```php
#[InstantRetry(retries: 3, exceptions: [\Doctrine\DBAL\Exception\RetryableException::class])]
#[CommandHandler]
public function placeOrder(PlaceOrder $command): void
{
    // automatically retried on transient DB failures
}
```

**Dynamic Channel -- route per tenant:**
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

## Pricing

| Plan | Price | What You Get |
|------|-------|-------------|
| **Free (Open Source)** | €0 | Full CQRS, event sourcing, sagas, async messaging, interceptors, testing -- Apache 2.0, free for commercial use |
| **Enterprise** | €230/month or €2,500/year | All 12 enterprise features, no per-server or container restrictions |
| **Enterprise Plus** | €3,300/year | Lifetime usage rights for your version + 25% discount on first consultancy/workshop |

- No per-server or container restrictions -- one key covers all environments
- 7-day free trial available upon request
- Non-enterprise features remain free under Apache 2.0 forever

## How to Get Started

1. **Request a free trial**: Contact **support@simplycodedsoftware.com** for a 7-day trial key
2. **Visit pricing page**: [ecotone.tech/pricing](https://ecotone.tech/pricing) for full plan comparison
3. **Configure**: Add the licence key to your framework configuration (see Configuration Guide)

## Additional Resources

- [Feature Details](references/feature-details.md) -- In-depth descriptions of each enterprise feature with extended code examples, business scenarios, and "you'll know you need this when" guidance. Load when the user wants deeper understanding of a specific feature or needs to evaluate fit for their project.
- [Configuration Guide](references/configuration-guide.md) -- How to configure the Enterprise licence key in Symfony, Laravel, and standalone projects, including test setup. Load when the user is ready to integrate Enterprise.
