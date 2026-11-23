# Breaking-changes review — dgafka/one-path-review-requirement @ d3ecbf4ca

PASSED

Release (B1): latest tag 2.0.0-beta.10, branch alias `dev-main` → `2.0.x-dev` (packages/Ecotone/composer.json:86). Target is a 2.0 pre-release, so this ships in a major. The diff (`git diff origin/main...HEAD`, 11 files) touches only maintainer-side `.agents/skills/framework-*` files and `.agents/evals/*` cases. There are no files under `packages/`, no `Api/` files, no user-land `ecotone-*` skills, no console commands and no storage code. The new GL51 row is added after GL50 and the new rule 22 is added after rule 18 in framework-service-design, so no existing requirement id or rule number moves.

- BC1 — not-apply — nothing written to a table, queue, outbox, dead letter or event store changes; the diff has no package code.
- BC2 — not-apply — no stored form changes.
- BC3 — not-apply — no signature under `packages/*/Api/`, attribute, gateway, `#[ServiceContext]` object or exception changes.
- BC4 — not-apply — no default value changes.
- BC5 — not-apply — no console command changes.
- BC6 — not-apply — application code sees no behaviour change; the new rule 22 and GL51 only change what agents write and review.
- BC7 — not-apply — the release is a major (2.0 beta), and no behaviour changes anyway.
- BC8 — not-apply — nothing is written to a shared queue or table.

Counts: 8 requirements — 0 missing, 0 covered, 8 not-apply.
