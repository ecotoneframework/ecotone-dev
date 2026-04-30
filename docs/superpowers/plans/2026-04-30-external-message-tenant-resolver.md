# External-Message Tenant Resolver Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a `#[WithTenantResolver(expression: ...)]` method-level attribute that lets users derive the multi-tenant header from inbound message headers (e.g. `kafka_topic`) before multi-tenant connection switching fires, for messages arriving from external broker channel adapters.

**Architecture:** A new Apache-licensed attribute in `Ecotone\Dbal\Attribute`, a new resolver service in `Ecotone\Dbal\MultiTenant`, and a single `registerBeforeMethodInterceptor` call inside the existing `MultiTenantConnectionFactoryModule::prepare()` per-config loop. Pointcut is the attribute itself, so the interceptor only fires on consumer methods that opt in. Channel-adapter modules (Kafka, AMQP) already propagate `getAllAnnotationDefinitions()` to the gateway — no core framework changes needed.

**Tech Stack:** PHP 8.1+, Ecotone DBAL package, PHPUnit 11. Tests run inside docker compose `app` service.

---

## File Structure

| File | Responsibility |
|---|---|
| `packages/Dbal/src/Attribute/WithTenantResolver.php` (new) | Method-level attribute carrying the expression string |
| `packages/Dbal/src/MultiTenant/MultiTenantHeaderResolver.php` (new) | Service whose `resolve()` method evaluates the expression and returns the tenant header |
| `packages/Dbal/src/MultiTenant/Module/MultiTenantConnectionFactoryModule.php` (modify) | Wire the resolver service + Before interceptor inside the existing per-config loop |
| `packages/Dbal/tests/Fixture/MultiTenant/ExternalMessage/ExternalKafkaLikeService.php` (modify) | Existing fixture; add a variant handler annotated with `#[WithTenantResolver]` |
| `packages/Dbal/tests/Integration/MultiTenant/ExternalMessageTenantMappingTest.php` (modify) | Existing reproduction; updated to assert the resolver derives the tenant header successfully |
| `packages/Dbal/tests/Integration/MultiTenant/WithTenantResolverTest.php` (new) | Behaviour tests: existing-header preservation, null expression, service-backed mapper, no-attribute no-op |
| `packages/Dbal/tests/Fixture/MultiTenant/ExternalMessage/TopicToTenantMapper.php` (new) | Tiny mapper service used by the `reference()`-based test |

The existing `BeforeInterceptorTenantWorkaroundTest` and `TenantHeaderInterceptor.php` fixture stay as-is — they document the underlying mechanism and serve as a regression check.

---

### Task 1: Create the `WithTenantResolver` attribute

**Files:**
- Create: `packages/Dbal/src/Attribute/WithTenantResolver.php`

- [ ] **Step 1: Create the attribute file**

```php
<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Attribute;

use Attribute;

/**
 * licence Apache-2.0
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class WithTenantResolver
{
    public function __construct(public string $expression)
    {
    }

    public function getExpression(): string
    {
        return $this->expression;
    }
}
```

- [ ] **Step 2: Verify the file is autoloadable**

Run: `docker compose exec -T app php -r "require 'vendor/autoload.php'; echo class_exists(Ecotone\\Dbal\\Attribute\\WithTenantResolver::class) ? 'OK' : 'MISSING';"`
Expected: `OK`

- [ ] **Step 3: Commit**

```bash
git add packages/Dbal/src/Attribute/WithTenantResolver.php
git commit -m "feat(dbal): add WithTenantResolver attribute"
```

---

### Task 2: Create the resolver service

**Files:**
- Create: `packages/Dbal/src/MultiTenant/MultiTenantHeaderResolver.php`

- [ ] **Step 1: Create the resolver class**

```php
<?php

declare(strict_types=1);

namespace Ecotone\Dbal\MultiTenant;

use Ecotone\Dbal\Attribute\WithTenantResolver;
use Ecotone\Messaging\Handler\ExpressionEvaluationService;
use Ecotone\Messaging\Message;

/**
 * licence Apache-2.0
 */
final class MultiTenantHeaderResolver
{
    public function __construct(
        private string $tenantHeaderName,
        private ExpressionEvaluationService $expressionEvaluationService,
    ) {
    }

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

- [ ] **Step 2: Verify the file is autoloadable**

Run: `docker compose exec -T app php -r "require 'vendor/autoload.php'; echo class_exists(Ecotone\\Dbal\\MultiTenant\\MultiTenantHeaderResolver::class) ? 'OK' : 'MISSING';"`
Expected: `OK`

- [ ] **Step 3: Commit**

```bash
git add packages/Dbal/src/MultiTenant/MultiTenantHeaderResolver.php
git commit -m "feat(dbal): add MultiTenantHeaderResolver service"
```

---

### Task 3: Wire the Before interceptor in the module

**Files:**
- Modify: `packages/Dbal/src/MultiTenant/Module/MultiTenantConnectionFactoryModule.php` (inside the existing `foreach ($multiTenantConfigurations as $multiTenantConfig)` loop, after the existing Around interceptor registration block ending at line 120)

- [ ] **Step 1: Add the new imports at the top of the file**

Add (alphabetised among the existing `use` block):

```php
use Ecotone\Dbal\Attribute\WithTenantResolver;
use Ecotone\Dbal\MultiTenant\MultiTenantHeaderResolver;
use Ecotone\Messaging\Handler\ExpressionEvaluationService;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInterceptorBuilder;
```

(`Definition`, `Reference`, `InterfaceToCallRegistry`, and `Precedence` are already imported.)

- [ ] **Step 2: Append the resolver wiring inside the per-config loop**

Inside the loop body, after the `registerAroundMethodInterceptor(...)` call that registers `propagateTenant` (the closing `);` is around line 120), insert:

```php
$resolverReference = 'multi_tenant_header_resolver.' . $multiTenantConfig->getReferenceName();
$messagingConfiguration->registerServiceDefinition(
    $resolverReference,
    new Definition(
        MultiTenantHeaderResolver::class,
        [
            $multiTenantConfig->getTenantHeaderName(),
            Reference::to(ExpressionEvaluationService::REFERENCE),
        ]
    )
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

- [ ] **Step 3: Run the existing multi-tenant test suite to confirm no regressions**

Run: `docker compose exec -T app vendor/bin/phpunit packages/Dbal/tests/Integration/MultiTenantConnectionFactoryTest.php`
Expected: all tests pass (no errors, no failures).

- [ ] **Step 4: Commit**

```bash
git add packages/Dbal/src/MultiTenant/Module/MultiTenantConnectionFactoryModule.php
git commit -m "feat(dbal): register WithTenantResolver Before interceptor"
```

---

### Task 4: Update reproduction fixture to use the attribute

**Files:**
- Modify: `packages/Dbal/tests/Fixture/MultiTenant/ExternalMessage/ExternalKafkaLikeService.php`

- [ ] **Step 1: Replace the file with the resolver-annotated variant**

```php
<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\MultiTenant\ExternalMessage;

use Ecotone\Dbal\Attribute\WithTenantResolver;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Attribute\Parameter\Headers;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\QueryHandler;

/**
 * licence Apache-2.0
 */
final class ExternalKafkaLikeService
{
    private array $receivedHeadersList = [];

    #[Asynchronous('external_topic')]
    #[CommandHandler('externalArrived', endpointId: 'externalArrivedEndpoint')]
    #[WithTenantResolver(expression: "headers['source_topic']")]
    public function externalArrived(string $payload, #[Headers] array $headers): void
    {
        $this->receivedHeadersList[] = $headers;
    }

    #[QueryHandler('lastReceivedHeaders')]
    public function lastReceivedHeaders(): ?array
    {
        return array_shift($this->receivedHeadersList);
    }
}
```

Note on rationale: this fixture uses `#[Asynchronous]` rather than a real `#[KafkaConsumer]` because the DBAL test suite must not depend on the Kafka package. The reproduction test verifies that the resolver's pointcut matches when the framework propagates the attribute to the chain — see Task 5 about why the existing reproduction test will still surface the timing bug if it currently does, and pass once the resolver fires.

- [ ] **Step 2: Commit (kept separate from the reproduction-test edit so the fixture-only change is reviewable on its own)**

```bash
git add packages/Dbal/tests/Fixture/MultiTenant/ExternalMessage/ExternalKafkaLikeService.php
git commit -m "test(dbal): annotate fixture handler with WithTenantResolver"
```

---

### Task 5: Flip reproduction test to assert resolved tenant

**Files:**
- Modify: `packages/Dbal/tests/Integration/MultiTenant/ExternalMessageTenantMappingTest.php`

The existing test should already fail today on `'Lack of context about tenant in Message Headers'`. With Tasks 1-4 in place, the resolver should fire, set the tenant header from `source_topic`, and the handler should run. The assertion already expects `tenant_a` — no body changes needed.

- [ ] **Step 1: Run the reproduction test against the implementation**

Run: `docker compose exec -T app vendor/bin/phpunit packages/Dbal/tests/Integration/MultiTenant/ExternalMessageTenantMappingTest.php`

Expected (success path): test passes; no `Lack of context` error; received headers contain `'tenant' => 'tenant_a'`.

- [ ] **Step 2: If the test still fails, diagnose the pointcut mismatch**

If it fails with `Lack of context about tenant`, the pointcut isn't matching the polling-consumer gateway path used by `#[Asynchronous]`. This is the documented out-of-scope case in the spec. Two options:

a) Convert the reproduction to use a real broker channel adapter (would require Kafka or AMQP test infrastructure — heavier, but exercises the actual user path).
b) Build a fake `ChannelAdapterConsumerBuilder` test fixture that propagates method annotations like the real broker modules do — keeps the DBAL test self-contained.

Option (b) is preferred for v1 keeping DBAL tests broker-free. If this branch is reached, draft a follow-up task to add `packages/Dbal/tests/Fixture/MultiTenant/ExternalMessage/FakeInboundChannelAdapterBuilder.php` mimicking `KafkaInboundChannelAdapterBuilder`'s annotation propagation, then update the reproduction test to use it. **Do not** silently change the assertion or weaken the test — surface the gap.

- [ ] **Step 3: Commit (only if Step 1 succeeded; otherwise stop and discuss)**

```bash
git add packages/Dbal/tests/Integration/MultiTenant/ExternalMessageTenantMappingTest.php
git commit -m "test(dbal): reproduction now passes via WithTenantResolver"
```

---

### Task 6: Add a topic-to-tenant mapper fixture

**Files:**
- Create: `packages/Dbal/tests/Fixture/MultiTenant/ExternalMessage/TopicToTenantMapper.php`

- [ ] **Step 1: Create the mapper class**

```php
<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\MultiTenant\ExternalMessage;

/**
 * licence Apache-2.0
 */
final class TopicToTenantMapper
{
    /**
     * @param array<string, string> $mapping
     */
    public function __construct(private array $mapping)
    {
    }

    public function map(string $sourceTopic): ?string
    {
        return $this->mapping[$sourceTopic] ?? null;
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add packages/Dbal/tests/Fixture/MultiTenant/ExternalMessage/TopicToTenantMapper.php
git commit -m "test(dbal): add TopicToTenantMapper fixture"
```

---

### Task 7: Test — existing tenant header is preserved

**Files:**
- Create: `packages/Dbal/tests/Integration/MultiTenant/WithTenantResolverTest.php`

- [ ] **Step 1: Write the file with the first test**

```php
<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration\MultiTenant;

use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\Dbal\MultiTenant\MultiTenantConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use Enqueue\Dbal\DbalConnectionFactory;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\Dbal\Fixture\MultiTenant\ExternalMessage\ExternalKafkaLikeService;
use Test\Ecotone\Dbal\Fixture\MultiTenant\FakeConnectionFactory;

/**
 * @internal
 */
final class WithTenantResolverTest extends TestCase
{
    public function test_existing_tenant_header_is_preserved_over_resolver_expression(): void
    {
        $service = new ExternalKafkaLikeService();
        $ecotoneLite = $this->bootstrap($service);

        $ecotoneLite->sendCommandWithRoutingKey(
            'externalArrived',
            'hello',
            metadata: ['tenant' => 'tenant_a', 'source_topic' => 'tenant_b']
        );

        $ecotoneLite->run('external_topic', ExecutionPollingMetadata::createWithTestingSetup(1, 1));

        $headers = $ecotoneLite->sendQueryWithRouting('lastReceivedHeaders');

        $this->assertNotNull($headers);
        $this->assertSame('tenant_a', $headers['tenant'] ?? null, 'Explicit tenant header must win over resolver expression');
    }

    private function bootstrap(ExternalKafkaLikeService $service): \Ecotone\Lite\Test\FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            [ExternalKafkaLikeService::class],
            [$service, 'tenant_a_connection' => new FakeConnectionFactory(), 'tenant_b_connection' => new FakeConnectionFactory()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    PollingMetadata::create('external_topic')->setExecutionAmountLimit(1),
                    MultiTenantConfiguration::create('tenant', ['tenant_a' => 'tenant_a_connection', 'tenant_b' => 'tenant_b_connection'], DbalConnectionFactory::class),
                    DbalConfiguration::createWithDefaults()
                        ->withTransactionOnCommandBus(false)
                        ->withTransactionOnAsynchronousEndpoints(false)
                        ->withClearAndFlushObjectManagerOnCommandBus(false)
                        ->withDeduplication(false),
                ]),
            enableAsynchronousProcessing: [
                SimpleMessageChannelBuilder::createQueueChannel('external_topic'),
            ],
        );
    }
}
```

- [ ] **Step 2: Run the test**

Run: `docker compose exec -T app vendor/bin/phpunit packages/Dbal/tests/Integration/MultiTenant/WithTenantResolverTest.php --filter test_existing_tenant_header_is_preserved_over_resolver_expression`
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add packages/Dbal/tests/Integration/MultiTenant/WithTenantResolverTest.php
git commit -m "test(dbal): explicit tenant header wins over resolver"
```

---

### Task 8: Test — null expression result skips the header

**Files:**
- Modify: `packages/Dbal/tests/Integration/MultiTenant/WithTenantResolverTest.php`

- [ ] **Step 1: Append the test method (before the private `bootstrap` helper)**

```php
    public function test_resolver_returning_null_lets_existing_lack_of_context_error_surface(): void
    {
        $service = new ExternalKafkaLikeService();
        $ecotoneLite = $this->bootstrap($service);

        $ecotoneLite->sendCommandWithRoutingKey(
            'externalArrived',
            'hello',
            metadata: []
        );

        $this->expectException(\Ecotone\Messaging\Support\InvalidArgumentException::class);
        $this->expectExceptionMessage('Lack of context about tenant');

        $ecotoneLite->run('external_topic', ExecutionPollingMetadata::createWithTestingSetup(1, 1));
    }
```

The handler's expression is `headers['source_topic']`; when no `source_topic` is sent, `evaluate()` returns `null` and the resolver no-ops. The downstream `ObjectManagerInterceptor` then surfaces the canonical `Lack of context about tenant` error.

- [ ] **Step 2: Run the new test**

Run: `docker compose exec -T app vendor/bin/phpunit packages/Dbal/tests/Integration/MultiTenant/WithTenantResolverTest.php --filter test_resolver_returning_null_lets_existing_lack_of_context_error_surface`
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add packages/Dbal/tests/Integration/MultiTenant/WithTenantResolverTest.php
git commit -m "test(dbal): null resolver result preserves existing error path"
```

---

### Task 9: Test — service-backed mapper via `reference()`

**Files:**
- Modify: `packages/Dbal/tests/Integration/MultiTenant/WithTenantResolverTest.php`
- Create: `packages/Dbal/tests/Fixture/MultiTenant/ExternalMessage/MappedTenantService.php`

The existing fixture handler uses a literal `headers['source_topic']` expression. We need a separate handler whose attribute uses `reference('topicMapper').map(...)`.

- [ ] **Step 1: Create the new fixture handler**

Create `packages/Dbal/tests/Fixture/MultiTenant/ExternalMessage/MappedTenantService.php`:

```php
<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\MultiTenant\ExternalMessage;

use Ecotone\Dbal\Attribute\WithTenantResolver;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Attribute\Parameter\Headers;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\QueryHandler;

/**
 * licence Apache-2.0
 */
final class MappedTenantService
{
    private array $receivedHeadersList = [];

    #[Asynchronous('mapped_topic')]
    #[CommandHandler('mappedArrived', endpointId: 'mappedArrivedEndpoint')]
    #[WithTenantResolver(expression: "reference('topicMapper').map(headers['source_topic'])")]
    public function mappedArrived(string $payload, #[Headers] array $headers): void
    {
        $this->receivedHeadersList[] = $headers;
    }

    #[QueryHandler('mappedLastReceivedHeaders')]
    public function lastReceivedHeaders(): ?array
    {
        return array_shift($this->receivedHeadersList);
    }
}
```

- [ ] **Step 2: Append the test method to `WithTenantResolverTest.php`**

```php
    public function test_resolver_uses_service_backed_mapper_via_reference_expression(): void
    {
        $service = new \Test\Ecotone\Dbal\Fixture\MultiTenant\ExternalMessage\MappedTenantService();
        $mapper = new \Test\Ecotone\Dbal\Fixture\MultiTenant\ExternalMessage\TopicToTenantMapper([
            'orders.us' => 'tenant_a',
            'orders.eu' => 'tenant_b',
        ]);

        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [\Test\Ecotone\Dbal\Fixture\MultiTenant\ExternalMessage\MappedTenantService::class],
            [
                $service,
                'topicMapper' => $mapper,
                'tenant_a_connection' => new FakeConnectionFactory(),
                'tenant_b_connection' => new FakeConnectionFactory(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    PollingMetadata::create('mapped_topic')->setExecutionAmountLimit(1),
                    MultiTenantConfiguration::create('tenant', ['tenant_a' => 'tenant_a_connection', 'tenant_b' => 'tenant_b_connection'], DbalConnectionFactory::class),
                    DbalConfiguration::createWithDefaults()
                        ->withTransactionOnCommandBus(false)
                        ->withTransactionOnAsynchronousEndpoints(false)
                        ->withClearAndFlushObjectManagerOnCommandBus(false)
                        ->withDeduplication(false),
                ]),
            enableAsynchronousProcessing: [
                SimpleMessageChannelBuilder::createQueueChannel('mapped_topic'),
            ],
        );

        $ecotoneLite->sendCommandWithRoutingKey(
            'mappedArrived',
            'hello',
            metadata: ['source_topic' => 'orders.eu']
        );

        $ecotoneLite->run('mapped_topic', ExecutionPollingMetadata::createWithTestingSetup(1, 1));

        $headers = $ecotoneLite->sendQueryWithRouting('mappedLastReceivedHeaders');

        $this->assertNotNull($headers);
        $this->assertSame('tenant_b', $headers['tenant'] ?? null, 'Resolver must look up the tenant via the reference mapper');
    }
```

- [ ] **Step 3: Run the new test**

Run: `docker compose exec -T app vendor/bin/phpunit packages/Dbal/tests/Integration/MultiTenant/WithTenantResolverTest.php --filter test_resolver_uses_service_backed_mapper_via_reference_expression`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add packages/Dbal/tests/Fixture/MultiTenant/ExternalMessage/MappedTenantService.php packages/Dbal/tests/Integration/MultiTenant/WithTenantResolverTest.php
git commit -m "test(dbal): resolver supports reference()-based mapper expression"
```

---

### Task 10: Test — handler without the attribute is unaffected

**Files:**
- Modify: `packages/Dbal/tests/Integration/MultiTenant/WithTenantResolverTest.php`
- Create: `packages/Dbal/tests/Fixture/MultiTenant/ExternalMessage/UnannotatedTenantService.php`

- [ ] **Step 1: Create a fixture with no `WithTenantResolver`**

Create `packages/Dbal/tests/Fixture/MultiTenant/ExternalMessage/UnannotatedTenantService.php`:

```php
<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\MultiTenant\ExternalMessage;

use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Attribute\Parameter\Headers;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\QueryHandler;

/**
 * licence Apache-2.0
 */
final class UnannotatedTenantService
{
    private array $receivedHeadersList = [];

    #[Asynchronous('plain_topic')]
    #[CommandHandler('plainArrived', endpointId: 'plainArrivedEndpoint')]
    public function plainArrived(string $payload, #[Headers] array $headers): void
    {
        $this->receivedHeadersList[] = $headers;
    }

    #[QueryHandler('plainLastReceivedHeaders')]
    public function lastReceivedHeaders(): ?array
    {
        return array_shift($this->receivedHeadersList);
    }
}
```

- [ ] **Step 2: Append the test**

```php
    public function test_handler_without_resolver_attribute_is_unaffected(): void
    {
        $service = new \Test\Ecotone\Dbal\Fixture\MultiTenant\ExternalMessage\UnannotatedTenantService();

        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [\Test\Ecotone\Dbal\Fixture\MultiTenant\ExternalMessage\UnannotatedTenantService::class],
            [$service, 'tenant_a_connection' => new FakeConnectionFactory()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    PollingMetadata::create('plain_topic')->setExecutionAmountLimit(1),
                    MultiTenantConfiguration::create('tenant', ['tenant_a' => 'tenant_a_connection'], DbalConnectionFactory::class),
                    DbalConfiguration::createWithDefaults()
                        ->withTransactionOnCommandBus(false)
                        ->withTransactionOnAsynchronousEndpoints(false)
                        ->withClearAndFlushObjectManagerOnCommandBus(false)
                        ->withDeduplication(false),
                ]),
            enableAsynchronousProcessing: [
                SimpleMessageChannelBuilder::createQueueChannel('plain_topic'),
            ],
        );

        $ecotoneLite->sendCommandWithRoutingKey(
            'plainArrived',
            'hello',
            metadata: ['tenant' => 'tenant_a']
        );

        $ecotoneLite->run('plain_topic', ExecutionPollingMetadata::createWithTestingSetup(1, 1));

        $headers = $ecotoneLite->sendQueryWithRouting('plainLastReceivedHeaders');

        $this->assertNotNull($headers);
        $this->assertSame('tenant_a', $headers['tenant'] ?? null);
    }
```

- [ ] **Step 3: Run the test**

Run: `docker compose exec -T app vendor/bin/phpunit packages/Dbal/tests/Integration/MultiTenant/WithTenantResolverTest.php --filter test_handler_without_resolver_attribute_is_unaffected`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add packages/Dbal/tests/Fixture/MultiTenant/ExternalMessage/UnannotatedTenantService.php packages/Dbal/tests/Integration/MultiTenant/WithTenantResolverTest.php
git commit -m "test(dbal): unannotated handler is unaffected by resolver"
```

---

### Task 11: Run the full multi-tenant test suite

- [ ] **Step 1: Run every test that touches the multi-tenant module**

Run: `docker compose exec -T app vendor/bin/phpunit packages/Dbal/tests/Integration/MultiTenantConnectionFactoryTest.php packages/Dbal/tests/Integration/MultiTenant/ packages/Dbal/tests/Integration/DbalBusinessMethod/MultiTenantTest.php`

Expected: all tests pass (no errors, no failures).

- [ ] **Step 2: If anything red, stop and diagnose**

Do not proceed to the next task until everything in this scope is green. The most likely failure modes are: pointcut definition typo (`WithTenantResolver::class` vs full FQCN), wrong `MethodInterceptorBuilder::create` argument order, or a missing `use` import.

- [ ] **Step 3: Run the broader DBAL test suite to catch unintended interactions**

Run: `docker compose exec -T app vendor/bin/phpunit packages/Dbal/tests/`

Expected: no new failures compared to the pre-feature baseline. (Existing failures unrelated to this branch — if any — should be unchanged.)

---

### Task 12: Final tidy

- [ ] **Step 1: Re-read the new files for stray TODOs, debug fwrites, or unused imports**

Run: `git diff main..HEAD -- packages/Dbal/src packages/Dbal/tests | grep -E '(fwrite|var_dump|TODO|FIXME|XXX)' || echo 'clean'`
Expected: `clean`.

- [ ] **Step 2: Confirm the diff against main matches the spec's "Files touched" section**

Run: `git diff --stat main..HEAD`
Expected stat shows changes to (in addition to the spec doc already committed):
- `packages/Dbal/src/Attribute/WithTenantResolver.php`
- `packages/Dbal/src/MultiTenant/MultiTenantHeaderResolver.php`
- `packages/Dbal/src/MultiTenant/Module/MultiTenantConnectionFactoryModule.php`
- `packages/Dbal/tests/Fixture/MultiTenant/ExternalMessage/ExternalKafkaLikeService.php`
- `packages/Dbal/tests/Fixture/MultiTenant/ExternalMessage/MappedTenantService.php`
- `packages/Dbal/tests/Fixture/MultiTenant/ExternalMessage/TopicToTenantMapper.php`
- `packages/Dbal/tests/Fixture/MultiTenant/ExternalMessage/UnannotatedTenantService.php`
- `packages/Dbal/tests/Integration/MultiTenant/ExternalMessageTenantMappingTest.php`
- `packages/Dbal/tests/Integration/MultiTenant/WithTenantResolverTest.php`

(Plus the existing `BeforeInterceptorTenantWorkaroundTest.php` and `TenantHeaderInterceptor.php` which were committed as part of the brainstorming phase.)
