# Behaviour review — dgafka/one-path-review-requirement @ 682ae1273 (base origin/main 5ec5f01aa)

Re-review of 47455d84c..682ae1273 (18b439227 recorded re-check, 682ae1273 GL49 import). Previous verified head:
47455d84c, which I re-reviewed (ca1acce60..47455d84c) below.

PASSED — 0 missing, 3 covered (BH1, BH2, BH13), 19 not-apply

## Last fix (47455d84c..682ae1273)

- review-wiring-names-a-feature prompt imports `Ecotone\Messaging\Config\Container\Compiler\CompilerPass`, which
  exists (`packages/Ecotone/src/Messaging/Config/Container/Compiler/CompilerPass.php`). The graders are unchanged.
- `rules.md:412-413`: the mode bullet cites rule 2's table, which was my optional suggestion. The table splits
  "two genuinely different behaviours" into two classes, and its Open-core row chooses one in the container. The
  citation joins the two rows; that is wording for Guidelines and changes no behaviour.
- `rules.md:447-451`: the failure logger is now described as logging only when the polled channel is one of its own.
  That matches PR 779 (var/pr-779.diff:2779-2780, `in_array` over `POLLED_CHANNEL_NAME`).
- No new finding for my section; no packages/ code.
- Reran on 682ae1273: `php bin/check-agent-skills.php` "Agent skills are valid.", 41 checker cases passed, and
  `bin/test-agent-skills framework-service-design framework-review-guidelines` (var/eval-behaviour-review-4.log,
  report .agents/evals/results/2026-11-23T00-50-20Z): review-diff-guidelines 1.00, review-related-skills 1.00,
  review-scenario-branch 1.00, review-wiring-names-a-feature 1.00, add-dependency 0.91 (rule-18 FAIL FAIL FAIL,
  named arguments, a rule this change doesn't touch; it failed in rounds 1, 2 and 4 and passed in round 3),
  keep-ambient-state 1.00, route-replay-at-the-source 1.00.

## My round-2 observations

1. Boot-mode half of rule 22 had no grader. Resolved: review-wiring-names-a-feature's pool now picks `EvWait` or
   `StreamSelectWait` with `extension_loaded('ev')` on every `take()`. Grader rule-g3 criterion (5) requires it
   decided missing under GL51, with the choice moved to container build.
2. Pool example used `$this->connectionPoolWaitSeconds`, which is not on main. Resolved: `rules.md:418-420` now
   says the snippet is `ConnectionPool::waitForReturnedConnection()` as PR 779 changes it, where the tree waits a
   constant. The mode examples and the two filtering interceptors are attributed to PR 779 as well
   (`rules.md:406-407`, `:447-448`), and the evidence section says its "wrong" snippets are that branch's code
   (`rules.md:460-461`).

## The new ternary in isolation (asked by the coordinator)

Throwaway run, outside the tree: review-wiring-names-a-feature with one grader that judges only whether the
`extension_loaded('ev')` ternary is decided `missing` and fixed at container build. That grader accepts any
requirement id. 3 runs on each skill set, judge opus. Script and logs:
/tmp/claude-1000/-home-dgafka-orca-workspaces-ecotone-dev-implement-one-path-review-requirement/8737b17d-15d9-4ace-a415-f3224b76a853/scratchpad/iso/
(run.sh, head.log, base.log).

- **Head skills:** decided missing in 3 of 3 runs, PASS PASS PASS each time. Every run filed it under GL51 and
  named the `extension_loaded('openswoole')` example of rule 22, with the fix "one wait interface, the builder
  references EvWait or StreamSelectWait while compiling".
- **origin/main skills:** decided missing in 2 of 3 runs, once under GL26 and once inside a GL7 line, both through
  `framework-service-design` rule 2's "two services, one chosen in the container" table. The third run only
  remarked on it, which is a FAIL.

So the head skill decides the ternary missing on its own. It is not red on base for the ternary alone, though:
rule 2 on main already catches a per-call boot mode in most runs. Criterion (5) is red on base mainly because it
requires the GL51 id, which does not exist there. The case stays red on base for the right reason anyway, because
criterion (2), the compiler pass, fails on base in 3 of 3 runs. This doesn't make BH2 `missing`: the change's claim
is that the review files the per-call mode as a one-path break, and that holds 3 of 3 on head. But this criterion
does not show that rule 22 adds a catch of its own here; it overlaps rule 2.
Suggestion, not a requirement of this section: the mode bullet of rule 22 could cite rule 2's table as the same
decision.

## Does the new text break a Behaviour requirement

No. ef55ecc9a only adds attribution and keeps the meaning of rule 22. 47455d84c declares the prompts' classes as the
scenario's, renames the recording classes, corrects `sendMessage()` (true on main:
`DeadLetterHandler` calls `$messagingEntrypoint->sendMessage(...)`) and adds the ternary with criterion (5). All
three touched cases stay red on base and green on head (below), so the prompt rewrites did not make a case pass for
another reason. Still no packages/ code.

## Missing

(none)

## Covered

BH1 — covered — GL51 (`review-list.md:107`) → `framework-review-guidelines` G2/G3 → `framework-service-design` rule 22
      (`SKILL.md:102-133`, `references/rules.md:380-535`) reaches the three #779 places and the boot-mode half: the
      channel's in-place run and the interceptor's exemption (review-scenario-branch), the compiler pass naming
      another feature's class and the per-call extension ternary (review-wiring-names-a-feature), and the replay
      routed at its source (route-replay-at-the-source). The maintainer's boundaries hold: a refusal-only guard,
      the delay and the pool's own wait setting are not decided missing (rule-g3 of both review cases)
BH2 — covered — per claim, red on origin/main skills and green on the branch, 3 runs each (var/eval-base-3.log,
      var/eval-head-3.log): route-replay-at-the-source 0.50 → 1.00 (rule-22 FAIL 3/3 → PASS), review-scenario-branch
      0.33 → 1.00 (rule-g2, rule-g3), review-wiring-names-a-feature 0.50 → 1.00 (rule-g3 FAIL 3/3 → PASS). The
      ternary criterion (5) taken alone passes on base in 2 of 3 runs, apart from the GL51 id (section above)
BH13 — covered — run by me on 47455d84c: `bin/test-agent-skills framework-service-design framework-review-guidelines`
      (RUNS=1, THRESHOLD=0.8, judge opus; var/eval-behaviour-review-3.log, report
      .agents/evals/results/2026-11-23T00-07-12Z): review-diff-guidelines 1.00, review-related-skills 1.00,
      review-scenario-branch 1.00, review-wiring-names-a-feature 1.00, add-dependency 1.00 (rule-18 passed this run),
      keep-ambient-state 1.00, route-replay-at-the-source 1.00. No failing grader. framework-pull-request is
      unchanged since round 2. Graders cover every new or changed rule: rule 22 → rule-22; GL51/G3 → rule-g3 of
      both review cases, the mode half → rule-g3 (5); G2 → rule-g2; the description and Applies-when →
      skill-loaded. `php bin/check-agent-skills.php`: "Agent skills are valid."; `bin/test-check-agent-skills.php`:
      41 checker cases passed (php:8.3-cli via var/php)

## Not apply

BH3 to BH11 — not-apply — no packages/ code: no entry point, storage, engine, failure path, delivery, shared state,
      input, configuration, sync/async or time behaviour changes
BH12 — not-apply — no PHP test added or changed; the eval prompts are agent cases, not tests on Ecotone tables
BH14 — not-apply — no feature an application combines with others; the change is a development rule
BH15 to BH21 — not-apply — no service state, no wait, no I/O call, no consumer, no child execution, no coroutine test
BH22 — not-apply — no message flow or flow feature is added or changed
