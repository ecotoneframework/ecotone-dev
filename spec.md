# Ecotone Claude Code Skills - Implementation Spec

## Background

This spec defines a set of Claude Code skills for the Ecotone monorepo, designed to make contributions more effective, precise, and accessible for both new and experienced contributors. Skills follow the structure defined in [Anthropic's Complete Guide to Building Skills for Claude](https://claude.com/blog/complete-guide-to-building-skills-for-claude).

## Skill Architecture (from Anthropic Guide)

### Key Principles

1. **Progressive Disclosure** - Three levels of information:
   - Level 1: YAML frontmatter (always in system prompt) - tells Claude *when* to use the skill
   - Level 2: SKILL.md body (loaded when relevant) - tells Claude *how* to do it
   - Level 3: Linked files in `references/` (loaded on demand) - deep reference material

2. **File Structure** per skill:
   ```
   skill-name/
   ├── SKILL.md          # Required - instructions with YAML frontmatter
   ├── references/       # Optional - documentation loaded as needed
   └── scripts/          # Optional - executable validation scripts
   ```

3. **YAML Frontmatter** - Critical for triggering:
   - `name`: kebab-case, matches folder name
   - `description`: WHAT it does + WHEN to use it (trigger phrases). Under 1024 chars. No XML tags.

4. **Effective descriptions** include:
   - What the skill does (concrete outcome)
   - Trigger phrases users would actually say
   - Negative triggers if needed (to prevent over-triggering)

5. **Instructions** should be:
   - Specific and actionable (not vague)
   - Use numbered steps, bullet points
   - Include examples and error handling
   - Put critical instructions at the top

### Where Skills Live in Claude Code

Skills are placed in the `.claude/skills/` directory at the project root. Each skill is a subfolder containing a `SKILL.md` file. The skills are automatically available to Claude Code when working in the repository.

```
.claude/
└── skills/
    ├── write-ecotone-test/
    │   └── SKILL.md
    ├── create-message-handler/
    │   └── SKILL.md
    └── ...
```

---

## Proposed Skills

### Skill 1: `write-ecotone-test`

**Category:** Document & Asset Creation
**Priority:** Critical - most common contributor task
**Trigger scenarios:**
- "Write a test for this handler"
- "Add tests for this feature"
- "How do I test this aggregate?"
- "Create a test for the projection"

**What it teaches Claude:**
- Use `EcotoneLite::bootstrapFlowTesting()` as the primary test bootstrap
- Use `EcotoneLite::bootstrapFlowTestingWithEventStore()` for event-sourced aggregate tests
- Prefer inline anonymous classes with PHP 8.1+ attributes over separate fixture files
- Use `snake_case` for test method names (enforced by PHP-CS-Fixer)
- Write high-level tests from end-user perspective, not low-level unit tests
- Proper patterns for:
  - Simple handler testing (command/event/query)
  - Aggregate testing with commands and events
  - Event-sourced aggregate testing with `withEventsFor()`
  - Async handler testing with `enableAsynchronousProcessing` and `releaseAwaitingMessagesAndRunConsumer()`
  - Testing with service stubs (second argument to bootstrapFlowTesting)
  - Using `ServiceConfiguration` with `ModulePackageList::allPackagesExcept()`
  - Testing projections with `triggerProjection()`
- Include licence header on test files
- No comments in test code - use descriptive method names

**References to include:**
- `references/test-patterns.md` - Detailed examples of each testing pattern with real code from the codebase
- `references/ecotone-lite-api.md` - Key EcotoneLite and FlowTestSupport API methods

**Success criteria:**
- Generated tests use `EcotoneLite::bootstrapFlowTesting()` (not raw PHPUnit mocking)
- Test methods are in `snake_case`
- Tests include proper licence headers
- Tests follow end-user perspective (not testing internals)
- Inline anonymous classes used for test-only handlers/aggregates

---

### Skill 2: `create-message-handler`

**Category:** Document & Asset Creation
**Priority:** High - fundamental Ecotone pattern
**Trigger scenarios:**
- "Create a command handler"
- "Add an event handler"
- "Create a query handler"
- "Add a new handler for..."
- "Create an async handler"

**What it teaches Claude:**
- Proper PHP 8.1+ attribute usage: `#[CommandHandler]`, `#[EventHandler]`, `#[QueryHandler]`
- Routing key patterns for handlers
- `#[Asynchronous('channel-name')]` for async processing
- Handler method signatures (type-hinted message as first param)
- When to use routing vs class-based resolution
- Message metadata via `#[Header]` attribute
- Proper PHPDoc for public APIs (`@param`/`@return`)
- Licence headers
- No comments - use meaningful method names
- Follow existing patterns in the codebase

**References to include:**
- `references/handler-patterns.md` - Command, Event, Query handler patterns with examples
- `references/attributes-reference.md` - All Ecotone attributes and their parameters

**Success criteria:**
- Handlers use proper Ecotone attributes
- Public APIs have PHPDoc
- No comments in code
- Follows existing codebase patterns

---

### Skill 3: `create-aggregate`

**Category:** Document & Asset Creation
**Priority:** High - core DDD pattern
**Trigger scenarios:**
- "Create an aggregate"
- "Add a new aggregate"
- "Create an event-sourced aggregate"
- "Add a DDD aggregate for..."

**What it teaches Claude:**
- `#[Aggregate]` attribute on the class
- `#[Identifier]` on the identity field
- Static factory method with `#[CommandHandler]` for creation
- Instance method `#[CommandHandler]` for state changes
- Event recording patterns (for event sourcing)
- `#[AggregateIdentifierMapping]` for mapping command fields to aggregate ID
- Event-sourced aggregates with `#[EventSourcingHandler]`
- Proper test patterns for aggregates using `EcotoneLite`
- When to use state-stored vs event-sourced aggregates
- Licence headers, no comments

**References to include:**
- `references/aggregate-patterns.md` - State-stored and event-sourced aggregate examples

**Success criteria:**
- Aggregates use proper attributes
- Factory method pattern followed
- Event-sourced aggregates use proper event handlers
- Tests created alongside the aggregate

---

### Skill 4: `prepare-contribution`

**Category:** Workflow Automation
**Priority:** High - ensures contributions pass CI
**Trigger scenarios:**
- "Prepare my PR"
- "Check my contribution"
- "Validate before submitting"
- "Run pre-PR checks"
- "Make sure my code is ready for review"

**What it teaches Claude:**
- Step-by-step validation workflow (order matters):
  1. **Run new/changed tests first** - Run only the tests that were added or modified with `vendor/bin/phpunit --filter testMethodName tests/Path/To/TestFile.php` to get the fastest feedback loop. Fix any failures before proceeding.
  2. **Run full test suite for affected package** - From the package directory (`cd packages/PackageName`), run `composer tests:ci` which executes PHPStan + PHPUnit + Behat in sequence. This catches regressions in the broader package.
  3. Licence headers present on all new PHP files (Apache-2.0 or Enterprise)
  4. Code style passes (`vendor/bin/php-cs-fixer fix --dry-run`)
  5. PHPStan passes (`composer tests:phpstan`)
  6. Test method names in `snake_case`
  7. No comments in code
  8. Public APIs have PHPDoc
  9. PR description follows template (Why/What/CLA checkbox)
- How to run tests in the right context (monorepo vs package)
- How to use Docker containers for database-dependent tests
- How to test against multiple PHP versions and databases
- How to verify lowest/highest dependency compatibility

**References to include:**
- `references/ci-checklist.md` - Full CI checklist with exact commands
- `references/licence-format.md` - Licence header formats and placement

**Success criteria:**
- All checks pass before PR submission
- PR description is properly formatted
- CLA checkbox is included

---

### Skill 5: `create-ecotone-module`

**Category:** Document & Asset Creation
**Priority:** Medium - less frequent but complex task
**Trigger scenarios:**
- "Create a new module"
- "Add a module for..."
- "Register a new Ecotone module"
- "Create module configuration"

**What it teaches Claude:**
- Module class structure: implements `AnnotationModule`, annotated with `#[ModuleAnnotation]`
- `NoExternalConfigurationModule` base class when no external config needed
- Required methods: `create()`, `prepare()`, `canHandle()`, `getModulePackageName()`
- How `prepare()` registers handlers, converters, service definitions on `Configuration`
- How to use `AnnotationFinder` to scan for custom attributes
- How to use `ExtensionObjectResolver` for configuration
- Registering in `ModulePackageList`
- Package template usage from `_PackageTemplate/`

**References to include:**
- `references/module-anatomy.md` - Full module lifecycle and registration mechanics

**Success criteria:**
- Module follows the `AnnotationModule` contract
- Properly registers in the Ecotone module system
- Tests verify module registration

---

### Skill 6: `debug-test-failure`

**Category:** Workflow Automation
**Priority:** Medium - common pain point for contributors
**Trigger scenarios:**
- "Test is failing"
- "Debug this test failure"
- "Why is this test broken"
- "Help me fix this failing test"

**What it teaches Claude:**
- How to interpret PHPUnit output in Ecotone context
- Common failure patterns and their causes:
  - `ModulePackageList` not configured correctly
  - Missing service in container (second arg to bootstrapFlowTesting)
  - Channel not configured for async tests
  - Database DSN not set for integration tests
  - Licence header missing (CI failure)
  - PHP-CS-Fixer violations (snake_case, imports)
- How to run single tests for fast feedback: `vendor/bin/phpunit --filter testName`
- Docker container requirements for DB-dependent tests
- How to check if it's a lowest-dependency vs highest-dependency issue

**References to include:**
- `references/common-errors.md` - Common error messages and their solutions

**Success criteria:**
- Correctly diagnoses the root cause
- Suggests targeted fix rather than broad changes
- Verifies fix with targeted test run

---

### Skill 7: `review-ecotone-code`

**Category:** Workflow Automation
**Priority:** Medium - quality assurance
**Trigger scenarios:**
- "Review this code"
- "Check if this follows Ecotone conventions"
- "Review my changes"
- "Is this code ready for PR?"
- Do NOT use for general code reviews unrelated to Ecotone patterns

**What it teaches Claude:**
- Ecotone-specific code review checklist:
  1. No comments in code (use meaningful method names instead)
  2. PHP 8.1+ features used (attributes, enums, named arguments)
  3. Public APIs have `@param`/`@return` PHPDoc
  4. Licence headers present
  5. Test methods in `snake_case`
  6. Tests use `EcotoneLite::bootstrapFlowTesting()` (not raw mocking)
  7. Tests are high-level/end-user perspective
  8. Inline anonymous classes in tests (not separate fixture files for simple cases)
  9. Follows existing patterns in the codebase
  10. `ServiceConfiguration` properly configured with appropriate `ModulePackageList`
  11. No unnecessary imports
  12. Single quotes preferred
  13. Trailing commas in multiline
  14. `! $var` with space after not operator

**References to include:**
- `references/code-conventions.md` - Full coding conventions reference

**Success criteria:**
- Identifies convention violations
- Suggests concrete fixes
- References specific Ecotone patterns

---

## Implementation Plan

### Phase 1: Core Skills (Most Impact)
1. `write-ecotone-test` - Every contribution needs tests
2. `create-message-handler` - Most common code pattern
3. `prepare-contribution` - Ensures CI passes

### Phase 2: Pattern Skills
4. `create-aggregate` - Core DDD pattern
5. `review-ecotone-code` - Quality gate

### Phase 3: Advanced Skills
6. `create-ecotone-module` - Package development
7. `debug-test-failure` - Contributor support

### File Structure
```
.claude/
└── skills/
    ├── write-ecotone-test/
    │   ├── SKILL.md
    │   └── references/
    │       ├── test-patterns.md
    │       └── ecotone-lite-api.md
    ├── create-message-handler/
    │   ├── SKILL.md
    │   └── references/
    │       ├── handler-patterns.md
    │       └── attributes-reference.md
    ├── create-aggregate/
    │   ├── SKILL.md
    │   └── references/
    │       └── aggregate-patterns.md
    ├── prepare-contribution/
    │   ├── SKILL.md
    │   └── references/
    │       ├── ci-checklist.md
    │       └── licence-format.md
    ├── create-ecotone-module/
    │   ├── SKILL.md
    │   └── references/
    │       └── module-anatomy.md
    ├── debug-test-failure/
    │   ├── SKILL.md
    │   └── references/
    │       └── common-errors.md
    └── review-ecotone-code/
        ├── SKILL.md
        └── references/
            └── code-conventions.md
```

### SKILL.md Template Structure
Each SKILL.md follows this pattern:

```markdown
---
name: skill-name
description: What it does. Use when user asks to [trigger phrases]. Do NOT use for [negative triggers].
---

# Skill Name

## Instructions

### Step 1: [Action]
Specific instructions...

### Step 2: [Action]
...

## Examples

### Example 1: [Scenario]
```code```

## Important Rules
- Critical conventions to follow
- Common mistakes to avoid

## Troubleshooting
### Error: [Common error]
Cause: [Why]
Solution: [How to fix]
```

### Reference File Strategy
Reference files contain detailed examples extracted from the actual codebase. They serve as the "third level" of progressive disclosure - Claude reads them only when needed for a specific pattern. Each reference file should:
- Be focused on one topic
- Contain real code examples from the Ecotone codebase
- Be under 2000 words to avoid context bloat
- Be updated when codebase patterns evolve

## Sources

- [A complete guide to building skills for Claude (Blog)](https://claude.com/blog/complete-guide-to-building-skills-for-claude)
- [The Complete Guide to Building Skills for Claude (PDF)](https://resources.anthropic.com/hubfs/The-Complete-Guide-to-Building-Skill-for-Claude.pdf?hsLang=en)
- [Full Guide Markdown (GitHub Gist)](https://gist.github.com/YangSiJun528/fa5d9cd0eb41d6f545c78121d620080c)
