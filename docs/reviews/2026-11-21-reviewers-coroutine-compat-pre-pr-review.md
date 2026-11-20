# Pre-push review findings: the behaviour review checks coroutine compatibility, commit fddbf7955

Four independent reviews (guidelines, breaking changes, behaviour, developer experience). The breaking-changes
session found nothing: the change is confined to `.agents/`. The developer-experience session returned
NO USER-FACING SURFACE.

## Guidelines
- G1 (nit). `framework-review-behaviour` rule V9: *Expected* asks for a test that interleaves two executions for
  every risk the change touches, but the rule body accepts a test asserting the refusal for a consumer and for a
  wait coroutines cannot wait on. A grader reading only *Expected* fails a correct report.
- G2 (nit). V9 sends the reviewer to prove waiting, connections and consumers in real coroutines but does not say
  that a new coroutine test file has to be listed in `COROUTINE_TESTS` of `.github/workflows/test-monorepo.yml`,
  the only place CI runs such a file. Candidate for a deterministic check.

## Behaviour
Red-first proof produced by the session: the case `review-coroutine-compatibility` run three times against the
skill of `origin/main` (no V9) scored 0.50 each time, `rule-v9` judged FAIL by every judge; with V9 it scores 1.00.

- V1 (blocking). Two eval cases are below the 0.8 threshold in the session's single run on this tree: `open-pr`
  0.57 (rule-p1, rule-p3a, rule-p4) and `review-feature-combinations` 0.50 (rule-v8). The session reads the
  `open-pr` failures as judge noise on graders the change does not touch, and `review-feature-combinations` as
  unsteady before the change (0.5 and 0.83 on 14 November; 1.00 over three runs on this branch), with the answer
  now made longer by a V9 part of six speculative bullets.
- V2 (should fix). Same as G2; V9 also names only the Dbal suite of the three that run messages in real coroutines.
- V3 (should fix). Only one of V9's six risks is graded (state on a shared service). A consumer that has to be
  refused, state copied to a child coroutine and the one-line "unrelated" answer could be lost from the rule with
  every case still passing.
- V4 (nit). The bullet on a wait coroutines cannot wait on led a reviewer to file PostgreSQL through PDO as a
  finding, although `ConnectionPool` already refuses it. The bullet is about a new I/O call the diff introduces.
