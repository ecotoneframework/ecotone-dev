# Findings of the final four reviews (reviewed at e8e6c9a9f)

Guidelines, Breaking changes, Developer experience and Behaviour reviewed `origin/main...HEAD`: no blocking finding.
The layout of the comment's step tree (folding, collapsing) is not reworked in this round; wording and these items only.

## Should fix

- R1. Routing slip hints: one hint per step. Last entry taken: `chosen by: routing slip, now empty`; entries
  remaining: `chosen by: routing slip, then: Y, Z`; a step that puts entries on the slip: `routing slip: adds X`.
  Confirm what the two sides of today's arrow are; report uncovered cases with a proposed wording. Baselines and the
  #771 files regenerated in their own commit, with how many changed.
- R2. Committed framework-level tests on real coroutines (skipped without the extension): an in-memory consumer with
  several coroutines, some messages failing once with instant retry; a DBAL channel with one message going to the
  dead letter. Recording, exported span shapes per trace and outcomes equal the single-coroutine run.
- R3. An event published from a coroutine a handler started is recorded as its own process with no `sentBy`. Link
  it as a sub-flow of the step that started the coroutine, carrying what the link needs into the child execution
  without sharing mutable state; if that needs shared state, stop and report.
- R4. `build-flow-baseline.yml`: (a) must not collide with a release; implement only an option that leaves
  `release.yml` alone, else write the exact change in the report. (b) runs only in the upstream repository.
- R5. No assertion messages in the new tests; `SpanAttribute` constants for the execution attribute literals in
  `FrameworkTracingLevelTest`.
- R6. A changed process's note names the shallowest new or gone steps instead of repeating the heading.
- R7. Right after the summary line, every changed scenario by name with "N of M processes changed".
- R8. The `build` presentation's descriptions (`bin/build-flow.php`, `ecotone:build-flow:compare`, `workflow.md`) and
  the command a contributor is pointed to.
- R9. The format-change comment quotes the refusal up to "cannot be drawn." and adds "Nothing to do in this pull
  request."
- R10. Glossary: `runs in:`, `collected:`, gathering terms, `(part 1 of 2)`, the step words, `@@ N steps unchanged @@`,
  the routing slip forms.

## Nits

- R11. Named arguments; `PER_PROCESS_STATE`'s name; `withBoundedBuildTree`'s reason; the bot token named by its use
  outside a workflow; the comment's intro wording and the fallback's baseline sentence; presentation tests pin
  "runs only now" and a removed process at its place in run order.
- R12. Drop the `probe_members` table the behaviour session left in this worktree's MySQL.
