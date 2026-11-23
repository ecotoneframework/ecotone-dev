# The comment's step tree, final layout (head dde9d462c)

The maintainer settled the layout of "What ran, process by process" on a prototype checked at phone width. An inline
indented tree wraps and loses its nesting on a phone; a code block never wraps; GitHub folds only real nested
elements, and the only element that indents by a small step is a quote block.

- T1. One tree per process, first step to last, under one `<details>` "Steps of process N": open when the process
  changed, closed when unchanged (no more "… N unchanged steps" line for an unchanged process). The heading stays.
- T2. Steps are lines of fenced `diff` blocks: `+` new, `-` gone, hints `↳ key: value` one level under their step,
  two spaces per level relative to the enclosing fold. Adjacent lines share a block; a fold splits it.
- T3. Folds with a short flat summary: a send with steps under it (`📨 send backfill · 9 steps of channel
  interceptors`); a run of two or more wrapping interceptors (`🪝 5 interceptors around ⚙️ handler X`, what the last
  one wraps following at the first interceptor's level); a gateway, `entrypoint sends to`, `forward to`, `save`,
  `deliver` with steps under it (`<icon> <label> · N steps inside`); any other step with six or more steps under it
  whose parent runs several; four or more unchanged steps in a row (`… N unchanged steps`). A fold is open, its
  summary starting 🟢 (🔴 when it only holds gone steps), when something in it changed; closed otherwise.
- T4. What a fold holds sits in a `<blockquote>`, and folds under a step printed in a block sit in a `<blockquote>`
  under that block; indentation restarts inside a quote. At most six quotes deep, then named folds and unchanged
  runs still fold without a quote and no size-based fold is made. A chain of single children adds no quote.
- T5. One icon per kind (📨 📥 📬 🔀 ⚙️ 🚪 🪝 ➡️ ↩️ ✅ 🔎 💾 🔒 🧩 📦 📣 📊) in step lines and summaries; presentation of the
  comment only: `lines`, the recording and the baselines do not change.
- T6. "Like process N, except:" and "… N steps as in process X" are replaced by the same tree as every process.
- T7. Size: over 65,536 cut whole scenarios from the end; a scenario alone too large empties its unchanged processes'
  folds, then its unchanged-run folds ("in the run's artifact"), saying what was left out. Never cut inside a
  `<details>`, `<blockquote>`, fence or diagram. Tests for each rule, balanced elements on hostile inputs, and a blank
  line around every fence and HTML block.
- T8. The comment keeps its order; the glossary explains the fold summaries, the dots, the icons and the bar, and
  drops the forms T6 removes.
- T9. `compare --presentation=build` prints the same tree; the #771 comment regenerated; baselines unchanged.
