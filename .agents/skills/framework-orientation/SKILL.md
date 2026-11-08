---
name: framework-orientation
description: >-
  Entry point for developing the Ecotone framework in the ecotone-dev monorepo: what the
  project is, where packages live, and which framework-* skill holds the rules for each
  kind of work. Use when starting any task in this repository, before the first edit,
  or when unsure which framework skill applies.
---

# Developing the Ecotone framework

Ecotone is the enterprise architecture layer for Laravel and Symfony: CQRS, Event Sourcing, Sagas, Projections,
Workflows and Outbox messaging via PHP 8 attributes. This repository is the monorepo every `ecotone/*` package is
split from. [references/project.md](references/project.md) has the package table, architecture patterns and
canonical code shapes.

## Applies when

- Any task in this repository, before the first edit: load this skill, then the skills the task map below names
- You are unsure which rules govern the change you are about to make

## Does not apply when

- Writing an application that *uses* Ecotone: that is `api/skills/ecotone-*`, the public skills

## Task map

| Work | Load |
|---|---|
| Running tests, containers, phpstan, cs-fixer, CI failures | `framework-dev-environment` |
| Writing or changing a test | `framework-testing` |
| An exception, a bootstrap guard, a configuration mistake | `framework-error-handling` |
| A service, its dependencies, a module's `prepare()`, an interface | `framework-service-design` |
| Anything under `Api/`, a docblock, a name, a console option, a header | `framework-public-api` |
| A `licence Enterprise` file or feature | `framework-enterprise-licensing` |
| Storage, a table, a transaction, locking, an in-memory twin | `framework-storage-and-transactions` |
| A new module or package | `framework-module-scaffolding` |
| A design, a task brief, a numbered review | `framework-design-and-briefs` |
| Committing, pushing, opening a pull request | `framework-pull-request` |
| Reviewing a change in a review session | `framework-review-guidelines`, `framework-review-breaking-changes`, `framework-review-behaviour` |
| Adding or changing a rule, a skill, a check, `AGENTS.md` | `framework-agent-tooling` |

The five mistakes that cost the most, because each means throwing work away: tests that reach past the userland
surface (`framework-testing` rule 10), exception messages that do not name the way out (`framework-error-handling`
rule 1), nullable or defaulted service dependencies (`framework-service-design` rules 2, 2a), code before its
failing test (`framework-testing` rule T1), and names written from memory (rule O2).

Most code changes need three: the area skill, `framework-testing`, and `framework-pull-request` at the end.

## Rules

### O1. Load the skills the task needs before the first edit
Pick every row of the task map the change touches and load those skills; do not work from memory of the rules.
- **Expected:** before editing, the agent names (or loads) the framework skills matching the task, e.g. a new DBAL
  feature loads `framework-storage-and-transactions` and `framework-testing`.
- **Verified by:** `eval:choose-skills`

### O2. Verify every name against the tree before writing it
Every attribute, parameter, method, class and console option is checked against the current tree first. The API
moved in 2.0 (`Ecotone\Api\Attribute\CommandHandler`, not the old flat namespace); `upgrade/namespace-map-2.0.csv`
maps old to new. [references/verify-names.md](references/verify-names.md) has the evidence.
- **Expected:** the agent searches the tree (or the namespace map) for a class before using it and never presents
  a 1.x namespace such as `Ecotone\Modelling\Attribute\CommandHandler` as current.
- **Verified by:** `eval:choose-skills`, `check:agent-skills` (public skill imports must resolve)

### O3. AGENTS.md stays a pointer
`AGENTS.md` is loaded on every task, so it holds only the pointer to this skill (at most 200 characters). A new
instruction goes into the skill whose *Applies when* covers it.
- **Expected:** `AGENTS.md` is at most 200 characters; `CLAUDE.md` and `.claude/skills` are symlinks into it and
  `.agents/skills`.
- **Verified by:** `check:agent-skills`

## References

- [references/project.md](references/project.md) — package table, architecture patterns, handler/aggregate/
  configuration shapes, documentation resources. Load when you need the layout or a canonical example.
- [references/verify-names.md](references/verify-names.md) — where the rule numbering and the evidence come from.
