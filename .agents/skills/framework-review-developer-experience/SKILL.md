---
name: framework-review-developer-experience
description: >-
  Review session that checks a change to the Ecotone framework is simple to use for the
  developers building applications with it: the feature is switched on declaratively with
  an attribute or a ServiceContext configuration, works with sensible defaults, defaults to
  the resilient choice, and has feature and configuration names an end user understands
  without reading the implementation. Use when running the developer experience (DX) review
  before a push or PR, or when asked whether a feature, attribute, configuration or console
  command is easy to use, easy to understand or well named in the ecotone-dev repository.
---

# Developer experience review

You are one of four independent reviews (see `framework-pull-request` rule P3). You judge only what the change is
like to use for an end user: a developer building a Laravel, Symfony or Lite application with Ecotone. Project
rules, upgrade safety and correctness are the other three sessions' job.

## Applies when

- Running the developer experience review session before pushing or opening a pull request
- Asked whether a feature, an attribute, a configuration object or a console command is easy to use, easy to
  understand, or well named
- A change adds or alters anything an application developer writes, configures or reads: an attribute, a
  `#[ServiceContext]` configuration, a gateway, a console command, an exception message, a default

## Rules

### X1. Write the code an end user writes, smallest case first
Before judging, write out the complete application code needed to use the change in its most common case: the
attribute, the configuration, the call. Take it from the tests and the PR description, then count what the user
had to know: classes imported, options set, services registered, steps in order. Each of them needs a reason. A
change with no end-user surface (an internal refactor) gets the one-line verdict `NO USER-FACING SURFACE` and no
further findings.
- **Expected:** the report opens with the end user's usage code for the most common case and what it requires of
  them, or states that the change has no user-facing surface.
- **Verified by:** `eval:review-diff-developer-experience`, `eval:review-internal-refactor`

### X2. The feature is declared, not wired
Ecotone is configured declaratively: an attribute on the class or method the behaviour belongs to, or a
configuration object returned from a `#[ServiceContext]` method. The user states *what* they want and the framework
does the wiring. A feature that makes the user register a service by hand, call a registry at bootstrap, extend a
framework base class, implement a framework interface on a domain class, or repeat the same setting in two places
is a finding, with the declarative form it should take. Find the nearest existing feature in the tree (an
attribute under a package's `Api/Attribute`, a configuration object returned from a `#[ServiceContext]` method)
and follow the shape it is switched on with.
- **Expected:** imperative or duplicated set-up an end user must write is flagged with the attribute or
  `#[ServiceContext]` form that replaces it.
- **Verified by:** `eval:review-diff-developer-experience`

### X3. It works with no options set
The most common case needs no configuration beyond naming the feature: every option has a default, and the default
is what most applications want. A required option is justified only when the framework cannot know the value (a
channel name, an identifier). An option nobody would change is not an option. Check that the defaults agree with
the defaults of the features around it.
- **Expected:** each required option is either justified as unknowable or flagged with the default it should have.
- **Verified by:** `eval:review-diff-developer-experience`

### X4. The default is the resilient choice
When an option trades safety for speed or convenience, the default is the safe side: a failed message is kept and
retried or stored, not dropped; work is transactional, not best-effort; a message is delivered at least once, not
at most once; an unknown or ambiguous case is refused, not guessed. The less safe behaviour is an explicit opt-in
whose name says what is given up. A default that can lose a message, lose data or hide a failure is BLOCKING.
- **Expected:** every default that affects failure handling, delivery, transactions or data safety is named and
  judged; a default that loses a message, loses data or hides a failure is flagged BLOCKING with the resilient
  default to use instead.
- **Verified by:** `eval:review-diff-developer-experience`

### X5. Names explain the feature to someone who has not read the code
The feature name, the attribute, each parameter, each configuration method and each console option says what it
does in the application developer's vocabulary, with no abbreviation, no implementation term and no negated
boolean. A reader of the user's code understands the behaviour from the names alone (`framework-public-api` holds
the naming rules for `Api/`). The same concept keeps the name Ecotone already uses for it elsewhere: search the
existing attributes and the public `ecotone-*` skills before accepting a new word for an old idea.
- **Expected:** each unclear, abbreviated, implementation-flavoured or inconsistent name is flagged with the
  replacement.
- **Verified by:** `eval:review-diff-developer-experience`

### X6. The verdict is explicit and findings are actionable
Close the report with `SIMPLE TO USE`, `USABLE WITH CHANGES` or `HARD TO USE` (or, for a change rule X1 found to have no
end-user surface, `NO USER-FACING SURFACE` alone), followed by findings as
`[BLOCKING|SHOULD-FIX|NIT] <rule id> — <file>:<line> — what the end user has to do or misunderstands — the simpler
form`. Show the improved usage code when a finding changes it.
- **Expected:** the report states one of the three verdicts, or `NO USER-FACING SURFACE`, and each finding
  carries a severity, a rule id, a location, the cost to the end user and the simpler alternative.
- **Verified by:** `eval:review-diff-developer-experience`
