# Ecotone Claude Code Skills - Implementation Spec

## Design Philosophy

Make contributions to Ecotone as simple and straightforward as possible. Contributors should not need to know every convention - Claude applies them by default through **model-invocable skills** that load automatically when relevant.

Three principles:
1. **Conventions by default** - Claude auto-applies the right patterns without being asked
2. **Fast feedback** - Skills guide Claude to verify early and often
3. **No CI surprises** - `prepare-contribution` catches everything before PR submission

## Skill Architecture

### Progressive Disclosure (3 Levels)

| Level | What | When Loaded | Token Cost |
|-------|------|-------------|------------|
| 1. YAML frontmatter `description` | Tells Claude *when* to use the skill | Always in system prompt | ~50-100 tokens per skill |
| 2. SKILL.md body | Tells Claude *how* to do it | When skill is invoked | Main instructions |
| 3. `references/` files | Deep reference material | When Claude reads them | On demand |

### YAML Frontmatter Fields

```yaml
---
name: skill-name                    # kebab-case, matches folder name
description: >-                     # WHAT + WHEN, third person, under 1024 chars
  Creates message handlers following Ecotone conventions.
  Use when writing command, event, or query handlers.
disable-model-invocation: false     # true = user-only (for side-effect skills)
user-invocable: true                # false = model-only (background knowledge)
allowed-tools: Read, Grep, Glob     # restrict tools if needed
context: fork                       # isolate in subagent context
agent: Explore                      # subagent type (with context: fork)
argument-hint: "[feature-name]"     # autocomplete hint
---
```

### Invocation Strategy

| Configuration | User invokes | Claude auto-invokes | Use for |
|---------------|-------------|--------------------|---------|
| Default (both) | Yes | Yes | Code-writing skills (tests, handlers, aggregates) |
| `disable-model-invocation: true` | Yes | No | Side-effect workflows (prepare-contribution) |
| `user-invocable: false` | No | Yes | Background knowledge skills |

**Key insight**: Most skills should be model-invocable so Claude automatically applies Ecotone patterns when a contributor asks to write code, without them needing to know about `/write-ecotone-test` or `/create-message-handler`.

### Dynamic Context Injection

The `` !`command` `` syntax runs shell commands and injects output into the skill prompt:

```markdown
## Current changes
- Modified files: !`git diff --name-only`
- Staged diff: !`git diff --cached`
```

Use this for workflow skills that need runtime context (git state, test output, etc.).

### File Structure

```
.claude/skills/
├── write-ecotone-test/
│   ├── SKILL.md
│   └── references/
│       ├── test-patterns.md          # Real test examples from codebase
│       └── ecotone-lite-api.md       # EcotoneLite/FlowTestSupport API
├── create-message-handler/
│   ├── SKILL.md
│   └── references/
│       └── handler-patterns.md       # Handler examples with all attributes
├── create-aggregate/
│   ├── SKILL.md
│   └── references/
│       └── aggregate-patterns.md     # State-stored and event-sourced examples
├── prepare-contribution/
│   ├── SKILL.md
│   └── references/
│       ├── ci-checklist.md           # Full CI checklist with exact commands
│       └── licence-format.md         # Licence header formats
├── create-ecotone-module/
│   ├── SKILL.md
│   └── references/
│       └── module-anatomy.md         # Module lifecycle and registration
├── debug-test-failure/
│   ├── SKILL.md
│   └── references/
│       └── common-errors.md          # Error messages and solutions
└── review-ecotone-code/
    ├── SKILL.md
    └── references/
        └── code-conventions.md       # Full conventions reference
```

---

## Skills

### Skill 1: `write-ecotone-test`

**Priority:** Critical - every contribution needs tests

```yaml
---
name: write-ecotone-test
description: >-
  Writes tests for Ecotone components using EcotoneLite bootstrapping,
  inline anonymous classes, and snake_case method names.
  Use when writing tests, adding test coverage, or testing handlers,
  aggregates, projections, or async message processing.
---
```

**What the SKILL.md body covers:**

1. **Bootstrap selection:**
   - `EcotoneLite::bootstrapFlowTesting()` for standard handler/aggregate tests
   - `EcotoneLite::bootstrapFlowTestingWithEventStore()` for event-sourced aggregate tests

2. **Test structure rules:**
   - `snake_case` method names (enforced by PHP-CS-Fixer)
   - High-level tests from end-user perspective, never test internals
   - Inline anonymous classes with PHP 8.1+ attributes (not separate fixture files)
   - No comments - descriptive method names only
   - Licence header: `/** licence Apache-2.0 */`

3. **Patterns covered** (with code examples in body + references):
   - Simple handler testing (command/event/query)
   - Aggregate testing with commands and events
   - Event-sourced aggregate testing with `withEventsFor()`
   - Async handler testing: `enableAsynchronousProcessing` + `releaseAwaitingMessagesAndRunConsumer()`
   - Service stubs via second argument to `bootstrapFlowTesting`
   - `ServiceConfiguration` with `ModulePackageList::allPackagesExcept()`
   - Projection testing with `triggerProjection()`

4. **Common mistakes to avoid:**
   - Using raw PHPUnit mocking instead of EcotoneLite
   - Creating separate fixture classes for test-only handlers
   - Testing implementation details instead of behavior

**References:**
- `references/test-patterns.md` - Real code examples of each pattern from the codebase
- `references/ecotone-lite-api.md` - EcotoneLite and FlowTestSupport API methods

---

### Skill 2: `create-message-handler`

**Priority:** High - fundamental Ecotone pattern

```yaml
---
name: create-message-handler
description: >-
  Creates Ecotone message handlers with proper PHP 8.1+ attributes
  and conventions. Use when creating command handlers, event handlers,
  query handlers, or async message processors.
---
```

**What the SKILL.md body covers:**

1. **Handler types and attributes:**
   - `#[CommandHandler]` - handles commands, returns void or identifier
   - `#[EventHandler]` - reacts to events
   - `#[QueryHandler]` - handles queries, returns data
   - `#[Asynchronous('channel-name')]` - marks handler for async processing

2. **Method signatures:**
   - Type-hinted message object as first parameter
   - Optional `#[Header('headerName')]` parameters for metadata
   - Return types matching the query/command contract

3. **Routing patterns:**
   - Class-based resolution (default) - message class maps to handler
   - Routing key: `#[CommandHandler('order.place')]` for string-based routing

4. **Conventions:**
   - PHPDoc on public APIs (`@param`/`@return`)
   - No comments - meaningful method names
   - Licence header
   - Follow existing patterns in the codebase

**References:**
- `references/handler-patterns.md` - Command, Event, Query, Async handler examples

---

### Skill 3: `create-aggregate`

**Priority:** High - core DDD pattern

```yaml
---
name: create-aggregate
description: >-
  Creates DDD aggregates following Ecotone patterns, including
  state-stored and event-sourced variants. Use when creating aggregates,
  entities with command handlers, or event-sourced domain models.
---
```

**What the SKILL.md body covers:**

1. **State-stored aggregate structure:**
   - `#[Aggregate]` on the class
   - `#[Identifier]` on the identity field
   - Static factory method with `#[CommandHandler]` for creation
   - Instance methods with `#[CommandHandler]` for state changes

2. **Event-sourced aggregate structure:**
   - `#[EventSourcingAggregate]` on the class
   - `#[EventSourcingHandler]` for applying events
   - Recording events via `recordThat()` / return from handler
   - `#[AggregateIdentifierMapping]` for command-to-aggregate ID mapping

3. **When to choose which:**
   - State-stored: simpler domains, no audit trail needed
   - Event-sourced: complex domains, full event history required

4. **Testing guidance** - link to `write-ecotone-test` patterns for aggregates

**References:**
- `references/aggregate-patterns.md` - State-stored and event-sourced examples from codebase

---

### Skill 4: `prepare-contribution`

**Priority:** High - ensures CI passes

```yaml
---
name: prepare-contribution
description: >-
  Validates code changes against Ecotone CI requirements before PR submission.
  Runs tests, checks code style, verifies licence headers, and ensures
  all quality gates pass.
disable-model-invocation: true
argument-hint: "[package-name]"
---
```

**Why `disable-model-invocation: true`:** This skill runs tests and code fixers (side effects). It should only run when the contributor explicitly asks.

**Dynamic context injection in SKILL.md:**

```markdown
## Current state
- Branch: !`git branch --show-current`
- Modified files: !`git diff --name-only`
- Uncommitted changes: !`git status --short`
```

**Validation workflow (order matters):**

1. **Run new/changed tests first** - `vendor/bin/phpunit --filter testMethodName tests/Path/To/TestFile.php` for fastest feedback
2. **Run full test suite for affected package** - `cd packages/PackageName && composer tests:ci` (PHPStan + PHPUnit + Behat)
3. **Verify licence headers** on all new PHP files (`/** licence Apache-2.0 */`)
4. **Fix code style** - `vendor/bin/php-cs-fixer fix` (auto-fixes, then dry-run to verify)
5. **Verify PHPStan passes** - `composer tests:phpstan`
6. **Check conventions:** test method names in `snake_case`, no comments, PHPDoc on public APIs
7. **PR description** follows template: Why / What / CLA checkbox

**References:**
- `references/ci-checklist.md` - Full CI checklist with exact commands
- `references/licence-format.md` - Licence header formats and placement

---

### Skill 5: `review-ecotone-code`

**Priority:** Medium - quality assurance

```yaml
---
name: review-ecotone-code
description: >-
  Reviews code for Ecotone convention compliance including attribute usage,
  test patterns, code style, and PHP 8.1+ requirements. Use when reviewing
  changes, checking code quality, or verifying Ecotone patterns.
  Do NOT use for general code reviews unrelated to Ecotone.
allowed-tools: Read, Grep, Glob
---
```

**Why `allowed-tools` restricted:** This is a read-only review skill. Restricting tools prevents accidental modifications during review.

**Review checklist:**

1. No comments in code (meaningful method names instead)
2. PHP 8.1+ features used (attributes, enums, named arguments)
3. Public APIs have `@param`/`@return` PHPDoc
4. Licence headers present on new files
5. Test methods in `snake_case`
6. Tests use `EcotoneLite::bootstrapFlowTesting()` (not raw mocking)
7. Tests are high-level / end-user perspective
8. Inline anonymous classes in tests (not separate fixture files)
9. Follows existing patterns in the codebase
10. `ServiceConfiguration` properly configured with `ModulePackageList`
11. Code style: single quotes, trailing commas in multiline, `! $var` spacing

**References:**
- `references/code-conventions.md` - Full coding conventions reference

---

### Skill 6: `create-ecotone-module`

**Priority:** Medium - less frequent but complex

```yaml
---
name: create-ecotone-module
description: >-
  Creates new Ecotone modules following the AnnotationModule pattern.
  Use when building new framework modules, registering custom message
  handlers, or extending the Ecotone module system.
---
```

**What the SKILL.md body covers:**

1. **Module class structure:**
   - `#[ModuleAnnotation]` attribute
   - Implements `AnnotationModule`
   - Extends `NoExternalConfigurationModule` when no external config needed

2. **Required methods:**
   - `create()` - static factory, receives `AnnotationFinder` and `InterfaceToCallRegistry`
   - `prepare()` - registers handlers/converters/services on `Configuration`
   - `canHandle()` - declares supported extension objects
   - `getModulePackageName()` - returns module identifier

3. **Registration:**
   - Register in `ModulePackageList`
   - Use `_PackageTemplate/` as starting point for new packages

**References:**
- `references/module-anatomy.md` - Full module lifecycle, registration, and real examples

---

### Skill 7: `debug-test-failure`

**Priority:** Medium - common contributor pain point

```yaml
---
name: debug-test-failure
description: >-
  Diagnoses Ecotone test failures by analyzing error messages, checking
  common configuration issues, and suggesting targeted fixes.
  Use when tests fail, CI is broken, or debugging Ecotone test setup.
---
```

**What the SKILL.md body covers:**

1. **Diagnostic workflow:**
   - Read the full error message and stack trace
   - Identify which category the failure falls into
   - Check the specific configuration area
   - Suggest a targeted fix (not broad changes)
   - Verify with `vendor/bin/phpunit --filter testName`

2. **Common failure patterns:**
   - `ModulePackageList` not configured correctly
   - Missing service in container (second arg to `bootstrapFlowTesting`)
   - Channel not configured for async tests
   - Database DSN not set for integration tests
   - Licence header missing (CI failure, not a test failure)
   - PHP-CS-Fixer violations (snake_case, imports)

3. **Environment issues:**
   - Docker container requirements for DB-dependent tests
   - Lowest vs highest dependency failures
   - PHP version compatibility

**References:**
- `references/common-errors.md` - Error messages mapped to solutions

---

## Implementation Plan

### Phase 1: Core Skills (Highest Impact)
1. `write-ecotone-test` - Every contribution needs tests
2. `create-message-handler` - Most common code pattern
3. `prepare-contribution` - Ensures CI passes

### Phase 2: Pattern Skills
4. `create-aggregate` - Core DDD pattern
5. `review-ecotone-code` - Quality gate

### Phase 3: Advanced Skills
6. `create-ecotone-module` - Package development
7. `debug-test-failure` - Contributor support

### Reference File Guidelines

Reference files provide the "third level" of progressive disclosure. Guidelines:
- **One topic per file** - focused and scannable
- **Real code from the codebase** - not abstract examples
- **Under 500 lines** - keep context cost manageable
- **One level deep** - SKILL.md references files, but files should not chain-reference other files
- **Update when patterns change** - stale references cause wrong code

### SKILL.md Template

```markdown
---
name: skill-name
description: >-
  Does X following Ecotone conventions. Use when [trigger phrases].
---

## Steps

1. **First action**
   Specific instructions with code example.

2. **Second action**
   ...

## Key Rules
- Rule 1
- Rule 2

## Examples

### Simple case
[code example]

### Advanced case
[code example]
```

Keep SKILL.md under 500 lines. Move detailed examples to `references/`.

### Evaluation Strategy

For each skill, validate with three scenarios:
1. **Happy path** - Does Claude produce correct output for a standard request?
2. **Edge case** - Does Claude handle unusual patterns (e.g., event-sourced aggregate with saga)?
3. **Convention enforcement** - Does Claude follow Ecotone conventions without being reminded?

Iterate: run scenario without skill (baseline) → add skill → compare → refine.

## Sources

- [Extend Claude with skills](https://code.claude.com/docs/en/skills) - Official Claude Code documentation
- [Skill authoring best practices](https://platform.claude.com/docs/en/agents-and-tools/agent-skills/best-practices) - Anthropic platform docs
- [Anthropic Skills GitHub](https://github.com/anthropics/skills) - Official examples
- [Equipping agents with Agent Skills](https://claude.com/blog/equipping-agents-for-the-real-world-with-agent-skills) - Anthropic blog
