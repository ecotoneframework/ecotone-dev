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

## Existing workaround that works today

A `#[Before(pointcut: AsynchronousRunningEndpoint::class, changeHeaders: true)]` interceptor with default precedence already solves the problem, because Before interceptors are positioned in the chain *before* all Around interceptors regardless of precedence (`ChainedMessageProcessorBuilder::compileProcessor` lines 59-72). Verified in `BeforeInterceptorTenantWorkaroundTest`.

This works but is verbose and global — every async endpoint in the system pays the cost of the resolver class instantiation and pointcut evaluation, even handlers that have nothing to do with multi-tenancy. Users would need to teach this idiom themselves; nothing in the multi-tenant API points them to it.

## Solution

Introduce a method-level attribute `#[WithTenantResolver(expression: "...")]` and have the multi-tenant module automatically register a Before interceptor whose pointcut targets that attribute. Only handlers that opt in via the attribute pay any cost; for everyone else the chain is unchanged.

### User-facing API

```php
final class OrderService
{
    #[Asynchronous('orders_topic')]
    #[CommandHandler('processExternalOrder')]
    #[WithTenantResolver(expression: "headers['kafka_topic']")]
    public function process(string $payload, #[Headers] array $headers): void
    {
        // tenant header is set from kafka_topic before multi-tenant switching fires
    }
}
```

The expression has access to `payload` and `headers` (same context as `#[AddHeader]`). Service-backed mappers work via the existing expression-language `reference()` function:

```php
#[WithTenantResolver(expression: "reference('topicToTenantMapper').map(headers['kafka_topic'])")]
```

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

    public function resolve(Message $message, ?WithTenantResolver $resolverAttribute): array
    {
        if ($resolverAttribute === null) {
            return [];
        }
        if ($message->getHeaders()->containsKey($this->tenantHeaderName)) {
            return [];
        }

        $value = $this->expressionEvaluationService->evaluate(
            $resolverAttribute->getExpression(),
            [
                'payload' => $message->getPayload(),
                'headers' => $message->getHeaders()->headers(),
            ]
        );

        return $value === null ? [] : [$this->tenantHeaderName => $value];
    }
}
```

The `?WithTenantResolver` parameter is the matched endpoint annotation, populated by the framework via the same mechanism `EndpointHeadersInterceptor::addMetadata` uses to receive `?AddHeader`.

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

The pointcut is `WithTenantResolver::class`, which means the interceptor only fires on methods carrying that attribute. Methods without it are unaffected.

### Behaviour rules

| Condition | Outcome |
|---|---|
| Method has no `#[WithTenantResolver]` | Interceptor never fires. |
| Method has the attribute, message already has the tenant header | Skip — explicit headers win (preserves internal flows). |
| Expression returns `null` | Skip — let the existing "Lack of context" error surface so misconfigurations fail loudly. |
| Expression throws | Propagate. User's expression bugs surface immediately. |

### Scope (what this design does *not* cover)

- **Multiple multi-tenant configurations:** if more than one `MultiTenantConfiguration` exists, each config registers its own resolver instance. They will all fire on a `WithTenantResolver`-tagged method, each writing its own tenant header name. v1 assumes the single-config setup that the existing module's lines 80-85 already privileges. If multi-config support becomes a requirement later, the attribute can grow an optional `reference:` field naming the target config.
- **Producer-side derivation:** the existing `#[AddHeader]` already covers internal flows. This design targets the inbound-async case only, and the pointcut reflects that.
- **Documenting the underlying `#[Before]` recipe (option A):** explicitly out of scope per the agreed direction.

## Test strategy

- **Reproduction test** (already in repo): `ExternalMessageTenantMappingTest::test_externally_arriving_message_without_tenant_header_should_be_resolvable` — currently fails with "Lack of context about tenant"; should pass once the handler is annotated with `#[WithTenantResolver(expression: "headers['source_topic']")]`.
- **Workaround verification test** (already in repo): `BeforeInterceptorTenantWorkaroundTest` — passes today; documents that the underlying `#[Before]` mechanism is the foundation. Worth keeping as a regression check on the chain ordering it depends on.
- **New tests for the attribute:**
  - Header is derived from a single source header (the happy path the user asked for).
  - Service-backed expression via `reference('mapper').map(...)`.
  - Existing tenant header is preserved when present.
  - Expression returning `null` falls through to the existing error path.
  - A method *without* `#[WithTenantResolver]` is unaffected (no interceptor invocation, no behaviour change).

## Files touched

- `packages/Dbal/src/Attribute/WithTenantResolver.php` — new
- `packages/Dbal/src/MultiTenant/MultiTenantHeaderResolver.php` — new
- `packages/Dbal/src/MultiTenant/Module/MultiTenantConnectionFactoryModule.php` — register resolver and Before interceptor inside the existing per-config loop
- `packages/Dbal/tests/Fixture/MultiTenant/ExternalMessage/` — fixture handler(s) using the new attribute
- `packages/Dbal/tests/Integration/MultiTenant/` — new test class covering the attribute behaviours; existing reproduction updated to use the attribute and assert success
