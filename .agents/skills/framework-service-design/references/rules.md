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

