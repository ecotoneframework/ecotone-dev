# Behaviour review — dgafka/one-path-review-requirement @ 47455d84c (base origin/main 5ec5f01aa)

Re-review of the fixes ca1acce60..47455d84c (d714d5efd, ef55ecc9a, 47455d84c).

PASSED

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
