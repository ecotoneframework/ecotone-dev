---
name: framework-agent-tooling
description: >-
  How rules for coding agents are added and kept honest in the Ecotone repository: enforce
  a rule with a deterministic check that CI runs before reaching for an instruction, write
  every skill so each rule is individually verifiable (Applies when, Expected, Verified by),
  and test changed skills with eval cases. Use when adding or changing a rule, a convention,
  AGENTS.md, a skill under .agents/skills or api/skills, an eval case, or a check script, or
  when an agent keeps repeating the same mistake.
---

# Rules for agents: checks first, then skills

`AGENTS.md` is a pointer; framework rules live in `.agents/skills/framework-*` (loaded while developing Ecotone,
and linked into `.claude/skills`); `api/skills/ecotone-*` are the public skills split to `ecotoneframework/skills`
for Ecotone users ([references/public-skills-are-userland.md](references/public-skills-are-userland.md)).

## Applies when

- A review, a user correction, or a repeated agent mistake reveals a rule worth stating
- Adding or editing any `SKILL.md`, eval case, `AGENTS.md`, or check script
- Deciding whether a convention belongs in a check, a skill, or nowhere

## Rules

### A1. Move enforceable rules out of the LLM
A rule a program can decide is enforced by a program that CI runs: a phpstan rule, a php-cs-fixer rule, a
`bin/check-*.php` script, a test. CI then refuses every violation, from any agent or human, at no context cost. Only
a rule that needs judgement goes into a skill. When adding a check, register its id in `DETERMINISTIC_CHECKS` in
`bin/check-agent-skills.php` and wire it into a workflow, then point the rule's *Verified by* at it.
- **Expected:** for a mechanically decidable rule (a file-name pattern, a forbidden call, a header format) the
  agent proposes or writes a deterministic check plus CI step, not only a skill sentence.
- **Verified by:** `eval:new-rule-enforcement`

### A2. Otherwise improve the skill whose *Applies when* covers it, or create one
Add the rule to the existing skill for that kind of work; create a new skill only when no *Applies when* matches.
Never add it to `AGENTS.md` (200-character limit) and never to `docs/`.
- **Expected:** a judgement rule lands in the matching `.agents/skills/framework-*/SKILL.md` (or a new skill), not
  in `AGENTS.md` or `docs/`.
- **Verified by:** `eval:new-rule-enforcement`, `check:agent-skills`

### A3. Every skill is individually verifiable
Each `SKILL.md`, framework and public alike, has: frontmatter `name` equal to its directory and a `description`
with a `Use when ...` sentence; `## Applies when` with scenario bullets; `## Rules` where each rule is
`### <id>. <title>` with `- **Expected:** <observable outcome>` and `- **Verified by:** <check:id | eval:case>`.
An `eval:<case>` reference needs `<evals>/<skill>/<case>/graders/rule-<id>.md` (id lower-cased). Long material
goes to `references/`, linked with when to load it. Public skills never link outside `api/`; framework skills never
link into `docs/`.
- **Expected:** a new or edited skill passes `php bin/check-agent-skills.php`, and each rule's *Expected* is an
  outcome a grader can decide from the agent's answer or files.
- **Verified by:** `check:agent-skills`

### A4. A changed skill runs its eval cases before it is pushed
Eval cases live in `.agents/evals/<skill>/<case>/` (framework) and `api/evals/<skill>/<case>/` (public), in the
`claude plugin eval` format: `prompt.md` (frontmatter + task), `graders/rule-<id>.md` (one `llm` grader per rule,
PASS/FAIL rubric) and `graders/skill-loaded.md` (`tool_used: Skill`). Run `bin/test-agent-skills` — it runs the
cases of every skill changed against `main` (or the skills named as arguments) and fails below the threshold. It
uses real agent runs, so it never runs in CI (CI runs only the deterministic `check:agent-skills`); it runs in the
local review before the PR, as part of the behaviour review session (`framework-pull-request` rule P5).
- **Expected:** after changing a skill the agent runs `bin/test-agent-skills` (or explains it cannot) and adds or
  updates a grader for each new or changed rule.
- **Verified by:** `eval:new-rule-enforcement`

### A5. An eval case tests the skill, not the repository
A case starts in an empty directory: put the code under review in the prompt, ask for the outcome the rule
governs, and grade the answer. Phrase the prompt as the task a developer would give ("add a logger to this
service"), never by naming the rule, so the case also proves the skill is found from its description.
- **Expected:** prompts are self-contained tasks that do not quote the rule being graded.
- **Verified by:** `eval:new-rule-enforcement`

## Checks

| Id | Command | Enforces |
|---|---|---|
| `agent-skills` | `php bin/check-agent-skills.php` (its own tests: `php bin/test-check-agent-skills.php`) | AGENTS.md size and pointer, symlinks, skill format, rule headings, graders per rule, links and anchors, backticked paths, marketplace list, public skill class references resolve |
| `licence-header` | `php bin/check-licence.php` | licence header on every `src` file |
| `suite-parity` | `php bin/check-suite-parity.php` | root and per-package phpunit configs agree |
| `phpstan` | `vendor/bin/phpstan` | level 3 over `src` and `Api/` |
| `contribution-terms` | `.github/workflows/contribution-check.yml` | the PR body's contribution checkbox |

## References

- [references/public-skills-are-userland.md](references/public-skills-are-userland.md) — what belongs in a public
  skill.
