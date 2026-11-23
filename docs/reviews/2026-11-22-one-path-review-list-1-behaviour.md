# Behaviour review — dgafka/one-path-review-requirement @ d3ecbf4ca (base origin/main 5ec5f01aa)

NOT PASSED: BH2

## Problem and observable outcome (V1)

Reporter's terms: on PR #779 the pre-PR reviews passed three places where a component had learned about one of its
callers: (1) the coroutine channel ran the handler in place when outside a coroutine, (2) its holding interceptor let
a message past its refusal when it carried `ErrorContext::DLQ_MESSAGE_REPLIED`, a header any sender can set, (3) an
open-core compiler pass compared a channel's class with `CoroutineMessageChannel::class`. No review requirement spoke
about it. The outcome that proves it solved: a guidelines review session given such a diff decides each of the three
`missing` under a requirement, while a refusal-only guard and a branch on the component's own contract (delivery
delay) stay allowed; and an implementing agent asked to give one flow different treatment routes it at its source.

Trace: `review-list.md:107` adds GL51 citing `framework-service-design 22` → `framework-review-guidelines` G2/G3
decide every Guidelines requirement and load the cited skill (`SKILL.md:44`, `:58` now name the new case) →
`framework-service-design/SKILL.md:102-124` rule 22 (part-by-part decision, refusal-only allowed, exemption from a
refusal is the second path, own contract allowed, three alternatives onto an existing path) →
`references/rules.md:380-507` reasoning with the #779 wrong/right pairs. Place (1) and (2) are proven by evals
(below). Place (3) is reached by the text only ("or in the code that wires it", "a caller's or collaborator's
concrete class", `rules.md:469-480`), and no case grades it — see BH2.

## Missing

BH2 — missing — .agents/skills/framework-service-design/references/rules.md:469 (and review-list.md:107, "a caller's
      or collaborator's concrete class ... or in the code that wires it") — the third claimed behaviour, a reviewer
      deciding `missing` a compiler pass that compares a collaborator's class (`$innerChannel->getClassName() ===
      CoroutineMessageChannel::class`), has no eval case at all, so nothing shows it red on origin/main nor green on
      the branch. It is the one most likely to be misjudged: it runs at container build, and the same rule tells the
      reader that container build "is where a choice belongs" and that boot-time configuration is resolved there, so
      a reviewer can pass it as a build-time choice. Neither existing grader mentions it: rule-g3 grades only the
      channel's runtime branch and the interceptor's exemption, rule-22 grades only the channel/interceptor. — add a
      compiler-pass hunk to `.agents/evals/framework-review-guidelines/review-scenario-branch/prompt.md` (an
      Apache-2.0 pass checking `getClassName() === <Enterprise channel>::class`) or a separate case, with a grader
      that requires it decided `missing` under GL51 / rule 22 and a fix where the pass defines a marker the channel
      declares (not "move the comparison elsewhere"); show it red on origin/main skills and green on the branch
      (var/eval-two-cases.sh already does that) — skill: framework-agent-tooling

## Covered

BH1 — covered — trace above: GL51 → G2/G3 → service-design rule 22 reaches the outcome for places (1) and (2), proven
      by review-scenario-branch and route-replay-at-the-source; place (3) is reached by the rule text, its missing
      proof is BH2
BH13 — covered — `bin/test-agent-skills` on the tree as it is (RUNS=1, THRESHOLD=0.8, judge opus; changed skills
      framework-pull-request, framework-review-guidelines, framework-service-design; log
      var/eval-behaviour-review.log, report .agents/evals/results/2026-11-22T19-17-21Z): open-pr 1.00, update-open-pr
      1.00, review-diff-guidelines 1.00, review-related-skills 1.00, review-scenario-branch 1.00, add-dependency 0.91,
      keep-ambient-state 1.00, route-replay-at-the-source 1.00. One failing grader: add-dependency rule-18 (named
      arguments, service-design rule 18), FAIL FAIL FAIL, also in the implementer's run; rule 18 is not changed by this
      diff and the case is above the threshold. New/changed rules have graders: rule 22 → route-replay-at-the-source
      rule-22; GL51 and G3 → review-scenario-branch rule-g3; G2 → review-scenario-branch rule-g2; the new Applies-when
      bullet → route-replay-at-the-source skill-loaded. Red-first for both new cases: var/eval-base-1.log (origin/main
      skills: route-replay-at-the-source 0.50, rule-22 FAIL 3/3; review-scenario-branch 0.33, rule-g2 and rule-g3 FAIL
      3/3) vs var/eval-head-1.log (1.00, 3/3). `php bin/check-agent-skills.php`: "Agent skills are valid.";
      `bin/test-check-agent-skills.php`: 41 checker cases passed (both run by me in php:8.3-cli via var/php).

## Not apply

BH3 to BH11 — not-apply — no packages/ code: no entry point, storage, engine, failure path, delivery, shared state,
      input, configuration, sync/async or time behaviour changes
BH12 — not-apply — no PHP test added or changed; the eval prompts are agent cases, not tests on Ecotone tables
BH14 — not-apply — no feature an application combines with others; the change is a development rule
BH15 to BH21 — not-apply — the change has no service state, no wait, no I/O call, no consumer, no child execution and
      no coroutine test
BH22 — not-apply — no message flow or flow feature is added or changed
