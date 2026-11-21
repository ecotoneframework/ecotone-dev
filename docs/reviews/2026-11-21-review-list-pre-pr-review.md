# Pre-PR review: review list and Orca review sessions

Range `origin/main...17d4a5d41`. Four review sessions, run as Orca workers on Claude Opus 5.5 in the worktree of the change.

## Round 1: guidelines

PASSED

Guidelines review of `dgafka/ai-reviews`, range `origin/main...HEAD` (38fabbd1b, 17d4a5d41), head 17d4a5d41.
Reviewer: Orca worker session, Claude Opus 5.5, read only.

#### Inventory

Purpose (brief + commit messages): the four pre-PR reviews stop judging by their own reading of the skills. They
decide every requirement of one review list (GL/BC/BH/DX) as not-apply / covered / missing, run as Orca sub-sessions
(subagents only where Orca is absent) on Opus 5.5, `bin/test-agent-skills` grades with Opus by default, and
`bin/check-agent-skills.php` refuses a framework rule the list does not cite (and a citation of a rule that does not
exist).

Changed files (41), all read in full or as complete diffs:

- `.agents/skills/framework-pull-request/references/review-list.md` (new): the list, statuses, report format,
  GL1–GL50, *Rules no review decides*, BC1–BC8, BH1–BH21, DX1–DX10.
- `.agents/skills/framework-pull-request/SKILL.md`: description; P3 retitled and moved to the review list; P3a moved
  to missing/covered; new P3b (Orca first, subagent fallback, Opus 5.5); P5 moved to BH13; references.
- `.agents/skills/framework-pull-request/references/create-pr-command.md`: Step 4b moved to the new process and anchor.
- `.agents/skills/framework-review-guidelines/SKILL.md`: G1–G5 moved to the review list.
- `.agents/skills/framework-review-behaviour/SKILL.md`: intro maps V→BH; V2, V3, V7 wording; V6 is now the three statuses.
- `.agents/skills/framework-review-breaking-changes/SKILL.md`: B1, B3–B5 map to BC ids; B6 is now the three statuses.
- `.agents/skills/framework-review-developer-experience/SKILL.md`: intro maps X→DX; X1, X4 wording; X6 is now the three statuses.
- `.agents/skills/framework-agent-tooling/SKILL.md`: A2 adds "cite the new rule in the review list"; A1 table
  extends the `agent-skills` check description.
- `bin/check-agent-skills.php`: `checkReviewList()` and three constants.
- `bin/test-check-agent-skills.php`: three new checker cases and a review-list fixture in the valid tree.
- `bin/test-agent-skills`: `JUDGE_MODEL` (default `opus`) passed as `--judge-model`.
- 30 grader files under `.agents/evals/...`: rewritten for the statuses; new `rule-p3b.md` in `open-pr` and
  `update-open-pr`. No `prompt.md` changed.

Skills whose requirements apply, and why: `framework-agent-tooling` (rules, skills, graders and a check are
changed: GL44–GL47), `framework-orientation` (the list names classes, CLI flags and env vars: GL49),
`framework-pull-request` (commits: GL50), `framework-dev-environment` (PHP under `bin/`: GL48),
`framework-public-api` and `framework-service-design` (new PHP code in `bin/`: read to decide GL15/GL16).
`framework-error-handling` was read for GL23 because the check adds violation messages. No other skill applies: no
test of a package, no service, no `Api/`, no storage, no Enterprise, no module, no design.

Ran (read-only mount, throwaway `ecotone/php:8.5.3-dev` container, since no stack is up for this worktree):
`php bin/check-agent-skills.php` → `Agent skills are valid.` (exit 0); `php bin/test-check-agent-skills.php` →
`37 checker cases passed.` (exit 0, including `rule missing from the review list`, `review list citing a rule that
does not exist`, `review skills are not listed in the review list`).

#### Decided requirements

GL44 — covered — bin/check-agent-skills.php:328-367 `checkReviewList()` makes the new A2 obligation ("cite the rule in the review list") a CI refusal through the existing `agent-skills` entry of `DETERMINISTIC_CHECKS`, run by `.github/workflows/agent-skills.yml:30`; checker cases at bin/test-check-agent-skills.php:44-46; A1 table updated at framework-agent-tooling/SKILL.md:77. The other new rule, P3b (Orca first, Opus 5.5), is about how a session runs, so it needs judgement.
GL45 — covered — P3b sits in framework-pull-request/SKILL.md:110 (its *Applies when* covers pushing/PRs), and the A2 addition in framework-agent-tooling/SKILL.md:37-39. `AGENTS.md` and `docs/` are untouched.
GL46 — covered — every new or changed rule has a grader: rule-p3b.md in open-pr and update-open-pr; P3, P3a, G1–G5, V2–V9, B1–B6, X1–X6 graders rewritten. Eval runs on disk (`.agents/evals/results/`): the latest run of each of the 13 cases used today's grader text, and every case scores ≥ 0.8. open-pr 1.0 and update-open-pr 1.0 (14-10-15Z); review-diff-guidelines 0.917, review-related-skills 1.0, review-diff-breaking 0.857, review-diff-developer-experience 0.929, review-internal-refactor 1.0 (10-38-52Z); review-diff-behaviour 0.857, review-skill-change, review-feature-combinations, review-coroutine-* 1.0 (11-08-18Z); new-rule-enforcement 1.0 (10-04-36Z). See *Not on the list* 3 on how fresh these are; the binding re-run is BH13.
GL47 — covered — no `prompt.md` changed. The new P3b graders join existing cases whose prompts never mention Orca, subagents, Opus or the review list (`git grep` over the prompts), so the prompts still do not quote the rule they grade.
GL48 — covered — `phpstan-baseline.neon` is not in the diff (and `bin/` is outside phpstan's `src`/`Api/` paths).
GL49 — covered — names checked against the tree and the tools: `ConsumerCoroutines` (packages/Ecotone/src/Messaging/Endpoint/PollingConsumer/ConsumerCoroutines.php), `CopiedToChildExecution` and `ExecutionScope::stateOf` (packages/Ecotone/src/Messaging/Execution/), `COROUTINE_TESTS` (.github/workflows/test-monorepo.yml), `ORCA_TERMINAL_HANDLE` (set in this Orca terminal), `orca orchestration worker-start --worktree current --agent claude --model <id>` and `send --to dispatch:<id>` (both in `--help`), `claude plugin eval --judge-model` (in `--help`, default haiku). Every `framework-x id` citation in the list resolves (the check passes). No link points at the old P3 anchor outside `docs/reviews`.
GL50 — covered — `docs(skills): pre-PR reviews decide a review list in Orca sessions on Opus 5.5` and `ci(skills): the agent skills check refuses a rule the review list does not cite`. Both bodies state what was wrong (each review judged by its own reading; a rule was reviewed only if someone remembered to list it), why, and what changed.
GL15 — not-apply — the rule is `framework-service-design 18`, whose *Applies when* is a framework service class. The new calls in `bin/check-agent-skills.php` (`preg_match(RULE_HEADING, $line, $heading)`, `preg_match_all(..., PREG_SET_ORDER)`) are in a CI script and follow its existing positional style (lines 117–242).
GL16 — not-apply — the added PHP lines (bin/check-agent-skills.php, bin/test-check-agent-skills.php) have no comment or docblock, so there is nothing for the rule to refuse. The only added comment is the usage header of the bash script `bin/test-agent-skills:8-9`, which extends that script's existing usage block and is not PHP code.
GL1 to GL6 — not-apply — no package test is added or changed; `bin/test-check-agent-skills.php` is the checker's own script, not an `EcotoneLite` test.
GL7 to GL14 — not-apply — no service, constructor, interface, configuration, metadata read, SQL or new PHP class file.
GL17 to GL22 — not-apply — no framework concept, `Api/` class, public signature, console option or header. `JUDGE_MODEL` matches the `--judge-model` flag it feeds.
GL23 to GL27 — not-apply — nothing throws an exception and nothing an application configures is added. The new checker violations still name the offending rule, why it matters and both ways out, and tests assert their text (bin/test-check-agent-skills.php:44-45).
GL28 to GL36 — not-apply — the change reads and writes no storage and opens no transaction or connection.
GL37 — not-apply — no Enterprise feature and no file under `src` or `Api/`.
GL38 to GL40 — not-apply — no module or package.
GL41 to GL43 — not-apply — no design or spec, and no framework behaviour a 1.x application notices. The review vocabulary is internal process, and upgrade-2.0.md is 1.x→2.0 only.

No `missing` requirement, so there is no deterministic-check candidate to report (G5).

#### Not on the list

None of these changes the result. Each is a candidate fix or requirement for the implementing session to weigh.

1. `.agents/skills/framework-pull-request/references/review-list.md:110` says "Every rule of every project skill
   is either cited … or listed here". The check skips the four `framework-review-*` skills on purpose
   (`REVIEW_SKILL_PREFIX`, and the checker case `review skills are not listed in the review list`), so G1–G5,
   V1–V9, B1–B6 and X1–X6 are neither cited nor listed. The sentence claims more than the check enforces. Say
   "every rule of every framework skill except the four review skills, whose rules are how the list is decided",
   or list those skills in the table.
2. `framework-design-and-briefs` D4 (and its *Applies when*, "a review whose findings are numbered") still speaks of
   numbered findings. P3 now cites D4 for committing the *decided lists*, and the review list (line 118) says the
   push process carries D4 out. The reviews no longer produce numbered findings, so D4 as written no longer
   describes what P3 cites it for. Reword D4 to "a review's decided list or numbered findings", or drop the citation.
3. Eval freshness. `review-list.md`'s mtime is 12:22 local (11:22Z). The last runs of the review cases started
   before that: 10:38Z for the guidelines, breaking and DX cases, 11:08Z for the behaviour cases. So those scores
   may predate the list's last edit, which the review prompts point at. Also, `review-diff-behaviour` and
   `review-diff-breaking` are at score 0.857 with passRate 0, which is above the 0.8 threshold on score but no
   single run passed every grader. Re-running is BH13's job (behaviour session); I name it so that it is not missed.
4. `checkReviewList()` (bin/check-agent-skills.php:331-336) silently uses `$lists[0]` if two skills each ship a
   `references/review-list.md`, and returns silently if none exists. A deleted list is still refused today, but by
   the link check, because skills link to it. A violation for "zero or more than one review list" would make the
   rule hold on its own.

## Round 1: breaking-changes

PASSED

Breaking-changes review of dgafka/ai-reviews, git diff origin/main...HEAD (38fabbd1b, 17d4a5d41; head 17d4a5d41).

Release (B1): latest tag 2.0.0-beta.10; branch alias dev-main => 2.0.x-dev (packages/Ecotone/composer.json:86).
The change ships in a 2.0.0 pre-release, i.e. inside the 2.0 major. It touches none of what users install anyway:
all 41 changed files are under .agents/ (internal framework-* skills, their references and eval graders) and bin/
(check-agent-skills.php, test-check-agent-skills.php, test-agent-skills). Nothing under packages/, api/ (public
skills, marketplace) or upgrade/ changed. The release workflow (.github/workflows/release.yml:146-190) splits
only packages/* directories, so .agents/ and bin/ never reach an ecotone/* package.

BC1 — not-apply — nothing written to a table, queue, outbox, dead letter or event store changes; the diff has no file under packages/
BC2 — not-apply — no stored form changes (see BC1)
BC3 — not-apply — no signature under packages/*/Api/, no attribute, gateway, #[ServiceContext] configuration or exception changes; the only PHP changes are the internal CI scripts bin/check-agent-skills.php (new checkReviewList()) and bin/test-check-agent-skills.php, which are not shipped
BC4 — not-apply — no default an application relies on changes; the only new default, JUDGE_MODEL=opus in bin/test-agent-skills:17, is a monorepo dev script that grades skill evals and is not shipped
BC5 — not-apply — no Ecotone console command (ecotone:*) changes; bin/test-agent-skills gains a JUDGE_MODEL env var, an internal script, not a console command of the framework
BC6 — not-apply — no behaviour an application observes changes: routing, ordering, transactions, retry, error types and timing are untouched; the new check in bin/check-agent-skills.php fails only the repository's own agent-skills CI job
BC7 — not-apply — the release is the 2.0 major (B1) and there is no observable behaviour change (BC6)
BC8 — not-apply — nothing written to a shared queue or table changes, so mixed-version deployments are unaffected

Not on the list
- None for this section. (Whether `claude plugin eval` accepts --judge-model, and whether the current tree passes the new
  review-list check, are behaviour questions, not upgrade safety; I did not run them because this review is read only.)

## Round 1: behaviour

NOT PASSED: BH8

### Behaviour review — dgafka/ai-reviews (origin/main...17d4a5d41)

#### Problem and expected outcome (V1)

Problem, in the maintainer's terms: each of the four pre-PR reviews judged a change by its own reading of the
project skills, and ran as a subagent that lost its context between review rounds. Expected observable outcome:
(1) an implementer reading `framework-pull-request` starts the reviews as Orca sessions in the change's worktree,
and uses subagents only without Orca; (2) one review list cites every project rule, and every reviewer decides its
section as not-apply / covered / missing, sending the missing ones back with a skill to load; (3) review sessions
run on Opus 5.5, and `bin/test-agent-skills` grades with Opus by default; (4) CI (`bin/check-agent-skills.php`) fails
when a project rule is not in the review list.

Trace: (1)–(3) live in `.agents/skills/framework-pull-request/SKILL.md` P3/P3a/P3b, `references/review-list.md` and
the four review skills. The skill evals show agents act on them: open-pr and update-open-pr score 1.00, including
the new rule-p3b (Orca first, Opus 5.5). `bin/test-agent-skills:17,65` passes `--judge-model "$JUDGE_MODEL"`, which
defaults to `opus` (the CLI's own default is haiku). (4): `bin/check-agent-skills.php` `checkReviewList()` is called
from the main flow and is run by `.github/workflows/agent-skills.yml`. On the current tree it reports "Agent skills
are valid." The change reaches the outcome, except for the gap in BH8.

Commands run (read only, nothing in the tree changed):
- `php bin/test-check-agent-skills.php` (php:8.3-cli): 37 checker cases passed.
- `php bin/check-agent-skills.php`: Agent skills are valid.
- Red run, in a scratch copy holding `origin/main:bin/check-agent-skills.php` and the new test file: 2 of 37 failed,
  'rule missing from the review list' and 'review list citing a rule that does not exist' (both "got exit 0: Agent
  skills are valid."). 'review skills are not listed in the review list' passed, as expected: it guards against a
  false positive.
- Deleting `review-list.md` in a scratch copy of HEAD: the existing link check flags it ("SKILL.md links to
  references/review-list.md, which does not exist", 11 times).
- `bin/test-agent-skills`, RUNS=1, one skill per invocation (scores below).

#### Missing

BH8 — missing — bin/check-agent-skills.php:332 (`if ($lists === []) { return; }`) — when no
      `.agents/skills/*/references/review-list.md` exists, the check returns silently and does not check any rule.
      Deleting the file is caught today only by the link check, because skills link to it by that path. Renaming the
      file (say `references/requirements.md`) and updating the links passes CI with no rule checked: requirement (4)
      silently stops holding. No checker case covers the no-list input. Fix: refuse when framework skills have rules
      but the review list is absent, naming the expected path (or resolve the list at a fixed path under
      `framework-pull-request`). Add a checker case 'review list missing' that deletes the fixture's list and expects
      that refusal. Optionally refuse more than one match too: today `$lists[0]` ignores any second list. —
      skill: framework-agent-tooling

#### Covered

BH1 — covered — traced above: P3/P3a/P3b + review-list.md + the four review skills (proven by evals), `--judge-model
      "$JUDGE_MODEL"` defaulting to opus (bin/test-agent-skills:17,65), and checkReviewList() in the CI checker
      (bin/check-agent-skills.php:56)
BH2 (uncited rule refused) — covered — bin/test-check-agent-skills.php 'rule missing from the review list'; red on the
      base checker, reproduced here ("got exit 0")
BH2 (citation of a non-existent rule refused) — covered — 'review list citing a rule that does not exist'; red on the
      base checker, reproduced here
BH2 (review skills exempt) — covered — 'review skills are not listed in the review list' passes either way, as a
      guard against a false positive. It would fail if the REVIEW_SKILL_PREFIX skip (bin/check-agent-skills.php:341)
      were dropped, because framework-review-demo's rules 1 and 2 are not cited
BH2 (Orca sessions first, Opus 5.5 reviewers, decide the list) — covered — graders open-pr/rule-p3, rule-p3a,
      rule-p3b and update-open-pr/rule-p3b pass. On the base skill they would fail: origin/main's framework-pull-request
      SKILL.md never mentions Orca, Opus or the review list (only "a separate agent session or a subagent", line 46)
BH2 (Opus judge by default) — covered — the evals below ran with the new default, and `claude plugin eval --help`
      shows the base default was haiku; the haiku control run differs on rule-v6 (see BH13)
BH12 — covered — the three new checker cases build their own fixture tree and assert only the checker's exit code and
      message; no phpunit test was added
BH13 — covered — bin/test-agent-skills, RUNS=1, threshold 0.8, judge opus (logs in this directory):
      framework-pull-request: open-pr 1.00, update-open-pr 1.00
      framework-review-guidelines: review-diff-guidelines 1.00, review-related-skills 1.00
      framework-review-breaking-changes: review-diff-breaking 1.00
      framework-review-behaviour: review-coroutine-compatibility 1.00, review-coroutine-consumer 1.00,
        review-diff-behaviour 0.86 (rule-v6 FAIL FAIL FAIL), review-feature-combinations 1.00, review-skill-change 1.00
      framework-review-developer-experience: review-diff-developer-experience 1.00, review-internal-refactor 1.00
      framework-agent-tooling: new-rule-enforcement 1.00
      Every case is at or above the threshold. Failing grader: rule-v6 (grades V6, which this change rewrote). It failed
      unanimously again on a rerun of that case with the Opus judge (0.86, FAIL FAIL FAIL), and passed with
      JUDGE_MODEL=haiku (1.00, PASS PASS FAIL). The agent's answer looks like it meets the grader text: a NOT PASSED
      line, every BH id decided, skill: on each missing line. So either the V6 grader or V6 itself does not hold up
      under the stricter judge; the judge records no reason. Every new or changed rule has a grader:
      P3/P3a/P3b (open-pr, update-open-pr), V2–V9 and V6, B1–B6, X1–X6, G1–G5 (graders updated in the diff), and A2
      (rule-a2, plus the check:agent-skills enforcement it now names)

#### Not apply

BH3 — not-apply — no message guarantee; the checker has a single entry point (the CLI script run by
      agent-skills.yml), and it is the one tested
BH4 — not-apply — no storage or engine-sensitive code
BH5, BH6, BH7 — not-apply — the checker runs once over a static tree: no partial failure, no transaction, no message
      delivery, no shared state
BH9 — not-apply — no attribute, configuration or Ecotone usage added (a review-list mistake is a checker refusal,
      tested under BH2)
BH10, BH11 — not-apply — nothing runs asynchronously or depends on time
BH14 — not-apply — tooling only; no Ecotone feature an application combines with others
BH15 to BH21 — not-apply — no service state, no wait, no consumer, no coroutine test: unrelated to handling several
      messages at once

#### Not on the list

- The rule-v6 result above. With Opus now the default judge, the grader of a rule this change rewrote fails on every
  run, while its case still passes overall (0.86 ≥ 0.8). The threshold lets a rewritten rule ship with its own grader
  failing. Worth either fixing V6 or its grader in this change, or a requirement that the graders of rules a change
  touches pass (framework-agent-tooling). The full answer and votes are in
  `.agents/evals/results/2026-11-21T14-17-09Z/aggregate-result.json` (case review-diff-behaviour, grader rule-v6).
- `REVIEW_LIST_CITATION` counts a backticked `framework-x N` anywhere in the list, prose included. A rule mentioned in
  passing (for example inside an example block) counts as cited without a requirement or a reason. That is a
  low-risk looseness, not a failure.

## Round 1: developer-experience

PASSED

Developer experience review — branch dgafka/ai-reviews, range origin/main...HEAD (head 17d4a5d41)

NO USER-FACING SURFACE

DX1 to DX10 — not-apply — the change has nothing an application developer writes, configures or reads. The 41
changed files are all maintainer tooling of the ecotone-dev monorepo: the internal framework-* skills under
.agents/skills (framework-pull-request incl. the new references/review-list.md, the four framework-review-*
skills, framework-agent-tooling), their eval graders under .agents/evals, and the CI/dev scripts
bin/check-agent-skills.php, bin/test-check-agent-skills.php and bin/test-agent-skills. No file under packages/
(no Api/, attribute, #[ServiceContext] configuration, gateway, console command, exception message or default) and
no public end-user skill (api/skills/ecotone-*) is touched, so no usage code exists to write for X1.

Note (outside the DX section, for the maintainer's reading only, not a finding): the new tooling knobs read
clearly for a maintainer — JUDGE_MODEL (default opus) in bin/test-agent-skills, and the checkReviewList violation
messages in bin/check-agent-skills.php name both ways out (cite the rule in a requirement, or list it under
'Rules no review decides').
