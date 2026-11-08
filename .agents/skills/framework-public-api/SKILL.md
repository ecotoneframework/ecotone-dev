---
name: framework-public-api
description: >-
  Ecotone framework rules for the public surface and naming: Api/ as a sibling of src/,
  the boundary in both directions, names instead of comments and docblocks, the Api/ sweep
  rules (readonly properties, with*() immutability), console option names, and framework
  header naming. Use when adding or changing a class under Api/, a public signature, a
  docblock or comment, a console command option, or a message header name in the
  ecotone-dev repository.
---

# Public API, names and docblocks

[references/rules.md](references/rules.md) has the reasoning, wrong/right pairs and evidence for each rule.

## Applies when

- Adding, moving or changing a class under any package's `Api/`
- Writing a comment or docblock, or naming a class, method or concept
- Adding a console command option or a framework message header

## Rules

### 6. Names carry the meaning; no comments, no descriptive docblocks
Array shapes, generics and `@link https://docs.ecotone.tech/...` are the only allowed docblocks.
- **Expected:** new code has no inline comments and no prose docblocks; only shape/generic/`@link` docblocks.
- **Verified by:** `eval:add-public-class`

### 6a. One word per concept, through the whole path
A concept keeps one name from the attribute to the table: the configuration option, method, parameter, header and
column all use the same word, never a synonym.
- **Expected:** one concept introduced by the change carries one word across the class, method, option, console
  parameter and header names (e.g. `retention` everywhere, not `retention` in one and `ttl`/`keep` in another).
- **Verified by:** `eval:add-public-class`

### 6b. `Api/` is swept, not grandfathered
No prose docblock and no `@param`/`@return` restating the signature; the Enterprise marker under `Api/` is
`licence Enterprise` plus the one `Use requires` sentence; a getter that only returns a property becomes that
property made `public readonly`; immutability via promoted readonly properties and `with*()` returning
`new self(...[...get_object_vars($this), 'field' => $value])` (`new static` and a `final private` constructor on a
non-final class), never on a class whose parent or trait holds state.
- **Expected:** a new `Api/` value class uses promoted `public readonly` properties and `with*()` methods returning
  a new instance; no plain getters, no writable properties.
- **Verified by:** `eval:add-public-class`

### 12. The public surface is `Api/`, a sibling of `src/`
Never `src/Api/` (it breaks Tempest discovery). Everything outside `Api` is `@internal`.
- **Expected:** a class an application uses is placed in `packages/<Package>/Api/...` under `Ecotone\Api\...`.
- **Verified by:** `eval:add-public-class`

### 12a. The boundary holds both ways
Nothing an application writes stays outside `Api`, and no module, resolver or container plumbing moves into it.
An internal type is fine in a method the framework calls, a leak in one the application calls.
- **Expected:** no `src/` type appears in a public signature an application calls; no module/container class is
  placed under `Api/`.
- **Verified by:** `eval:add-public-class`

### 14. A console option's name is its PHP parameter name verbatim
camelCase, never kebab-case.
- **Expected:** `--partitionKey` for parameter `$partitionKey`, never `--partition-key`.
- **Verified by:** `eval:add-public-class`

### 14a. A framework header is named `ecotone_{module}_{feature}_{action}`
Lower snake_case, no dots, held in one constant. A header already written to a queue, outbox, dead letter or event
store is renamed only with a read path for its old name.
- **Expected:** a new header is e.g. `ecotone_dbal_deadletter_limit`, defined once as a constant.
- **Verified by:** `eval:add-public-class`

## References

- [references/rules.md](references/rules.md) — rules 6, 6a, 6b, 12, 12a, 14, 14a in full.
