## 2. Never take a nullable service dependency

A `?SomeService $service = null` constructor parameter always becomes a null check that is really a mode switch,
and the two modes then drift. Register services unconditionally and decide at runtime.

```php
// wrong
public function __construct(private ?AttributeExpressionExecutor $executor = null) {}
// ... later
if ($this->executor !== null) { /* expression mode */ } else { /* payload mode */ }
```

There are four ways to remove one. Pick by what the null actually meant:

| The null meant | Do this | Precedent |
|---|---|---|
| Two genuinely different behaviours | **Split the class in two** | `65ba2a852` — `InMemoryEventSourcedRepository` keeps array storage, `EventStoreEventSourcedRepository` requires a store; shared `canHandle()` moves to a trait |
| "There is no expression / no value here" | **A null-object factory + a `has*()` question** | `7f7d24bc0` — `AttributeExpressionExecutor::withoutExpression()`, then `hasExpression()` instead of `!== null` |
| Nothing — every real call site passes one | **Make the parameter required** | `3e9e92d56` — `InMemoryEventStore` and `DbalEventStore` dropped `?AppendStrategy`/`?TagCollaborator` defaults |
| Open core versus Enterprise | **Two services, one chosen in the container** | `LicenceDecider::prepareDefinition()` (rule 7) |

Services are registered unconditionally, always:
`packages/Ecotone/src/Messaging/Config/Container/Compiler/RegisterSingletonMessagingServices.php` registers every
core service with no conditions at all, `LicenceDecider` included — the licence is a constructor argument to it,
not a reason to skip the registration.

Evidence: the `implement-no-nullable-services` worktree (`fb35cf316`), and `eb859ceb8` in `upgrade-2.0.md`.

The rule predates the code it had to correct, and correcting it cost a worktree of its own: 6 commits over four
classes — `InMemoryEventStore`, `DbalEventStore`, `DecisionModelParameterLoader`, `InMemoryEventSourcedRepository` —
deleting defaults that, in the commits' own words, "every real construction site already passed concrete instances"
for (`3e9e92d56`, `7f7d24bc0`, `65ba2a852`, `84e70e8c4`, `e15bd042f`, `eb859ceb8`). There is nothing here to
discover later, only work to redo, so check it while you are writing the constructor.

### 2a. Never default a dependency either

`EcotoneClockInterface $clock = new NativeClock()` is rule 2 with the null written out. The parameter is optional,
so the class has two ways to run — the injected one and the fallback — and nothing tells a construction site which
one it chose. The sites that pass the dependency and the sites that take the default then diverge exactly like the
two branches of a null check, except that no `if` marks the fork. Every dependency is a required constructor
parameter, at every construction site, and the container is what passes it: a module registers it with a
`Reference`, a runtime caller passes the instance it was itself given.

```php
// wrong — eight construction sites, two pass the clock, six silently run on real time
public function __construct(ConnectionFactory $factory, EcotoneClockInterface $clock = new NativeClock()) {}

// right — a site that cannot name a clock cannot be written
public function __construct(ConnectionFactory $factory, EcotoneClockInterface $clock) {}
```

Evidence: the 2.0.0-beta.2 clock regression. `DbalReconnectableConnectionFactory` and `DbalContext` both took a
defaulted clock. The two channel adapters passed the container's clock, while the transaction interceptor, dead
letter, document store, deduplication, batch forwarding and database setup took the default. The connection cache is
keyed by the wrapped factory, so the first caller's clock won, the transaction interceptor was always first, and a
DBAL consumer on real time never saw a message the pinned test clock had made due. In 1.x the context read a global
`Clock::get()`, so every site agreed by accident; 2.0 made the clock a dependency, and a default turned each missed
site into a silent second mode.

The same shape hid in a value object: `MessageHeaders::create()` filled a missing timestamp from a fresh
`NativeClock`, so a `#[Scheduled]` method's result and `sendDirectToChannel()` carried real time inside a test
pinned to another date. The fix was the same: `MessageBuilder` became the container service that holds the clock
and stamps on `build()`, and the value object refuses a missing timestamp instead of inventing one
(`MessageTimestampFromTestClockTest`, `MessageHeadersWithoutTimestampTest`).

A third copy sat in the event stores: `DbalEventStore` wrote `created_at` from real `now` when an appended event had
no timestamp, and `InMemoryEventStore` recorded none, so the two stores disagreed and a pinned test loaded back a
time it never set. Both stores now take the clock and stamp the metadata before recording
(`EventStoreConformanceTest::test_an_event_appended_without_a_timestamp_is_stamped_by_the_clock_ecotone_was_given`).
Where a site genuinely cannot be given the dependency — `DbalContext` is built by the connection factory an
application constructs from a DSN — the class does not default it: the one path that has it sets it, and the accessor
refuses with a message naming that path (`DbalContextWithoutClockTest`).

---

## 3. Constructor-injected services are stateless

A service reached through the container is a singleton for the life of the process. Anything it remembers between
calls leaks into the next message, the next transaction, and the retry of a message that already failed.

Forbidden in a constructor-injected service: instance properties that accumulate, static caches, and
`$somethingByMessageId` collectors.

**Where mutable state goes instead: a function-scoped, immutable value object.**
`packages/Ecotone/src/Modelling/DecisionModel/DecisionModelLoadedState.php` is the worked example — a `final`
class of `readonly` properties, built once per handler invocation by a before-interceptor and carried on a message
header, read by the converter, the append interceptor and `SaveAggregateService`. It replaced two singleton
collectors keyed by message id (`6b42c7a9b`).

The maintainer's own record of why, in `docs/superpowers/specs/2026-09-20-dcb-design.md` (2026-09-28 row):

> Services injected through constructors must be stateless: the snapshot tracking leaked into the next transaction
> on the same connection, and the collectors leaked conditions/instances across query handlers, converter failures
> and retries with the same message id.

Static caches count. `a98c23fa8` removed `DecisionModelReflection`'s static caches; reflection facts are read on
demand. `ca8a1b0fc` removed per-connection snapshot tracking from `DbalTagVersionRegister`.

Statelessness is a testable property: see
`packages/Ecotone/tests/Modelling/DecisionModel/DecisionModelStatelessExecutionTest.php` (`6b42c7a9b`), which drives
the same handler repeatedly and asserts the later executions are unaffected by the earlier ones.

**A mechanism that remembers anything between calls ships its leak test as the RED commit**, before the mechanism
is designed rather than after a later unit trips over it. Two of the three DCB friction reports name this
independently as the most-repeated corrective pattern in their scope. `424220979` ("InnoDB own-bump tracking must
not leak into the next transaction") is exactly the test that should have gated the tracking `ca8a1b0fc` deleted
one commit later — and it landed two units downstream of the unit that built it, so the cost fell on somebody
else's schedule. Some of these bugs are only provable under real contention on a real connection pool, which is
the reason to write that test first, not a reason to skip it.

---

## 3a. State a service cannot avoid lives in `ExecutionScope`, never in a property

Rule 3 removes state. Some state cannot be removed, because it is ambient: when a handler publishes an event, the
event bus has no message in its hands to read the headers of the command being handled from, so something has to
remember "the message this execution is handling". The same holds for "is a transaction open", "is a collector
scope active", "which tenant is active", "which spans are open", "which database connection does this execution
hold".

Kept in a property, that state is shared by everything the process runs. Under php-fpm and a CLI consumer the
process handles one message at a time, so a stack that is pushed and popped in `finally` looked safe. Under Swoole
or OpenSwoole with coroutines, and under fibers, one process handles several messages interleaved: measured on
OpenSwoole 26.2 with 300 interleaved requests, 296 event handlers received another request's `tenant` header, and a
request that answered "ok" lost its row because it ran inside the transaction of a request that rolled back.

`Ecotone\Messaging\Execution\ExecutionScope` gives each execution its own state: the coroutine's, the fiber's, or
the process's when neither runs.

Wrong:

```php
final class MessageHeadersPropagatorInterceptor
{
    private array $currentlyPropagatedHeaders = [];

    public function storeHeaders(MethodInvocation $methodInvocation, Message $message)
    {
        $this->currentlyPropagatedHeaders[] = $message->getHeaders()->headers();
        try {
            return $methodInvocation->proceed();
        } finally {
            array_pop($this->currentlyPropagatedHeaders);
        }
    }
}
```

Right:

```php
final class PropagatedHeaders
{
    public array $headersOfHandledMessages = [];
}

final class MessageHeadersPropagatorInterceptor
{
    public function storeHeaders(MethodInvocation $methodInvocation, Message $message)
    {
        $propagated = ExecutionScope::stateOf($this, static fn () => new PropagatedHeaders());
        $propagated->headersOfHandledMessages[] = $message->getHeaders()->headers();
        try {
            return $methodInvocation->proceed();
        } finally {
            array_pop($propagated->headersOfHandledMessages);
        }
    }
}
```

Three things to hold to:

- Fetch the state on every use. A state object cached in a property is the property again.
- The owner passed to `stateOf()` is the service itself, so two instances of one class (one collector per channel)
  keep separate state.
- A state object that holds a resource returns it in `__destruct`, which runs when the execution ends
  (`Ecotone\Dbal\Connection\PooledConnection` returns its connection to the pool that way).

The test is the fiber interleaving, at the userland level: a handler calls `Fiber::suspend()`, the test sends a
second message while the first is suspended, resumes, and asserts through a query handler that each saw only its
own state. `packages/Ecotone/tests/Messaging/Execution/InterleavedExecutionsTest.php` and
`packages/Dbal/tests/Integration/InterleavedExecutionsTest.php` are the worked examples; it needs no Swoole.

State that belongs to the process, not to a message, is the exception and stays in a property: a pool that hands a
resource from one execution to the next (`Ecotone\Dbal\Connection\ConnectionPool`, the open channels
`Ecotone\Amqp\AmqpReconnectableConnectionFactory` keeps for the next execution) and a record of which execution
holds what (`DbalTransactionInterceptor::$executionsHoldingTransaction`, a `WeakMap` keyed by the execution so it
empties itself). The test is the same question asked of the property: if two messages are handled at once, does it
hold anything of either message? A pool of idle connections does not; a stack of the handled message's headers
does. Such state ships with the same interleaving test.

A coroutine a handler starts is a new execution with a copy of the context it needs and none of the resources: a
state class that implements `Ecotone\Messaging\Execution\CopiedToChildExecution` is copied into it
(`PropagatedHeaders` copies the headers of the message being handled, which carry the tenant and the correlation id),
every other state starts empty, so the child takes its own connection and is in no transaction. Implement the
interface only for read-only context; a transaction flag, a collector or a connection must not be copied. Fibers
have no parent to copy from.

What is not covered: an object the application hands Ecotone (a handler, a Doctrine `EntityManager`, a
framework-owned connection) is one instance per process, and Ecotone cannot scope it. Where sharing it would lose
data, refuse it with a message naming the ways out, as `DbalTransactionInterceptor` does for a transaction another
execution already holds on the same connection.

---

## 4. Do not mix queries with writes

A method that returns a value must not change state, and a method that changes state returns `void`. The name has
to say which it is.

Three shapes this went wrong in, all fixed in `4bb23eb51`:

- **A read ran DDL.** `loadByCriteria()` used to create the tag tables it was about to read. Now a missing table
  raises the setup `ConfigurationException` (rule 1a) and reads never issue DDL.
- **A mutator returned a value.** Tag-version bumps, the backfill and the in-memory append now return `void`.
- **A resolver mutated a static cache.** `MessageTagValueResolver` no longer does.

**When a name hides a write, rename it.** `getRecordedEvents()` cleared the buffer it returned, and agents wrote
`assertCount(2, ...)` after a second command and were wrong. It is now `popRecordedEvents()`, and every destructive
reader on `packages/Ecotone/Api/Lite/Test/FlowTestSupport.php` is `pop*`: `popRecordedCommands()`,
`popRecordedEventHeaders()`, `popRecordedMessagesFrom()`. This was decided as D2 in
`docs/superpowers/specs/2026-09-15-agent-detours-2-0-design.md`, with no aliases kept.

---

## 5. Do not create an interface with a single implementation

An interface in this codebase marks a real seam, and there are three kinds: **open core versus Enterprise**
(`AggregateMethodInvoker`, `DbalTagCollaborator`), **in-memory versus storage-backed** (`EventStore`,
`StreamSource`), and **a dependency inverted across a package or layer boundary** — core must not name a class
from an optional package, and `Api` must not name a platform-specific `src` class. If there is one implementation
and none of the three applies, the class is the type.

The third kind is the narrow one, so it carries its own test: **would removing the interface force a reference in
the wrong direction?** If not, it is not this category.

- `OutboxForwardingChannel` has one implementation, `OutboxForwardingMessageChannel` in Dbal's `Api`, and
  `MessagingSystemConfiguration` in core asks `instanceof OutboxForwardingChannel`. Collapsing it makes
  `packages/Ecotone` name a class from the optional Dbal package.
- `TerminationListener` has one implementation, `PcntlTerminationListener`. Collapsing it put
  `PcntlTerminationListener` into `Api/Projecting/ProjectingManager`'s constructor, so the public surface declared
  it needs the pcntl implementation rather than something that can answer `shouldTerminate()`. Collapsed in
  `85ca52eda`, reverted in `2866f6794` once that reached review.

The technique for removing one without reintroducing the construction cycle that motivated it: **pass the concrete
class as a method argument, not a constructor argument.**

```
// wrong: an interface that exists only so a collaborator can call back into its caller
interface InMemoryStreamAccess { ... }          // one implementation: InMemoryEventStore

// right
public function append(InMemoryEventStore $store, ...): void   // per call, never stored
```

Evidence: `84e70e8c4` (drops `InMemoryStreamAccess`), `e15bd042f` (drops `DbalEventRowAccess`), and
`docs/superpowers/specs/2026-09-28-dcb-readability-review.md` §4, which flags `AppendableStore` as "an interface
that exists only to let the strategy call back into its caller".

---

## 13. Configuration is attributes plus `#[ServiceContext]`

No YAML and no XML for Ecotone configuration — the only YAML in `packages/` is the Symfony bundle's own framework
wiring. Users configure declaratively with attributes, and programmatically with a `#[ServiceContext]` method
returning an extension object:

```php
final class EcotoneConfiguration
{
    #[ServiceContext]
    public function dbal(): DbalConfiguration
    {
        return DbalConfiguration::createWithDefaults()->withTransactionOnCommandBus(true);
    }
}
```

A module reads them with `ExtensionObjectResolver::resolve(MyConfig::class, $extensionObjects)`.

Configuration is **compiled into a container**, so anything a module registers has to be expressible as a
`Definition` rather than a constructed object. A value object that needs to survive compilation implements
`DefinedObject` (`packages/Ecotone/src/Messaging/Config/Container/DefinedObject.php`) and returns a `Definition`
naming its class, constructor arguments and optional factory. `LicenceDecider::prepareDefinition()` and
`ExpressionLocation::definitionFor*()` are the patterns to copy.

**Module registration is explicit, not discovered.** A new module class must be added to the right
`ModuleClassList` constant, and a new package's name to `ModulePackageList` (the constant) and to `ModuleClassList`
(`packageNames()`, and the `forPackage()` match arm). `ModulePackageList` is `Ecotone\Api\ExtensionObject\ModulePackageList`,
because an application names its constants in `EcotoneLite::bootstrap(..., enabledModules: [...])` and the framework's `enabledModulePackageNames` key; the lookups only the
framework calls stay in the internal `ModuleClassList` (rule 12a). Modules implement `AnnotationModule`, carry `#[ModuleAnnotation]`, and
are `final`. [creating-a-module.md](../../framework-module-scaffolding/references/creating-a-module.md) has the full scaffold — including the `NoExternalConfigurationModule`
base class and the `AnnotationFinder` API — and is the place to look rather than this file.

### 13a. Class metadata comes from the registry, not from fresh reflection

`InterfaceToCall` and `ClassDefinition` are the metadata layer. They exist so nothing else has to build a
`ReflectionClass`, and `InterfaceToCallRegistry` memoizes both —
`packages/Ecotone/src/Messaging/Handler/InterfaceToCallRegistry.php` keys `getFor($class, $method)` and
`getClassDefinitionFor($type)` by name and returns the same instance on every later ask. A module or builder that
reflects for itself pays the cost again and gets an object the rest of the framework cannot reuse.

So during configuration building, ask the registry `prepare()` already gave you:

```php
// wrong — the same facts the registry already holds, re-derived
$attributes = (new ReflectionClass($className))->getAttributes(EventTag::class);

// right
$interfaceToCall = $interfaceToCallRegistry->getFor($className, $methodName);
$interfaceToCall->getMethodAnnotationsOf(EventTag::class);
$interfaceToCallRegistry->getClassDefinitionFor(Type::object($className));
```

`packages/Ecotone/src/Modelling/AggregateFlow/SaveAggregate/AggregateResolver/AggregateDefinitionResolver.php:29`
and `packages/Ecotone/src/Modelling/Config/Routing/BusRoutingMapBuilder.php:94` are the shape for the second one.

**And on the message path, take the metadata as a constructor argument rather than reading it.** A `Definition`
that needs an `InterfaceToCall`, one of its parameters, or an attribute instance names it as a reference —
`InterfaceToCallReference`, `InterfaceParameterReference`, `AttributeReference` — and the
`RegisterInterfaceToCallReferences` compiler pass
(`packages/Ecotone/src/Messaging/Config/Container/Compiler/RegisterInterfaceToCallReferences.php`) walks every
definition's arguments and method calls and registers each one as its own container definition. The reflection runs
once, while compiling; the compiled container holds the result, and the runtime service receives it already built.
`packages/Ecotone/src/Modelling/AggregateFlow/LoadAggregate/LoadAggregateServiceBuilder.php:77` and
`packages/Ecotone/src/Projecting/Config/ProjectingAttributeModule.php:184` are the pattern; 36 places in
`packages/*/src` build an `InterfaceToCallReference` this way.

Raw reflection has not disappeared — 31 files under `packages/*/src` still build a `ReflectionClass` — but look at
which ones: the layer that *produces* this metadata (`TypeResolver`, `ClassDefinition`, `InterfaceToCall`,
`AttributeResolver`, the annotation finders), and compile-phase modules, builders and guards asking something the
metadata layer does not expose, such as `class_exists()` or `isAbstract()`. The line is not "never reflect". It is
**nothing on the message path reflects, and nothing re-derives what the registry already holds.**

If a value genuinely is not knowable until a message arrives, read it on demand and **do not cache it** — a cache
on a container service is rule 3, and both attempts at one were deleted rather than kept: `a98c23fa8` removed
`DecisionModelReflection`'s static caches, and `4bb23eb51` stopped `MessageTagValueResolver` mutating one. That is
the reason to move the lookup to compile time rather than to memoize it at runtime: the memo is not available to
you.

---

## 15. Orchestrating methods, with the SQL one level down

A method that reads as a list of steps stays readable; the same method with SQL inlined into it does not. SQL
belongs to the collaborator that owns the table.

`4bb23eb51` is the worked example: tag knowledge went to `TagResolver`/`EventsTags`/`AppendedTags`/`TagKey`; the
DBAL side split into `DbalTagVersionRegister` (capture and bump), `DbalTagIndex` (the
`ecotone_tagged_events` SQL), `DbalTagTables` (readiness) and `TagConcurrencyGuard` — "the six main functions read
as step lists with no inline SQL".

Related: do not pass the store into its own collaborators as a stored dependency
(`docs/superpowers/specs/2026-09-28-dcb-readability-review.md` §5); pass it per call (rule 5).

---

## 17. `final` and `declare(strict_types=1)` on new files

113 of the 121 classes added since 1.x are `final`; 132 of 133 new `src` files declare strict types. Older files
predate both — do not sweep them, but do not add a new file without them.

Interfaces are the exception to `final`, and are only created under rule 5.

---

## 18. PHP 8.1+ features, used where they say something

Attributes, enums, named arguments, `readonly` properties, constructor property promotion, first-class callables.
`DecisionModelLoadedState` and `ExpressionLocation` (both above) are the house style: promoted `readonly`
constructor parameters, static named factories, no setters.

Named arguments are the norm at call sites with more than two parameters —
`EcotoneLite::bootstrapFlowTesting(classesToResolve: [...], licenceKey: ...)` — because the signature has ten
parameters and positional calls are unreadable.

---

## 22. A component has one path; a flow that needs another is routed where it starts

A channel, an interceptor, a handler or a compiler pass that asks "which scenario is this message from?" has learned
about one of its callers. The next scenario adds the next branch, each branch is a second way of doing something the
framework already does one way, and the two ways drift like the two modes of a nullable dependency (rule 2). The
component keeps one path; the flow that needs something different is routed differently where it starts and reuses
a path that already exists.

### Deciding a branch

For every `if`, `match` or `?:` a change adds on the message path, or in the code that wires it, name what each part
of the condition reads. A condition joined with `&&` or `||` is decided part by part.

| The condition reads | Decision |
|---|---|
| **The scenario**: a header or marker another feature sets (`ErrorContext::DLQ_MESSAGE_REPLIED`, a retry counter), the concrete class of a caller or collaborator, the runtime situation (`Coroutine::getCid()`, `PHP_SAPI`, console or HTTP) | Breaks the rule, unless the branch only refuses (throws). A part of a condition that lets one scenario past a refusal the others get is not a refusal: it is the second path |
| **A mode known when the application boots**: a flag, an enum, a licence, an installed extension, whose branch chooses which code handles the message | Breaks the rule as a runtime branch. It is resolved when the container is built, into one of two collaborators |
| **The component's own contract**: data the component defines and documents as its input: a header, or a setting it was built with that every message meets the same way | Follows the rule |

The ownership test for a header: it is defined for the component that reads it, and the component would still need
it if every other feature were removed. A channel reads `MessageHeaders::DELIVERY_DELAY`, `PRIORITY` and
`TIME_TO_LIVE` because delaying, ordering and expiring are what a channel does; a gateway reads `REPLY_CHANNEL`; the
aggregate flow reads `AggregateMessage::AGGREGATE_ID`. These say what to do with this message. A header that says
where the message has been (`ecotone.dlq.message_replied`, `ecotone_retry_number`) belongs to the feature that
wrote it, and any sender can set it: a receiver that changes its behaviour on it hands that switch to every sender.

A value known at boot is not always a mode. The question is what the branch on it selects. The examples are from
the coroutine message channel's branch (pull request 779 at `ffee52a2e`), none of them is in the tree:

- It is a **mode** when the two arms are two ways of doing the work, so that two classes could each hold one arm
  and the container could pick: `extension_loaded('openswoole') ? 'OpenSwoole' : 'Swoole'` asked on every call of
  that branch's `CoroutineMessageChannelSleep`. Nothing about it can change after boot, so the choice is made once,
  when the container is built. It is the decision rule 2's table makes for a null that meant two different
  behaviours: two classes, one picked in the container.
- It is the component's **own setting** when the component is documented to take it and every message meets it
  the same way: a size, a limit, a wait, a name. The component passes it on, compares against it or guards on it,
  and no second class would hold "the other arm": both would be the same class with another number.

```php
// ConnectionPool::waitForReturnedConnection() as pull request 779 changes it: the pool gets a wait in seconds as a
// setting, where the tree waits a constant. Follows the rule: the wait is the pool's own setting, 0 is documented
// as "fail at once", the branch only refuses
if ($this->connectionPoolWaitSeconds <= 0.0) {
    throw ConnectionPoolExhausted::withAllConnectionsInUse(connectionName: $this->connectionName, connectionPoolSize: $this->size, waitedSeconds: 0.0);
}
$connection = $this->returnedToWaitingCoroutines->pop($this->connectionPoolWaitSeconds);
```

Four review sessions applied the rule to that guard of pull request 779 and two of them disagreed, one reading
it as configuration branched at runtime. It is not: the value is read at boot but the question is asked of each
acquire that finds the pool exhausted, the answer is the pool's one behaviour for that wait, and `pop(0)` would
wait forever on both runtimes, so the branch cannot be removed by choosing a collaborator.

A guard is not a second path. `if (<not inside a coroutine>) { throw ... }` reads the runtime situation and is
allowed, because every message that reaches it is treated the same way: it is refused, with the ways out
(`framework-error-handling` rule 1).

### What the change does instead

In order of preference, and in all three the other path already exists:

1. **Route at the source.** The code that starts the differing flow sends it somewhere else: another channel, a
   routing slip, the endpoint directly. The dead letter already works this way: `DeadLetterHandler::replayMessage()`
   chooses the entrypoint of a replay itself (the `targetChannel` argument, the polled channel, the first step of
   the stored routing slip) and no receiver is asked whether a message is a replay.
2. **Compose for that flow only.** An interceptor or a decorator registered for the one flow, by the module that
   owns the flow, so the component it wraps is unchanged for everyone else. The registration is the selection: an
   interceptor placed on every asynchronous endpoint that logs a failure only when the message's polled channel is
   one of its own, or one on a shared channel that drops a message when its payload matches (both were on pull
   request 779's branch, in `CoroutineMessageChannelFailureLogger` and
   `CoroutineMessageChannelConsumerStopInterceptor`), has moved the scenario branch into the interceptor. Register it on the channels or endpoints it
   is for.
3. **Choose at container build.** Two collaborators, one selected while compiling, by the module of the feature
   that needs the other one: `LicenceDecider::prepareDefinition()` for Enterprise (rule 2's table,
   `framework-enterprise-licensing` rule 7).

A second implementation of something the framework already does (invoking a handler, acknowledging, opening a
transaction, running an endpoint's interceptors) breaks the rule even when no `if` selects it: the test is whether
the message can now be handled in two ways, not whether a keyword was added.

### Evidence: the coroutine message channel (pull request 779 at `ffee52a2e`)

Every "wrong" snippet below is code of that pull request's branch, not of the tree; each "right" one is what the
rule asks for, and only the one with a commit was written.

The pre-PR reviews passed the first two of the following at `ffee52a2e`, because no requirement spoke about them.

**The channel executed in a second way when it was called outside a coroutine.**

```php
// wrong: CoroutineMessageChannel::enqueue() reads the runtime situation and handles the message in place
parent::send($message);
if (Coroutine::getCid() < 1) {
    $this->consumer()->createConsumer()->run();
    return;
}
$this->schedule(/* a coroutine that consumes the queue */);

// right: the channel has one path, and a send it cannot serve is refused where it is made
parent::send($message);
$this->schedule(/* a coroutine that consumes the queue */);
```

The in-place run existed for one caller, the dead-letter replay from a console command. That flow is routed at its
source instead: the code that replays sends the message to the endpoint, not through the channel.

**The interceptor let one scenario past its refusal, on a header any sender can set.**

```php
// wrong: the second part reads the dead letter's header and exempts the replay from the refusal
if (Coroutine::getCid() < 1 && ! $message->getHeaders()->containsKey(ErrorContext::DLQ_MESSAGE_REPLIED)) {
    throw ConfigurationException::create("Coroutine message channel `{$this->channelName}` cannot send outside a Swoole or OpenSwoole coroutine. ...");
}

// right: the guard only refuses, the same way for every message
if (Coroutine::getCid() < 1) {
    throw ConfigurationException::create("Coroutine message channel `{$this->channelName}` cannot send outside a Swoole or OpenSwoole coroutine. ...");
}
```

Decided part by part: `Coroutine::getCid() < 1` reads the runtime situation and only refuses, which is allowed.
`! containsKey(ErrorContext::DLQ_MESSAGE_REPLIED)` reads a header the dead letter defines, and it does not refuse:
it lets the replay through. Any application message carrying `ecotone.dlq.message_replied` skipped the refusal.

**An open-core compiler pass named one feature's class** (`08d8e57bf`, on the same branch after `ffee52a2e`).

```php
// wrong: RecordExecutionCompilerPass, Apache-2.0, compares a channel's class with the Enterprise channel
$innerChannel instanceof Definition && $innerChannel->getClassName() === CoroutineMessageChannel::class,

// right (`6348dc35e`): the pass asks a question it owns, and any channel answers it
$innerChannel instanceof Definition && is_a($innerChannel->getClassName(), ConsumedInTheSendingProcess::class, true),
```

This one runs at container build, which is where a choice belongs; what breaks the rule is who knows whom. The
recording learned about one channel. With the marker the recording defines what it needs to know and the channel
declares it, so the next channel consumed in the sending process needs no change to the pass.

**What the same change did right:** the channel's delay handling.

```php
$delay = $message->getHeaders()->containsKey(MessageHeaders::DELIVERY_DELAY) ? $message->getHeaders()->get(MessageHeaders::DELIVERY_DELAY) : 0;
// ... a match over TimeSpan, Duration and DateTimeInterface, then
if ($delay > 0) { /* release when due */ }
```

Three branches, all on the channel's own contract: a delay is what a delayed channel is given, by whoever sends.

### Why no program decides this

Measured on `5ec5f01aa` (`main`): 178 framework header constants under `packages/*/src` and `packages/*/Api`, 169
reads of them from a message's headers, 139 of those in another module directory than the constant, 75 on a
branching line. Nearly all are contracts: `MessageHeaders` (35 reads) and `AggregateMessage` (45) are shared
vocabularies defined once and read by the components they are inputs of, so where a constant is defined says
nothing about who owns it. An allow-list would start at 139 entries and every legitimate contract read would add
one. Two of the three places above read no header at all. The rule is decided by a reviewer (requirement GL51).

Code that predates the rule has not been audited against it; `RecordExecutionCompilerPass` on `main` is built on
comparing definition classes. A review decides the rule on what a change adds (`framework-review-guidelines` rule
G4).

---

