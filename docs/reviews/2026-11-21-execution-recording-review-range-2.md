# Findings on d4e49b017..5e7547e99, after merging main (a4ed79f8a)

Guidelines, Breaking changes and Developer experience sessions, and the Behaviour session's items.

## Blocking

- Q1. `waitUntilShippingAtOnce()` is a hand-written `usleep` loop that gives up silently: a real coroutine barrier
  that fails loudly when the expected number never arrives; a testing-support helper in its own commit with its own
  test if needed.

## Should fix

- Q2. The outlives-its-handler test orders the coroutine's publish against `next()` with channels, not sleeps, and
  asserts the one expected step.
- Q3. `ExecutionRecordingShapes` moves out of `src/Test` into the tests.
- Q4. `FlowStepTree::ICONS` private again; the glossary icon test asserts rendered output of a fixture with every
  kind.
- Q5. A handler dropping or replacing routing slip entries: decide from the code whether a userland flow can do it,
  and make the recording and the glossary say it, with tests of the forms that occur.
- Q6. The legend line explains `🟢 new ·` / `🔴 gone ·`, names the 🔴 fold, and says what past six bars means.
- Q7. `around the next 61 steps, through <code>…</code>`; glossary.
- Q8. The moved-steps sentence counts the named steps, with the steps that run inside them in brackets.
- Q9. `started in: a coroutine started by process 2`.
- Q10. The glossary row of the routing slip forms reads without contradiction.
- Q14. The build-flow tests that record or compare an execution finish when the queue is empty; the flow suite
  under the 1 ms probe, before and after; no other `createWithTestingSetup()` added by the branch.
- Q15. Red-first evidence for N1, N2, N3, N6, N9, N10, N13 and every test this list adds.
- Q16. The coroutine that outlives its handler links to the nearest still-open ancestor: asserted exactly, and
  said in row 3h and the skill.

## Nits

- Q11. "The contents of 1 unchanged process and 30 folds of unchanged steps … are left out".
- Q12. Fold summaries use `<code>` for the label in every form.
- Q13. Whether "1 of 1 scenario changes its message path" is what CI posts for #771.
