# Developer experience review — dgafka/one-path-review-requirement

Branch head d3ecbf4ca, range `origin/main...HEAD` (5ec5f01aa..d3ecbf4ca, 2 commits).

**PASSED** — 0 missing, 0 covered, 10 not-apply.

NO USER-FACING SURFACE

The diff touches only contributor-facing material under `.agents/`:
`framework-service-design` (rule 22 in SKILL.md and references/rules.md), `framework-review-guidelines`
(Verified-by lines of G2/G3), the review list (GL51), and two eval cases
(`route-replay-at-the-source`, `review-scenario-branch`). No file under `packages/`, `api/skills/` (the user-land
`ecotone-*` skills), `upgrade/` or docs changes, so an application developer building a Laravel, Symfony or Lite
application writes, configures and reads nothing new: no attribute, `#[ServiceContext]` configuration, gateway,
console command, option, default, exception message or name.

| Id | Decision | Why |
|---|---|---|
| DX1 | not-apply | no end-user usage code exists for the change |
| DX2 | not-apply | no feature is switched on |
| DX3 | not-apply | no configuration is added |
| DX4 | not-apply | no option is added or changed |
| DX5 | not-apply | no option is added |
| DX6 | not-apply | no default is added or changed |
| DX7 | not-apply | no failure-handling, delivery, transaction or data-safety default is added or changed |
| DX8 | not-apply | no less safe behaviour is offered |
| DX9 | not-apply | no name an end user reads is added or changed (GL51 and rule 22 are read by framework contributors) |
| DX10 | not-apply | no end-user name is added |

Note, not a requirement of this diff: the rule's worked example (PR #779) moves a dead-letter replay from a console
command off the coroutine channel and onto a refusal outside a coroutine. Where that lands in #779 it changes what an
end user sees (the refusal message has to name the ways out), and the DX review of #779 decides it there.

## Re-check at ca1acce60 (range d3ecbf4ca..ca1acce60, 4 commits)

**PASSED** — 0 missing, 0 covered, 10 not-apply.

Round 1 had no `missing` finding, so nothing needed resolving. The new commits still have NO USER-FACING SURFACE. They
change contributor material under `.agents/`:
- rule 22 now separates a mode known at boot from a component's own setting, and the skill description changed
- the GL51 wording
- the eval prompts now declare their own code
- a new eval case, `review-wiring-names-a-feature`

They also add four decided review lists under `docs/reviews/`. These are internal review records, not
docs.ecotone.tech pages. The DX one matches this file. No `packages/`, `api/skills`, `upgrade/` or user
documentation file changes. DX1–DX10 stay `not-apply` for the same reasons as above.
