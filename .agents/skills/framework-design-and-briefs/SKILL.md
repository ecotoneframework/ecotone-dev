---
name: framework-design-and-briefs
description: >-
  Process rules for Ecotone framework design work: justify new storage against existing
  read paths, make a design's claims the acceptance tests, state a brief's boundaries up
  front, commit numbered review lists, keep upgrade-2.0.md and specs current, and practices
  worth repeating. Use when writing or reviewing a design, a spec, a task brief for another
  agent, an upgrade note, or a numbered review in the ecotone-dev repository.
---

# Designs, briefs and reviews

[references/process.md](references/process.md) has the evidence (three friction reports over the DCB effort).

## Applies when

- Proposing a design or new storage (a table, an index, a backfill)
- Writing a brief that hands work to another agent or worktree
- Producing a review whose findings are numbered
- A change an application would notice (it needs an upgrade note)

## Rules

### D1. A design justifies new storage against the read paths that already exist
Name the index or read path checked and why it is not enough before proposing a new one.
- **Expected:** a design proposing a table or index names the existing read path it considered and why it fails.
- **Verified by:** `eval:design-new-index`

### D2. A design document's claims are the unit's acceptance criteria
Each claim ships with a test named after it, in the same commit.
- **Expected:** every guarantee the design states maps to a named test in the plan.
- **Verified by:** `eval:design-new-index`

### D3. A brief states its boundaries up front
Whether an exclusion covers tests as well as production code, whether "handle X here" reaches the paths X shares
with other features, which database engines the change is verified on, and which rules it is checked against.
- **Expected:** a written brief contains scope (incl. tests), shared paths, engines, and the skills/rules it will
  be reviewed against.
- **Verified by:** `eval:design-new-index`

### D4. A review that numbers its findings commits the numbered list
So a fix citing `(M1)` can be read against what was asked.
- **Expected:** numbered review findings are saved to a committed file before fixes cite them.
- **Verified by:** `eval:design-new-index`

### D5. A move of several co-imported types reverts newest-first
One commit per moved type keeps a rename reviewable, but adjacent `use` lines make the commits revert only as a
suffix. Run `git revert --no-commit <sha>` / `git revert --abort` against the unit's head, state in the report that
the unit reverts newest-first, and do not contort sorted files to avoid it.
- **Expected:** for a multi-commit move of co-imported types the report states the unit reverts newest-first
  (checked with `git revert --no-commit`), rather than claiming each commit reverts alone or squashing them.
- **Verified by:** `eval:design-new-index`

### 19. Keep the two audiences' documents current
A behaviour change a 1.x application would notice gets an `upgrade-2.0.md` entry with Before / Now / How to adapt;
a design decision goes into a spec. A rename updates the spec in the same commit.
- **Expected:** a user-observable behaviour change includes an upgrade note in Before / Now / How to adapt form.
- **Verified by:** `eval:design-new-index`

## References

- [references/process.md](references/process.md) — rule 19, the four process rules with evidence, reverting a
  co-imported move newest-first, practices worth repeating.
