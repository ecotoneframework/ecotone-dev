---
name: framework-pull-request
description: >-
  Finishing a unit of work in the Ecotone framework repository: conventional commits, the
  pre-PR check list, four independent review sessions (guidelines, breaking changes,
  behaviour, developer experience) run as Orca sub-sessions, or as subagents where Orca is
  not available, that decide every requirement of the review list as not-apply, covered or
  missing and must all pass before anything is pushed, and drafting the PR body from the template for the maintainer's approval. Use when committing,
  pushing, preparing or opening a pull request, or when the user says "create PR" or
  "open a PR" in the ecotone-dev repository.
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
`php bin/check-framework-transactions.php`, `php bin/check-framework-connections.php`, `php bin/check-flow-catalogue.php`,
`php bin/check-agent-skills.php`, php-cs-fixer, phpstan, MySQL/MariaDB for engine-sensitive work, the PHP 8.2 floor.
- **Expected:** before pushing, the agent runs (or reports running) the checklist and stops on a failure.
- **Verified by:** `eval:open-pr`

### P3. Four independent reviews pass the review list before anything is pushed
Before every `git push` and `gh pr create`, run **four separate review sessions** automatically, started for the
first push of a branch and resumed for a later one while they still can be (rule P3a): the agent
starts or resumes them itself, without asking whether to, every time the branch is about to be published. That
includes a
push to a pull request that is already open (new commits, a merge from `main`, a rebase), and a request worded
"now", "just push" or "create the PR now", which asks for no further questions, not for no reviews (the
P4 approval of the title, body and contribution terms is still asked for). Only the maintainer saying in so many
words to skip the reviews for this push skips them, and the agent says in its report that they were skipped. The
skip is the maintainer's to bring up: the agent does not offer it as the faster way, and does not end its plan asking
which way the maintainer wants it. Each
review runs in its own sub-session, which starts with a fresh context and can be resumed later: an Orca session, or
a subagent only where Orca is not available (rule P3b), never a fork of the implementing conversation, so no review
is anchored on the implementer's reasoning. Give each only: the diff range (`git diff main...HEAD`), the problem the change solves in the reporter's
terms, and the skill to load. The behaviour session also gets the red-first evidence it can no longer produce
itself: the failing output of each new test against the base commit, or the commit that added the test before the
change it proves:

| Session | Loads | Verifies |
|---|---|---|
| Guidelines | `framework-review-guidelines` | every changed file against the project rules, one requirement per rule |
| Breaking changes | `framework-review-breaking-changes` | storage, `Api/` and behaviour compatibility for users upgrading |
| Behaviour | `framework-review-behaviour` | the feature solves the problem, tests cover it, its edge cases and its combinations with existing features, and it holds when one process handles several messages at once on Swoole or OpenSwoole coroutines |
| Developer experience | `framework-review-developer-experience` | the change is simple for an end user: declarative configuration, sensible and resilient defaults, names that explain the feature |

Each session reviews against its section of the [review list](references/review-list.md), not against its own
reading of what matters: it decides every requirement there as `not-apply` (the change has nothing the requirement
speaks about), `covered` (with the evidence) or `missing` (with the file and line, what is missing, what to
add and the skill to load to cover it), and it passes when no requirement is `missing`. The `missing` requirements come back to the implementing
session: they are the work to cover before the re-review (rule P3a). Save the four decided lists to files under `docs/reviews/` before fixing
anything (`framework-design-and-briefs` rule D4); `docs/` is in `.gitignore`, so they are not committed. The developer-experience session may instead decide its whole
section `not-apply` with `NO USER-FACING SURFACE`, which is a complete report. Run them in
parallel, all four as sub-sessions in the worktree the change was made in: no reviewer gets or creates a worktree
of its own (`git worktree add`), and none edits, switches, stashes or resets the tree, so every session reads the
same commit. The guidelines, breaking-changes and developer-experience sessions only read. The behaviour session
may run tests and `bin/test-agent-skills` on the tree as it is, and is the only one that does. While a review is
running, from starting or resuming a session until its report arrives, the implementing session runs no suite and
makes no edit or commit, so every session reads one commit and no two test suites share the compose services.
Between reports the tree is the implementer's again: fixes, the P2 checks and commits happen then. Keep the four
sub-sessions open until the branch is
pushed and the pull request is merged: rule P3a resumes them.
- **Expected:** the agent runs four separate review sub-sessions (one per review skill) in the worktree of the
  change, none in a worktree of its own, each started from a fresh context or, for a later push, resumed, on its
  own, without asking; each decides every requirement of its section of the review list as `not-apply`, `covered`
  or `missing`; the agent waits for all four reports and saves the decided lists to files before fixing or pushing, also
  when the PR is already open or the user asks for it "now"; it does not review the change itself in place of
  them.
- **Verified by:** `eval:open-pr`, `eval:update-open-pr`

### P3a. A fix is re-verified by resuming the review sub-sessions
Cover every `missing` requirement, loading the skill its line names before writing the fix, commit, then resume the same sub-sessions; do not start new ones for the
re-check and do not judge the fix yourself. A requirement you believe was decided wrongly is taken up with the
session that decided it, with the evidence, and the session decides it again; only the maintainer's explicit
decision leaves a requirement `missing`, and the report names it. A resumed session already holds the diff, the
rules it loaded and its own decided list, so it is given only the commits since the last one it verified
(`git diff <last verified sha>..HEAD`) and asked two things: is each requirement it decided `missing` now
`covered`, and do the new commits change the status of any other requirement (a `covered` one broken, a
`not-apply` one that now applies). Resume every session, also one that passed or returned
`NO USER-FACING SURFACE`, because the fix commits are new code no session has seen.
A resumed behaviour session runs the affected tests again on the new head, which the shared worktree is already at
(and `bin/test-agent-skills` when the fix touched a skill). Repeat after each further fix, until all four sessions
pass: no requirement of the review list is `missing`; only then push. A session that can no longer be resumed is
replaced by a new one, which is given the whole range (`git diff main...HEAD`) and the saved decided lists. A later push to the same pull request
resumes the same sub-sessions while they can still be resumed, and starts new ones otherwise; after a rebase or a
merge from `main` the last verified commit no longer bounds the branch's own changes, so the resumed sessions are
given the whole range (`git diff main...HEAD`) again.
- **Expected:** after covering the `missing` requirements the agent resumes the review sub-sessions with the
  commits since the last one each verified, and repeats until none has a `missing` requirement before pushing; it
  does not start a fresh review for the re-check, re-verify the fix itself, or push on the strength of the fix
  alone.
- **Verified by:** `eval:open-pr`

### P3b. A review sub-session is an Orca session; a subagent only where Orca is not available
When the implementing session runs inside Orca (an Orca-managed terminal: `ORCA_TERMINAL_HANDLE` is set and the
`orchestration` skill is offered), every review sub-session is an Orca worker session, started through that skill in
the workspace of the change (`orchestration worker-start --worktree current`), one worker per review skill. An Orca
session is a full agent session that stays addressable, which a review needs twice. It talks both ways while it
works: a reviewer that cannot tell whether something is intended asks the implementing session (`ask`) and gets
the answer (`reply`) instead of guessing or reporting a finding on an assumption, and the implementer can ask a
reviewer what a `missing` requirement asks for before covering it. And it keeps its context between reviews: the P3a re-check is a
`send` to the same worker (`--to dispatch:<dispatch id>`), which still holds the diff, the skills it loaded and its
own decided list, also after it has reported and closed. Record the four dispatch ids when starting the
workers, so a later push can address them. Only where Orca is not available (the session does not run inside Orca,
or its CLI cannot be run) does each review run as a subagent of the implementing session, started with a fresh
context and resumed by messaging that same subagent. The fallback is for Orca's absence, not for convenience: a
slower start is no reason to use a subagent, and a worker that fails to start is recovered or retried as the
`orchestration` skill says. Every review session runs on Claude Opus 5.5, whichever model the implementing session
runs on: an Orca worker is started with `--agent claude --model claude-opus-5-5`, a subagent with that model.
Measured on the review eval cases, Sonnet 5.5 took about half the time and cost, and skipped the skill evals and a
coroutine finding that Opus 5.5 caught, so only the maintainer naming another model for this push changes it. The
agent names in its report which of the two ran the reviews, and on which model.
- **Expected:** the plan runs the four reviews as Orca worker sessions in the current workspace when the session
  runs inside Orca, and as subagents only when Orca is not available, on Claude Opus 5.5 in both cases; it resumes
  a review by sending to the same worker or subagent, and answers a reviewer's question rather than leaving it to
  guess.
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
its report. A case below the threshold makes requirement BH13 of the review list `missing`; the PR is not opened
until it passes or the maintainer accepts the score.
- **Expected:** for a branch that changes a skill, the pre-PR plan includes the eval run inside the behaviour review,
  treats a failing case as a `missing` requirement that blocks the push, and does not rely on CI to evaluate
  skills.
- **Verified by:** `eval:open-pr`

## References

- [references/checklist.md](references/checklist.md) — the full pre-PR check list.
- [references/review-list.md](references/review-list.md) — every requirement the four reviews decide, and the
  three statuses.
- [references/opening-a-pull-request.md](references/opening-a-pull-request.md) — title, body sections, extras.
- [references/create-pr-command.md](references/create-pr-command.md) — step-by-step `gh pr create` flow with the
  HEREDOC body.
