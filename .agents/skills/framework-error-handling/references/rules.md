## 1. Exceptions drive the solution

This is the most-corrected rule in the repository. An exception is read by the agent or developer who has to fix
the problem, so it must contain enough to fix it. Three parts, in order:

1. **What was wrong**, named concretely — the class, method, tag, table, attribute or option, never "invalid input".
2. **Why it cannot work**, one clause.
3. **Every way out**, each an exact API call, attribute, option or console command.

`packages/Ecotone/src/Modelling/EventSourcingExecutor/OpenCoreAggregateMethodInvoker.php` is the shape in one line:

```php
throw InvalidArgumentException::create(
    "Using multiple parameters for #[Apply] method: {$applyMethod} is part of Enterprise features. "
    . 'Without Enterprise, keep a single event parameter and carry the value you need (for example the time of the '
    . 'change) in the event itself. To read metadata here, obtain Enterprise: https://docs.ecotone.tech/enterprise'
);
```

`packages/Dbal/src/Database/MissingTableInstructions.php` is the fuller model: it names the feature, the table, the
connection when it is not the default, and then the exact command for the framework the application is running —
`bin/console`, `php artisan`, `./tempest`, or the PHP to call when there is no console at all.

Enumerate *all* the ways out, not one. `TagTransactionRequirement` lists four, and gained the fourth
(`7cf42b477`) precisely because a user could be blocked by `#[WithoutDatabaseTransaction]` and the message did not
say so.

**Test the message, not just the class.** The message is the deliverable, so it is what the test asserts. See
`packages/Dbal/tests/Unit/Database/MissingTableInstructionsTest.php`: one test per framework, each asserting the
command string it must contain.

```php
$this->expectException(ConfigurationException::class);
$this->expectExceptionMessage('#[WithoutDatabaseTransaction]');
```

Evidence: `7cf42b477`, `5fa0024b2`, `c9b54d3f9`, `048f614d8`, `d8f81c26b`, `f85f057a8`, `d3a25ac3c`,
`84623fa0e`, and the whole `implement-expression-failure-context` worktree.

### 1a. A configuration mistake is refused at bootstrap, never at runtime

If a combination cannot work, detect it while the messaging system is being built, so it fails on the first test
run rather than on a production message. Twelve commits in the DCB feature alone did nothing but move a failure
earlier: `823dd41c3`, `c48e1b7c7`, `383de545a`, `9984fae25`, `d90ae125f`, `083b9edbd`, `39d420535`, `6ef6e48df`,
`d92883148`, `d3a25ac3c`, `b984c8d41`, `24edcf663`.

**The validation ships in the same commit as the rule it enforces.** This is the most expensive omission the DCB
retrospective measured, and two of its three independent friction reports found it. `implement-dcb-edge-cases` is a
whole worktree of 20 commits, 18 of them `fix(dcb):`, and every one has the same shape: a rule the design spec or
the upgrade guide already stated had no enforcing code, so the misconfiguration booted silently. A model whose
handled events share no `#[EventTag]` name "folded nothing and guarded nothing" (`083b9edbd`); a class carrying both
`#[DecisionModel]` and `#[Aggregate]` "booted silently with two contradictory roles" (`9984fae25`); a command
property holding an array "silently scoped the model by its first element" (`6ef6e48df`). The remaining six did
raise an error, but named the wrong table, the wrong cause, or a resume position that did not exist (`7cf42b477`,
`f85f057a8`, `d8f81c26b`, `c9b54d3f9`, `d2a0f5171`, `d3a25ac3c`) — which is rule 1, and is why the test asserts the
message and not the exception class. A spec sentence with neither the guard nor the message test is an edge case
waiting for someone to spend a worktree finding it.

`packages/Ecotone/src/EventSourcing/Tagging/AggregateCounterTagGuard.php` is the pattern: static assertions the
module calls at compile time, each throwing `ConfigurationException` that names the class and what to do instead.

Never let a wrong configuration read as an empty result. `048f614d8` fixed exactly that: a missing event-stream
table used to return no events, so a decision was taken on an empty aggregate. It now raises the setup
instructions.

### 1b. Failure context is a value object built at container-build time

Do not assemble location strings at the throw site. `packages/Ecotone/src/Messaging/Handler/ExpressionLocation.php`
is compiled into the container with the attribute name, target and owner already in it, and every expression
failure in the framework goes through it, producing
`#[Fetch] on $activity in Handler::count failed. Expression: ... . <cause>` (`c45cf02bd`).

### 1c. A check that can run at compile time runs at compile time

Rule 1a says *when* a wrong configuration fails. This is *where the check lives*: in the module, during
configuration building, not in a service on the message path.

`Module::prepare()` is handed the `InterfaceToCallRegistry` as its fourth parameter for exactly this — it can see
every handler, its parameters and its attributes before a single message exists:

```php
public function prepare(
    Configuration $messagingConfiguration,
    array $extensionObjects,
    ModuleReferenceSearchService $moduleReferenceSearchService,
    InterfaceToCallRegistry $interfaceToCallRegistry,
): void
```

The shape is a **guard**: a class of static assertions the module calls, each throwing `ConfigurationException`.
`packages/Ecotone/src/Modelling/DecisionModel/Config/DecisionModelModule.php:113` calls two of them from
`prepare()` — `CrossConnectionDecisionModelGuard::assertNoCrossConnectionInjection()` (:126) and
`DecisionModelTagResolvabilityGuard::assertEveryModelTagResolvableFromItsMessage()` (:133), the second taking the
annotation finder and the registry so it can answer the question over every model in the application.
`packages/Ecotone/src/EventSourcing/Tagging/Config/EventTaggingModule.php:71` does the same with
`AggregateCounterTagGuard` (:82-83).

**The same knowledge answers both questions, so ask it early.** `MessageTagValueResolver` has two entry points:
`canResolve($tagName, $messageClass)`, which the resolvability guard calls at compile time, and
`resolve($tagName, $payload)`, which `DecisionModelParameterLoader` calls per message. Because the guard refuses an
unresolvable tag at bootstrap, the runtime path has no unresolvable case to handle — no branch, no fallback, and no
silently empty result (rule 1a).

**What is left to runtime is message content, and only that.** The shape of the configuration is knowable while
compiling; the value carried by a particular message is not. A tag value's length in characters and its UTF-8
validity are runtime checks for that reason (`d92883148`) — everything about *which* tag, on *which* model, from
*which* property is compile-time.

So, before adding a check: ask whether it could have been answered from the class, the method, the parameter types
and the attributes alone. If it could, it belongs in a guard the module calls, and the test that proves it boots
Ecotone and expects a `ConfigurationException`.


### 1d. The common usage is the default; any other usage is refused, never given a second meaning

Every attribute, configuration object and gateway defaults to the usage most applications need, so the shortest code
is the right code. `#[EventHandler]` passes the message payload as the first parameter, because that is what almost
every handler wants, and resolves the rest from headers and services; `#[CommandHandler]` without a routing key
routes by the payload's class. Nobody has to opt into the normal case.

A usage the feature is not meant for is refused at bootstrap, naming the meant one (rules 1, 1a). It is never quietly
accepted with a meaning of its own, because then two applications read the same code differently, and the one that
guessed wrong finds out in production. `#[EventHandler]` on a class is refused by its attribute target. When two
readings are possible, refuse rather than pick one: two configurations of one feature, a message carrying both
`tracingId` and `traceparent`, an attribute combined with one it cannot work with.

Before shipping an attribute, a configuration or a gateway, go through what an application could write and decide,
for each one, whether it is supported (with a test) or refused (with a message test). None may be left to chance:

- **Placement** — the attribute's `#[Attribute(...)]` target is as narrow as the meant usage.
- **Parameters** — the first parameter is checked against what it receives (rule 1c), so a wrong type fails at
  bootstrap, not on the first message after the work committed.
- **Combinations** — every other attribute that can sit beside it, `#[Asynchronous]` first, is either supported or
  refused by the attribute itself, with its own reason. An endpoint attribute that cannot run asynchronously returns
  that reason from `asynchronousExecutionRefusal()`, and `AsynchronousModule` uses it instead of asking for an
  `endpointId` the attribute cannot take.
- **Repetition** — a configuration object registered twice is refused; the first one never silently wins.
- **Settings that contradict** — an option combination that cannot work, such as a subscriber with notifications
  turned off, is refused naming the setting to remove.

Evidence: flow tracking shipped three of these gaps at once. `#[Asynchronous]` on a flow notification subscriber asked for an
`endpointId` the attribute cannot take, a second `FlowTrackingConfiguration` was silently dropped, and a subscriber
with a mistyped first parameter failed on every notification at runtime (`FlowTrackingTest`, the three bootstrap
refusals).

---

