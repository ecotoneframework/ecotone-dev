# Findings on the layout round (reviewed at d4e49b017)

Guidelines, Breaking changes, Developer experience and Behaviour reviewed `e8e6c9a9f..HEAD`: no blocking finding.
The layout (T1 to T9) stands.

## Should fix

- N1. Past six quotes a fold's body keeps its real indentation relative to the last quote; tested; glossary.
- N2. An interceptor fold whose last interceptor wraps several steps names where it ends: `🪝 6 interceptors around
  the next 61 steps, up to 📨 release collected message to backfill`; tested with two in a row; glossary.
- N3. A coroutine is linked to the step open when it was created, not when it first records; one that first records
  after its parent's entry ended stays unlinked, said in row 3h and the skill; tested with a coroutine that outlives
  its handler.
- N4. `chosen by: routing slip, its last entry` / `chosen by: routing slip, next: Y, Z`; baselines and #771 files
  regenerated in their own commit.
- N5. A userland test per routing slip and coroutine hint form, or the form removed with its glossary text; both
  coroutine hint lines asserted; the orchestrator's added entries recorded or the `adds` row says when it appears.
- N6. The moved-steps sentence counts per parent.
- N7. The glossary documents the process notes and the scenario sentence; `Runs other steps now` only as fallback.
- N8. One word per thing in the comment builder and `workflow.md`: folds of unchanged steps, whole sections.
- N9. A new or gone step drawn as a fold summary says `🟢 new ·` / `🔴 gone ·`; the plain dot means something
  inside changed; glossary; the process fold follows the rule or the glossary says its status is in the heading.

## Nits

- N10. No unchanged-run fold inside a fold where nothing changed.
- N11. A legend line above the first process; icons or glossary words for bridge, orchestrator, splitter,
  repository, recover, chained.
- N12. Wording of the gathering summary, wiring-only and traced-spans-only scenarios, left-out scenarios marked in
  the list, `part of:` for `work in:`, the baseline lag in the local-command sentence, the `drops`/`replaces` row.
- N13. Backticks in a label or title do not split inline code.
- N14. Named arguments, blank lines, docblock shapes, unused parameter, assignment in a key, `iconOf()` private, one
  step counter and arounds count, shared coroutine test helpers, a test keeping the glossary icon row in step with
  the code.
