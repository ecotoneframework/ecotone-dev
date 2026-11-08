# Where the conventions come from

Every rule below is one the codebase already follows and that a contributor got wrong at least once. Each carries
a *why* and a citation — a file, or a commit you can `git show`. Where a rule is easy to misread, a wrong/right
pair sits next to it; the wrong side is real code that was corrected.

Four independent audits have measured the tree against these rules. What they found and has **not** been
actioned yet — with each item's disposition, and the four places an audit's own claim turned out to be wrong —
is in [known-deviations.md](../../framework-review-guidelines/references/known-deviations.md).

Every `docs/superpowers/` path cited below — the design records, the audits and the research reports — lives on
branch `dgafka/dcb-docs` and is deliberately untracked here, since `docs/superpowers/` is in `.gitignore`. The
paths resolve on that branch.

Rule numbers are global across the framework skills: rules 1-11 are the maintainer's. Rules 12-19 are conventions the code holds to consistently. Section 20 lists the
mechanics a tool enforces for you, and section 21 the gates and landmines. Lettered sub-rules (1a, 1b, 1c, 1d, 6a, 10a, 10b, 16a, 16b,
12a, 13a, 14a) continue the rule they hang off; they are numbered that way so nothing above them ever renumbers.

> **Verify names before you write them.** Every attribute, parameter, method and console option in code you write
> or documentation you edit must be checked against the current tree first. The 2.0 API moved
> (`Ecotone\Api\Attribute\CommandHandler`, not the old flat namespace), so recall is unreliable. Three invented
> facts reached the first draft of the 2.0 docs this way. A wrong name in a guide is worse than no guide.

---

