---
name: framework-error-handling
description: >-
  Ecotone framework rules for exceptions and configuration guards: messages that name
  every way out, refusing a misconfiguration at bootstrap, compile-time checks in a
  module's prepare(), and refusing ambiguous usages. Use when throwing or changing an
  exception, adding a guard or validation, or deciding how an attribute, configuration
  or gateway reacts to an unsupported usage in the ecotone-dev repository.
---

# Exceptions and configuration guards

The exception message is what the developer or agent reacts to; it is the deliverable.
[references/rules.md](references/rules.md) has the reasoning, wrong/right pairs and evidence for each rule.

## Applies when

- Throwing a new exception or rewording an existing one
- Validating an attribute, a `#[ServiceContext]` configuration, a gateway or a console option
- Deciding what happens for a placement, a parameter type or a combination the feature was not designed for

## Rules

### 1. Exceptions drive the solution
A message names what was wrong, why it cannot work, and *every* way out: the exact API call, attribute, option or
command. The message is asserted in a test.
- **Expected:** the exception message contains the offending name, the reason, and at least one concrete fix
  (an attribute, method, option or command); a test asserts the message text.
- **Verified by:** `eval:refuse-misconfiguration`

### 1a. A configuration mistake is refused at bootstrap
Never at runtime, and never read as an empty result. The guard and its message test ship in the commit that
states the rule.
- **Expected:** a misconfiguration throws a `ConfigurationException` while the application boots, not on the first
  message, and never silently yields an empty result.
- **Verified by:** `eval:refuse-misconfiguration`

### 1b. Failure context is a value object built at container-build time
Context an exception needs at runtime is computed once while the container is built and carried as a value
object, not recomputed per failure.
- **Expected:** runtime error context is a value object passed in through the `Definition`, not computed by
  reflection or lookups when the failure happens.
- **Verified by:** `eval:refuse-misconfiguration`

### 1c. A check that can run at compile time runs at compile time
In a guard the module calls from `prepare()`, which receives the `InterfaceToCallRegistry` for exactly that. Only
message *content* is left to runtime.
- **Expected:** a check on handler signatures or attribute placement lives in the module's `prepare()` path using
  `InterfaceToCallRegistry`, with no runtime branch for the unresolvable case.
- **Verified by:** `eval:refuse-misconfiguration`

### 1d. The common usage is the default; any other usage is refused
Never given a second meaning. For every attribute, configuration and gateway decide each placement, parameter
type, combining attribute (`#[Asynchronous]` first), repeated registration and contradicting setting: supported
with a test, or refused at bootstrap with a message test. When two readings are possible, refuse.
- **Expected:** for an unsupported or ambiguous usage (e.g. the attribute on a class, a second configuration, a
  contradicting option) the agent adds a bootstrap refusal with a message test rather than picking a reading.
- **Verified by:** `eval:refuse-misconfiguration`

## References

- [references/rules.md](references/rules.md) — rules 1–1d in full, with the commits that evidence them.
