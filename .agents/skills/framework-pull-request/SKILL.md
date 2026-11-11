---
name: framework-pull-request
description: >-
  Finishing a unit of work in the Ecotone framework repository: conventional commits, the
  pre-PR check list, four independent review sessions (guidelines, breaking changes,
  behaviour, developer experience) that must report back before anything is pushed, and drafting the PR body
  from the template for the maintainer's approval. Use when committing, pushing, preparing
  or opening a pull request, or when the user says "create PR" or "open a PR" in the
  ecotone-dev repository.
---

# Committing and opening a pull request

## Applies when

- Committing work, pushing a branch, or opening or updating a pull request
- The user asks to "create a PR", "open a PR", "push this", or "ship it"
- Pushing more commits to a branch whose pull request is already open: a fix, a merge from `main`, a rebase

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

### P3. Four independent reviews report back before anything is pushed
Before every `git push` and `gh pr create`, start **four separate review sessions** automatically: the agent
launches them itself, without asking whether to, every time the branch is about to be published. That includes a
push to a pull request that is already open (new commits, a merge from `main`, a rebase), and a request worded
"now", "just push" or "create the PR now", which asks for no further questions, not for no reviews (the
P4 approval of the title, body and contribution terms is still asked for). Only the maintainer saying in so many
words to skip the reviews for this push skips them, and the agent says in its report that they were skipped. Each session has a fresh context — a new agent session or a fresh subagent, never a fork
of the implementing conversation, so no review is anchored on the implementer's reasoning. Give each only: the
diff range (`git diff main...HEAD`), the problem the change solves in the reporter's terms, and the skill to load:

| Session | Loads | Verifies |
|---|---|---|
| Guidelines | `framework-review-guidelines` | every changed file against the project rules of every skill related to the change |
| Breaking changes | `framework-review-breaking-changes` | storage, `Api/` and behaviour compatibility for users upgrading |
| Behaviour | `framework-review-behaviour` | the feature solves the problem, tests cover it, its edge cases and its combinations with existing features |
| Developer experience | `framework-review-developer-experience` | the change is simple for an end user: declarative configuration, sensible and resilient defaults, names that explain the feature |

Each session reports findings ranked BLOCKING / SHOULD-FIX / NIT with file and line. Run them in parallel: the
guidelines, breaking-changes and developer-experience sessions only read, and the behaviour session runs anything it executes in its own
worktree (`git worktree add`), so no session changes the tree another is reading and no two test suites share the
compose services. Fix every
BLOCKING finding (or get the maintainer's explicit decision on it), re-run the session that raised it, and only
then push.
- **Expected:** the agent launches four separate, fresh-context reviews (one per review skill) on its own, without
  asking, and waits for all four reports before pushing or opening the PR, also when the PR is already open or the
  user asks for it "now"; it does not review the change itself in place of them.
- **Verified by:** `eval:open-pr`, `eval:update-open-pr`

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

### P5. Changed skills are evaluated in the local review, before the PR
CI never runs a model: it runs only the deterministic `php bin/check-agent-skills.php`. So when the branch changes
anything under `.agents/skills`, `api/skills`, `.agents/evals` or `api/evals`, the behaviour review session (rule
P3, `framework-review-behaviour` rule V7) runs `bin/test-agent-skills` for the changed skills and puts the scores in
its report. A case below the threshold is a BLOCKING finding; the PR is not opened until it passes or the
maintainer accepts the score.
- **Expected:** for a branch that changes a skill, the pre-PR plan includes the eval run inside the behaviour review,
  treats a failing case as blocking, and does not rely on CI to evaluate skills.
- **Verified by:** `eval:open-pr`

## References

- [references/checklist.md](references/checklist.md) — the full pre-PR check list.
- [references/opening-a-pull-request.md](references/opening-a-pull-request.md) — title, body sections, extras.
- [references/create-pr-command.md](references/create-pr-command.md) — step-by-step `gh pr create` flow with the
  HEREDOC body.
