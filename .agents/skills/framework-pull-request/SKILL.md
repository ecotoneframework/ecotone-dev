---
name: framework-pull-request
description: >-
  Finishing a unit of work in the Ecotone framework repository: conventional commits, the
  pre-PR check list, three independent review sessions (guidelines, breaking changes,
  behaviour) that must report back before anything is pushed, and drafting the PR body
  from the template for the maintainer's approval. Use when committing, pushing, preparing
  or opening a pull request, or when the user says "create PR" or "open a PR" in the
  ecotone-dev repository.
---

# Committing and opening a pull request

## Applies when

- Committing work, pushing a branch, or opening or updating a pull request
- The user asks to "create a PR", "open a PR", "push this", or "ship it"

## Rules

### P1. Conventional, scoped commit subjects; one increment per commit
`feat(dcb):`, `fix(event-sourcing):`, `refactor(modelling):`, `test(dbal):`, `docs(2.0):`, `style(dcb):`. The
message lets the defect be reconstructed without the diff: what broke, why, what changed.
- **Expected:** every commit subject matches `^(feat|fix|refactor|test|docs|style|chore|ci|perf)(\(.+\))?: `.
- **Verified by:** `eval:open-pr`

### P2. The pre-PR checks pass first
CI is `fail-fast`, so the first failure hides everything after it. Run the list in
[references/checklist.md](references/checklist.md): the changed test, the package's `composer tests:ci`,
downstream suites for a core change, `php bin/check-licence.php`, `php bin/check-suite-parity.php`,
`php bin/check-agent-skills.php`, php-cs-fixer, phpstan, MySQL/MariaDB for engine-sensitive work, the PHP 8.2 floor.
- **Expected:** before pushing, the agent runs (or reports running) the checklist and stops on a failure.
- **Verified by:** `eval:open-pr`

### P3. Three independent reviews report back before anything is pushed
Before `git push` or `gh pr create`, start **three separate review sessions**, each with a fresh context — a new
agent session or a fresh subagent, never a fork of the implementing conversation, so no review is anchored on the
implementer's reasoning. Give each only: the diff range (`git diff main...HEAD`), the problem the change solves in
the reporter's terms, and the skill to load:

| Session | Loads | Verifies |
|---|---|---|
| Guidelines | `framework-review-guidelines` | every changed file against the project rules and the skills relevant to it |
| Breaking changes | `framework-review-breaking-changes` | storage, `Api/` and behaviour compatibility for users upgrading |
| Behaviour | `framework-review-behaviour` | the feature solves the problem, tests cover it and its edge cases |

Each session reports findings ranked BLOCKING / SHOULD-FIX / NIT with file and line. Run them in parallel: the
guidelines and breaking-changes sessions only read, and the behaviour session runs anything it executes in its own
worktree (`git worktree add`), so no session changes the tree another is reading and no two test suites share the
compose services. Fix every
BLOCKING finding (or get the maintainer's explicit decision on it), re-run the session that raised it, and only
then push.
- **Expected:** the agent launches three separate, fresh-context reviews (one per review skill) and waits for all
  three reports before pushing or opening the PR; it does not review the change itself in place of them.
- **Verified by:** `eval:open-pr`

### P4. The PR body is drafted from the template and agreed before publishing
Title and branch use a conventional prefix (`feat:`, `fix:`, `refactor:`, `docs:`, `test:`). The body follows
`.github/PULL_REQUEST_TEMPLATE.md`: **Why** (the problem in the reporter's terms), **Description of Changes**
(what an application observes differently, usage examples for a new feature, a Mermaid diagram for a changed
message flow), and the **contribution terms** checkbox, which `contribution-check.yml` requires. Ask the user
whether they agree to the terms; never tick it for them. Show the full title and body and get it approved before
`gh pr create`.
- **Expected:** the agent presents the drafted title and body and asks for approval (and for the contribution
  terms) before running `gh pr create`.
- **Verified by:** `eval:open-pr`

### P5. Changed skills run their tests
A change under `.agents/skills`, `api/skills`, `.agents/evals` or `api/evals` runs `bin/test-agent-skills` for the
changed skills before pushing (`framework-agent-tooling` rule A4).
- **Expected:** a branch that changes a skill runs `bin/test-agent-skills` and reports the scores.
- **Verified by:** `eval:open-pr`

## References

- [references/checklist.md](references/checklist.md) — the full pre-PR check list.
- [references/opening-a-pull-request.md](references/opening-a-pull-request.md) — title, body sections, extras.
- [references/create-pr-command.md](references/create-pr-command.md) — step-by-step `gh pr create` flow with the
  HEREDOC body.
