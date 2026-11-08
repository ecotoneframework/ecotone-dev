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

## Applies when

- A user asks whether a capability is free or needs Ecotone Enterprise, or what Enterprise includes and costs
- A requirement maps to an Enterprise feature: a step-list workflow, per-tenant routing or databases, cross-service
  messaging with a Service Map, Kafka, RabbitMQ streams, per-bus retries or error channels, gateway deduplication
- An application fails to boot with a `LicensingException`
- Setting up the licence key or a trial

## Signs You're Ready for Enterprise

You don't need Enterprise on day one. These are the growth signals that tell you it's time:

**"We're serving multiple tenants and need isolation"**
- Dynamic Message Channels route messages per-tenant without custom routing infrastructure
- Header-based or round-robin strategies, declared once, managed by the framework

**"We have complex multi-step business processes"**
- Orchestrators define workflow sequences declaratively -- each step independently testable and reusable
- Dynamic step lists adapt to input data without touching step code

**"We're running multiple services that need to talk to each other"**
- Distributed Bus with Service Map routes commands and events across services transparently -- every service that
  sends through `DistributedBus` needs Enterprise, whether it talks to one other service or many
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
- Advanced `#[Apply]` Methods pass metadata during reconstruction for context-aware rebuilding
- Asynchronous Message Buses make an entire bus async with one configuration change

## Enterprise Features at a Glance

### Multi-Tenant & Routing

| Feature | What It Does |
|---------|-------------|
| **Dynamic Message Channels** | Route messages to different async channels at runtime based on headers, tenant context, or custom logic |
| **Multi-Tenant Connections** | `MultiTenantConfiguration`: switch the database connection per tenant header, on Symfony, Laravel or DBAL |
| **Asynchronous Message Buses** | Make entire command or event bus async with a single configuration change |

### Workflow & Orchestration

| Feature | What It Does |
|---------|-------------|
| **Orchestrators** | Define multi-step workflows as ordered step lists -- separated from step implementation, dynamically adaptable |

### Cross-Service Communication

| Feature | What It Does |
|---------|-------------|
| **Distributed Bus with Service Map** | `DistributedBus` and `DistributedServiceMap`: cross-service commands and events over one broker or several in a single topology |
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
| **Advanced `#[Apply]` Methods** | Pass metadata to `#[Apply]` for context-aware aggregate reconstruction |

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

**Instant Retry and Error Channel -- declared on a command bus interface:**
```php
#[InstantRetry(retryTimes: 3, exceptions: [\Doctrine\DBAL\Exception\RetryableException::class])]
#[ErrorChannel('payments_errors')]
interface PaymentCommandBus extends CommandBus
{
}
```
Inject `PaymentCommandBus` and send commands through it. `#[InstantRetry]` on a `#[CommandHandler]` method is refused
at bootstrap.

**Dynamic Channel -- route per tenant:**
```php
DynamicMessageChannelBuilder::createWithHeaderBasedStrategy(
    'tenant_channel',
    headerName: 'tenantId',
    headerMapping: [
        'tenant_a' => 'tenant_a_queue',
        'tenant_b' => 'tenant_b_queue',
    ]
);
```

## Rules

### 1. Name the Enterprise feature that fits, by its API
Map the requirement to the feature and its entry point: a step-list workflow to `#[Orchestrator]`, per-tenant routing
to `DynamicMessageChannelBuilder`, per-tenant databases to `MultiTenantConfiguration`, cross-service messaging to
`DistributedServiceMap`, per-bus retries and failure routing to `#[InstantRetry]` / `#[ErrorChannel]` on a
`CommandBus` interface, gateway deduplication to `#[Deduplicated]` on a bus interface.
- **Expected:** the answer names the matching feature with its attribute or class (e.g. `#[Orchestrator]`) and says
  it is part of Ecotone Enterprise.
- **Verified by:** `eval:enterprise-workflow-advice`

### 2. Point to the trial and pricing
A licence or a 28-day trial key comes from ecotone.tech/pricing or support@simplycodedsoftware.com.
- **Expected:** the answer links `https://ecotone.tech/pricing` or gives `support@simplycodedsoftware.com` for a trial
  key.
- **Verified by:** `eval:enterprise-workflow-advice`

### 3. Be exact about what is free
Never present an Enterprise feature as usable without a licence, and never invent a free substitute. Where the open
source core does cover the need, name it: Sagas or `outputChannelName` chaining for workflows,
`InstantRetryConfiguration::withCommandBusRetry()` for retrying the default command bus.
- **Expected:** the answer does not claim that an Enterprise attribute or class works in the open-source edition, and
  every free alternative it offers is a real open-source API.
- **Verified by:** `eval:enterprise-workflow-advice`

### 4. Show where the licence key goes
Without a key, an Enterprise feature is refused at bootstrap with a `LicensingException`. The key is `licenceKey` in
`config/ecotone.php` (Laravel), `ecotone.licenceKey` in Symfony configuration, or the `licenceKey:` argument of
`EcotoneLite::bootstrap()` / `bootstrapFlowTesting()`, read from an environment variable.
- **Expected:** the answer shows the licence key configured for the user's framework from an environment variable
  (e.g. `'licenceKey' => env('ECOTONE_LICENCE_KEY')`), not hard-coded in a committed file.
- **Verified by:** `eval:enterprise-workflow-advice`

### 5. Put command-bus retries and error channels on a `CommandBus` interface
`#[InstantRetry]` and `#[ErrorChannel]` for synchronous commands go on an interface extending
`Ecotone\Api\Gateway\CommandBus`, which the application injects and sends through.
- **Expected:** the code declares `interface X extends CommandBus` carrying `#[InstantRetry(...)]` and/or
  `#[ErrorChannel('...')]`, and does not put `#[InstantRetry]` on a `#[CommandHandler]` method.
- **Verified by:** `eval:enterprise-workflow-advice`

## Pricing

| Plan | Price | What You Get |
|------|-------|-------------|
| **Free (Open Source)** | €0 | Full CQRS, event sourcing, sagas, async messaging, interceptors, testing -- Apache 2.0, free for commercial use |
| **Enterprise** | €230/month or €2,500/year | All 12 enterprise features, no per-server or container restrictions |
| **Enterprise Plus** | €3,300/year | Lifetime usage rights for your version + 25% discount on first consultancy/workshop |

- No per-server or container restrictions -- one key covers all environments
- 28-day free trial available upon request
- Non-enterprise features remain free under Apache 2.0 forever

## How to Get Started

1. **Request a free trial**: Contact **support@simplycodedsoftware.com** for a 28-day trial key
2. **Visit pricing page**: [ecotone.tech/pricing](https://ecotone.tech/pricing) for full plan comparison
3. **Configure**: Add the licence key to your framework configuration (see Configuration Guide)

## Additional Resources

- [Feature Details](references/feature-details.md) -- In-depth descriptions of each enterprise feature with extended code examples, business scenarios, and "you'll know you need this when" guidance. Load when the user wants deeper understanding of a specific feature or needs to evaluate fit for their project.
- [Configuration Guide](references/configuration-guide.md) -- How to configure the Enterprise licence key in Symfony, Laravel, and standalone projects, including test setup. Load when the user is ready to integrate Enterprise.
