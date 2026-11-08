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

## References

- [references/rules.md](references/rules.md) — rules 2–5, 13, 13a, 15, 17, 18 in full.
