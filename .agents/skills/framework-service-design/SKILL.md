---
name: framework-service-design
description: >-
  Ecotone framework rules for services and modules: no nullable or defaulted dependencies,
  stateless container services, query/command separation, interfaces only at real seams,
  attribute-plus-ServiceContext configuration compiled to Definitions, class metadata from
  InterfaceToCallRegistry, step-list orchestration, final and strict_types. Use when adding
  or changing a framework service, a constructor dependency, a module, an interface or a
  Definition in the ecotone-dev repository.
---

# Services, dependencies and modules

[references/rules.md](references/rules.md) has the reasoning, wrong/right pairs and evidence for each rule.

## Applies when

- Adding or changing a service class, its constructor, or how the container builds it
- Adding a module, a `Definition`, a compiler pass, or reading class/method metadata
- Introducing an interface, a cache, or a property that changes between messages
- Tracking something "current" while a message is handled: a stack, a flag, a scope, a held resource
- Making one flow behave differently from the others in code a message passes through: a replay, a retry, a
  console call, a test, a request, or adding an `if`, `match` or `?:` there

## Rules

### 2. Never take a nullable service dependency
Register unconditionally, decide at runtime. Split the class, add a null-object, make the parameter required, or
pick between two services in the container (`LicenceDecider` for Enterprise).
- **Expected:** no constructor parameter typed `?Service` or `Service|null`, and no `= null` default on a service
  dependency.
- **Verified by:** `eval:add-dependency`

### 2a. Never default a dependency either
`$clock = new NativeClock()` is rule 2 with the null spelled out: every construction site that forgets the argument
silently runs a second way.
- **Expected:** every service dependency is a required parameter passed by the container; no `new X()` default.
- **Verified by:** `eval:add-dependency`

### 3. Constructor-injected services are stateless
No accumulating properties, no static caches, no per-message collectors. Mutable state goes in a function-scoped
immutable value object carried on a message header.
- **Expected:** the service has no property written after construction and no static cache; per-message state
  travels on a header or a local value object.
- **Verified by:** `eval:add-dependency`

### 3a. State a service cannot avoid lives in `ExecutionScope`, never in a property
First remove the state: carry it on a header or a local value object (rule 3). Only state that is ambient by
nature (what the message being handled is, whether a transaction or a scope is open, which resource this
execution holds) may stay, and then the service reads it per call with
`ExecutionScope::stateOf($this, fn () => new <State>())` and keeps no property written after construction. One
process handles several messages at once under coroutines (Swoole, OpenSwoole) and fibers; a property is shared by
all of them, `ExecutionScope` gives each its own. The state ships with a test that interleaves two executions with
`Fiber::suspend()` in a handler and proves neither sees the other's state. The one exception is state that belongs
to the process and not to a message: resources handed from one execution to the next (the connection pool, AMQP
channels kept open) and a record of which execution holds what (the guard of a shared connection). It may sit in a
property, holds nothing of the message being handled, and ships with the same interleaving test.
- **Expected:** state a service needs across calls of one message is a small state class fetched with
  `ExecutionScope::stateOf($this, ...)` on every use, with no property on the service that holds anything of the
  message being handled, and the answer includes (or names) a test that interleaves two executions with fibers.
- **Verified by:** `eval:keep-ambient-state`

### 4. Do not mix queries with writes
A read never runs DDL, a mutator returns `void`, and the name says which it is (`popRecordedEvents()`, not
`getRecordedEvents()` that clears).
- **Expected:** a method that changes state returns `void` or is named for the mutation; a getter changes nothing.
- **Verified by:** `eval:add-dependency`

### 5. No interface with a single implementation
An interface marks a real seam: open core vs Enterprise, in-memory vs storage-backed, or a dependency inverted
across a package or layer boundary. Test: would removing it force a reference the wrong way?
- **Expected:** a new interface has two implementations or inverts a package/layer dependency; otherwise the
  concrete class is used.
- **Verified by:** `eval:add-dependency`

### 13. Configuration is attributes plus `#[ServiceContext]`
Compiled into the container via `DefinedObject`/`Definition`. No YAML, no XML.
- **Expected:** new configuration is an extension object returned from a `#[ServiceContext]` method, and what a
  module registers is expressible as a `Definition`.
- **Verified by:** `eval:add-dependency`

### 13a. Class metadata comes from the registry, never fresh reflection
`InterfaceToCall`/`ClassDefinition` via `InterfaceToCallRegistry`; on the message path take an
`InterfaceToCallReference` in the `Definition`.
- **Expected:** no `new ReflectionClass`/`ReflectionMethod` in framework code that the registry could answer.
- **Verified by:** `eval:add-dependency`

### 15. Orchestrating methods read as step lists
SQL belongs to the collaborator that owns the table.
- **Expected:** a coordinating method is a short sequence of named calls; SQL lives in the table-owning class.
- **Verified by:** `eval:add-dependency`

### 17. New files are `final` and `declare(strict_types=1)`
- **Expected:** every new PHP class file declares `strict_types=1` and the class is `final`.
- **Verified by:** `eval:add-dependency`

### 18. PHP 8.1+ features where they say something
Named arguments once past two parameters; enums, readonly, first-class callables where they carry meaning.
- **Expected:** a call with three or more arguments uses named arguments.
- **Verified by:** `eval:add-dependency`

### 22. A component has one path; a flow that needs another is routed where it starts
Framework code a message passes through does not decide what to do from which scenario the message belongs to: a
replay, a retry, a console call, a test, an HTTP request. For every `if`, `match` or `?:` added on the message path
or in the code that wires it, name what each part of the condition reads:

- **The scenario**: a header or marker another feature sets, the concrete class of a caller or collaborator, the
  runtime situation (inside a coroutine, a console command, an HTTP request). It may only refuse (throw). It never
  selects between two ways of handling a message, and never lets one scenario past a refusal the others get.
- **Configuration known when the application boots**: not a runtime branch. It is resolved when the container is
  built, into one of two collaborators.
- **The component's own contract**: data the component defines and documents as its input (a channel reading its
  delivery delay). It may be branched on.

The flow that differs is, in order of preference: sent to another target where it starts (another channel, a
routing slip, the endpoint directly); given an interceptor or decorator registered for that flow only; or served by
a collaborator chosen at container build, as Enterprise features are. In all three the other path already exists:
a second implementation of something the framework already does (invoking a handler, acknowledging, opening a
transaction) breaks the rule even with no `if`.
- **Expected:** a change that makes one flow behave differently adds no branch in the receiving component that
  reads another feature's header, a caller's or collaborator's class, or the runtime situation to pick how the
  message is handled or to skip a refusal; the flow is routed at its source, composed for that flow only, or chosen
  at container build, onto a path that already exists, and the only scenario-reading branches left are refusals.
- **Verified by:** `eval:route-replay-at-the-source`

## References

- [references/rules.md](references/rules.md) — rules 2–5, 13, 13a, 15, 17, 18, 22 in full.
