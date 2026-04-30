# External-Message Tenant Resolver — Design

**Date:** 2026-04-30
**Status:** Draft, awaiting review
**Branch:** `feat/external-message-tenant-mapping`

## Problem

In a multi-tenant Ecotone application that consumes messages from an external (non-Ecotone) producer like Kafka, the inbound message envelope carries no `tenant` header — only headers that identify the source (e.g. `kafka_topic`). Users want to derive the tenant from one of those source headers so that the multi-tenant connection switching can pick the right database for the rest of the handler invocation.

The natural attempt — `#[AddHeader('tenant', expression: "headers['kafka_topic']")]` on the handler — does not work for externally-arriving messages. The handler-level `#[AddHeader]` is registered as a *before-send* interceptor (`EndpointHeadersInterceptorModule`, precedence -3000) which fires when a producer-side gateway sends the message into the handler. For external messages, no producer-side gateway is involved: the message is polled from the broker and dispatched to the handler chain directly. The async dispatch chain then runs:

```
PollToGatewayTaskExecutor
  → propagateTenant Around (-2001)        ← reads tenant header, none found, proceeds
    → CollectorSender Around
      → ObjectManagerInterceptor (-1998)  ← calls getConnectionFactory()
        → throws "Lack of context about tenant in Message Headers"
            (handler is never reached, so #[AddHeader] never runs)
```

A reproduction is in `packages/Dbal/tests/Integration/MultiTenant/ExternalMessageTenantMappingTest.php`.

## Existing primitives we'll use

Two pieces of the existing framework make this clean:

1. **Before-method interceptors run before all Around interceptors at the same intercepted method**, regardless of precedence (`ChainedMessageProcessorBuilder::compileProcessor` lines 59-72). Verified in `BeforeInterceptorTenantWorkaroundTest`: a `#[Before(pointcut: AsynchronousRunningEndpoint::class, changeHeaders: true)]` interceptor with default precedence successfully sets the tenant header before multi-tenant Around fires.

2. **Channel-adapter modules already propagate consumer-method annotations to the gateway.** `KafkaModule.php:151` and `RabbitConsumerModule.php:68` both call `->withEndpointAnnotations($annotatedMethod->getAllAnnotationDefinitions())` when registering the inbound adapter. So a method-level attribute placed alongside `#[KafkaConsumer]` / `#[RabbitConsumer]` reaches the gateway's endpoint annotations and becomes visible to interceptor pointcuts and parameter matching at compile time.

(For `#[Asynchronous]` polling consumers — the internal flow — handler-method annotations do *not* propagate to the gateway. That's fine: those flows have an Ecotone producer where `#[AddHeader]` already works. This feature is for the inbound-broker case.)

## Solution

Introduce a method-level attribute `#[WithTenantResolver(expression: "...")]` placed on the same consumer method as `#[KafkaConsumer]` / `#[RabbitConsumer]`. The multi-tenant module registers a Before interceptor whose pointcut is the attribute itself, so it fires only on consumer methods that opt in.

### User-facing API

```php
final class OrderEvents
{
    #[KafkaConsumer('orders_consumer', 'orders_topic')]
    #[WithTenantResolver(expression: "headers['kafka_topic']")]
    public function process(string $payload, array $metadata): void
    {
        // tenant header is set from kafka_topic before multi-tenant switching fires
    }
}
```

The expression has access to `payload` and `headers` (same context as `#[AddHeader]`). Service-backed mappers work via the existing expression-language `reference()` function:

```php
#[WithTenantResolver(expression: "reference('topicToTenantMapper').map(headers['kafka_topic'])")]
```

Same idiom works for AMQP via `#[RabbitConsumer]` and any future broker module that follows the same `getAllAnnotationDefinitions()` propagation contract.

### Components

#### 1. Attribute — `Ecotone\Dbal\Attribute\WithTenantResolver`

```php
#[Attribute(Attribute::TARGET_METHOD)]
final class WithTenantResolver
{
    public function __construct(public string $expression) {}

    public function getExpression(): string
    {
        return $this->expression;
    }
}
```

Single field, single getter. No defaults — expression is required.

#### 2. Resolver service — `Ecotone\Dbal\MultiTenant\MultiTenantHeaderResolver`

```php
final class MultiTenantHeaderResolver
{
    public function __construct(
        private string $tenantHeaderName,
        private ExpressionEvaluationService $expressionEvaluationService,
    ) {}

    public function resolve(Message $message, ?WithTenantResolver $config = null): array
    {
        if ($config === null) {
            return [];
        }
        if ($message->getHeaders()->containsKey($this->tenantHeaderName)) {
            return [];
        }

        $value = $this->expressionEvaluationService->evaluate(
            $config->getExpression(),
            [
                'payload' => $message->getPayload(),
                'headers' => $message->getHeaders()->headers(),
            ]
        );

        return $value === null ? [] : [$this->tenantHeaderName => $value];
    }
}
```

The `?WithTenantResolver $config` parameter is matched at compile time from the gateway's endpoint annotations. The defensive `if ($config === null)` is there because the framework can pass null if pointcut and parameter matching diverge in edge cases; in normal operation the pointcut guarantees presence.

#### 3. Wiring — `MultiTenantConnectionFactoryModule::prepare()`

Inside the existing per-config loop, register the resolver service and a Before method interceptor whose pointcut is the new attribute:

```php
$resolverReference = 'multi_tenant_header_resolver.' . $multiTenantConfig->getReferenceName();
$messagingConfiguration->registerServiceDefinition(
    $resolverReference,
    new Definition(MultiTenantHeaderResolver::class, [
        $multiTenantConfig->getTenantHeaderName(),
        Reference::to(ExpressionEvaluationService::REFERENCE),
    ])
);

$messagingConfiguration->registerBeforeMethodInterceptor(
    MethodInterceptorBuilder::create(
        Reference::to($resolverReference),
        $interfaceToCallRegistry->getFor(MultiTenantHeaderResolver::class, 'resolve'),
        Precedence::DEFAULT_PRECEDENCE,
        WithTenantResolver::class,
        true
    )
);
```

The pointcut `WithTenantResolver::class` only matches gateways whose endpoint annotations include the attribute — i.e. consumer methods that opt in. Methods without it incur zero overhead (no interceptor evaluation, no pointcut match).

### Behaviour rules

| Condition | Outcome |
|---|---|
| Consumer method has no `#[WithTenantResolver]` | Interceptor never fires. Zero overhead. |
| Method has the attribute, message already has the tenant header | Skip — explicit headers win (preserves any internal-flow case where producer set it). |
| Expression returns `null` | Skip — let the existing "Lack of context" error surface so misconfigurations fail loudly. |
| Expression throws | Propagate. User's expression bugs surface immediately. |
| Method is `#[Asynchronous]` (internal polling consumer), not a broker channel adapter | Pointcut won't match (handler annotations aren't on the gateway in this path). Use `#[AddHeader]` for internal flows. |

### Scope (what this design does *not* cover)

- **`#[Asynchronous]` polling consumers fed by external producers:** would require either a framework change to `InterceptedPollingConsumerBuilder` (propagate handler annotations to the gateway) or a different mechanism. The user's reported case is the broker-channel-adapter path, which this design covers.
- **Multiple multi-tenant configurations:** if more than one `MultiTenantConfiguration` exists, each registers its own resolver instance with its own tenant header name; both fire on a `WithTenantResolver`-tagged method. v1 assumes the single-config setup that the existing module's lines 80-85 already privileges. If multi-config support becomes a requirement later, the attribute can grow an optional `reference:` field naming the target config.
- **Producer-side derivation:** `#[AddHeader]` already covers internal flows. This design targets the inbound-broker case only.

## Test strategy

- **Reproduction test** (already in repo): `ExternalMessageTenantMappingTest::test_externally_arriving_message_without_tenant_header_should_be_resolvable` — currently fails with "Lack of context about tenant". The CommandBus-based reproduction simulates the symptom (producer-less arrival in the queue) but doesn't exercise the broker-channel-adapter path. A new integration test on Kafka would cover the real path.
- **Workaround verification test** (already in repo): `BeforeInterceptorTenantWorkaroundTest` — passes today; documents the foundational mechanism. Worth keeping as a regression check on the chain ordering this design depends on.
- **New tests for the attribute (DBAL package, no broker dependency):**
  - Attribute is recognised and routed correctly when present on a `#[KafkaConsumer]`-style fixture method (use a fake channel-adapter builder that mimics annotation propagation, similar to `FakeMessageChannelWithConnectionFactoryBuilder`).
  - Header is derived from a single source header.
  - Service-backed expression via `reference('mapper').map(...)`.
  - Existing tenant header is preserved when present.
  - Expression returning `null` falls through to the existing error path.
  - A consumer method *without* `#[WithTenantResolver]` is unaffected.
- **New integration test (Kafka package, optional v1 add):** end-to-end verification with a real Kafka topic — sends a message, consumer derives tenant from `kafka_topic`, multi-tenant switches connection. Marks the feature as proven on a real broker.

## Files touched

- `packages/Dbal/src/Attribute/WithTenantResolver.php` — new
- `packages/Dbal/src/MultiTenant/MultiTenantHeaderResolver.php` — new
- `packages/Dbal/src/MultiTenant/Module/MultiTenantConnectionFactoryModule.php` — register resolver and Before interceptor inside the existing per-config loop
- `packages/Dbal/tests/Fixture/MultiTenant/ExternalMessage/` — fixture handler(s) using the new attribute
- `packages/Dbal/tests/Integration/MultiTenant/` — new test class covering the attribute behaviours; existing reproduction updated to use the attribute and assert success

No core framework changes required.
