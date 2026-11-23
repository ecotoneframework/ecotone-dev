# The comment shows the step tree, and CI pushes as the Ecotone bot (head 03ab9c921)

Maintainer decisions.

- C1. "What ran, process by process" of the `build` presentation reads like the review page built for #771: one block
  per process in run order, every process listed; a heading per process (`Flow · process 2 of 6` or
  `Sub-flow · process 3 of 6` naming the sender), a status (`unchanged`, `2 new steps`, `runs only now`,
  `no longer runs`), the step count (`46 → 48 steps`, one number when equal), the process title and the one-sentence
  note the comparison already writes; an unchanged process is the heading and `… N unchanged steps`; a changed
  process shows its step tree in context (every ancestor of a changed step from the root, kind first, unchanged
  siblings and subtrees collapsed to `… N unchanged steps`, `+`/`-` lines, the hints the lines show as `↳ key: value`)
  in its own `<details open>`; processes that repeat another keep "like X except:". Order: heading and what is
  compared, the Before/Now summary, this section, the message-path diagram, the collapsed wiring. The 65,536 limit
  rules stay: cut whole processes or scenarios from the end, never inside a tree, a diagram or a `<details>`. Glossary,
  presentation tests and the #771 comment updated; the baselines do not change.
- C2. The baseline workflow pushes with the bot token `release.yml` uses and the git identity it configures;
  the separate baseline token secret is removed everywhere; `paths-ignore` stays; `workflow.md` says what runs on that commit;
  the refused-push message says to check that the bot's token may push to `main`. The comment workflow keeps
  `github.token` and still finds its own comment.
- C3. Nothing a pull request shows (the comment, its glossary) names a secret.
