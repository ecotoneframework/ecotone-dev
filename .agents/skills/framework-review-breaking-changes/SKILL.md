---
name: framework-review-breaking-changes
description: >-
  Review session that checks whether a change to the Ecotone framework is safe for users to
  upgrade to: no storage format change outside a major version, no break of the public Api/
  surface (classes, attributes, gateways, configuration, console commands), no silent
  behaviour change without an upgrade note. Use when running the breaking-changes review
  before a push or PR, or when asked whether a diff is backward compatible or safe to
  release in the ecotone-dev repository.
---

# Breaking-changes review

You are one of four independent reviews (see `framework-pull-request` rule P3). You judge only whether users can
upgrade to a release containing this change without breaking their application or their stored data.

## Applies when

- Running the breaking-changes review session before pushing or opening a pull request
- Asked whether a change is backward compatible, safe to release, or needs an upgrade note

## Rules

### B1. Establish the release the change ships in
Read the latest tag (`git describe --tags --abbrev=0`) and the branch alias (`dev-main` in
`api/composer.json` or `packages/Ecotone/composer.json`). A change may break storage or `Api/` only when the next
release is a new major (`x.0.0` → `x+1.0.0`, including its pre-releases); otherwise it ships in a minor or patch
and must not break.
- **Expected:** the report states the current version, the target release and whether it is a major.
- **Verified by:** `eval:review-diff-breaking`

### B2. Storage stays readable and writable across a non-major upgrade
Storage is anything an existing deployment holds: table and column names and types created by a table manager or
`ecotone:migration:database:setup`, event store metadata keys (`_aggregate_*`), message headers written to a
queue, outbox, dead letter or event store, serialized payload shapes, projection state, document store keys. A
change to any of them outside a major is BLOCKING. Within a major it needs a read path for the old form (a header
keeps a read path for its old name) and an `upgrade-2.0.md` entry with the migration step.
- **Expected:** a renamed/added/removed column, metadata key or persisted header is flagged BLOCKING for a minor,
  and for a major requires an old-format read path plus a migration note.
- **Verified by:** `eval:review-diff-breaking`

### B3. The public surface keeps working for application code
Inspect every changed file under `packages/*/Api/`, every attribute, gateway, `#[ServiceContext]` configuration
object, console command name and option, exception class an application may catch, and default value. Removed or
renamed classes, methods, parameters (including parameter names: applications use named arguments), narrowed
types, new required parameters, changed defaults and changed console names are BLOCKING outside a major. A new
optional parameter at the end, a new class, a new method on a final class are safe.
- **Expected:** each `Api/` signature change is classified safe or breaking with the reason; a renamed parameter
  or new required parameter is BLOCKING for a minor.
- **Verified by:** `eval:review-diff-breaking`

### B4. A behaviour change an application observes has an upgrade note
Different routing, ordering, transaction scope, retry, error type or timing that existing code can notice is a
breaking change even with identical signatures. It needs an `upgrade-2.0.md` entry (Before / Now / How to adapt)
and, outside a major, an opt-in instead of a new default.
- **Expected:** an observable behaviour change without an upgrade note, or flipped default in a minor, is flagged.
- **Verified by:** `eval:review-diff-breaking`

### B5. Mixed-version deployments keep running
During a rolling upgrade, old and new consumers share queues and tables. A message written by the new version
must be consumable by the old one and vice versa, unless the release is a major with that stated in the upgrade
note.
- **Expected:** a change to what is written to a shared queue or table is checked for old-reader/new-writer
  compatibility.
- **Verified by:** `eval:review-diff-breaking`

### B6. The verdict is explicit
End with `SAFE TO RELEASE`, `SAFE WITH UPGRADE NOTE` or `BREAKING`, followed by findings as
`[BLOCKING|SHOULD-FIX|NIT] <rule id> — <file>:<line> — what breaks for which user — the fix`.
- **Expected:** the report ends with one of the three verdicts and rule-cited findings.
- **Verified by:** `eval:review-diff-breaking`
