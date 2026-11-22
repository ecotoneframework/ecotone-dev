# Behaviour review — dgafka/one-path-review-requirement @ ca1acce60 (base origin/main 5ec5f01aa)

Re-review of the fixes d3ecbf4ca..ca1acce60 (d9f6506f5, 41414d694, 9d29ac08c, ca1acce60).

PASSED

## Round-1 findings

BH2 (round 1: missing at rules.md:469, the compiler pass comparing a collaborator's class had no eval case) —
resolved by ca1acce60: `.agents/evals/framework-review-guidelines/review-wiring-names-a-feature` (prompt: a
recording compiler pass adding `$innerChannel->getClassName() === InProcessMessageChannel::class`, next to a pool
guard on its own wait setting; grader rule-g3 requires the class comparison missing under GL51 / rule 22, a fix by a
contract the pass defines and the channel declares, and the pool guard not decided missing). G3 names it
(`framework-review-guidelines/SKILL.md:58`). Rule 22 now says it outright (`framework-service-design/SKILL.md:125-127`):
a pass of another feature comparing a definition's class has learned about it, which removes the "container build is
where a choice belongs" reading I flagged.

Base-red confirmed (var/eval-base-2.log, origin/main skills with the branch's cases, 3 runs): review-wiring-names-a-
feature 0.50, rule-g3 FAIL FAIL FAIL in 3/3 runs. It fails for the right reason: the three base answers
(var/eval-results/base-19-30-47/aggregate-result.json) end NOT PASSED on GL1–GL5, GL23, GL24, GL43, GL49, GL50, and
none of them decides the class comparison as a one-path break, since no such requirement exists on main. Head
(var/eval-head-2.log): 1.00 in 3/3 runs. The other two cases are still red on base and green on head with the changed
prompts: route-replay-at-the-source 0.50 → 1.00, review-scenario-branch 0.33 → 1.00 (3 runs each).

## Does the new text break a Behaviour requirement

No. The changes are skill text, eval prompts/graders and the committed docs/reviews lists; still no packages/ code.
- 41414d694 (GL49) rewrites two prompts to declare the code they ask about (`BackgroundConsumers::runNow()`,
  `InProcessEndpoint::handle()`); the graders follow. Both cases stay red on base and green on head (above), so the
  rewrite didn't make them pass for another reason.
- 9d29ac08c splits "configuration known at boot" into a *mode* (a runtime branch, resolved at container build) and a
  component's *own setting* every message meets the same way (a contract, may be branched on). The setting half is
  graded by review-wiring-names-a-feature criterion (4) (pool wait guard not missing). This also changes GL51
  (`review-list.md:107`). The interceptor "the registration selects" clause (`SKILL.md:123-125`,
  `rules.md:442-445`) elaborates an existing part of rule 22, and route-replay-at-the-source rule-22's "interceptor
  placed on every send" clause grades it.
- Observation, not a requirement of this section: the mode half ("a flag ... asked on every send is resolved at
  container build", `rules.md:406-410`) has no case where a reviewer must decide a boot-mode ternary missing. It
  narrows text that was already ungraded at d3ecbf4ca, which I decided covered. A future case could add one.
- Observation for Guidelines (GL49), not decided here: the covered pool example at `rules.md:415-418` uses
  `$this->connectionPoolWaitSeconds`; on main `ConnectionPool.php:150` pops with
  `self::SECONDS_WAITING_FOR_RETURNED_CONNECTION`, so the property comes from PR #779 and not from the tree.

## Missing

(none)

## Covered

BH1 — covered — GL51 (`review-list.md:107`) → `framework-review-guidelines` G2/G3 → `framework-service-design` rule 22
      (`SKILL.md:102-133`, `references/rules.md:380-530`) reaches all three #779 places: the channel's in-place run and
      the interceptor's exemption (review-scenario-branch), the compiler pass naming another feature's class
      (review-wiring-names-a-feature); the author side routes the replay at its source (route-replay-at-the-source).
      The maintainer's boundaries are graded: a refusal-only guard and the delay/own-setting branches are not decided
      missing (rule-g3 of both review cases)
BH2 — covered — per claim, red on origin/main skills and green on the branch, 3 runs each: route-replay-at-the-source
      (0.50 → 1.00, rule-22), review-scenario-branch (0.33 → 1.00, rule-g2, rule-g3), review-wiring-names-a-feature
      (0.50 → 1.00, rule-g3); var/eval-base-2.log, var/eval-head-2.log
BH13 — covered — run by me on ca1acce60: `bin/test-agent-skills framework-service-design framework-review-guidelines`
      (RUNS=1, THRESHOLD=0.8, judge opus; var/eval-behaviour-review-2.log, report
      .agents/evals/results/2026-11-22T23-22-33Z): review-diff-guidelines 1.00, review-related-skills 1.00,
      review-scenario-branch 1.00, review-wiring-names-a-feature 1.00, add-dependency 0.91, keep-ambient-state 1.00,
      route-replay-at-the-source 1.00. This grades the three service-design cases that the usage limit cut from the
      implementer's run. One failing grader: add-dependency rule-18 (named arguments, rule 18), FAIL FAIL FAIL, the same
      in every run since round 1. Rule 18 is not changed here, and the case is above the threshold.
      framework-pull-request (changed only in GL51's wording) was graded in round 1 at 1.00/1.00. Graders exist for
      every new or changed rule: rule 22 → rule-22; GL51/G3 → rule-g3 of review-scenario-branch and
      review-wiring-names-a-feature; G2 → rule-g2; the description and Applies-when → skill-loaded.
      `php bin/check-agent-skills.php`: "Agent skills are valid."; `bin/test-check-agent-skills.php`: 41 checker cases
      passed (php:8.3-cli via var/php)

## Not apply

BH3 to BH11 — not-apply — no packages/ code: no entry point, storage, engine, failure path, delivery, shared state,
      input, configuration, sync/async or time behaviour changes
BH12 — not-apply — no PHP test added or changed; the eval prompts are agent cases, not tests on Ecotone tables
BH14 — not-apply — no feature an application combines with others; the change is a development rule
BH15 to BH21 — not-apply — no service state, no wait, no I/O call, no consumer, no child execution, no coroutine test
BH22 — not-apply — no message flow or flow feature is added or changed
