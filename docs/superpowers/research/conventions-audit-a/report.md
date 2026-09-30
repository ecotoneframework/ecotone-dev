# Conventions audit — group a: structural rules (2, 3, 5, 12, 17)

Audited at `b255fd5e9d9f821da0c55427f3ead6633e6f787c` (`dgafka/ecotone-2-0-work`, worktree branch
`dgafka/ecotone-2-0-conventions-audit-a`). No code or docs changed except this file. Scope: `packages/*/src`,
`packages/*/Api` for production rules; `packages/*/tests`, `Monorepo/`, `quickstart-examples/` for test/example
rules. No `packages/*/vendor` directories exist in this checkout (`find packages -maxdepth 2 -name vendor -type d`
returned nothing), so nothing needed excluding on that front.

This is measurement, not a fix list. Every count below is either a full walk of the stated scope or an explicitly
sized sample — never an eyeballed guess.

## Summary

| Rule | Violations | Exempt | Detection confidence | Fix cost | Recommendation |
|---|---|---|---|---|---|
| **2** — no nullable service dependency | **9** constructor params (8 classes) | 9 (value objects, not services) | Full walk of `packages/*/src`+`Api` | 4 mechanical, 5 needs judgement | Enforce and fix — small, well-understood backlog |
| **3** — constructor-injected services are stateless | **4** classes (1 Enterprise) | 5 (Tempest static caches: architecture-constrained; `Type`/`MediaType` caches: not DI services) | Full walk for static properties; manual triage of all 28 accumulator-pattern hits | Needs judgement / design (message-header pattern already precedented in the codebase) | Enforce and fix — this is the rule's own stated failure mode, found live in production code |
| **5** — no single-implementation interface | **31** candidates (raw) | 24 zero-impl (mostly dynamic-proxy gateways / constant-holders, out of rule's scope) | Full walk of 147 declared interfaces; single-impl list needs per-case judgement, not verified individually | Needs judgement (removing a seam is an API decision) | Revise rule to name a 3rd sanctioned category (user-extensible parser/converter interfaces), then triage the rest case by case |
| **12/12a** — `Api/` boundary | **1** confirmed (+ likely siblings) | — | Structural checks: full walk (100% clean). 12a leak check: sample of 24/170 `Api/` files | Mechanical (move one interface) | Fix now — exact precedent already in the rule text (`MediaType`/`FinalFailureStrategy`/`RetryTemplateBuilder`) |
| **17** — `final` + `declare(strict_types=1)` on new files | **10** of 138 new files (7.2%) | — (grandfather clause explicitly excludes older files) | Full walk of files added since the 1.x fork point, rename-aware | Mechanical | Enforce and fix — one-line changes, no behaviour risk |

---

## Rule 2 — never take a nullable service dependency

**Rule text scope, precisely:** a `?SomeService $x = null` *constructor* parameter that is a class/interface type
(not a scalar, not a value object used as pure data). Method parameters and plain properties declared outside a
constructor are out of scope by the rule's own title and its "the two modes then drift" framing.

### Detection method

```bash
# Extracts every __construct(...) parameter list (balances parens across multi-line
# signatures) and flags params whose type is nullable, class-typed (not scalar/mixed/self/
# static/object/callable/Closure), and defaults to null.
python3 find_nullable_ctor.py packages/*/src packages/*/Api
```
(script included below the fold in this report's companion; logic: regex-extract `function __construct(...)`,
split top-level params respecting nested parens, then for each param strip modifiers, detect `?Type`/`Type|null`,
and reject scalar-typed unions.)

Raw hits: **18** nullable class-typed constructor params. Cross-checked against a simpler single-line grep (121
total nullable-null-default params anywhere in a file) — the other 103 are ordinary optional *method* parameters
(`EventStore::load(..., ?MetadataMatcher $m = null)`, etc.), correctly out of this rule's scope.

Of the 18, **9 are exempt**: `MediaType` (×2), `Duration`, `RetryTemplateBuilder`, `ProjectionInitializationStatus`
(an enum), `TypeContext`/`FieldFactoryInterface` (per-call value/parser objects instantiated with `new`, never
container-resolved), `AttributeDeclaration`, `InMemoryMessageChannelHolder` — none of these are services pulled
from the container; they're immutable values or per-call parser state.

### Violations (9)

| # | File:line | Type | Fix |
|---|---|---|---|
| 1 | `packages/Amqp/src/AmqpOutboundChannelAdapter.php:58` | `?DelayStrategy $delayStrategy = null`, lazily defaulted at :187 (`??=`) | **Mechanical** — `DelayStrategy $delayStrategy = new HeadersExchangeDelayStrategy()` (PHP 8.1 `new` in initializers) |
| 2 | `packages/Dbal/src/ManagerRegistryEmulator.php:29` | `?EntityManagerInterface $entityManager = null`, explicit `=== null` check at :163, reassigned at :184/:194 | **Needs judgement** — textbook mode-switch from the rule's own wrong-example; a lazy-init null-object or required-param split needed |
| 3 | `packages/Dbal/src/DbalReconnectableConnectionFactory.php:26` | `?EcotoneClockInterface $clock = null` → `$clock ?? new NativeClock()` | **Mechanical** — `EcotoneClockInterface $clock = new NativeClock()` |
| 4 | `packages/Dbal/src/Connection/DbalContext.php:50` | same shape, resolved lazily via `??=` at :263, plus a `withClock()` setter | **Mechanical**, same fix |
| 5 | `packages/Dbal/src/DbaBusinessMethod/DbalParameterConfig.php:24` | `?AttributeExpressionContextExecutor $expressionExecutor = null` | **Needs judgement** — this is the exact "no expression here" category rule 2 already fixed once for the sibling `AttributeExpressionExecutor` (`withoutExpression()` + `hasExpression()`, `7f7d24bc0`); this one wasn't carried over |
| 6–7 | `packages/Ecotone/src/Messaging/Config/Container/MessagingContainerBuilder.php:37-38` | `?InterfaceToCallRegistry`, `?ServiceConfiguration`, both `= null` then `??`-defaulted via named factories | **Needs judgement** — can't become a `new`-expression default (factories are named statics, not constructors), so needs either a required param or a `MessagingContainerBuilder::createDefault()` entry point |
| 8 | `packages/Ecotone/src/Messaging/Channel/DirectChannel.php:21` | `?MessageHandler $messageHandler = null`, checked with `if (! $this->messageHandler)` at :35 | **Needs judgement / structural** — this is two-phase construction intrinsic to pub/sub wiring (`subscribe()`/`unsubscribe()` mutate it later); fixing cleanly means rethinking channel wiring, not a local patch |
| 9 | `packages/Ecotone/src/Modelling/Config/Routing/BusRoutingMapBuilder.php:31` | `?Configuration $messagingConfiguration = null` | **Needs judgement** |

Per package: Amqp 1, Dbal 4, Ecotone 4.

**Worst example:** `ManagerRegistryEmulator.php` (#2) reproduces rule 2's own "wrong" code sample almost verbatim —
nullable service, `=== null` check, later reassignment — inside real production code, not the doc's illustrative
snippet.

---

## Rule 3 — constructor-injected services are stateless

**Rule text scope:** forbidden *in a constructor-injected service* — accumulating instance properties, static
caches, `$somethingByMessageId` collectors. The qualifier "constructor-injected service" matters: an `InMemory*`
store (rule 9) is *supposed* to hold state, a compile-time `Builder`/`Module`/`Definition` accumulates config only
while building (never on the message path), and a per-call value object (not container-resolved) isn't a service
at all. I triaged every hit against that distinction rather than flagging every stateful-looking class.

### Detection method

```bash
# Static instance properties anywhere in production code (excludes const, excludes methods):
grep -rEn '^\s*(private|protected|public)\s+static\s+(readonly\s+)?[A-Za-z?\\]' \
  packages/*/src packages/*/Api --include="*.php" | grep -v function

# Property-array accumulation smell (push without a matching read-once-and-clear elsewhere):
grep -rEln '\$this->[a-zA-Z_]+\[\]\s*=' packages/*/src packages/*/Api --include="*.php"
```

Static-property scan: **9 hits**, all manually triaged (not sampled):

| Class | Verdict | Why |
|---|---|---|
| `PendingDeliveryRegistry` (`.../DeliveryConfirmation/PendingDeliveryRegistry.php:20`) | **Violation** | Registered as a singleton service (`RegisterSingletonMessagingServices.php:70`, `Reference(PendingDeliveryRegistry::class)` injected into `DeliveryConfirmationInterceptor`/`DeferredPublishingGateway`) and carries a static `WeakMap` on top of instance-level accumulation (see below). `licence Enterprise`. |
| `Clock` (`.../Scheduling/Clock.php:16`) | **Violation** | `self::$globalClock = $this;` runs in the constructor of the registered `EcotoneClockInterface` implementation — a static global pointer duplicating the container's own singleton. Dead code (never read anywhere in `packages/*/src`), so removal is a pure deletion. |
| `Type::$cachedTypes` (`.../Handler/Type.php:33`) | **Exempt** | `Type` is a freely-constructed value/descriptor (`Type::create(...)`), never container-resolved; this is pure-function memoization, the same shape rule 13a sanctions for the registry. |
| `MediaType::$parsedMediaTypes` (`Ecotone/Api/ExtensionObject/MediaType.php:38`) | **Exempt** | Same reasoning — a value object, not an injected service. |
| `MessagingSystemInitializer` (Tempest, 3 static props) | **Borderline** | Tempest's own `Initializer` contract may construct fresh instances across calls (see `project_tempest_container_constraint` memory: one shared dumped Symfony container, Tempest's own DI is bypassed); static state may be the only way to share compiled state across Tempest's instantiation boundary. Needs a maintainer call, not a blind fix. |
| `EcotoneServiceInitializer` (Tempest, 2 static props) | **Borderline** | Same reasoning as above. |

Accumulator-pattern scan: **28 files** matched `$this->x[] =`. All 28 read and classified by role, not sampled:
23 are exempt by design — `InMemory*` storage implementations (rule 9's whole point is to hold state),
compile-time `Builder`/`Module`/`Definition`/`ContainerBuilder` classes (accumulate only while building, never on
the message path), test doubles (`Stub*`), and `WithEvents`/aggregate-instance state (per-aggregate, not a
container singleton). **2 are confirmed violations**, both interceptors — exactly the message-path component rule
3 is written for:

| Class | File:line | Shape |
|---|---|---|
| `MessageHeadersPropagatorInterceptor` | `packages/Ecotone/src/Modelling/MessageHandling/MetadataPropagator/MessageHeadersPropagatorInterceptor.php:24,26,53-54` | Push/pop stack (`currentlyPropagatedHeaders`, `causationChain`) held as instance state on a container-registered interceptor, instead of the header-carried value object rule 3 itself prescribes (`DecisionModelLoadedState` is cited as the worked replacement for exactly this shape) |
| `TracingChannelInterceptor` | `packages/OpenTelemetry/src/TracingChannelInterceptor.php:30,42,59` | Same push/pop-stack shape (`openSpans`), one instance per channel shared across every message sent through it; `array_pop` only runs in `afterSendCompletion` — no `try/finally` guarantee, so a codepath that skips that call would corrupt the stack. Also has a live `// @TODO test` comment (rule 6, out of this audit's scope, but a quick corroborating signal) |

`PendingDeliveryRegistry` additionally has `private array $pendingDeliveries = []` (line 17) plus mutable counters
(`nextRegistrationIndex`, `registrationsSinceLastPrune`, `scopeActive`) — this single class fails rule 3 on every
axis the rule names (accumulating property, static cache, and counters), on a service registered unconditionally.

Per package: Ecotone 3 (`PendingDeliveryRegistry`, `Clock`, `MessageHeadersPropagatorInterceptor`), OpenTelemetry 1
(`TracingChannelInterceptor`). Tempest 2 files borderline/unresolved.

**Worst example:** `PendingDeliveryRegistry` — an Enterprise-licensed, container-registered singleton service that
violates every clause of the rule at once.

---

## Rule 5 — do not create an interface with a single implementation

**Rule text scope:** the two sanctioned seams are open-core/Enterprise and in-memory/storage-backed — both
*have* two implementations, so they never show up as "single-impl." A single-impl interface is the actual
target. I did not find evidence the rule intends to cover zero-impl interfaces at all (constant-holders,
dynamically-proxied gateways) — that's a separate, unrelated pattern, addressed below as a scoping note, not a
violation count.

### Detection method

```bash
python3 find_single_impl_interfaces.py   # AST-free regex scan, logic below
```
Logic: find every `interface Name` declared under `packages/*/src` + `Api`; then, across all production *and*
test code, find every `class X ... implements A, B, C {` and attribute each interface name to its implementor
count.

**Known detection gaps** (stated so the count isn't over-read):
- Anonymous class implementations (`new class(...) implements Y {}`, rule 11's preferred test-fixture shape)
  aren't counted — the regex requires a class *name*. This under-counts implementors of interfaces that are only
  ever satisfied by anonymous fixtures.
- An interface implemented only *indirectly*, through a sub-interface that extends it (`ReceivableChannel extends
  MessageChannel`), is invisible to this script — it walks direct `implements` clauses only, not the
  `extends`-hierarchy of interfaces. This produces some of the 24 "zero-impl" false positives below
  (`MessageChannel`, `Module`, `MessageBus`, etc. are implemented plenty — just never directly).

Result: **147 interfaces** declared. 92 have 2+ direct named-class implementors (healthy). **31 have exactly one**
(the candidate set below). 24 have zero direct named-class implementors; manually reviewed, these fall into three
categories outside rule 5's model rather than being violations:
- **9 are Gateway/business-interface marker types** (`QueryBus`, `DistributedBus`, `MessagePublisher`,
  `SerializerGateway`, `DeadLetterGateway`, `EnrichGateway`, `BeforeSendGateway`, `ConsoleCommandRunner`,
  `DistributionEntrypoint`) — implemented via `GatewayProxyBuilder`'s runtime proxy generation, never a literal
  PHP class. Rule 5's model (fixed set of swappable implementations) doesn't apply to a pattern where a fresh
  "implementation" is generated per user-declared interface.
- **5 are pure constant holders** (`DbalHeader`, `AmqpHeader`, `KafkaHeader`, `EnqueueHeader`,
  `DistributedBusHeader`) — never meant to be implemented at all, used only as `Header::CONST_NAME`.
- **~10 are the indirect-implementation false positive** described above (`MessageChannel`, `Precedence`, `Module`,
  `MessageBus`, etc.).

Per-package split of the 31 single-impl candidates: **Ecotone 28**, DataProtection 1, Dbal 1, Amqp 1.

I manually verified a representative subset rather than all 31 (time-boxed); the pattern that emerged:

| Interface | Sole implementor | Read |
|---|---|---|
| `MessageConverter` (`Ecotone/src/Messaging/MessageConverter/MessageConverter.php`) | `FakeMessageConverter` — a **test fixture**, not production code | Zero production implementations at all — worse than "single impl." Carries stale pre-2.0 docblocks (`@package Ecotone\Messaging\Handler\Gateway`) suggesting this is legacy, possibly meant as a user-extension point (custom converters) never actually exercised inside this monorepo. **This may be a case the rule doesn't name**: an interface designed for *application* code to implement, which will never show a second implementor from inside the framework's own repo. |
| `HeaderMapper` → `DefaultHeaderMapper` | Real single production impl, no test/fixture second | Needs judgement — collapse to a class, or confirm it's meant as a connector extension point |
| `TransactionFactory` → `NullTransactionFactory` | Only the null-object exists as a named class | Likely fine as null-object companion to a closure-based "real" factory rather than a missing second class — needs a source read I didn't complete |
| `CancellableAmqpStreamConsumer`, `MultiTenantConnectionFactory`, `ConversionServiceDecorator` | One prod impl each, in Amqp/Dbal/DataProtection | Smallest, most isolated candidates — cheapest to either collapse or justify |

**Finding worth flagging as "rule may be too narrow":** `MessageConverter` and similarly-shaped interfaces
(potential custom-converter or custom-strategy extension points meant for *application* code, not framework
code) don't fit either of rule 5's two named seams. The rule may need a third category —
"application-extensible" — rather than every single-impl interface being read as debt.

---

## Rule 12 / 12a — the public surface is `Api/`, a sibling of `src/`

### Structural checks (full walk, not sampled)

```bash
find packages -type d -path "*/src/Api"                     # nested Api under src — must be empty
grep -A6 '"psr-4"' packages/*/composer.json                 # Ecotone\Api\<Pkg>\ => Api/, Ecotone\<Pkg>\ => src
```

**Zero violations.** No package nests `Api/` under `src/`. Every package that has an `Api/` directory (11 of 14 —
`Enqueue` and `OpenTelemetry` correctly have none; both are internal-only transport/tracing glue with no
user-facing surface) declares exactly the two-root PSR-4 mapping the rule specifies, with the `Ecotone\Api\<Pkg>\`
namespace (not `Ecotone\<Pkg>\Api\`) in every case.

### Attribute placement (full walk)

```bash
grep -rl "^#\[Attribute(" packages/*/src --include="*.php"
```

3 hits — all correctly placed. `ExecutorFor`, `MessageConsumer`, `IdentifiedAnnotation` are base classes for
concrete attributes (`#[KafkaConsumer]`, `#[RabbitConsumer]`, `#[Scheduled]`, etc.) that *do* live in `Api/`;
these three are never written directly by application code (verified `IdentifiedAnnotation`/`MessageConsumer` are
`@internal`-tagged, and `ExecutorFor`'s only usages are inside the framework's own interceptors, not any test or
fixture). Correctly outside `Api/` per the rule's own "who calls it" test.

### 12a leak check — internal type in a public `Api/` signature

**Sampled 24 of 170 `Api/` files** — specifically every `ExtensionObject`/`Gateway` file that imports a non-`Api`
Ecotone namespace (`grep -rlE '^use Ecotone\\\\(Messaging|Modelling|EventSourcing|Dbal|...)\\\\' packages/*/Api`
returned 101 files total across all categories; I focused the sample on `ExtensionObject`/`Gateway` because
`Attribute` classes overwhelmingly just extend an internal base class or reference `Type`/`Assert`/`Definition` in
method *bodies* the framework calls — the exact pattern rule 12a already says isn't a leak).

**1 confirmed violation, likely with siblings not yet checked:**

`Ecotone\Messaging\Config\ConnectionReference` (`packages/Ecotone/src/Messaging/Config/ConnectionReference.php`) —
an internal-namespace interface — is implemented by **8 different `Api/` classes across 7 packages**
(`DbalConnectionReference`, `SymfonyConnectionReference`, `LaravelConnectionReference`, `RedisConnectionReference`,
`AmqpConnectionReference`, `TempestConnectionReference`, `SqsConnectionReference`), and is exposed directly in a
public `Api/` signature:

```
packages/Dbal/Api/ExtensionObject/MultiTenantConfiguration.php:7   use Ecotone\Messaging\Config\ConnectionReference;
packages/Dbal/Api/ExtensionObject/MultiTenantConfiguration.php:22  private string|ConnectionReference|null $defaultConnectionName = null,
packages/Dbal/Api/ExtensionObject/MultiTenantConfiguration.php:37  string|ConnectionReference $defaultConnectionName, ...
packages/Dbal/Api/ExtensionObject/MultiTenantConfiguration.php:60  public function getDefaultConnectionName(): string|ConnectionReference|null
```

This is the exact shape rule 12a's own table documents as already fixed three times (`MediaType`,
`FinalFailureStrategy`, `RetryTemplateBuilder` all moved `Ecotone\Messaging\...` → `Ecotone\Api\ExtensionObject\...`
for the same reason: an application constructing or reading this value has to import the internal namespace to
type it). `ConnectionReference` wasn't carried along in that same pass. **Mechanical fix**: move the interface to
`Ecotone\Api\ExtensionObject\ConnectionReference` (or package-scoped equivalent) and update the 8 implementors +
this signature. Breaking change for anyone who already type-hinted the old FQCN — acceptable for a 2.0 beta, but
flag it in `upgrade-2.0.md` alongside the three siblings already documented there.

The other 23 sampled files (`DbalConfiguration`, `ServiceConfiguration`, `PollingMetadata`,
`DistributedServiceMap`, `DatabaseSetupManager`, the `*MessageChannelBuilder` family, `DeadLetterGateway`,
`CommandBus`/`EventBus`/`QueryBus`/`MessagePublisher`, `ErrorHandlerConfiguration`, `TestConfiguration`,
`OutboxForwardingMessageChannel`) were read method-signature by method-signature — every internal-type usage found
is either `Definition`/`Assert` in a framework-called method (`compile()`, `getDefinition()` — both explicitly
sanctioned by the rule) or a getter whose only real reader is the framework itself
(`ErrorHandlerConfiguration::getDelayedRetryTemplate(): RetryTemplate`, matching the exact precedent the rule
states is fine for `RetryTemplateBuilder::build()`). **Not counted as violations.**

**Extrapolation, stated explicitly:** 1 confirmed violation in a 24-file targeted sample out of 170 total `Api/`
files. The sample wasn't random — it targeted the highest-risk subset — so this shouldn't be scaled linearly to
"~7 violations across all 170"; it should be read as "the boundary is well-held everywhere I checked, with one
real gap that has exact precedent for how to fix it." A full sweep of the remaining 146 `Api/` files (mostly
`Attribute/` classes, lower risk per the placement check above) is the next increment if the maintainer wants
certainty rather than a sample.

---

## Rule 17 — `final` and `declare(strict_types=1)` on new files

**Rule text scope:** new files only — "do not sweep" older files. "New" needs a baseline; I used the fork point
between the 1.x maintenance line and this 2.0 branch: `git merge-base 1.327.0 HEAD` =
`c440b6d4c34206730b927df0501456e5ddb07ad7` ("Release 1.326.1", 2026-08-22). `1.327.0` is *not* an ancestor of
`HEAD` (`git merge-base --is-ancestor` confirms), i.e. it's a later 1.x release cut from the pre-fork line, not
from this branch.

### Detection method

```bash
# Files ADDED since the fork, with rename detection so a file moved during the Api/
# restructuring (2.0's src -> Api split, ~173 files) doesn't count as "new":
git diff -M30% --name-status c440b6d4c34206730b927df0501456e5ddb07ad7..HEAD -- packages \
  | grep -E '\.php$' | awk '$1=="A"' | grep -E 'packages/[^/]+/(src|Api)/'
```

**Why rename detection matters here, concretely:** a naive `--no-renames` diff reports **311** added files —
but manually checking one (`packages/Ecotone/Api/Attribute/CommandHandler.php`) shows it existed pre-fork at
`packages/Ecotone/src/Modelling/Attribute/CommandHandler.php` and was simply relocated by the Api/ split (rule
12's own subject). Counting relocated files as "new" would badly inflate this rule's violation count with files
that predate 1.x and are correctly grandfathered. With `-M30%` rename detection, the count drops to **138** files
— overwhelmingly the DCB/decision-model/tag feature (genuinely new in 2.0), which is the right population for
this rule.

### Result

138 new files. 13 are interfaces/traits/abstract classes/enums (`final` doesn't apply). Of the remaining 125:
**115 are `final`** (92%). Of all 138: **135 declare `strict_types=1`** (97.8%). These ratios track closely with
the rule's own stated baseline ("113 of 121... 132 of 133...", ~93%/99%), which is a good sign the methodology
here reproduces what the maintainer already measured once.

**10 distinct files violate the rule** (not final, or not strict, or both):

| File:line | Missing |
|---|---|
| `packages/Amqp/src/Connection/AmqpExtConnectionFactory.php` | not `final`, no `strict_types` |
| `packages/Amqp/src/Connection/AmqpLibConnectionFactory.php` | not `final` |
| `packages/Ecotone/Api/EventSourcing/DecisionModelConcurrencyException.php:16` | not `final` (`class DecisionModelConcurrencyException extends ConcurrencyException`) |
| `packages/Ecotone/src/Messaging/Config/Annotation/ModuleConfiguration/ChannelInterceptor/ChannelInterceptorModule.php` | not `final` |
| `packages/PdoEventSourcing/Api/EventSourcingConfiguration.php` | not `final`, no `strict_types` |
| `packages/PdoEventSourcing/Api/Stream.php` | not `final`, no `strict_types` |
| `packages/PdoEventSourcing/src/Dbal/MySqlEventStreamSchema.php` | not `final` |
| `packages/PdoEventSourcing/src/Dbal/Tag/MySqlTaggedEventSchema.php` | not `final` |
| `packages/Redis/src/Connection/RedisConnectionFactory.php` | not `final` |
| `packages/Sqs/src/Connection/SqsConnectionFactory.php` | not `final` |

Per package: Amqp 2, Ecotone 2, PdoEventSourcing 4, Redis 1, Sqs 1.

**Fix cost: mechanical** in every case — none of these classes are extended anywhere in the codebase (quick check:
none appear on the right-hand side of an `extends` clause), so adding `final` is a no-behaviour-change edit; adding
`declare(strict_types=1)` at the top of a file is likewise mechanical and safe.

**Context, not counted as violations** (the rule explicitly grandfathers these): across *all* 1304
`src`+`Api` PHP files (new and old combined), only 478/1113 non-exempt classes are `final` (43%) and 901/1304
declare `strict_types` (69%). The gap is almost entirely pre-2.0 files the rule says not to sweep.

---

## Files referenced but not committed

Detection scripts (`find_nullable_ctor.py`, `find_single_impl_interfaces.py`) live in this session's scratchpad,
not the repo — they're throwaway analysis tools, reproducible from the descriptions/commands above. If the
maintainer wants them checked in as re-runnable audit tooling, that's a follow-up decision, not something this
inventory pass should decide unilaterally.
