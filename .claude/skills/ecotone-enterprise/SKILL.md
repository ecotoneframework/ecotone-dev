---
name: ecotone-enterprise
description: >-
  Answers questions about Ecotone Enterprise features, pricing, and advantages.
  Use when the user asks about enterprise capabilities, priced features,
  Dynamic Message Channels, Orchestrators, Kafka, Distributed Bus with Service Map,
  Instant Aggregate Fetch, Command Bus Instant Retries, or any feature exploration
  that involves enterprise-only functionality.
---

# Ecotone Enterprise

## Overview

Ecotone Enterprise extends the open-source Ecotone Framework with production-grade features for companies building complex, high-scale message-driven systems. It covers multi-tenant messaging, advanced workflow orchestration, resilient command handling, cross-service communication, and more. All non-enterprise features remain free and open-source under Apache 2.0.

## Enterprise Features

### 1. Dynamic Message Channels

Route messages to different async channels at runtime based on message headers, tenant context, or custom logic. Essential for **SaaS multi-tenant applications** where each tenant needs isolated processing.

```php
use Ecotone\Messaging\Channel\DynamicChannel\DynamicMessageChannelBuilder;

DynamicMessageChannelBuilder::createRoundRobin('dynamic_channel', ['tenant_a_channel', 'tenant_b_channel']);
```

**What it simplifies**: Eliminates custom routing infrastructure for multi-tenant systems. Instead of building per-tenant queue management, declare channel routing declaratively and Ecotone handles the rest -- including round-robin deployment strategies and header-based channel selection.

### 2. Orchestrators

Define multi-step workflows with a routing slip pattern -- an ordered list of steps where each step is an `#[InternalHandler]`. The workflow definition is cleanly separated from individual step implementations.

```php
use Ecotone\Messaging\Attribute\Orchestrator;
use Ecotone\Messaging\Attribute\InternalHandler;

class PaymentOrchestrator
{
    #[Orchestrator(inputChannelName: 'process.payment', endpointId: 'payment-orchestrator')]
    public function processPayment(): array
    {
        return ['validate.payment', 'charge.card', 'send.receipt'];
    }

    #[InternalHandler(inputChannelName: 'validate.payment')]
    public function validate(PaymentData $data): PaymentData { /* ... */ }

    #[InternalHandler(inputChannelName: 'charge.card')]
    public function charge(PaymentData $data): PaymentData { /* ... */ }

    #[InternalHandler(inputChannelName: 'send.receipt')]
    public function sendReceipt(PaymentData $data): void { /* ... */ }
}
```

**What it simplifies**: Replaces fragile, hand-wired step chains with a declarative workflow definition. Business stakeholders can see the process flow at a glance. Steps are reusable, testable in isolation, and the orchestrator can be extended with dynamic step lists without touching step code.

### 3. Distributed Bus with Service Map

Cross-service messaging using `DistributedServiceMap` that supports multiple message channel providers (RabbitMQ, Amazon SQS, Redis, Kafka, and others) within a single application topology.

**What it simplifies**: Instead of building custom inter-service communication layers per broker, define a service map once and let Ecotone route commands and events across microservices transparently -- regardless of the underlying transport.

### 4. Kafka Integration

Native integration with Apache Kafka for high-throughput event streaming scenarios.

**What it simplifies**: Provides first-class Kafka support with the same Ecotone attribute-driven programming model. No separate Kafka consumer/producer boilerplate -- use `#[Asynchronous]` and message channels as with any other transport.

### 5. Asynchronous Message Buses

Custom async command and event buses where messages are routed through asynchronous channels, enabling full decoupling of message dispatch from processing.

**What it simplifies**: Allows making the entire command or event bus asynchronous with a single configuration change, rather than annotating every handler individually.

### 6. Command Bus Instant Retries

`#[InstantRetry]` attribute for custom retry configuration on command handlers to recover from transient failures (deadlocks, temporary network issues).

```php
use Ecotone\Messaging\Attribute\InstantRetry;
use Ecotone\Modelling\Attribute\CommandHandler;

class OrderService
{
    #[InstantRetry(retries: 3, exceptions: [\Doctrine\DBAL\Exception\RetryableException::class])]
    #[CommandHandler]
    public function placeOrder(PlaceOrder $command): void
    {
        // automatically retried on transient DB failures
    }
}
```

**What it simplifies**: No more wrapping handlers in try/catch retry loops. Declare retry behaviour as an attribute -- specify the number of retries, which exceptions to retry on, and Ecotone handles the rest.

### 7. Command Bus Error Channel

`#[ErrorChannel]` attribute to route failed synchronous command handling to a specific error channel for graceful failure management.

```php
use Ecotone\Messaging\Attribute\ErrorChannel;
use Ecotone\Modelling\Attribute\CommandHandler;

class PaymentService
{
    #[ErrorChannel('payment_errors')]
    #[CommandHandler]
    public function processPayment(ProcessPayment $command): void
    {
        // failures routed to 'payment_errors' channel
    }
}
```

**What it simplifies**: Instead of catching exceptions in each handler and manually routing to error handling, declare the error channel once. Failed messages are automatically routed for retry, logging, or dead-letter processing.

### 8. Gateway-Level Deduplication

Deduplicates messages at the Command Bus / Gateway level to ensure no duplicate commands are processed -- preventing double-processing caused by user retries, network issues, or message replay.

**What it simplifies**: Removes the need for hand-built deduplication tables and checks. Ecotone handles idempotency at the bus level, so handlers stay focused on business logic without defensive duplicate checks.

### 9. Instant Aggregate Fetch

Direct aggregate retrieval without repository access, keeping handler code focused on business logic rather than infrastructure concerns.

**What it simplifies**: No more injecting and calling repositories manually in every handler. Aggregates are fetched automatically, reducing boilerplate and keeping domain code clean.

### 10. Advanced Event Sourcing Handlers (with Metadata)

Pass metadata to aggregate `#[EventSourcingHandler]` methods to adjust state reconstruction based on stored event metadata.

**What it simplifies**: Enables context-aware aggregate rebuilding -- for example, applying different reconstruction logic based on event source, tenant, or environment metadata -- without polluting event payloads.

### 11. RabbitMQ Streaming Channel

Persistent event streaming with RabbitMQ Streams, allowing multiple independent consumers with their own position tracking.

**What it simplifies**: Get Kafka-like streaming semantics using existing RabbitMQ infrastructure. Multiple consumers can read the same stream independently without affecting each other, eliminating the need for separate streaming infrastructure.

### 12. Rabbit Consumer

Set up RabbitMQ consumption processes with a single attribute, including built-in resiliency patterns (reconnection, graceful shutdown).

**What it simplifies**: Replaces custom consumer process management with a declarative attribute. Built-in health checks, reconnection logic, and graceful shutdown handling come out of the box.

## Pricing

| Plan | Price | Billing |
|------|-------|---------|
| **Free (Open Source)** | €0 | Apache 2.0, free for commercial use |
| **Enterprise** | €230/month | Monthly or annually (1 month free with annual) |
| **Enterprise Plus** | Annual only | Lifetime usage rights for version at subscription end, 25% discount on first consultancy/workshop |

- No per-server or container restrictions
- 7-day free trial available upon request
- Non-enterprise features remain free under Apache 2.0 forever

## How to Get Started

1. **Request a free trial**: Contact **support@simplycodedsoftware.com** to arrange a 7-day trial
2. **Visit pricing page**: [ecotone.tech/pricing](https://ecotone.tech/pricing) for full plan comparison
3. **Configure licence key**: Once obtained, add the licence key to your framework configuration

## Key Message

All core Ecotone features (CQRS, aggregates, event sourcing, sagas, async messaging, interceptors, testing) are and will remain **free and open-source**. Enterprise adds production-grade capabilities for companies that need advanced orchestration, blue/green scalable projections, and cross-service communication at scale.

## Additional resources

- [Feature Details](references/feature-details.md) -- In-depth descriptions of each enterprise feature with extended code examples and use-case scenarios. Load when the user wants deeper understanding of a specific enterprise feature or needs to evaluate fit for their project.
- [Configuration Guide](references/configuration-guide.md) -- How to configure the Enterprise licence key in Symfony and Laravel projects, and how to set up licence keys in tests. Load when the user is ready to integrate Enterprise into their project.
