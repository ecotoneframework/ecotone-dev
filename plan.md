# Plan: Restructure Ecotone Skills to Match Anthropic's Official Guidelines

Source: https://code.claude.com/docs/en/skills

## Current State Audit

16 skills in `.claude/skills/`. All have SKILL.md + references/ directory with 1-2 files.

| Skill | SKILL.md Lines | Reference Lines | Reference Links |
|---|---|---|---|
| ecotone-workflow | **527** (over limit) | 453 | Weak (1 line at end) |
| ecotone-metadata | **491** (near limit) | 488 | Weak (1 line at end) |
| ecotone-identifier-mapping | 429 | 392 | **Missing entirely** |
| ecotone-symfony-setup | 374 | 283 | **Missing entirely** |
| ecotone-business-interface | 366 | 457 | Weak (1 line at end) |
| ecotone-interceptors | 333 | 236+152 | Weak (2 lines at end) |
| ecotone-laravel-setup | 318 | 232 | **Missing entirely** |
| ecotone-event-sourcing | 294 | 325+186 | Weak (2 lines at end) |
| ecotone-resiliency | 280 | 262 | Weak (1 line at end) |
| ecotone-handler | 270 | 243 | Weak (1 line at end) |
| ecotone-asynchronous | 270 | 153+205 | Weak (2 lines at end) |
| ecotone-aggregate | 262 | 280 | Weak (1 line at end) |
| ecotone-distribution | 261 | 272 | Weak (1 line at end) |
| ecotone-testing | 231 | 279+137 | Weak (2 lines at end) |
| ecotone-module-creator | 221 | 285 | Weak (1 line at end) |
| ecotone-contributor | 194 | 118+79 | Weak (2 lines at end) |

**Description budget**: 5,145 / 16,000 chars (32%) — healthy, no action needed.

## Problems Identified (Based on Anthropic's Guidelines)

### Problem 1: One skill exceeds the 500-line limit
Official guideline: **"Keep SKILL.md under 500 lines. Move detailed reference material to separate files."**

`ecotone-workflow` is at 527 lines — the only skill that exceeds the hard limit.
`ecotone-metadata` is at 491 — technically under but near the limit.

### Problem 2: No actionable reference links
Official guideline: **"Reference supporting files from SKILL.md so Claude knows what each file contains and when to load it."**

Current state: Most skills have a single throwaway line at the very bottom like `See references/xxx.md for more examples`. Three skills (ecotone-identifier-mapping, ecotone-laravel-setup, ecotone-symfony-setup) have **no reference links at all**.

The problem: Claude has no guidance on **when** to load references or **what specific content** they contain. This defeats progressive disclosure — Claude either never loads them, or loads them unnecessarily.

### Problem 3: Large inline code blocks that belong in references
Some SKILL.md files contain 15-25 full PHP code blocks with complete class definitions (constructors, methods, use statements). This inflates context usage when the skill loads. The official pattern is: SKILL.md has the essential workflow/decision guidance with compact examples; references have the complete, copy-paste-ready code.

### NOT a Problem: No duplication detected
The earlier plan claimed content duplication between SKILL.md and references. Audit found **no actual duplication** — the references contain different/additional examples. This means the migration only needs to **move** content from overlong SKILL.md files to references, not deduplicate.

## Target Structure (Per Anthropic's Guidelines)

```
skill-name/
├── SKILL.md           (under 500 lines — essentials + navigation)
│   ├── Frontmatter: name + description (already good)
│   └── Body: overview, decision tables, compact examples,
│             key rules, and STRONG reference links
└── references/        (loaded only when Claude needs them)
    └── *.md           (full code examples, API definitions,
                        advanced patterns, testing patterns)
```

### Reference Link Pattern (from official docs)

Instead of:
```
- See `references/xxx.md` for more examples
```

Use:
```
## Additional resources
- For complete API details and constructor parameters, see [handler-patterns.md](references/handler-patterns.md)
- For full working examples with tests, see [test-patterns.md](references/test-patterns.md)
```

This tells Claude **what** the file contains and **when** it's useful, enabling proper progressive disclosure.

## Changes — Organized by Priority

### Priority 1: Fix the over-limit skill (ecotone-workflow)

**ecotone-workflow** (527 lines → target under 450):
- Move 2-3 of the longest complete code examples to `references/workflow-patterns.md`
- Keep compact snippets inline (attribute + method signature only)
- Add strong reference links explaining what each reference file covers
- Ensure NO examples are lost — every code block moved must exist in references

### Priority 2: Slim the near-limit skill (ecotone-metadata)

**ecotone-metadata** (491 lines → target under 400):
- Move the longest examples (propagation patterns, interceptor-based header modification) to `references/metadata-patterns.md`
- Keep the core header/attribute quick-reference inline
- Add strong reference links

### Priority 3: Slim heavy skills with many inline examples

These skills are under 500 lines but are heavy enough (350+) that moving some complete code blocks to references would improve context efficiency:

**ecotone-identifier-mapping** (429 lines → target under 350):
- Has 23 inline code blocks — the most of any skill
- Move the detailed identifier resolution examples to references
- **Add reference links** (currently missing entirely)

**ecotone-symfony-setup** (374 lines → target under 300):
- Move complete configuration examples to references
- **Add reference links** (currently missing entirely)

**ecotone-business-interface** (366 lines → target under 300):
- Move complete interface implementation examples to references
- Add strong reference links

**ecotone-laravel-setup** (318 lines → target under 280):
- Move complete configuration examples to references
- **Add reference links** (currently missing entirely)

### Priority 4: Add proper reference links to all remaining skills

For all skills NOT listed above, the SKILL.md content is already a reasonable length (under 340 lines). These only need the reference link fix:

| Skill | Action |
|---|---|
| ecotone-interceptors (333) | Replace weak links with descriptive ones |
| ecotone-event-sourcing (294) | Replace weak links with descriptive ones |
| ecotone-resiliency (280) | Replace weak links with descriptive ones |
| ecotone-handler (270) | Replace weak links with descriptive ones |
| ecotone-asynchronous (270) | Replace weak links with descriptive ones |
| ecotone-aggregate (262) | Replace weak links with descriptive ones |
| ecotone-distribution (261) | Replace weak links with descriptive ones |
| ecotone-testing (231) | Replace weak links with descriptive ones |
| ecotone-module-creator (221) | Replace weak links with descriptive ones |
| ecotone-contributor (194) | Replace weak links with descriptive ones |

For each of these, replace the bottom-of-file `See references/xxx.md` lines with a proper `## Additional resources` section that describes:
- **What** the reference file contains (e.g., "complete working examples with all methods", "full API parameter reference", "testing patterns")
- **When** to load it (e.g., "when implementing a new aggregate", "when configuring channel types")

## Content Preservation Rule (Critical)

**No examples may be lost during the transition.**

For every skill transformation:
1. Catalogue every code block in the current SKILL.md before making changes
2. After rewriting, verify each code block exists in either the new SKILL.md (as a compact snippet) or in a reference file (as the full example)
3. When moving a code block from SKILL.md to references, keep a compact version (attribute + method signature, ~3-5 lines) inline as a snippet

## Execution Strategy

### Phase 1: Priority 1-3 skills (parallel, 6 skills)
Launch parallel Task agents for the 6 skills that need content moved to references:
- ecotone-workflow
- ecotone-metadata
- ecotone-identifier-mapping
- ecotone-symfony-setup
- ecotone-business-interface
- ecotone-laravel-setup

Each agent:
1. Reads current SKILL.md and all reference files
2. Catalogues every code block (before state)
3. Moves overlong code blocks to references (appending if the reference file already exists)
4. Replaces moved blocks with compact snippets in SKILL.md
5. Adds a proper `## Additional resources` section with descriptive links
6. Verifies no code block was lost (after state)

### Phase 2: Priority 4 skills (parallel, 10 skills)
Launch parallel Task agents for the remaining 10 skills that only need reference link improvements:

Each agent:
1. Reads current SKILL.md
2. Replaces the weak `See references/xxx.md` lines at the bottom with a proper `## Additional resources` section
3. Does NOT modify code examples or content — only the reference link section

### Phase 3: Verification
After all skills are transformed:
- [ ] Every SKILL.md is under 500 lines
- [ ] Every SKILL.md has an `## Additional resources` section with descriptive links
- [ ] No SKILL.md has zero reference links
- [ ] No code examples were lost (diff check: total code blocks before = total code blocks after, across SKILL.md + references)
- [ ] Description budget still under 16,000 chars (should be unchanged)
- [ ] All reference files are standalone (readable without first reading SKILL.md)
