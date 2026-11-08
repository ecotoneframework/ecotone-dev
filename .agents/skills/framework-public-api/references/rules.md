## 6. Names carry the meaning. No comments, no descriptive docblocks

The rule is not "explain less". It is that the explanation belongs in a name, an exception message, or a document —
never in a comment that will drift from the code beside it.

**Allowed docblocks, and nothing else:**

- array shapes and generics: `@param array<class-string, ?string> $aggregateTypesByClass`, `@return class-string[]`,
  `@template`
- `@link https://docs.ecotone.tech/...` on an `Ecotone\Api` class

**Not allowed:** prose describing what a class or method does, inline `//` comments, and `@param string $routingKey
The event routing key` on a parameter whose name already says it.

Decided as D4 in `docs/superpowers/specs/2026-09-15-agent-detours-2-0-design.md`:

> No descriptive docblocks in the API. Semantics come from names and exception messages; rename where a name does
> not convey meaning.

The docblocks removed under this rule were accurate and useful — that is the point. `5c3a9ed2c`
("docblocks carry array shapes only"), `9ca15e6ea` and `75e943982` ("drop explanatory docblocks — names carry
it"), `8f065ed98` ("the expression classes speak through their names alone"). `764d53c49` names where the
explanation went instead: *"`DdlOutsideActiveTransaction` — drop the explanatory docblock, the name and upgrade
guide §8 carry it."* (That class has since been removed along with runtime DDL — see rule 16 — but the commit is
still the clearest statement of where a deleted explanation is supposed to land.)

So when you delete an explanation, put it in one of these: a better class or method name, the text of the
exception the situation raises, `upgrade-2.0.md`, or a design spec under `docs/superpowers/specs/`.

Legacy prose docblocks remain in older files under `src/`. Leave them where you are not otherwise touching the file;
do not add new ones. `Api/` is not grandfathered — see 6b.

### 6a. One word per concept, through the whole path

Two names for one value forces every reader to re-prove they are the same thing. `a8f23212c` settled DCB's two
numbers: **`version`** is only ever the optimistic-lock counter, **`sequence`** only ever the order stamp. Before
it, the same two values travelled as `version`, `expectedVersion`, `capturedVersion`, `tagVersion`, `sequence`,
`sequencedBy`, `seq` — and `EventsTags::sequencedBy(array $versionsByTagKey)` took versions and emitted sequences.
The cost is spelled out in `docs/superpowers/specs/2026-09-28-dcb-readability-review.md` §2.

When you introduce a term, grep for the concept first and reuse the word already in use. When you rename, rename
the whole path in one commit. `c3b1cfa8e` and `40eacaa22` are renames of this kind.

### 6b. `Api/` is the context an agent loads, so it is swept, not grandfathered

An agent that writes Ecotone code loads `Api/` — 218 files. Before wave 22 they were 14,049 lines, 3,059 of them
(21.8%) docblock, and `Api/Attribute/EventTag.php` carried an 18-line Enterprise notice over a 7-line class whose two
properties were already `public readonly`. Every line there is read on every load, so `Api/` follows four rules
`src/` does not have to:

- **No prose docblock, and no `@param`/`@return`/`@var` that restates the declared type.** Array shapes, generics,
  `@template`, `@link https://docs.ecotone.tech/...` and `@internal` stay, and so does an annotation that narrows the
  declared type (`@return T` on an `object` return). Prose the documentation already covers becomes an `@link` to
  that page. A fact neither a name, an exception nor the documentation carries stays, and says so — the xdebug note on
  `executionTimeLimitInMilliseconds`, the header mapper's pattern format, `Duration::toFloat()` being seconds. Wave 22
  removed 1,619 docblock lines this way (3,059 to 1,440).
- **The Enterprise marker is `licence Enterprise` plus one sentence**, `Use requires an Ecotone Enterprise licence (see
  LICENSE-ENTERPRISE; trial: https://ecotone.tech/pricing).` `src/` keeps the full notice of rule 7a. The `Api/` file
  is the one loaded into context, and an 18-line notice over a 7-line class is 2.5x the code it guards; the full
  terms are one hop away in `LICENSE-ENTERPRISE`, which ships in every package, so the pointer survives the split.
  `bin/check-licence.php` enforces both forms — a `packages/<Package>/Api/` file with the full notice is refused as
  firmly as one with none — and `bin/add-enterprise-licence.php` rewrites either form into the other. 42 files,
  -546 lines.
- **A getter whose body is only `return $this->property;` is the property, made `public readonly`.** Under the name
  the property already has: `getDescription()` becomes `->description`, `isAutoIncreased()` becomes
  `->autoIncrease`. Property names are not renamed to suit this rule. Three kinds of getter stay methods: one an
  interface declares (PHP 8.2 interfaces cannot declare properties), one that is part of a duck-typed contract with
  a class outside `Api` (`EventHandler::getOutputChannelName()` is read through a union with
  `InputOutputEndpointAnnotation`), and one whose name other classes declare too unless every call site can be
  rewritten by type. Rewrite call sites by type — phpstan's undefined-method report, run over `tests/` and the
  directories the root config leaves out as well — never by a name sweep.
- **Immutability is kept with readonly promoted properties and a copy that rebuilds the instance.** A `readonly`
  property cannot be assigned on a clone, on 8.2 or 8.5, and asymmetric visibility needs 8.4. So every property is
  promoted in the constructor — private where it was private — and every `with*()` returns
  `new self(...[...get_object_vars($this), 'field' => $value])`; on a class that is not `final`, `new static(...)`
  with a `final private function __construct`, which keeps a subclass the way `clone` did and is what lets phpstan
  accept `new static`. A property left out of the constructor fails loudly, as PHP's `Unknown named parameter`. **The
  limit of the pattern:** `get_object_vars()` inside the class does not return a parent's private properties, so it
  cannot be used on a class that extends one or uses a trait with state — it would drop that state silently. Merge a
  keyed array with `array_replace()`, not a spread, which renumbers a numeric key. Each class proves its copies in a
  round-trip test: every field populated, each `with*()` changing exactly its own fields and leaving the original
  untouched (`ImmutableConfigurationCopyTest`, `DbalConfigurationCopyTest`, `DataProtectionConfigurationCopyTest`).

A `with*()` that mutates and returns `$this` is not this pattern and is not converted by it: making it immutable
silently loses every statement-form call, and PHP 8.2 cannot make that loud. `AmqpQueue` and `AmqpExchange` are such
classes and keep their contract until a rename says what they do (rule 4). `ServiceConfiguration` mixed both kinds and
was converted, with every statement-form caller made to assign.

---

## 12. The public surface is `Api/`, a sibling of `src/`

Everything an application is meant to reference — attributes, `#[ServiceContext]` extension objects, gateways —
lives under `Ecotone\Api`. **Everything outside `Api` is `@internal` and may change in a minor version**
(`upgrade-2.0.md` §13).

`Api/` is a directory at the package root, **never** `src/Api/`. Nesting the `Ecotone\Api\*` PSR-4 root inside the
`Ecotone\<Package>\*` root broke Tempest's package auto-discovery: `AutoloadDiscoveryLocations` derives one scan
location per declared PSR-4 prefix, so a nested prefix walks the same files twice and PHP fatals with "Cannot
redeclare class". Fixed at the layout level for every package in `52f582967`; the regression test is
`packages/Tempest/tests/Application/AppNamespaceAutoDiscoveryTest.php`.

Every package declares exactly:

```json
"autoload": { "psr-4": { "Ecotone\\Api\\<Package>\\": "Api/", "Ecotone\\<Package>\\": "src" } }
```

Note the namespace is `Ecotone\Api\<Package>`, not `Ecotone\<Package>\Api`.

**Where a new class goes:**

| It is | Namespace |
|---|---|
| A cross-cutting messaging or modelling attribute | `Ecotone\Api\Attribute\*` |
| A configuration or extension-object value object | `Ecotone\Api\ExtensionObject\*` |
| A gateway interface | `Ecotone\Api\Gateway\*` |
| Anything a user only meets after opting into a feature | `Ecotone\Api\<Module>\*`, **flat** |
| Anything in another Composer package | `Ecotone\Api\<Package>\*` |

Two rules resolve the overlaps:

- **The sub-module rule beats the kind rule.** A module-scoped class stays flat inside its module even when it is
  an attribute or a gateway: `Ecotone\Api\Projecting\Projection`, `Ecotone\Api\Projecting\ProjectionStateGateway`
  — never `Ecotone\Api\Projecting\Attribute\Projection`.
- **Only a package whose `Api/` mixes kinds *and* is large enough gets kind sub-namespaces.** Today that is core
  and `Dbal` only. `Amqp`, `Kafka`, `Redis`, `Sqs`, `Symfony`, `Laravel`, `Tempest`, `DataProtection`,
  `JmsConverter` and `PdoEventSourcing` are flat — adding `Attribute\` for one class adds noise.

Full reasoning and the old→new mapping: `docs/superpowers/specs/2026-09-16-api-namespace-layout-mapping.md` and
`upgrade/namespace-map-2.0.csv`.

### 12a. The boundary holds in both directions

The placement table says where a user-facing class goes. Two invariants say what may cross the line at all, and the
second one is the easier to break, because adding to `Api/` never fails a build.

**Nothing an application writes stays outside `Api`.** Every attribute, every `#[ServiceContext]` extension object,
every gateway interface, every enum or value object named in a configuration call. If a user has to type the name,
it is public surface, and leaving it in `src` makes the whole of `src` look quotable.
The 2.0 sweep missed `EventStore`, the most-used gateway in the tree, which stayed in `src` until it became
`Ecotone\Api\EventSourcing\EventStore` (`upgrade-2.0.md` §13b). `ConversionService` moved in beside it and back out
again: its methods take the internal `Handler\Type`, and the conversion API an application calls is
`SerializerGateway`, which takes strings — the public type was the gateway, not the engine behind it.
The audits missed them too, because they only ever read `Api/` for internal types leaking in; finding a public type
left out means reading `src` for names an application has to type. Reading it that way found nine more, moved in
`upgrade-2.0.md` §13c: the aggregate traits `WithEvents` and `WithAggregateVersioning`, `Event`, the interceptor
types `MethodInvocation` and `Precedence`, `TimeSpan`, `MessageHeaders`, `ModulePackageList` and
`DynamicMessageChannelBuilder`. Reading `Api` signatures for the internal types they name found ten more, moved in
`upgrade-2.0.md` §13d: `MetadataMatcher` with the `Operator` and `FieldType` it is built from, `DatePoint` with its
parent `SleepInterface` and the `Duration` both name, `Future`, `ErrorContext`, `LoggingLevel` and `FetchMode`.
`EcotoneLite` stayed until the types it returns could move with it — moving it alone would have shifted the boundary
one hop without closing it — and then seven moved together, in `upgrade-2.0.md` §13e: `EcotoneLite`,
`FlowTestSupport` with the `MessagingTestSupport` it returns, `ConfiguredMessagingSystem`, and `MessageChannel` and
`PollableChannel` with `MessagePoller`, which `PollableChannel` extends. The console writer types followed in
`upgrade-2.0.md` §13f: a `#[ConsoleCommand]` takes `ConsoleWriter` and calls its `progressBar()`, and a flow test reads
`InMemoryConsoleWriter`, so all four moved into `Ecotone\Api\Console`. `Message` followed in §13g: five `FlowTestSupport` methods, the
channel interfaces and the dead-letter gateway name it, and a handler takes it to receive the whole message.

**Nothing internal moves into `Api`.** No modules, resolvers, interceptors, services, compiler passes or container
plumbing. 204 files live under `packages/*/Api` today; the reason the number stays honest is that a class is added
there on purpose, never because it was convenient.

**Builders are allowed in `Api`, because an application constructs a builder, and it never constructs a module or a
container.** `MessageChannelBuilder` is taken by `DynamicMessageChannelBuilder::createWithSendOnlyStrategy()` and
`withInternalChannels()`, both in `Api`, and the four `*BackedMessageChannelBuilder` subclasses an application
returns from its `#[ServiceContext]` methods are in `Api` already, so keeping the builder type out left those
signatures naming an internal one. A builder only the framework constructs or reads is still internal — the who-calls-it
test below decides it like any other class.

**The test is who calls it, not what it is named.** An `Api` class may name an internal type in a method *the
framework* calls — that is the `DefinedObject`/channel-builder contract, and `compile(MessagingContainerBuilder
$builder): Definition` appears in `Api` on purpose in `SimpleMessageChannelBuilder.php:159`,
`Dbal/Api/ExtensionObject/DbalDeadLetterBuilder.php:153` and each framework package's channel builder. The same
goes for what a method body reaches for: `Assert`, `Definition` and `DefinedObject` are imported by 48 of the 204
`Api` files and none of that is a leak.

It is a leak when **the application** is the caller and an internal type is in its way — a parameter it has to
construct, a default it has to name, a return type it has to import. Three of those were in the tree, and the way
they were found is the way to find the next one: read the imports of `packages/*/Api`, and for each internal type
ask which method exposes it and who calls that method.

| Was | Now | How an application met it |
|---|---|---|
| `Ecotone\Messaging\Conversion\MediaType` | `Ecotone\Api\ExtensionObject\MediaType` | `CommandBus`, `QueryBus`, `EventBus`, `DistributedBus`, `MessagePublisher` and `#[ContentType]` all name it |
| `Ecotone\Messaging\Endpoint\FinalFailureStrategy` | `Ecotone\Api\ExtensionObject\FinalFailureStrategy` | a default parameter value on `SimpleMessageChannelBuilder::create()`/`::createQueueChannel()`, and on `#[KafkaConsumer]`/`#[RabbitConsumer]` |
| `Ecotone\Messaging\Handler\Recoverability\RetryTemplateBuilder` | `Ecotone\Api\ExtensionObject\RetryTemplateBuilder` | `ServiceConfiguration::withConnectionRetryTemplate()`, `ErrorHandlerConfiguration::create()` |

`upgrade-2.0.md` §13a and the three new rows in `upgrade/namespace-map-2.0.csv` are the record. Note what did
**not** move with them: `RetryTemplateBuilder::build()` still returns
`Ecotone\Messaging\Handler\Recoverability\RetryTemplate`, and that is correct — the framework calls `build()`,
the application only calls the static factories. The who-calls-it test decides the class *and* each of its methods.

**`#[ModuleAnnotation]` is the deliberate edge case**, and worth knowing so it is not read as precedent: no
application writes it, only a module does, and modules are written in packages outside this monorepo too. Extension
authors are users. Anything whose only caller is Ecotone itself is not.

**Nothing checks this for you.** `bin/check-licence.php` walks `packages/*/src` only, and phpstan (rule 21) reports
a class that does not exist or a return type a method breaks, never an internal type in a public signature. The
boundary is held in review, which is why it is written down.

**A sibling in the same namespace is named bare — except in an attribute argument, which has to be fixed by hand
when either class moves.** PHP resolves a bare name against the current namespace, so a same-namespace `use` adds
nothing, and php-cs-fixer's `no_unused_imports` deletes it whenever it runs over the file — in an attribute argument
too. That is what the tree does: across `packages/*/Api` on `f5493764b`, 55 of the 60 file-to-sibling references
inside one namespace are bare (each file's class-name tokens matched against the classes its own namespace declares).
The attribute argument is the one place a bare name is a hazard, because it resolves lazily: when a class leaves the
namespace, a sibling its attribute arguments name bare now resolves against the *new* namespace and fails only at
reflection time, which phpstan does not catch at any level. **Moving a class out of a namespace therefore includes
importing every sibling its attribute arguments name.** This broke fifteen classes during the namespace split; see §5
of the mapping spec.

---

## 14. A console option's name is its PHP parameter name, verbatim

`#[ConsoleParameterOption]` takes no name argument. The CLI option is the parameter name exactly as written, so it
is camelCase, never kebab-case.

```php
#[ConsoleCommand('ecotone:event-store:backfill-tags')]
public function backfill(
    #[ConsoleParameterOption] int $batchSize = 500,
    #[ConsoleParameterOption] bool|string $skipUndeserializable = false,
): ConsoleCommandResultSet
```

gives `--batchSize` and `--skipUndeserializable`. Writing `--skip-undeserializable` in an exception message or a
document is a bug: `5fa0024b2` fixed exactly that, in a hint that told users to run an option that did not exist.
See also `packages/Dbal/src/Database/DatabaseSetupCommand.php` (`--onlyUsed`, `--initialize`, `--connection`).

### 14a. A framework header is named `ecotone_{module}_{feature}_{action}`

A header Ecotone adds for itself is lower snake_case, carries the `ecotone_` prefix, and names its module, then the
feature, then what it holds: `ecotone_dbal_deadletter_limit`, `ecotone_modelling_aggregate_target_version`,
`ecotone_projection_partition_key`, `ecotone_kafka_consumer_acknowledge`. A prefix built on at runtime ends in `_`:
`ecotone_dbal_business_method_parameter_value_`. The value lives in one constant, and code reads the constant, never
the literal.

- **The prefix keeps framework headers apart from application headers.** An application header can be named
  anything; `ecotone_` is reserved for Ecotone.
- **No dots.** The event store builds a JSON path from a metadata key (`JSON_EXTRACT(metadata, '$.{$field}')` in
  `MySqlEventStreamSchema` and `SqliteEventStreamSchema`), so `a.b.c` is read there as a nested path and matches
  nothing, while PostgreSQL's `->>` reads it literally — a dotted key works on one engine and silently fails on the
  others.
- **Exempt, and kept as they are:** the core message headers shared with Spring Integration (`id`, `timestamp`,
  `correlationId`, `parentId`, `contentType`, `routingSlip`, `errorChannel`, `priority`, …), the event store's
  `_aggregate_*` and `_stream_name` keys (generated columns and the unique index are built on them), and the
  `amqp_*` / `kafka_*` copies of broker properties.
- **A header that leaves the process is renamed only with a read path for its old name.** One that is written to a
  queue, the outbox, the dead letter table or the event store — `polledChannelName` and `inboundRequestChannel`
  among them, which dead-letter replay reads back from the stored message — is still there under its 1.x name after
  an upgrade. Headers that never leave the process can be renamed freely.

Channel names are not headers and are not covered by this rule.

---

