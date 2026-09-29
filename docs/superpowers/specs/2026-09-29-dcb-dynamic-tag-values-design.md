# Dynamic tag values in DCB — research and proposal

Status: **research, no implementation** — proposal awaiting the maintainer
Date: 2026-09-29
Base: `dgafka/ecotone-2-0-dcb-design` at `a98c23fa` (aggregate-backed decision models merged)
Builds on: `2026-09-20-dcb-design.md` §4.3–§4.4, §4.11–§4.12; `2026-09-28-dcb-fetched-aggregates-design.md` §1.1;
`2026-09-28-dcb-aggregate-full-tag-design.md` Part 9; `upgrade-2.0.md` DCB section

Answers the maintainer's question:

> "What if we would need to evaluate the tag dynamically like via expression like with fetch. For example map it
> from `reference(mapper).map(payload.orderId)` or access headers. How can we achieve this, run research."

**The headline, verified by running it.** `#[Fetch("reference('mapper').map(payload.orderId)")]` and
`#[Fetch("headers['tenant']")]` **already work today** on a decision model's tag values, on an aggregate-backed
model's identifier and on a `#[Fetch]`-ed aggregate — on `#[CommandHandler]`, `#[EventHandler]`,
`#[QueryHandler]` and on an `#[EventSourcingAggregate]` command handler, with a string expression or a PHP 8.5
closure. The expression is evaluated exactly once per parameter, before the read, in the handler's single batched
load. Nothing needs to be built for the maintainer's two literal examples.

What is missing is everything around them: the **event side** has no expression at all, `#[DecisionBoundary]`
gets the command and nothing else, one context variable (`value`) is available on two of the three producers and a
syntax error on the third, **no expression is validated at bootstrap** — not its syntax, not the services it
names, not the tag names it must produce — and every runtime failure throws a `ConfigurationException` (a
*bootstrap* exception class) whose message never quotes the expression that failed. `symfony/expression-language`
is `require-dev` in `packages/Ecotone/composer.json` and is not even in `suggest`, so a production install that
writes a string expression gets a bare `\InvalidArgumentException` per message.

Every claim in Part 1 is marked **[verified]** (a throwaway `EcotoneLite` test run in this worktree on PHP 8.5.3,
PHPUnit 12.5.36, reported here and not committed) or **[read]** (read from the code at `a98c23fa`).

---

## Part 1 — Inventory, precisely

### 1.0 The one machine underneath

Every dynamic value in Ecotone goes through one pair of classes
(`packages/Ecotone/src/Messaging/Handler/ClosureExpression/`) **[read]**:

| Class | Role |
|---|---|
| `AttributeExpressionExecutorCompiler` | at bootstrap, turns a `WithExpression` attribute into a container `Definition` |
| `AttributeExpressionExecutor` | at runtime, `execute(Message $message, array $additionalContext = []): mixed` |

`AttributeExpressionExecutor::execute()` branches on the expression's PHP type
(`AttributeExpressionExecutor.php:57-73`) **[read]**:

- a **`Closure`** — arguments come from `ClosureParameterResolver`s compiled at container build time, one per
  closure parameter. Each parameter is resolved by the *ordinary handler-parameter rules*: `#[Payload]`,
  `#[Header]`, `#[Headers]`, `#[Reference]`, `#[ConfigurationVariable]`, an attribute declared on the owning
  method or class, else first-parameter-is-payload, else a container reference by type hint
  (`AttributeExpressionExecutorCompiler::defaultConverterBuilderFor()`). **The expression-language library is
  never touched on this path.**
- a **`string`** — `ExpressionEvaluationService::evaluateWithMessage()`, which is
  `SymfonyExpressionEvaluationAdapter` merging `['payload' => …, 'headers' => …]` with the caller's
  `$additionalContext` and evaluating through `symfony/expression-language`.

`Fetch` (`packages/Ecotone/Api/Attribute/Fetch.php`) is `string|Closure` and implements `WithExpression` **[read]**.
Closures in attributes are a **PHP 8.5** constant-expression feature and must be `static function` — `static fn`
is rejected by the engine with `Fatal error: Constant expression contains invalid operations`, because an arrow
function implicitly captures scope **[verified]**. The existing suite gates this with
`#[RequiresPhp('>= 8.5.0')]` (`tests/Messaging/Unit/Handler/ClosureExpressionTest.php:30`); the package floor is
PHP 8.2, so closures are an opt-in for users who are already on 8.5.

### 1.1 What a string expression can see

From `SymfonyExpressionEvaluationAdapter` **[read]**:

| Variable / function | Source | Available where |
|---|---|---|
| `payload` | `$message->getPayload()` | every `evaluateWithMessage()` caller |
| `headers` | `$message->getHeaders()->headers()` | every `evaluateWithMessage()` caller |
| `referenceService` | the `ReferenceSearchService` itself | always (merged in `evaluate()`) |
| `reference('name')` | `referenceService.get('name')` | always |
| `parameter('name')` | `ConfigurationVariableService::getByName()` | always |
| `extract`, `each`, `createArray`, `isArray`, `isset` | registered helpers | always |
| `value` | **caller-supplied** `$additionalContext['value']` | **only where the caller passes it — see §1.6** |

`payload` is the *raw message payload*. On a command bus send of an object that is the command object; on
`sendCommandWithRouting('h1', ['orderId' => 'o-1'])` it is the array, and `payload['orderId']` works **[verified]**.

### 1.2 Command-side decision model tag values

`DecisionModelParameterLoader::resolveCriteria()` **[read]**: if the parameter carries a `#[Fetch]` expression the
loader evaluates it, otherwise it falls back to `MessageTagValueResolver` (reflection over the payload object:
a property carrying `#[EventTag('name')]`, else a property named `name` / `nameId` / `name_id`).

The expression path is `resolveTagValuesFromExpression()`:

```php
$resolved = $this->expressionExecutor->execute($message);   // no additional context
```

then, per tag name of the model: an **associative array** result is read by tag name; a **non-array** result is
taken as the value when the model has exactly one tag; otherwise `null`. Each value goes through
`EventTagValueNormalizer` (the same normaliser event tags use).

What works **[all verified]**:

| # | Expression | Result |
|---|---|---|
| E1 | `#[Fetch("reference('mapper').map(payload.orderId)")]` | **works** — the maintainer's example, verbatim |
| E2 | `#[Fetch("headers['tenant']")]` | **works** |
| E3 | `#[Fetch('payload.nested.accountId')]` | **works** — nested paths |
| E4 | `#[Fetch(static function (#[Payload] Cmd $c, Mapper $m): string { return $m->map($c->orderId); })]` | **works** (PHP 8.5) — service by type hint |
| E5 | `#[Fetch(static function (#[Header('tenant')] string $t): string { return $t; })]` | **works** (PHP 8.5) |
| E8 | `#[Fetch("reference('mapper').mapToBoth(payload.orderId)")]` returning `['account' => …, 'customer' => …]` | **works** — multi-tag map from one service call |
| H5 | `#[Fetch("{'tenant': headers['tenant'], 'username': payload.username}")]` | **works** — mixed header/payload map, incl. a filter-only tag |
| H1 | array payload + `#[Fetch("reference('h1mapper').map(payload['orderId'])")]` | **works** |
| H6 | `#[Fetch("parameter('defaultAccount')")]` | **works** — configuration variables |
| F13 | expression returning a `Stringable` | **works** — normalised to its string |
| — | expression returning `int 7` matching `#[EventTag]` `'7'` | **works** (`DecisionModelFetchTest`) |

Where it works **[verified]**: `#[CommandHandler]` (E1), `#[QueryHandler]` (E6), `#[EventHandler]` (E7), and a
`#[CommandHandler]` **on an `#[EventSourcingAggregate]`** injecting a model (F14). No producer is excluded.

**Evaluation count and placement [verified + read].** With one injected model the mapper is called **once**
(F12); with the same model class injected twice under the same expression, **twice** (F12b) — once per parameter,
no dedup. `DecisionModelBatchLoader::load()` evaluates every loader's expression, ORs the criteria, and issues the
single `loadByCriteria()`; the executor is a stateless singleton, all per-message state is the `Message` argument.
The batch loader is registered as a **before interceptor at `Precedence::SYSTEM_PRECEDENCE_AFTER` (1001)**
(`DecisionModelModule.php:158-165`), while the database transaction is an around interceptor at
`DATABASE_TRANSACTION_PRECEDENCE` (-2000) — so **the expression, and any service call inside it, runs inside the
handler's database transaction** and before the capture-and-read.

What fails **[all verified]**:

| # | Input | Actual failure |
|---|---|---|
| F5a | `#[Fetch('value.ref')]` | `Symfony\…\SyntaxError: Variable "value" is not valid around position 1 for expression 'value.ref'` |
| F7 | `#[Fetch("headers['missing']")]`, non-nullable model | `ConfigurationException: #[Fetch] expression for DecisionModel …\TagModel did not resolve tag 'account'.` (plus a PHP warning `Undefined array key "missing"`) |
| H4 | multi-tag model, `#[Fetch('[payload.a, payload.b]')]` (a list, not a map) | `ConfigurationException: … did not resolve tag 'x'.` — never says "a multi-tag model needs a map keyed by tag name" |
| I1 | `#[Fetch('payload..broken(((')]` | **bootstrap passes**; per message: `Symfony\…\SyntaxError: Unclosed "(" around position 17` |
| I2 | `#[Fetch("reference('totallyUnknownService').map(payload.account)")]` | **bootstrap passes**; per message: `InvalidArgumentException: Reference totallyUnknownService was not found in definitions` |
| I3 | `#[Fetch('false')]` | `ConfigurationException: Could not resolve tag 'account' … Tag 'account' value cannot be empty.` |
| H7 | `#[Fetch('true')]` | **silently succeeds** with tag value `"1"` |
| I4 | expression returning `DateTimeImmutable` | `ConfigurationException: … must be scalar, Stringable, null or an array of those, got DateTimeImmutable.` |
| F15 | the service itself throws | the service's own exception propagates unwrapped (`DomainException: mapper blew up`) — no mention of model, tag or handler |
| F6 | `reference('nope')` | `InvalidArgumentException: Reference nope was not found in definitions` |

A **nullable** model parameter whose expression yields `null` receives `null` and contributes nothing (F7b)
**[verified]** — the documented rule holds for expressions as well as properties.

**Bootstrap validation is skipped entirely for `#[Fetch]` parameters [verified + read].**
`DecisionModelTagResolvabilityGuard::modelClassResolvedByConvention()` returns `null` as soon as the parameter
carries `#[Fetch]` (`DecisionModelTagResolvabilityGuard.php:104-108`), so E11 — a command with only
`public string $bankAccountCode` and `#[Fetch('payload.bankAccountCode')]` for tag `account` — boots and runs.
That is correct as far as it goes (the guard could not check an expression), but it means **the moment a user
reaches for `#[Fetch]`, every bootstrap guarantee the design advertises for tag values disappears**.

### 1.3 Aggregate-backed decision model identifiers (§4.12)

`AggregateBackedDecisionModelLoader::identifiersFromExpression()` **[read]**:

```php
FetchAggregateConverter::identifiersFrom(
    $this->expressionExecutor->execute($message, ['value' => $message->getPayload()]),
    …
);
```

Same expression machinery, **plus `value`**, and the result is mapped through the aggregate's identifier mapping
(a scalar onto the single identifier; a map for several). `#[Fetch("headers['tenant']")]` on a
`#[DecisionModel(aggregate: Wallet::class)]` parameter loads wallet `w-1` from a header **[verified, E13]**;
`#[Fetch('value.ref')]` works here **[verified, F5b]** and is a syntax error on the tag-backed path (F5a) — the
only difference is the `['value' => …]` argument three lines apart in two sibling loaders.

By convention (no `#[Fetch]`) the identifier comes from `MessageAggregateIdentifierResolver`: a property named
`name` / `nameId` / `name_id` — the same candidates tag values use, minus the `#[EventTag]`-on-the-property route
**[read]**. The bootstrap guard checks those candidate names and, like the tag guard, is **skipped when `#[Fetch]`
is present** **[read]**.

### 1.4 `#[Fetch]` on aggregates

`FetchAggregateConverter::resolveIdentifiers()` **[read]** — identical to §1.3, `['value' => $payload]` included.
A service-call expression on a fetched aggregate parameter works **[verified, E14]**, and in a decision-model
handler the same expression is evaluated a second time by `FetchedAggregateCounterCapture` to build the counter
criterion **[read]** — so a `#[Fetch]`-ed aggregate in a decision-model handler calls its mapper **twice per
message**, once for the capture and once for the load.

### 1.5 `#[DecisionBoundary]` — the escape hatch, boxed in

`DecisionBoundaryEvaluator::assertBoundaryShape()` **[read]** requires the method to be **`static`**, to take
**exactly one parameter**, that parameter to be a class or interface, and the return type to be exactly
`EventCriteria`. `criteriaFor()` calls it with the payload converted to the command and nothing else.

Consequences **[verified]**:

- E9 — a boundary with a second `array $headers` parameter:
  `ConfigurationException: #[DecisionBoundary] …::boundary must take exactly one parameter -- the command or event of the handler it scopes, as its first parameter.`
- F11 — a boundary on a class whose only handler is a `#[QueryHandler]`:
  `ConfigurationException: … scopes no handler: no #[CommandHandler] or #[EventHandler] of … takes … as its first parameter.`
- Being `static`, it can hold no injected services; there is no `#[Reference]` route in.

So the escape hatch is the *least* dynamic of the producers: it can compute anything from the command, and cannot
see a header or a service at all. A tenant-scoped boundary — the single most likely reason to need headers — is
not expressible.

### 1.6 The `value` variable, three producers, two answers

| Producer | Call | `value` |
|---|---|---|
| tag-backed model (`DecisionModelParameterLoader`) | `execute($message)` | **absent — SyntaxError** |
| aggregate-backed model (`AggregateBackedDecisionModelLoader`) | `execute($message, ['value' => $payload])` | present |
| `#[Fetch]` aggregate (`FetchAggregateConverter`, `FetchedAggregateCounterCapture`) | `execute($message, ['value' => $payload])` | present |

`AdditionalContextResolver` additionally binds `value` from a header or the payload for `#[Header]`/`#[Payload]`
closure expressions, and `service` for `#[Reference]` **[read]** — neither applies to `#[Fetch]`.

### 1.7 Event-side `#[EventTag]` values — no expressions at all

`EventTag` (`packages/Ecotone/Api/Attribute/EventTag.php`) is `__construct(string $name, ?string $value = null)`
**[read]**. `new EventTag('order', expression: 'payload.x')` is
`Error: Unknown named parameter $expression` **[verified, E10]**. There are exactly three
`EventTagValueSource`s **[read]**:

| Source | Target | Dynamism |
|---|---|---|
| `PropertyEventTagValueSource` | property / promoted ctor param | reflection read, `[]` when uninitialised |
| `MethodEventTagValueSource` | public method | `call_user_func([$event, $method])` — **no arguments, no container** |
| `LiteralEventTagValueSource` | class-level `value:` | a compile-time constant string |

So the *only* computed event tag is a **no-argument public method on the event itself**. It cannot take a service,
cannot see headers, and cannot see the message — by design, since tags are resolved above the serializer from the
event object alone (§4.3). The registry is built once at bootstrap from the annotation scan and is not extended by
reflection at append time (§4.3, "Scan limitation").

This asymmetry is the sharpest one in the feature: **the read side can map a tag value through a container
service; the write side cannot.** A user who writes
`#[Fetch("reference('tenantMapper').resolve(payload.orderId)")]` on the model has no way to make the *event*
carry the value that mapper produces, other than computing it in the handler and passing it to the event's
constructor — which is, in fairness, the correct thing to do, but nothing says so.

### 1.8 Normalisation

`EventTagValueNormalizer::normalize()` **[read]** is applied on both sides: `null` → `[]`; arrays flattened
recursively; scalars and `Stringable` cast to string and validated (non-empty, valid UTF-8, no NUL, ≤ 255
characters, no trailing whitespace); anything else is a `ConfigurationException`. `DecisionModelParameterLoader`
re-wraps failures to name the model, and rejects an array result with the "one value per tag" message before
normalising **[read]**.

Two silent coercions fall out of `is_scalar()` **[verified]**: `true` → `"1"` (H7, accepted silently) and
`false` → `""` → "value cannot be empty" (I3). A boolean-returning expression is always a mistake, and one of the
two cases is not caught.

### 1.9 The `symfony/expression-language` dependency

`packages/Ecotone/composer.json` **[read]**: `symfony/expression-language` is under **`require-dev`** (line 69),
and `suggest` mentions only `symfony/console`. `RegisterSingletonMessagingServices:63` registers
`ExpressionEvaluationService::REFERENCE` unconditionally via `SymfonyExpressionEvaluationAdapter::create()`, which
returns `StubExpressionEvaluationAdapter` when `ExpressionLanguage` does not exist **[read]** — so the service is
never nullable, which matches the maintainer's rule. The stub throws, **per message**:

```
InvalidArgumentException: Missing Symfony Expression Language, add `symfony/expression-language` in order to use expressions
```

No model, no tag, no handler, no expression, and no bootstrap warning. A **closure** expression bypasses the
service entirely, so closures are already a complete answer for an application that does not want the dependency.

---

## Part 2 — The gaps, ranked by how likely a user hits them

### G1 — No expression is validated at bootstrap (highest)

Anyone who writes `#[Fetch]` at all is exposed. A typo in a property path, a misspelled service name, a map whose
keys do not match the model's tag names, or a syntax error all boot cleanly and fail on the first message in
production (I1, I2, H4) **[verified]**.

*Confusion:* the design's own contract is "a tag that cannot be resolved is a bootstrap error when statically
knowable" (§4.4). Users will reasonably read `#[Fetch]` as *more* explicit than the naming convention, and it is
the only route with **no** bootstrap check whatsoever.

*Smallest coherent fix:* compile every string expression at bootstrap (`ExpressionLanguage::parse()` with the
known variable names) and fail with the handler, parameter, model and expression in the message. Costs one parse
per expression at container build.

### G2 — Runtime failures throw `ConfigurationException` and never quote the expression

`#[Fetch] expression for DecisionModel …\TagModel did not resolve tag 'account'.` is what you get for a missing
header, a wrong map key, a list where a map was needed, and a path that yields `null` **[verified: F7, H4]** — four
distinct mistakes, one message, and it names a *configuration* exception for a *per-message* condition, so it is
not catchable as a runtime error and reads to an operator like a boot problem.

*Smallest coherent fix:* one message that includes the expression text, what it returned (`get_debug_type` plus a
short scalar), the tag names still unfilled, and — for a multi-tag model — "expected an array keyed by
`'x'`, `'y'`". Keep the class or introduce a runtime sibling (see OQ3).

### G3 — `#[DecisionBoundary]` cannot see headers or services

The documented escape hatch "for a boundary no model expresses" cannot express the most common dynamic boundary
there is: one scoped by a tenant header (E9) **[verified]**.

*Smallest coherent fix:* let the boundary declare additional parameters resolved by the ordinary handler rules
(`#[Header]`, `#[Headers]`, `#[Reference]`, `#[ConfigurationVariable]`), keeping "first parameter is the command"
as the matching rule.

### G4 — `value` is available on two producers and a syntax error on the third

A user who copies a working `#[Fetch('value.orderId')]` from an aggregate parameter onto a decision model gets
`Variable "value" is not valid` **[verified, F5a vs F5b]**, with nothing in the message hinting that the same
attribute has two context sets.

*Smallest coherent fix:* pass `['value' => $message->getPayload()]` in `DecisionModelParameterLoader` too — one
argument, and the three producers become one contract.

### G5 — The event side has no dynamic values at all

There is no way to say "this event's `tenant` tag is `reference('tenantMapper')->of($this->orderId)`" (E10)
**[verified]**. The only computed source is a no-argument method on the event.

*Confusion:* asymmetric with the read side, and the natural workaround (compute in the handler, pass to the
constructor) is never stated in the upgrade guide.

*Smallest coherent fix:* **document the rule** — an event's tags are a function of the event alone, because tags
are resolved above the serializer and must be reproducible from a stored event during a backfill. A service-backed
tag value belongs in the handler that constructs the event.

### G6 — `symfony/expression-language` is not a declared dependency of the feature

A production install of `ecotone/ecotone` that writes a string `#[Fetch]` gets a bare `\InvalidArgumentException`
per message, with no model, tag or handler **[read]**.

*Smallest coherent fix:* add it to `suggest`, and fail at **bootstrap** — when a string expression exists and the
library does not — naming the handler, parameter and `composer require symfony/expression-language`. Folds into
G1's compile pass.

### G7 — Silent boolean coercion

`#[Fetch('true')]` becomes tag value `"1"` **[verified, H7]**; `false` becomes an "empty value" error (I3). Always
a mistake, caught in one direction only.

*Smallest coherent fix:* reject `bool` in `EventTagValueNormalizer` with "a tag value must be a string, int, float
or Stringable; got bool — did you mean to compare instead of return?".

### G8 — A `#[Fetch]`-ed aggregate in a decision-model handler evaluates its expression twice

Once for `FetchedAggregateCounterCapture`, once for `FetchAggregateConverter` **[read]**. Invisible with a pure
expression; with `reference('mapper')` it is two calls, inside the transaction, per message.

*Smallest coherent fix:* resolve the identifiers once in the batch loader and carry them in
`DecisionModelLoadedState` for the converter to read — the state header already exists for exactly this reason.

### G9 — A service call in a tag expression runs inside the database transaction

Structural, not a bug: the batch loader is a before interceptor at precedence 1001, inside the transaction at
-2000 **[read]**. An HTTP-backed mapper therefore holds a database transaction open for its latency.

*Smallest coherent fix:* say so in the docs — a tag mapper must be local and fast (an in-memory map, a hash, a
lookup on the same connection), never a network call.

---

## Part 3 — Design proposal: one model for dynamic tag values

### 3.1 The shape

**Keep `#[Fetch]` as the one expression attribute. Do not add `#[TagValue]` or `#[Tag]`.**

The maintainer's naming rule (§4.3 Decision 5: "`#[Fetch]` for explicit mapping") already settled this, and a
second attribute would have to answer, for every parameter, which one wins. `#[Fetch]` already means exactly one
thing — *"this parameter's subject is identified by this expression"* — and it means it uniformly for a decision
model's tag values, an aggregate-backed model's identifier and a fetched aggregate's identifier. The work below
is to make that sentence true without exceptions, not to add vocabulary.

### 3.2 The guaranteed context, identical on every producer

One table, enforced by one shared helper rather than by three call sites:

| | String expression | Closure (PHP 8.5) |
|---|---|---|
| `payload` | raw message payload | first parameter, or `#[Payload]` |
| `headers` | all headers, as an array | `#[Headers]`, or `#[Header('name')]` for one |
| `value` | the payload, as an alias of `payload` | — |
| `reference('name')` | container service by id | type hint, or `#[Reference('name')]` |
| `parameter('name')` | configuration variable | `#[ConfigurationVariable('name')]` |
| the `Message` itself | **not exposed** | a `Message`-typed parameter |

Everything in that table except `value`-on-tag-models is already true **[verified]**; `value` is one argument in
`DecisionModelParameterLoader`. The `Message` is deliberately left out of the string grammar: exposing it would
make expressions depend on framework types, and the closure form already covers the rare case.

Implementation shape — a stateless value object built at bootstrap, so the three loaders cannot drift again:

```php
final class TagExpressionContext
{
    public static function forMessage(Message $message): array
    {
        return ['value' => $message->getPayload()];
    }
}
```

and `DecisionModelParameterLoader`, `AggregateBackedDecisionModelLoader`, `FetchAggregateConverter` and
`FetchedAggregateCounterCapture` all call `execute($message, TagExpressionContext::forMessage($message))`.
No new interface, no new service, no state.

### 3.3 How a service-backed mapping is evaluated

Already correct, and the design should state it as a guarantee rather than leave it as an accident **[read +
verified]**:

- `reference('mapper')` resolves through the container's `ReferenceSearchService` at evaluation time. The mapper
  is an ordinary singleton service; nothing is cached, nothing is keyed by message id.
- `AttributeExpressionExecutor` holds only the compiled expression and the parameter resolvers — the `Message` is
  an argument. It satisfies the 2026-09-28 "services injected through constructors must be stateless" rule
  as it stands.
- The evaluation is **once per injected parameter, per message** (F12, F12b), inside
  `DecisionModelBatchLoader::load()`, before the single `loadByCriteria()`. Two parameters carrying the same
  expression are evaluated twice; this is correct, because an expression may be impure, and deduplicating it
  would be a behaviour the user cannot see or control.
- G8 is the one place the guarantee is currently broken (a fetched aggregate is resolved twice); fixing it makes
  "one evaluation per parameter" true everywhere.

The cost is **one service call per parameter per message, inside the transaction** (G9). That is the honest
statement for the docs; it is also why a "cache the mapping" facility must not be offered — a per-message cache
is the stateful-collector pattern the 2026-09-28 decision deleted.

### 3.4 Bootstrap validation when the value is dynamic

`DecisionModelTagResolvabilityGuard` checks static properties and today skips `#[Fetch]` parameters entirely
(G1). It cannot evaluate an expression — there is no message at bootstrap, and a dry evaluation would call the
user's mapper at container build. **The proposal is compile-only, plus a shape check, and no dry run.**

A new bootstrap pass — call it `DecisionModelExpressionGuard`, a sibling of the existing guard, running over the
same handler scan:

1. **The library is present.** A string expression anywhere and no `symfony/expression-language` →
   `ConfigurationException` naming the handler, the parameter and `composer require symfony/expression-language`
   (G6). Closure expressions skip this.
2. **The expression parses.** `ExpressionLanguage::parse($expression, ['payload', 'headers', 'value',
   'referenceService'])` — Symfony's parser rejects an unknown variable and a syntax error alike, so one call
   covers I1, F5a-class typos and an unknown variable. The message quotes the expression and the parser's own
   error (G1).
3. **The services it names exist.** `reference('name')` literals are extractable from the parsed node tree;
   each is checked against the container's known references, failing with the handler, the parameter and the
   name (I2). Only literal arguments are checkable; `reference(payload.x)` is left to runtime, which is right.
4. **The shape matches the model.** For a **multi-tag** model, when the expression's root node is an array
   literal, its keys must be exactly the model's tag names — the H4 and wrong-key cases become bootstrap
   errors. When it is anything else, nothing is claimed.
5. **Closures** get 1–3 for free (they are compiled into `ClosureParameterResolver`s at build time already, and
   an unresolvable parameter is a build-time failure); only 4 applies, and only by return type when one is
   declared (`: array` for a multi-tag model, `: string|int|Stringable` for a single-tag one).

What stays runtime-only, by construction: whether a header is present, what a mapper returns, and whether the
value normalises. Those get G2's message.

### 3.5 Error messages

One template, at both the boundary of the expression and the normaliser, naming **handler → parameter → model →
tag → expression → what came back**:

```
#[Fetch] on $coupon in OrderService::place (DecisionModel CouponRedemptions) did not resolve tag 'coupon'.
Expression: headers['couponCode']
It returned: null
A single-tag model needs a scalar or Stringable value. A nullable parameter (?CouponRedemptions) may receive null.
```

```
#[Fetch] on $usage in CheckoutService::redeem (DecisionModel CustomerCouponUse) did not fill every tag.
Expression: reference('mapper').mapToBoth(payload.orderId)
It returned: array with keys 'account', 'customer'
This model is scoped by 'customer' and 'coupon'; the expression must return an array keyed by those names.
```

And, when the user's own service throws (F15), the exception is wrapped once so the handler, parameter, model and
expression are on the message, with the original as `$previous`.

### 3.6 `#[DecisionBoundary]` — the escape hatch, opened

The boundary already receives the command built by the same compiled `PayloadBuilder` the loaders use. Extend it
to the ordinary parameter rules (G3) while keeping the matching rule untouched:

```php
#[DecisionBoundary]
public static function boundary(
    RateCourse $command,
    #[Header('tenant')] string $tenant,
    TenantCourseMapper $mapper,
): EventCriteria {
    return EventCriteria::tag('tenant', $tenant)
        ->andTag('course', $mapper->courseOf($command->rating));
}
```

`assertBoundaryShape()` changes from "exactly one parameter" to "**at least** one parameter, the first a class or
interface"; the remaining parameters are compiled with `ParameterConverterAnnotationFactory::getConverterFor()`,
which is what every handler already uses, so `#[Header]`, `#[Headers]`, `#[Reference]`,
`#[ConfigurationVariable]` and a bare service type hint all work with no new code. Static stays: the method is
still called before any instance exists, and services arrive as arguments rather than through a constructor,
which keeps it stateless.

This is the right home for the genuinely hard cases — a boundary over several values, an OR of criteria, a
tenant-qualified scope — and with headers and services it becomes a complete escape hatch rather than a
command-only one.

`#[DecisionBoundary]` on a `#[QueryHandler]` stays rejected (F11): a query appends nothing, so a boundary would
guard nothing. The message should say that, rather than "scopes no handler".

### 3.7 Event-side values

**Recommendation: do not add `#[EventTag('order', expression: …)]`.** Three reasons, in order of weight:

1. Tags are resolved from the event object **above the serializer** (§4.3) and must be reproducible from a
   *stored* event by `backfill-tags`. An expression that reads `headers` or calls a container service is not
   reproducible: the backfill has no message and no request context, so a backfilled tag would silently differ
   from the one written at append time — exactly the "index disagrees with the events" failure §4.8 is built to
   avoid.
2. It would make a tag a function of ambient state, which breaks the §4.3 invariant that the same event class
   always produces the same tag names, and with it the bootstrap checks that a model's handled events carry its
   tags.
3. The method source already covers every reproducible computation (hashing an identifier, deriving a composite),
   and a service-backed value belongs in the handler that constructs the event, where it is visible, testable and
   recorded in the event's own data.

The work here is **documentation** (G5): one paragraph in `upgrade-2.0.md` and §4.3 saying that an event's tags
are a pure function of the event, that a computed value goes through a public no-argument method, and that a
mapper-derived value is computed by the handler and passed to the event's constructor — with the mapper-derived
example below showing both halves.

`EventTagValueNormalizer` stays the single normaliser for both sides (it already is), plus the `bool` rejection
from G7 — which is a change on the event side too, and correct there for the same reason.

### 3.8 Usage examples

**Header-derived tenant tag.** Works today except that the tenant must also reach the event.

```php
#[DecisionModel(tags: ['tenant', 'username'])]
final class TenantUsernameTaken
{
    private bool $taken = false;

    #[EventSourcingHandler]
    public function registered(UserRegistered $event): void { $this->taken = true; }

    public function isTaken(): bool { return $this->taken; }
}

final class Registrations
{
    #[CommandHandler]
    public function register(
        RegisterUser $command,
        #[Header('tenant')] string $tenant,
        #[Fetch("{'tenant': headers['tenant'], 'username': payload.username}")] TenantUsernameTaken $existing,
    ): array {
        if ($existing->isTaken()) {
            throw new UsernameTaken($command->username);
        }

        return [new UserRegistered($tenant, $command->username)];   // the tag value is *in* the event
    }
}

final readonly class UserRegistered
{
    public function __construct(
        #[EventTag('tenant')]   public string $tenant,
        #[EventTag('username')] public string $username,
    ) {}
}
```

With `withFilterOnlyTags(['tenant'])` this is the §4.3 mixed scope: folded per tenant, guarded on `username`.
**Verified working end to end (H5).**

**Mapper-derived tag.** The maintainer's example, with the write side completed.

```php
final class Orders
{
    #[CommandHandler]
    public function place(
        PlaceOrder $command,
        OrderToAccountMapper $mapper,
        #[Fetch("reference('orderToAccountMapper').accountOf(payload.orderId)")] AccountBalance $balance,
    ): array {
        $accountId = $mapper->accountOf($command->orderId);

        if ($balance->available() < $command->total) {
            throw new InsufficientFunds($accountId);
        }

        return [new OrderPlaced($command->orderId, $accountId, $command->total)];
    }
}

final readonly class OrderPlaced
{
    public function __construct(
        public string $orderId,
        #[EventTag('account')] public string $accountId,
        public int $total,
    ) {}
}
```

The mapper is called on both sides of the same handler — once by the expression to scope the read, once by the
handler to put the value in the event. That duplication is the honest consequence of §3.7, and the docs should
show it rather than hide it; a user who dislikes it injects the mapper only and uses `#[DecisionBoundary]`.
**Verified working (E1, and the closure form E4.)**

**Multi-tag map from one expression.**

```php
#[CommandHandler]
public function redeem(
    RedeemCoupon $command,
    #[Fetch("reference('couponMapper').scopeOf(payload.orderId)")] CustomerCouponUse $usage,
): array
```

where `scopeOf()` returns `['customer' => …, 'coupon' => …]` — the keys must be the model's tag names, which
§3.4 step 4 makes a bootstrap error when the expression is an array literal and a §3.5 runtime message otherwise.
**Verified working (E8).**

**Aggregate-backed model id from a header.**

```php
#[DecisionModel(aggregate: Wallet::class)]
final class WalletBalance
{
    private int $balance = 0;

    #[EventSourcingHandler]
    public function credited(WalletCredited $event): void { $this->balance += $event->amount; }

    public function balance(): int { return $this->balance; }
}

#[CommandHandler]
public function charge(
    ChargeCurrentWallet $command,
    #[Fetch("headers['walletId']")] WalletBalance $wallet,
): array
```

**Verified working (E13)** — the resolved identifier feeds both `loadAggregateEvents()` and the
`aggregate_Wallet` counter tag, so the two cannot disagree (§4.12).

---

## Part 4 — Constraints check

| Rule | Verdict |
|---|---|
| **Stateless services, no static caches** | Holds. `AttributeExpressionExecutor` takes the `Message` as an argument; `TagExpressionContext::forMessage()` is a pure static function returning an array, not a cache. The new bootstrap guard runs at container build and holds nothing. `SymfonyExpressionEvaluationAdapter` keeps an `ExpressionLanguage` parse cache — *instance* state on a singleton, message-independent, and already shipped. §3.3 explicitly declines a per-message evaluation cache. |
| **No nullable service dependencies** | Holds. `ExpressionEvaluationService` is registered unconditionally with a stub fallback (`RegisterSingletonMessagingServices:63`); §3.4 step 1 turns the stub into a *bootstrap* error when a string expression exists, without making the service conditional. The boundary's extra parameters are ordinary converters, not optional injections. |
| **No single-implementation interfaces** | Holds. Nothing new is an interface. `TagExpressionContext` is a final class with one static method; `DecisionModelExpressionGuard` is a final class with one static method, matching `DecisionModelTagResolvabilityGuard`. `EventTagValueSource` keeps its three implementations and gains none. |
| **CQS** | Holds. Expression evaluation is a query; the boundary method returns `EventCriteria` and is forbidden from appending by construction; §3.7 keeps a mapper-derived *event* value in the handler that already commands. |
| **Black-box tests** | Holds — Part 5 is `EcotoneLite` only: no reflection into Ecotone internals, no direct SQL against the tag tables, behaviour observed through handlers, thrown exceptions and appended events. |
| **Enterprise gating** | Holds. `#[Fetch]`, `#[DecisionModel]` and `#[DecisionBoundary]` are all `licence Enterprise` and DCB is gated at bootstrap behind `DynamicConsistencyBoundaryConfiguration` (2026-09-28). Nothing proposed is reachable open-core; the new guard runs only over handlers that already require the licence. |
| **Names** | `TagExpressionContext`, `DecisionModelExpressionGuard` follow the existing `DecisionModelTagResolvabilityGuard` / `EventTagValueNormalizer` pattern — self-describing, no docblocks needed. No new user-facing name is introduced: the user still writes `#[Fetch]`. |
| **Capture-before-read** | Preserved. Expressions are evaluated in `DecisionModelBatchLoader::load()` *before* the single `loadByCriteria()` that captures counters — the same place they are today. G8's fix moves an evaluation earlier, never later. |
| **One load per handler** | Preserved. No proposal adds a store round trip. G8 *removes* a duplicate expression evaluation; §3.6's boundary parameters are resolved from the message, not from the store. |
| **No comments in code** | The proposed classes are one method each, named for what they do. |

The one place where a rule is under tension is **G9**: a `reference()` call inside the transaction is the user's
choice, not the framework's, and the framework cannot move the evaluation out of the transaction without breaking
capture-before-read. The resolution is documentation, not a mechanism.

---

## Part 5 — Test plan

Black-box `EcotoneLite` throughout, in the existing
`packages/Ecotone/tests/Modelling/DecisionModel/` suite. Method names carry the assertion; no docblocks, no
inline comments, no assertion messages.

**`DecisionModelFetchTest` (in-memory) — extend**

- `test_fetch_expression_maps_a_tag_value_through_a_container_service`
- `test_fetch_expression_reads_a_tag_value_from_a_header`
- `test_fetch_expression_reads_a_tag_value_from_a_nested_payload_path`
- `test_fetch_expression_resolves_value_as_an_alias_of_payload` *(G4)*
- `test_fetch_expression_maps_several_tags_of_one_model_through_one_service_call`
- `test_fetch_expression_combining_a_header_and_a_payload_property_scopes_a_mixed_filter_only_model`
- `test_fetch_expression_resolving_a_configuration_variable_scopes_a_model`
- `test_fetch_expression_is_evaluated_once_per_injected_parameter` *(a counting service; 1 for one parameter, 2 for
  the same model injected twice — observable in userland because the service is the user's)*
- `test_fetch_expression_resolving_a_boolean_is_rejected_naming_the_model_and_the_tag` *(G7)*
- `test_fetch_expression_failing_inside_the_service_names_the_handler_model_and_expression` *(G2/§3.5)*
- `test_fetch_expression_returning_a_map_with_wrong_keys_names_the_expected_tag_names` *(G2)*

**`DecisionModelValidationTest` (in-memory) — extend, bootstrap guards**

- `test_syntactically_invalid_fetch_expression_is_rejected_at_bootstrap_quoting_the_expression` *(G1)*
- `test_fetch_expression_naming_an_unknown_service_is_rejected_at_bootstrap` *(G1)*
- `test_fetch_expression_map_literal_with_keys_other_than_the_models_tags_is_rejected_at_bootstrap` *(G1)*
- `test_fetch_expression_using_an_unknown_variable_is_rejected_at_bootstrap`

**`DecisionModelHandlerVariantsTest` — extend**

- `test_query_handler_reads_a_model_scoped_by_a_service_backed_expression`
- `test_event_handler_reads_a_model_scoped_by_a_header_expression`
- `test_event_sourcing_aggregate_command_handler_reads_a_model_scoped_by_a_service_backed_expression`

**`DecisionBoundaryTest` — extend** *(G3)*

- `test_boundary_scopes_a_handler_by_a_tag_read_from_a_header`
- `test_boundary_builds_criteria_through_an_injected_service`
- `test_boundary_whose_first_parameter_is_not_the_handlers_command_is_rejected_at_bootstrap`
- `test_boundary_on_a_query_handler_is_rejected_explaining_that_a_query_appends_nothing`

**`AggregateBackedDecisionModelTest` — extend**

- `test_aggregate_backed_model_identifier_resolved_from_a_header`
- `test_aggregate_backed_model_identifier_mapped_through_a_container_service`

**Closure variants** — one `#[RequiresPhp('>= 8.5.0')]` class beside `ClosureExpressionTest`:

- `test_closure_fetch_expression_maps_a_tag_value_through_a_type_hinted_service`
- `test_closure_fetch_expression_reads_a_tag_value_from_a_header_parameter`
- `test_closure_fetch_expression_needs_no_expression_language_library` *(G6 — the closure path never touches it)*

**Dbal (`packages/PdoEventSourcing/tests/…`), every engine the DCB suite already covers** — the point is that a
dynamically resolved value indexes and guards identically to a statically resolved one, so two tests suffice:

- `test_service_mapped_tag_value_folds_the_same_events_as_a_property_tag_value`
- `test_header_mapped_tag_value_guards_the_append_against_a_competing_write` *(the two-connection pattern from
  `DeduplicationModuleTest:141`, per Part 4½ #7)*

**Not tested, by policy.** Evaluation happening inside the transaction (G9) and the single `loadByCriteria()` are
statement-level facts, not observable through userland — protected by code review, as §4.4 already states for the
one-load guarantee.

### Docs to touch

| File | Change |
|---|---|
| `upgrade-2.0.md`, DCB section (~line 600) | The tag-value contract gains the expression half: available variables and functions, that it works on every handler kind, the mapper-derived example with **both** sides, and the "a tag mapper must be local and fast — it runs inside the transaction" warning (G9) |
| `upgrade-2.0.md`, DCB section (~line 615) | `#[DecisionBoundary]` gains headers and services (G3) |
| `upgrade-2.0.md`, DCB section (~line 545) | One paragraph: an event's tags are a pure function of the event; a computed value uses a public no-argument method; a mapper-derived value is computed by the handler and passed to the constructor (G5) |
| `2026-09-20-dcb-design.md` §4.3 | Same rule, with the backfill reproducibility argument |
| `2026-09-20-dcb-design.md` §4.4 | "Tag values come from the message" gains the guaranteed context table and the bootstrap-validation rules |
| `2026-09-20-dcb-design.md` §4.12 | The identifier paragraph points at the same shared context |
| `2026-09-20-dcb-design.md` Part 8 | Decision-log row once the maintainer decides |
| `packages/Ecotone/composer.json` | `symfony/expression-language` added to `suggest` (G6) |

---

## Part 6 — Open questions, with recommended answers

**OQ1 — Is `#[Fetch]` the right attribute for tag values, or should a dedicated `#[TagValue]` exist?**
*Recommended: keep `#[Fetch]`.* Decision 5 already named it, it is one sentence ("this parameter's subject is
identified by this expression") that holds for all three producers, and a second attribute needs a precedence rule
for no gain. **Low risk.**

**OQ2 — Compile-only validation, or a dry evaluation at bootstrap?**
*Recommended: compile-only (§3.4).* A dry evaluation would call the user's mapper at container build, with a
fabricated message — a side effect at boot, and a false verdict either way. Parse + reference-existence + map-key
shape catches every failure observed in this research except "the header was absent" and "the mapper returned the
wrong thing", which are genuinely runtime. **Low risk.**

**OQ3 — Should runtime tag-resolution failures keep throwing `ConfigurationException`?**
*Recommended: introduce a runtime sibling, and keep `ConfigurationException` for bootstrap.* Today one class
covers both (F7, I3, I4), so an operator cannot tell a boot problem from a bad message, and a retry policy cannot
distinguish them either. A `TagValueNotResolvable` extending Ecotone's runtime messaging exception, thrown by the
loaders and the normaliser's re-wrap, would fix that. **Medium risk** — it changes what user code may already
catch on the 2.0 branch; the maintainer should decide whether 2.0 is still free enough for that. If not, keep
`ConfigurationException` and take §3.5's message improvements alone.

**OQ4 — Is `symfony/expression-language` a hard requirement of DCB, optional with a bootstrap message, or should
closures be the only dynamic route?**
*Recommended: optional, with a bootstrap message (§3.4 step 1).* It is `require-dev` today and nothing in DCB
needs it — the convention path is reflection, the closure path bypasses it entirely. Promoting it to `require`
would put a dependency on every open-core install for an Enterprise feature. The current failure mode (a bare
`\InvalidArgumentException` per message) is the actual problem, and a bootstrap error plus a `suggest` entry fixes
it. **Low risk.**

**OQ5 — Should `#[DecisionBoundary]` receive headers and services?**
*Recommended: yes (§3.6).* It is documented as the escape hatch for a boundary no model expresses, and the most
common such boundary is tenant-scoped. The change reuses `ParameterConverterAnnotationFactory` wholesale, keeps
the method static and stateless, and does not touch the matching rule. **Low risk.**

**OQ6 — Should `#[EventTag]` gain an expression?**
*Recommended: no (§3.7).* Tags must be reproducible from a stored event by `backfill-tags`; an expression reading
headers or a service is not. Document the rule and the handler-computes-it pattern instead. **Low risk, but this
is the question the maintainer most likely meant to ask** — if the answer is "yes anyway", the follow-up question
is what `backfill-tags` does with such an event, and the honest answer is that it must refuse to backfill that
event class, which is a worse contract than not having the feature.

**OQ7 — Should the same expression on two parameters be evaluated once?**
*Recommended: no.* An expression may be impure; deduplicating it would make the number of service calls depend on
how many parameters happen to share a string. G8 is different — that is *one* parameter evaluated twice, which is
a defect. **Low risk.**

**OQ8 — Should `value` remain, or should the alias be dropped in favour of `payload` alone?**
*Recommended: keep it and make it universal (§3.2).* It is already public API on `#[Fetch]`-ed aggregates and
aggregate-backed models; removing it would break users, and the asymmetry is the bug, not the alias. **Low risk.**

**OQ9 — Does a tag expression's service call inside the transaction need a mechanism, or a warning?**
*Recommended: a warning in the docs (G9).* Moving the evaluation outside the transaction would break
capture-before-read; a timeout mechanism would be a second concurrency story for a case the user creates. The
docs should say a tag mapper is a local lookup, never a network call. **Low risk** — but worth the maintainer's
eye, because it is the one constraint this feature imposes that a user cannot discover from the API.
