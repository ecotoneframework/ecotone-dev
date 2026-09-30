# Conventions audit — group c: tests, docs and conventions of expression

Measured against `docs/coding-conventions.md` at HEAD `b255fd5e9` (branch `dgafka/ecotone-2-0-conventions-audit-c`,
base `dgafka/ecotone-2-0-work`). Inventory only — no production code, tests, or documentation other than this file
were changed to produce it.

Scope: `packages/*/src` and `packages/*/Api` for production rules (6, 7, 14); `packages/*/tests`, `Monorepo/`,
`quickstart-examples/` for test rules (10, 11). `find packages -maxdepth 2 -name vendor -type d` returned nothing
at audit time, so no per-package build-artifact `vendor/` directories needed excluding from any count below.
`bin/check-licence.php` could not be run natively in this worktree (no `php` binary on host, no root `vendor/`
installed, no running docker containers) — rule 7's `src`/`Api` coverage numbers are a grep-based approximation of
the script's own regex, cross-checked against the rule doc's stated 1009/227 baseline (it reproduces exactly,
which is strong evidence the approximation is correct, but it is not the script itself).

Five rules were audited by independent sub-agents in parallel, each given the rule's full text, its stated scope
and exceptions, and the violation / exempt / rule-is-wrong framing below, then asked to prefer reproducible
commands over judgement and to say plainly where a full count wasn't possible. Numbers in this report are exactly
what those audits measured; nothing has been rounded up or padded to look more decisive than the underlying method
supports. **Note on rule 11 in particular**: an earlier pass at this same audit (committed to this branch, later
superseded by this version) reported "100 of 141 test files added since 1.x," extending the rule's own "92 of 141"
baseline forward. This version's audit went back to git history to verify that denominator and found it does not
reconstruct — see rule 11 below for why, and for the reproducible whole-suite number used instead.

## Summary — what to do

| Rule | Violations (headline) | Exempt | Fix cost | Recommendation |
|---|---|---|---|---|
| **6** — names carry meaning, no comments/prose docblocks | ~271 genuine inline comments + ~789 genuine prose docblocks (Ecotone-authored, non-ported) across `src`+`Api`; only **15** of those (13 docblocks + 2 comments) were added in the last 10 days, in the exact DCB/EventSourcing area the rule targets | ~180 `//`/docblock hits sit in vendored third-party ports (Enqueue transports, cron-expression, illuminate/database, DataProtection's `Encryption/`); 613 of 922 docblock hits pre-date 2025 and are grandfathered by the rule's own "leave legacy files alone" text | Legacy/ported: leave alone. The 15 fresh hits: needs judgement (several carry real design rationale needing a new home — a name, an exception message, or a spec doc) | **Enforce for new code only.** Fix the 15 fresh hits now, especially `FinalFailureStrategy.php` (see below — moved into `Api/` in the same commit at HEAD without its prose docblock being cleaned up). Do not sweep the legacy/ported ~793 hits |
| **6a** — one word per concept | 2 concrete, live violations found: `expectedVersion`/`capturedVersion` — the exact pair the rule's own text claims was "settled" by `a8f23212c` — is not settled (`DecisionModelConcurrencyException.php`, `DbalTagVersionRegister.php`, both current at HEAD); and a unit qualifier silently dropped, `confirmationTimeoutInMilliseconds` → `confirmationTimeout`, across 3 SQS classes | Sample was 11 files touched / 8 read in full (of a planned 15–20) across Dbal/Amqp/Kafka/Sqs — too small to certify the rest of the codebase clean | Mechanical — both are 1–2 line renames | **Fix the 2 found now** (cheap, in the exact area the rule's own example is about); treat the rest as unaudited, not clean |
| **7** — Enterprise features live in separate files | **2** confirmed mixed-licence-branch violations — `FetchAggregateConverter.php` and `EcotoneProjectorExecutor.php` (×2 call sites) — an `if ($this->licenceDecider->hasEnterpriseLicence())` inside an ordinary class instead of two classes behind one interface; **272 of 2011** test files (13.5%) are missing any licence header (convention only, not CI-gated) | `packages/*/src` (1134 files) and `packages/*/Api` (170 files): **0 missing headers** — fully clean, and the Apache-2.0/Enterprise totals (1009 / 227 combined) reproduce the rule doc's stated baseline **exactly**, unchanged despite the intervening `8828abc1e` Api-move | Mixed-branching: needs a design decision (is a single-metadata-key difference worth a full interface split?). Header gap: mechanical bulk-backfill, but `bin/add-*-licence.php` are hard-coded to `src/` only and would need repointing at `tests/` first | **Decide** the 2 branch violations (split per the rule's own `AggregateMethodInvoker` pattern, or explicitly scope the rule to exclude trivial single-field branches); **bulk-fix** the 272 test headers, or extend `check-licence.php` to gate `tests/` too since the convention is otherwise unenforceable |
| **10** — tests validate at userland level only | **30 clear violations**: 2 raw-SQL assertions directly on Ecotone's own tables (`ecotone_deduplication`, `ecotone_document_store`'s internal envelope fields), **26** internal-container-reference uses instead of a gateway (**25 of them one systematic pattern, 17 files, in `packages/Ecotone/tests`**, plus 1 isolated instance inside the already-"fixed" `PdoEventSourcing`), 2 reflection violations (both in `Tempest/tests/Hardening`, an area the historical fix worktree never touched) | Statement-counting: **0 anywhere** — the rule's own claimed removal (`04e831b55`) holds exactly. Most raw-SQL/reflection grep hits are legitimate (a stream name passed to a public gateway method, or a unit test of the reflection-producing layer itself). 1 internal-collaborator-named test found (`DbalConsumerPositionTrackerTest`), sampled against the full ~465-file test-class-name population | The 26 container-ref swaps: **mechanical**, zero risk — the exact `getGateway(...)` replacement already exists and works in sibling test files in the same directories. The 2 raw-SQL and 2 reflection cases: needs judgement (one likely needs deletion, one likely needs a small public-API addition) | **Fix the 26 swaps now** — free, zero-risk, closes by far the largest and most systematic gap found. Judge the remaining 4 cases individually; no dedicated worktree needed, this is a handful of commits |
| **10a** — a guarantee is proved on every path | Illustrative 2-feature sample only (not a count, not mechanically auditable at scale): `#[WithoutDatabaseTransaction]` is well covered across class-routed send, routed send, and a console command in one file; the tag-version optimistic-lock save-guard is tested **only** via class-routed `sendCommand()`, never `sendCommandWithRouting()` — the exact class-routed-vs-routed-send split that already caused one shipped bug (`57c076084`→`9ae4e3fd3`) for a different guarantee | n/a | One test-file addition, small | Add one `sendCommandWithRouting()` test alongside the existing class-routed ones in `AggregateSaveTagGuardDbalTest.php` / `AggregateBackedDecisionModelConcurrencyTest.php`; treat this as a lead worth following up, not a full audit |
| **11** — test shape (inline anonymous fixtures) | The rule's own "92 of 141 test files added since 1.x" denominator **does not reconstruct against git history** (see below) — reporting instead against the current, reproducible whole suite: **132 of 518 current test files (25%)** declare a named fixture below the `TestCase`; **123 of those 132 (93%)** contain at least one genuine violation (no PHP-forced or attribute-argument reason for the name). At the class level, **~366 of 530** extra named declarations (69%) are genuine violations | **~164 of 530 (31%)** extra named declarations are legitimately exempt — under the rule's own two documented exceptions, plus a **third, undocumented but equally hard constraint this audit surfaced**: interfaces, enums, and native `#[Attribute]`-implementing classes have no anonymous-class syntax in PHP at all (~25 files) | Mechanical for the large majority (a plain service class referenced only via `[X::class]` — the pattern already proven correct in the rule's own `BasketTest`/`MessageBusTest` examples). Needs judgement where a class is reused across multiple call sites, or forms an inheritance pair with another named fixture | **Enforce for new code**, concentrated on `Ecotone/Modelling/DecisionModel`, `Ecotone/Modelling/AggregateBoundary` and `PdoEventSourcing/Integration/Tagging` — the highest-yield, most structurally repetitive clusters. **Correct the rule's own baseline sentence** (the "since 1.x" framing no longer corresponds to any recoverable git boundary) and **add the interface/enum/`#[Attribute]` exception as a documented third bullet** so a future audit doesn't misflag ~25 unfixable files |
| **14** — console option name = PHP parameter name | **0** in production code — `#[ConsoleParameterOption]` has no constructor and cannot carry a name override, so the rule is structurally unviolable from PHP itself. **9 occurrences (5 distinct option names)** written in kebab-case in `upgrade-2.0.md`, describing two real `PdoEventSourcing` commands (`ecotone:event-store:backfill-tags`, `ecotone:event-store:verify-schema`) | n/a | **Mechanical** — 5 find-and-replace pairs across 7 lines in one file, pure documentation fix, no behaviour change | **Enforce and fix now** — this is exactly the failure mode (`5fa0024b2`) the rule was written from, recurring in the 2.0 upgrade guide itself, arguably the document most likely to be followed verbatim |

---

## Rule 6 — Names carry the meaning; no comments, no descriptive docblocks

**Scope**: `packages/*/src` and `packages/*/Api` only — test comments/docblocks are rule 11's concern, not counted
here.

### Detection method

```bash
find packages -maxdepth 2 -name vendor -type d                              # confirm no stray build dirs (none)
grep -rn '^\s*//' --include='*.php' packages/<Pkg>/src packages/<Pkg>/Api   # inline comments, per package
```
Descriptive docblocks were found with an ad hoc script walking every `/** ... */` block, skipping licence blocks
and array-shape/`@template`/`@link` docblocks, and flagging (a) any non-empty prose line before the first `@`-tag,
and (b) any `@param`/`@return` line with prose after the type/variable. Every flagged hit was dated with
`git blame -L <line>,<line> --porcelain` to separate recent additions from long-standing prose.

Exclusions applied and why: `@codeCoverageIgnoreStart`/`End` (2 hits, tool directives, not prose); `licence
Apache-2.0`/`licence Enterprise` docblocks (rule 7's concern, not rule 6's); `// TODO` markers (9 hits, counted
separately — a different flavour, task markers rather than "what this code does" prose); and 32 files that are
explicit third-party ports (carrying an MIT/BSD-3-Clause header or an explicit "modified version of ..." note —
php-enqueue transport adapters, `dragonmantank/cron-expression`, `illuminate/database`'s PDO wrappers) plus
`DataProtection/src/Encryption/*`, which reads as an unmarked but clearly verbatim port of a known PHP encryption
library by its class names and comment style. These are vendored code the rule was never really aimed at; they are
reported separately, not folded into "Ecotone's own" debt.

### Counts per package (raw baseline, before the ported/legacy split)

| Package | `//` (raw) | Docblocks (raw) | of which in ported files |
|---|---|---|---|
| Ecotone (src) | 219 | 599 | 63 (cron-expression library) |
| Ecotone (Api) | 0 | 55 | 0 |
| Dbal (src) | 18 | 62 | 26 (enqueue-dbal transport port) |
| Dbal (Api) | 2 | 16 | 0 |
| DataProtection (src) | 105 | 54 | 0 (unmarked port, see above) |
| Amqp (src) | 36 | 32 | 2 (enqueue-amqp transport port) |
| Amqp (Api) | 0 | 6 | 0 |
| Laravel (src) | 6 | 42 | 39 (illuminate/database PDO port) |
| Kafka (src/Api) | 5 / 8 | 14 / 8 | 0 |
| Sqs (src/Api) | 7 / 0 | 3 / 3 | 2 (enqueue-sqs transport port) |
| Redis (src/Api) | 1 / 0 | 1 / 3 | 1 (enqueue-redis transport port) |
| PdoEventSourcing (src) | 9 | 7 | 0 |
| Tempest (src/Api) | 16 / 0 | 2 / 2 | 0 |
| Symfony (Api + other) | 1 | 2 | 0 |
| JmsConverter | 0 | 1 | 0 |
| OpenTelemetry (src) | 1 | 2 | 0 |
| Enqueue (src) | 0 | 8 | 0 |
| **Total (raw)** | **434** | **922** | **133 docblock / 47 comment hits in explicitly-marked ports** |

Totals after exclusions: of 434 raw `//` hits, 2 are coverage directives, 47 sit in explicitly-marked ported files,
105 more sit in the unmarked-but-clearly-ported DataProtection `Encryption/` subtree, and 9 are TODO markers —
**~271 are genuine Ecotone-authored explanatory comments**. Of 922 raw docblock hits, 133 sit in explicitly-marked
ported files — **~789 are Ecotone's own**.

Docblock age (via `git blame` on every flagged line, all 922 raw hits):

| Year added | Count |
|---|---|
| 2022 | 468 |
| 2023 | 49 |
| 2024 | 96 |
| 2025 | 143 |
| 2026 (through 09-29) | 166 |
| — of which 2026-09-20 through 2026-09-29 | **13** |

66% of all raw docblock hits (613 of 922) pre-date 2025 and are true 1.x-era legacy, squarely covered by the
rule's own text: *"Legacy prose docblocks remain in older files. Leave them where you are not otherwise touching
the file; do not add new ones."* Only **13 docblock hits + 2 comment hits** date from the last 10 days — but all
13 sit in `Ecotone/src/EventSourcing/`, `Ecotone/src/Modelling/DecisionModel/`, `Ecotone/src/Modelling/
EventSourcingExecutor/` and `Ecotone/Api/EventSourcing/` — exactly the DCB/modelling area the rule was mined from,
and in the same 2026-09-20..09-30 window as the enforcement commits the rule text itself cites
(`5c3a9ed2c`, `9ca15e6ea`, `75e943982`, `8f065ed98`). New violations are still being added in the very area and
window the rule targets, alongside commits that were actively removing the same kind of prose.

Side observation: the one docblock form the rule explicitly encourages, `@link https://docs.ecotone.tech/...` on
an `Ecotone\Api` class, has **zero** occurrences anywhere in the repo. Every existing `@link` points at an
external spec (confluent.io, librdkafka) or is a PHPDoc `{@link ClassName}` cross-reference.

### Worst handful of concrete examples

1. **`packages/Ecotone/Api/ExtensionObject/FinalFailureStrategy.php:10-14`** — moved into `Api/` at HEAD by
   `8828abc1e` ("move MediaType, FinalFailureStrategy and RetryTemplateBuilder into Api"), carrying a four-line
   prose docblock written 2025-07-13 when the class still lived under `src/Messaging/Endpoint/`. `Api/` is the
   strictest zone for this rule (only `@link` is allowed there), and the file was touched in the very commit that
   landed it there without the docblock being cleaned up.
2. **`packages/Ecotone/src/EventSourcing/Tagging/TagResolver.php:92-95,109-111`** — added 2026-09-29, the same day
   as the rule's own cited enforcement commits, explaining *why* a filter-only tag is stamped with sequence 0.
3. **`packages/Ecotone/src/EventSourcing/EventSourcedRepositoryAdapter.php:155-158`** — added 2026-09-29
   (`f8035b25f`), explaining snapshot-fold-shape invalidation in prose rather than a name or exception message.
4. **`packages/PdoEventSourcing/src/Dbal/DbalEventStore.php:267-268`** — added 2026-09-24, an inline `//` narrating
   a PostgreSQL aborted-transaction workaround.
5. **`packages/Ecotone/src/Messaging/Precedence.php`** (whole file, 2022) — the clearest grandfathered example: every
   interface constant carries a redundant one-line docblock a better name would remove (e.g. `TRACING_PRECEDENCE`
   is already self-explanatory).
6. **`packages/DataProtection/src/Encryption/File.php`** — 41 inline comments in one file (e.g. "Initialize a
   streaming HMAC state.", "PASS #1: Calculating the HMAC."), added 2026-02-11 but reading as a verbatim port of a
   third-party encryption library by class names (`Core`, `Crypto`, `Encoding`, `KeyOrPassword`) and style.

### Fix cost

Legacy (613 hits) and ported-library (~180 hits) content: leave alone, mechanical delete-only-if-touching-anyway.
The 15 fresh hits: needs judgement — `TagResolver.php` and `EventSourcedRepositoryAdapter.php` carry real design
rationale that the rule says belongs in a name, an exception message, or a spec doc, not just a deletion.
`FinalFailureStrategy.php` documents every `FinalFailureStrategy` enum case's per-transport redelivery behaviour —
deleting it outright would lose information with nowhere else to live yet, so this one needs the content *moved*
(to a transport-behaviour doc under `docs/`), not just deleted — a design decision, not mechanical work.

### Recommendation

**Enforce for new code only.** Do not sweep the 613 legacy or ~180 ported-library hits — the rule's own text
grandfathers the former, and the latter isn't Ecotone's authorial voice. Fix the 15 fresh hits (a short follow-up
commit) and the `FinalFailureStrategy.php` `Api/`-move violation specifically, since it's the newest and most
visible violation on the public surface at HEAD.

---

## Rule 6a — One word per concept, through the whole path

### Method

Two passes: (1) a targeted grep re-verifying the rule's own claim that `version`/`expectedVersion`/
`capturedVersion`/`sequence` drift was "settled" by `a8f23212c`; (2) a blind sample intended to cover 15-20 files
across Dbal/Amqp/Kafka/Laravel/Sqs, of which **11 files were touched and 8 read in full** — smaller than planned,
reported honestly rather than padded. This is a sample, not an audit; absence of a finding elsewhere is not
evidence of cleanliness.

### Findings

**Not actually settled.** `packages/Ecotone/Api/EventSourcing/DecisionModelConcurrencyException.php` stores the
concurrency value as `private int $expectedVersion` (line 32), exposes it via `expectedVersion()` (line 103), and
keys it as `CONFLICT_EXPECTED_VERSION_FIELD` (line 21) — but both its factories, `forConflict(...)` (line 44) and
`forAggregateConflict(...)` (line 65), take it as a parameter named **`$capturedVersion`**, renaming it back to
`$expectedVersion` only inside a private `describing()` method (lines 124, 128). This file was last touched
2026-09-29. The same `$capturedVersion` name for the identical value also appears in
`packages/PdoEventSourcing/src/Dbal/Tag/DbalTagVersionRegister.php:103-125`, called from
`DbalTagConditionalAppender.php:68` with a value the producing method calls `expectedVersion` — i.e. the value is
`expectedVersion` at its producer and `capturedVersion` one hop later at its consumer, in code current at HEAD,
not a regression reintroduced after `a8f23212c` (that parameter name predates and survives the "settling" commit).

**From the blind sample**: a different flavour of the same failure — a unit qualifier silently dropped, not a
different word for the whole concept. `?int $confirmationTimeoutInMilliseconds` (validated as "a positive amount
of milliseconds") arrives at `SqsBackedMessageChannelBuilder.php:42` / `SqsMessagePublisherConfiguration.php:83,89`,
is stored as `private ?int $confirmationTimeout` (already dropping the unit from the property name), repeats
through `SqsOutboundChannelAdapterBuilder.php:41,47,93`, and lands at `SqsOutboundChannelAdapter.php:40` with no
unit in the name at all — divided by 1000 at line 184 to recover seconds, confirming it is still milliseconds. A
reader at the adapter has to trace back two classes to learn the unit.

Everything else in the (partial) sample — `Dbal/src/DbalConnection.php`, `DbalInboundChannelAdapter.php`,
`DbalHeader.php`, `ManagerRegistryEmulator.php`, `MultiTenantConnectionFactoryModule.php`,
`Amqp/src/AmqpReconnectableConnectionFactory.php`, `Kafka/src/Configuration/KafkaConsumerConfiguration.php` — was
clean and consistent. One adjacent, non-6a smell noted in passing: `packages/Dbal/src/DbaBusinessMethod/` (a
directory and one class, `DbaBusinessMethodModule`) is misspelled "Dba" against the correctly-spelled
`DbalBusinessMethodHandler.php` in the same directory — a spelling drift rather than a deliberate second name.

### Recommendation

Fix both found cases now — each is a 1-2 line rename in exactly the code path the rule's own example is about.
Do not treat the sample (11 files touched, 8 read in full) as having cleared the rest of the codebase; a real
audit of this rule would need to cover far more of the ~15 packages.

---

## Rule 7 — Enterprise features live in separate files

### Detection method

`bin/check-licence.php` was read first to confirm it is read-only (only `file_get_contents`/`preg_match`/`throw`,
never writes) but could not be executed natively in this worktree (no `php` on host, no root `vendor/`, no running
docker containers). Its exact regex was reproduced with grep instead:

```bash
find packages -maxdepth 2 -name vendor -type d                       # confirm no stray build-artifact dirs (none)
grep -rLzE '\*[[:space:]]*(@licence|licence)[[:space:]]+(Enterprise|MIT|Apache-2\.0|BSD-3-Clause)' \
    --include='*.php' packages/*/src      # and packages/*/Api, packages/*/tests
grep -rn 'hasEnterpriseLicence\|hasLicence\|LicenceDecider' --include='*.php' packages/*/src packages/*/Api
```
Confidence in the approximation is high because it reproduces the rule's own stated "1009 Apache-2.0 / 227
Enterprise" baseline exactly (see below) — strong evidence the regex is equivalent — but it is not the CI gate
itself. `bin/add-apache-licence.php`/`bin/add-enterprise-licence.php` were read, not run.

### Licence header coverage — per package

| Package | src Apache-2.0 | src Enterprise | src missing (gate violation) | Api total / missing | tests total / missing (convention only) |
|---|---|---|---|---|---|
| Amqp | 22 | 8 | 0 | 4 / 0 | 84 / 3 |
| DataProtection | 14 | 20 | 0 | 2 / 0 | 33 / 33 |
| Dbal | 53 | 4 | 0 | 19 / 0 | 180 / 25 |
| Ecotone | 663 | 117 | 0 | 126 / 0 | 975 / 53 |
| Enqueue | 14 | 0 | 0 | 0 / 0 | 1 / 0 |
| JmsConverter | 7 | 0 | 0 | 1 / 0 | 62 / 6 |
| Kafka | 0 | 15 | 0 | 5 / 0 | 38 / 4 |
| Laravel | 18 | 0 | 0 | 2 / 0 | 130 / 66 |
| OpenTelemetry | 15 | 0 | 0 | 0 / 0 | 29 / 0 |
| PdoEventSourcing | 31 | 24 | 0 | 2 / 0 | 286 / 59 |
| Redis | 7 | 0 | 0 | 3 / 0 | 10 / 0 |
| Sqs | 10 | 2 | 0 | 3 / 0 | 13 / 0 |
| Symfony | 0 | 0 | 0 | 2 / 0 | 95 / 21 |
| Tempest | 22 | 0 | 0 | 1 / 0 | 75 / 2 |
| **Total** | **876** | **190** | **0** | **170 / 0** | **2011 / 272** |

`src` + `Api` combined: Apache-2.0 = 1009, Enterprise = 227 — **matches the rule's stated baseline exactly**, i.e.
the rule's original count was taken over `src`+`Api` together, and that combined total has not drifted at all
despite the intervening `8828abc1e` Api-move and the DCB Enterprise-extraction history the rule narrates.
`packages/*/tests` (2011 files, explicitly **not** walked by `bin/check-licence.php`) has real, unenforced drift:
272 files (13.5%) carry no licence header at all, concentrated in Laravel (66), PdoEventSourcing (59), Ecotone
(53), DataProtection (33), Dbal (25), Symfony (21); OpenTelemetry, Redis and Sqs are clean.

### Mixed-branching violations (licence checked inside an arbitrary class, not the `LicenceDecider` chokepoint)

Of 19 hits of `hasEnterpriseLicence`/`hasLicence`/`LicenceDecider` in `src`+`Api`, 13 are legitimate container
wiring (the `LicenceDecider` class itself, or `LicenceDecider::prepareDefinition(...)` calls at compile time). **2
files are genuine violations** of the rule's own opening anti-pattern:

- `packages/Ecotone/src/Messaging/Handler/Processor/MethodInvoker/Converter/FetchAggregateConverter.php:37`
  (`licence Enterprise`): `getArgumentFrom()` runtime-checks `$this->licenceDecider->hasEnterpriseLicence()` and
  throws if absent, rather than putting the "not licensed" exception in a separate `licence Apache-2.0` open-core
  class chosen by `LicenceDecider` at the container level — the exact pattern the rule's own canonical example
  (`AggregateMethodInvoker`/`OpenCoreAggregateMethodInvoker`/`EnterpriseAggregateMethodInvoker`) demonstrates
  correctly one directory over.
- `packages/Ecotone/src/Projecting/EcotoneProjectorExecutor.php:42` and `:129` (`licence Apache-2.0`): both
  `project()` and `withProjectionName()` conditionally add an Enterprise-only metadata header via a runtime
  `if ($this->licenceDecider->hasEnterpriseLicence())` branch, duplicated across two call sites in the same file.

No other hits outside these two files; every other `LicenceDecider` reference is compile-time wiring.

### Not mechanically checkable

"Open-core behaviour stays byte-for-byte unchanged" when an Enterprise feature is layered on is a behavioural
invariant, not a lexical property of any file — verifying it exhaustively would mean a full before/after
regression sweep across every feature that has ever branched on licence. Out of scope for a static audit; noted
because the rule calls it out explicitly.

### Fix cost

Missing test headers (272 files): mechanical, high-volume, but `bin/add-apache-licence.php`/
`bin/add-enterprise-licence.php` are hard-coded to `packages/*/src` only (`$finder->files()->in(...".../packages/*/src")`)
and would need repointing at `tests/` before they'd touch any of the 272. Since no test file in this codebase is
Enterprise-only by convention, all 272 would take `licence Apache-2.0 @internal`. Mixed-branching (2 files): needs
a design decision — for `EcotoneProjectorExecutor` in particular, the behavioural difference is a single metadata
header, so the maintainer may reasonably judge the full interface-split ceremony isn't worth it, in tension with
the rule's literal "never inside one class" wording.

### Recommendation

The CI gate (`src`) and the `Api` convention are both fully clean today; the rule's 1009/227 baseline still holds
exactly. The 272-file test-header gap is real but explicitly unenforced — either extend `bin/check-licence.php` to
walk `tests/` too, or run a `tests`-targeted variant of `bin/add-apache-licence.php` once as a backfill. The two
mixed-branching hits need a maintainer call: split into open-core/enterprise pairs per the rule's own pattern, or
explicitly scope the rule's wording to exclude single-field/metadata-only branches.

---

## Rule 10 — Tests validate at the userland level only

**Scope**: `packages/*/tests` and `Monorepo/CrossModuleTests/Tests` (the only `Monorepo/*/tests`-shaped directory
that exists). `quickstart-examples/*/tests` were left out of scope — they are example apps, not the packages being
audited.

### Detection method

```bash
grep -rn "CREATE TABLE" --include='*.php' packages/*/src packages/*/Api       # find real internal table names
grep -rn "ecotone_tagged_events\|ecotone_tag_versions\|ecotone_projection_state\|ecotone_deduplication\|\
ecotone_consumer_positions\|ecotone_error_messages\|ecotone_document_store\|ecotone_event_stream" \
    --include='*.php' packages/*/tests Monorepo/CrossModuleTests/Tests
grep -rn "RAW_REFERENCE" --include='*.php' packages/*/tests                    # 0 hits
grep -rhn "getServiceFromContainer(" --include='*.php' packages/*/tests | sed 's/^[^:]*:[0-9]*://' | sort -u
grep -rn 'new ReflectionClass\|new ReflectionMethod\|new ReflectionProperty\|ReflectionClass::\|\
->getProperty(\|::getMethod(' --include='*.php' packages/*/tests Monorepo/CrossModuleTests/Tests
grep -rln "QueryCountingDbalConnection\|assertQueryCount\|statementCount\|getExecutedQueries\|\
CountingConnection\|QueryCounter" --include='*.php' packages       # 0 hits, whole packages/
find packages/*/tests -iname "*Test.php" -printf '%f\n' | sort -u | grep -iE \
    "Register|Backfiller|Guard|Resolver|ConditionalAppender|Reader|Tracker|Manager|Repository|Writer|Injector|Appender|Indexer|Backfill"
find packages -maxdepth 2 -name vendor -type d                                  # confirm clean, none found
```
Every hit for each category was read in context and classified — e.g. distinguishing a stream name passed as a
public `EventStore::appendTo()` argument (not a violation) from an actual `SELECT`/`INSERT` against an internal
table (a violation), and a white-box unit test of Ecotone's own reflection-producing classes (`TypeResolverTest`,
`ClassDefinitionTest` — accepted, a framework needs tests of its own internals) from reflection used to bypass a
userland feature's public surface (a violation).

### Internal table names identified

| Constant | Value | Defined at |
|---|---|---|
| `StreamTableRegistry::DEFAULT_STREAM` | `ecotone_event_stream` | `packages/PdoEventSourcing/src/StreamTableRegistry.php:17` |
| `TagTableManager::TAGGED_EVENTS_TABLE` | `ecotone_tagged_events` | `packages/PdoEventSourcing/src/Database/TagTableManager.php:23` |
| `TagTableManager::TAG_VERSIONS_TABLE` | `ecotone_tag_versions` | `packages/PdoEventSourcing/src/Database/TagTableManager.php:24` |
| `ProjectionStateTableManager::DEFAULT_TABLE_NAME` | `ecotone_projection_state` | `packages/PdoEventSourcing/src/Database/ProjectionStateTableManager.php:26` |
| `DeduplicationInterceptor::DEFAULT_DEDUPLICATION_TABLE` | `ecotone_deduplication` | `packages/Dbal/src/Deduplication/DeduplicationInterceptor.php:37` |
| `DbalConsumerPositionTracker::COLLECTION_NAME` | `ecotone_consumer_positions` | `packages/Dbal/src/Consumer/DbalConsumerPositionTracker.php:16` |
| `DbalDeadLetterHandler::DEFAULT_DEAD_LETTER_TABLE` | `ecotone_error_messages` | `packages/Dbal/src/Recoverability/DbalDeadLetterHandler.php:38` |
| `DbalDocumentStore::ECOTONE_DOCUMENT_STORE` | `ecotone_document_store` | `packages/Dbal/src/DocumentStore/DbalDocumentStore.php:28` |
| `EventStoreReference::EVENT_STORE_INSTANCE` | `ecotone.eventSourcing.eventStore.instance` (container reference, not a table) | `packages/PdoEventSourcing/src/Config/EventStoreReference.php:12` |

### Violations — per package, per violation type

| Package | Raw SQL on internal tables | Internal container ref instead of gateway | Reflection | Statement counting | Internal-collaborator-named test |
|---|---|---|---|---|---|
| Dbal | 1 clear | 0 clear (setup-only uses judged acceptable) | 0 | 0 | 1 (`DbalConsumerPositionTrackerTest`) |
| Ecotone | 0 clear | **25 / 17 files** | 0 | 0 | 0 |
| PdoEventSourcing | 1 clear + 3 borderline | 1 clear | 0 | 0 | 0 |
| Tempest | 0 | 0 | 2 clear | 0 | 0 |
| All other packages | 0 | 0 | 0 | 0 | 0 |
| **Total** | **2 clear + 4 borderline** | **26 clear** | **2 clear** | **0** | **1** |

Statement counting is completely eradicated — `04e831b55`'s deletion of `QueryCountingDbalConnection` was total.
The internal-container-reference category is the one finding worth acting on for its size and concentration: 25 of
26 occurrences sit in one area (`packages/Ecotone/tests`, the in-memory `EventStore`/`DocumentStore` counterpart of
the DCB/tagging tests) that the historical `implement-blackbox-tests` worktree evidently never reached, while the
parallel DBAL-backed tests for the same features, in `packages/PdoEventSourcing/tests` (the package that worktree
did target), consistently use `getGateway(EventStore::class)` correctly.

### Worst handful of concrete examples

1. **The systematic one** — `packages/Ecotone/tests/Modelling/AggregateBoundary/FetchedAggregateReadOnlyTest.php:49`:
   ```php
   $this->assertSame(0, count($ecotone->getServiceFromContainer(EventStore::class)->loadByCriteria(...)->events));
   ```
   vs. the sanctioned form proven to work in the identical in-memory bootstrap by a sibling file,
   `packages/Ecotone/tests/EventSourcing/EventStore/AppendStrategy/AppendStrategyTest.php:32`:
   `$eventStore = $this->bootstrapEcotone()->getGateway(EventStore::class);`. 17 files, 25 occurrences total, all
   under `packages/Ecotone/tests/{EventSourcing/Tagging,Modelling/AggregateBoundary,Modelling/DecisionModel}`.
2. `packages/PdoEventSourcing/tests/Integration/EventStreamAggregateQueryTest.php:80` —
   `getServiceFromContainer(EventStoreReference::EVENT_STORE_INSTANCE)`, a raw container-reference string,
   structurally identical to the rule's own `RAW_REFERENCE` "wrong" example, inside the package the fix worktree
   otherwise cleaned up.
3. `packages/Dbal/tests/Integration/MultiTenant/DeduplicationCleanupMultiTenantTest.php:73-78` — a private
   `countDeduplicationRows()` helper runs `SELECT COUNT(*) FROM ecotone_deduplication` and the test asserts on the
   count directly — exactly rule 10's canonical wrong example, against a different table.
4. `packages/PdoEventSourcing/tests/Integration/Tagging/AggregateBackedDecisionModelSnapshotDbalTest.php:100-107,171-176` —
   asserts on internal snapshot envelope field names (`covered_position`, `state`) read via raw SQL against
   `ecotone_document_store`; this test's entire premise is pinning down internal representation, the most direct
   violation of the rule's spirit found.
5. `packages/Tempest/tests/Hardening/ConsoleProxyArgumentMappingTest.php:91-96` — reflects into a generated
   internal console proxy, bypasses its constructor, and sets three private properties directly via
   `ReflectionProperty`.

Four further raw-SQL `INSERT`s (`TagBackfillConsoleCommandTest.php`, `MultiTenantTagBackfillConsoleCommandTest.php`,
`TagTransactionRequirementTest.php`) write directly into `ecotone_event_stream` to simulate pre-feature legacy
events for a backfill command — flagged as borderline/needs-a-maintainer-call rather than counted as clear
violations, since there is no public API to backdate an event that way and the command under test exists
specifically to migrate data that can no longer occur through the live path.

### Fix cost

The 26 `getServiceFromContainer(...)` → `getGateway(...)` swaps: mechanical, zero risk, already proven correct by
sibling tests in the same files. The 2 raw-SQL-assertion tests and 2 reflection tests: needs judgement — one raw-SQL
test's stated purpose may have no userland-only equivalent and could warrant deletion (similar to `04e831b55`'s
precedent) rather than rewriting; the Tempest reflection cases likely need a small public-API addition (a
constructor/factory seam, a connection accessor) rather than only test changes.

### Recommendation

Fix the 26 container-reference swaps first — free, mechanical, closes by far the largest gap. Take a maintainer
decision on the 4 borderline backfill-simulation inserts (likely: an accepted, documented exception). The remaining
4 clear violations (2 raw-SQL, 2 reflection) are few enough to fix in one judged pass each; the total (30 clear
violations across ~2,000 test files) does not warrant a dedicated worktree.

---

## Rule 10a — A guarantee is proved on every path

Not mechanically greppable — requires reading whether a behavioural guarantee is exercised through more than one
of Ecotone's dispatch paths. This section is a **qualitative sample of 2 features** (3 files read in full, plus one
directory-wide grep), not a full audit.

**`#[WithoutDatabaseTransaction]` honoured — well covered.**
`packages/Dbal/tests/Integration/Transaction/TransactionTest.php` exercises this guarantee via routed send
(`sendCommandWithRouting`, lines 190/202), a real console-command dispatch (same lines), and class-routed
`CommandBus::send()` (`test_it_can_disable_transactions_on_class_routed_command_handler`, lines 213-224) — the
precise path `9ae4e3fd3` had to add after `57c076084` missed it, now present as a named test in its own right.

**Tag-version optimistic-lock save-guard — a plausible gap.** Both files exercising this guarantee
(`AggregateSaveTagGuardDbalTest.php`, `AggregateBackedDecisionModelConcurrencyTest.php`) drive every scenario
exclusively through class-routed `$ecotone->sendCommand(...)`. Neither this area's other tests use
`sendCommandWithRouting()` for this specific guard, and there is no console-command path exercising the live-save
guard (the backfill/reconstruction side is separately covered, but that is a different path of the concept). This
matters because the identical class-routed-vs-routed-send split already caused a real, shipped bug
(`57c076084`→`9ae4e3fd3`) for a different guarantee — the same structural risk is currently untested here.

**Recommendation**: add one `sendCommandWithRouting()`-based test alongside the existing class-routed ones before
extending the tag-scoped concurrency guard further. This is a 2-feature sample; a fuller pass would need to repeat
the same read-every-entry-point exercise across the ~30-40 other DCB/tagging test files and any non-DCB feature
reachable through more than one bus path.

---

## Rule 11 — Test shape: inline anonymous fixtures

### The "92 of 141" baseline does not reconstruct

`git tag --list` shows the 1.x line ending at `1.327.0` and 2.0 development starting at `2.0.0-beta.1`
(`c440b6d4c`, the merge-base of `1.327.0` and `HEAD`) — a real, identifiable fork point. But
`git log --diff-filter=A -- 'packages/*/tests/**/*Test.php' 2.0.0-beta.1..HEAD` returns **586** file-adds, already
far more than 141, of which only 472 (91% of the current 518-file suite) still exist today — the rest were
deleted or moved in a way git didn't track as a rename across the `Api/` restructuring. There is no tag, branch
point, or commit in the repository's history that yields a 141-file cohort. **Conclusion: the rule's 141 was very
likely computed earlier during 2.0 development, against a smaller test suite than exists today, not against a
fixed boundary still recoverable from history.** This report uses the current, reproducible whole-suite figure
instead.

### Detection method

```bash
find packages -path '*/tests/*Test.php' -type f | sort            # 518 files, current population
git tag --list | sort -V | tail -5                                 # 1.327.0 (1.x) vs 2.0.0-beta.1 (2.0 start)
git merge-base 1.327.0 HEAD                                        # == 2.0.0-beta.1's commit
git log --oneline --diff-filter=A -- 'packages/*/tests/**/*Test.php' 2.0.0-beta.1..HEAD | wc -l   # 586, unreliable
find packages -maxdepth 2 -name vendor -type d                     # confirm clean, none found
```
"Named fixture below the `TestCase`" was detected with a script matching top-level declarations at column 0
(`^(final |abstract )?(class|interface|trait|enum) \w+`), which correctly excludes anonymous classes (always
indented, never a bare `class Name` line). This yielded 132 candidate files — small enough to classify
exhaustively rather than sample. Each extra declaration was checked for: a `#[Attribute(...ClassName::class...)]`
occurrence anywhere in the file (exception 2); a parameter/return type on a message-handling method
(`#[CommandHandler]`, `#[EventHandler]`, `#[QueryHandler]`, `#[EventSourcingHandler]`, `#[InternalHandler]`,
converter/interceptor attributes) or a `#[Fetch(...)]`-marked parameter (exception 1); or an `interface`/`enum`/
native `#[Attribute]`-implementing kind (a third, PHP-level hard constraint the rule's text doesn't name — see
below). All files in `Amqp`, `Kafka`, `Laravel`, `Tempest`, `Dbal`, and ~40 of the 89 `Ecotone` files (every
`Modelling/DecisionModel` and `Modelling/AggregateBoundary` file, the highest false-positive-risk area) were read
in full to validate the heuristic before trusting it on the rest.

### The refreshed number, per package

| Package | Total test files | Files with a named fixture | …of which genuine violations | …of which fully exempt |
|---|---|---|---|---|
| Ecotone | 231 | 89 | 80 | 9 |
| Dbal | 51 | 1 | 1 | 0 |
| PdoEventSourcing | 98 | 33 | 33 | 0 |
| Amqp | 25 | 5 | 5 | 0 |
| Sqs | 8 | 0 | 0 | 0 |
| Redis | 5 | 0 | 0 | 0 |
| Kafka | 12 | 2 | 2 | 0 |
| Enqueue | 1 | 0 | 0 | 0 |
| Laravel | 14 | 1 | 1 | 0 |
| Symfony | 19 | 0 | 0 | 0 |
| Tempest | 31 | 1 | 1 | 0 |
| JmsConverter | 5 | 0 | 0 | 0 |
| OpenTelemetry | 4 | 0 | 0 | 0 |
| DataProtection | 14 | 0 | 0 | 0 |
| **Total** | **518** | **132** | **123** | **9** |

At the class level: 530 extra named declarations across the 132 files, of which ~366 (69%) are genuine violations
and ~164 (31%) are exempt. `Dbal`, `Sqs`, `Redis`, `Enqueue`, `Symfony`, `JmsConverter`, `OpenTelemetry` and
`DataProtection` are effectively clean (0-1 affected file each) — the drift is concentrated almost entirely in
`Ecotone` (especially `Modelling/DecisionModel` and `Modelling/AggregateBoundary`, up to 15 named classes in a
single file) and `PdoEventSourcing/Integration/Tagging`.

### Worst handful of concrete examples of genuine violations

1. `packages/Ecotone/tests/Messaging/Unit/Channel/ChannelInterceptorAttributeTest.php:155-332` — **eleven** named
   classes (`CapturingHandler`, `AsyncCapturingHandler`, `UppercasingInterceptor`, etc.), each referenced only via
   `[X::class]` and a matching instance — exactly the pattern the rule's own `BasketTest` example already proves
   works anonymously.
2. `packages/Ecotone/tests/Modelling/DecisionModel/DecisionBoundaryTest.php:295-655` — **fifteen** named classes
   (`RatingHandlerForBoundaryTest`, `NonStaticBoundaryHandlerForBoundaryTest`, etc.), none referenced as a type
   anywhere else and none inside another fixture's attribute argument — the single largest violation cluster found.
3. `packages/Amqp/tests/FinalFailureStrategyTest.php:105` and
   `packages/Ecotone/tests/Messaging/Unit/Endpoint/FinalFailureStrategyTest.php:167-224` — `FailingService`,
   `SuccessService`, `RejectingService`, `ManualAckService`: plain one-method service classes with nothing forcing
   the name.
4. `packages/PdoEventSourcing/tests/Integration/Tagging/AggregateBackedDecisionModelDbalTest.php:483` —
   `CompetingWalletSaveForAggregateBackedDbalTest`, used only via `getServiceFromContainer(X::class)` — could be
   anonymous and held in a local variable.

### Examples of correctly-exempt named fixtures

- **Exception 2** — `ScheduledModuleTestMarker` in `ScheduledModuleTest.php:21`, referenced inside
  `#[Before(pointcut: ScheduledModuleTestMarker::class)]` at line 48 (attribute arguments must be constant
  expressions).
- **Exception 1, the rule's own cited case** — `OrderCreated` in `AggregateNotFoundCausationTest.php:60-66`, a
  named event used as an `#[EventHandler]` parameter type; the aggregates around it stay anonymous.
- **A third, undocumented hard constraint: interfaces** — `interface DelayedRetryCommandBus extends CommandBus` in
  `ErrorChannelCommandBusTest.php:222`. PHP has no anonymous-interface syntax at all; this applies equally to the
  ~17 other gateway/business-interface fixtures found, one native `#[Attribute]`-implementing class, and one
  `enum` — none of which the rule's text currently names as exempt.

### Secondary checks

`snake_case` test methods: **0 violations** across all 518 files — php-cs-fixer is doing its job. Non-`final` test
classes: **106 of 518 (20%)**, concentrated in `Ecotone` (67) and `DataProtection` (14) — a separate piece of
rule-11 drift, not sized further here. Assertion messages on real PHPUnit assert calls: **~121 occurrences**
(a conservative, single-line-regex count, not exhaustive).

### Fix cost

Mechanical for the majority: a plain service/handler class referenced only via `[X::class]` and `new X()` converts
directly to the anonymous-class-plus-`$x::class` pattern already used correctly in `BasketTest`/`MessageBusTest`/
`AggregateNotFoundCausationTest` — roughly 300+ of the ~366 violating classes fall in this bucket. Needs judgement
where a class is referenced from multiple call sites in one file (each site may need its own anonymous instance or
a factory helper), where two named fixtures form an inheritance pair (only one side can drop its name), or where a
class is passed to `EventCriteria::aggregate()`/`ofTypes()` (a real exemption-lookalike the rule text already says
is *not* actually forcing — these should convert too). Interfaces, enums, and native `#[Attribute]` classes are
not fixable at all and must be excluded from any sweep up front.

### Recommendation

1. Replace the rule's "92 of the 141 test files added since 1.x" sentence with a reproducible measure — "132 of
   the current 518 test files" — or drop the "since 1.x" framing entirely, since that boundary no longer
   corresponds to anything git can recover.
2. Add interfaces, enums, and native `#[Attribute]`-implementing classes as an explicit third documented exception
   alongside the two existing ones — they are exempt for the identical "PHP has no anonymous syntax for this"
   reason, and any future mechanical audit will otherwise misflag ~25 files that cannot actually be fixed.
3. If drift reduction is wanted, `Ecotone/Modelling/DecisionModel`, `Ecotone/Modelling/AggregateBoundary`, and
   `PdoEventSourcing/Integration/Tagging` are the highest-yield targets — the largest violation clusters, with
   enough repeated naming structure (`...ForXxxTest`) that one conversion pattern would likely apply to most files
   in a single pass.

---

## Rule 14 — A console option's name is its PHP parameter name, verbatim

### Detection method

```bash
grep -rn "ConsoleParameterOption" --include='*.php' packages/*/src packages/*/Api
cat packages/Ecotone/Api/Attribute/ConsoleParameterOption.php     # the attribute's own definition
grep -rnoE -- '--[a-zA-Z][a-zA-Z]*-[a-zA-Z-]+' --include='*.php' packages/*/src packages/*/Api packages/*/tests
grep -rnoE -- '--[a-zA-Z][a-zA-Z]*-[a-zA-Z-]+' docs/*.md *.md
grep -rniE -- '(only-used|handled-message-limit|execution-time-limit|memory-limit|stop-on-failure|\
finish-when-no-messages|batch-size|from-no|dry-run|skip-undeserializable|legacy-stream)' \
    --include='*.php' --include='*.md' .
find packages -maxdepth 2 -name vendor -type d                     # confirm clean, none found
```

### The attribute cannot be misused

`packages/Ecotone/Api/Attribute/ConsoleParameterOption.php:7-13` — the attribute has no constructor and no
properties:
```php
#[Attribute(Attribute::TARGET_PARAMETER)]
class ConsoleParameterOption
{
}
```
`ConsoleCommandModule.php:132` confirms the option name always comes from the PHP parameter's own name, never a
value from the attribute. **This makes rule 14 structurally unviolable from inside PHP** — every possible
violation lives in prose that names an option independently of the attribute.

### All registered console options (verified against source)

| Package | Console command | Options (= PHP parameter names) |
|---|---|---|
| Dbal | `ecotone:migration:database:setup` | `feature`, `initialize`, `sql`, `onlyUsed`, `missing`, `connection` |
| Dbal | `ecotone:migration:database:delete` | `feature`, `force`, `onlyUsed`, `connection` |
| Ecotone | async endpoint run command | `handledMessageLimit`, `executionTimeLimit`, `memoryLimit`, `cron`, `stopOnFailure`, `finishWhenNoMessages` |
| Ecotone | channel delete / setup | `channel`, `force` / `channel`, `initialize` |
| Ecotone | projection init | `all` |
| PdoEventSourcing | `ecotone:event-store:backfill-tags` | `stream`, `event`, `batchSize`, `fromNo`, `dryRun`, `skipUndeserializable` |
| PdoEventSourcing | `ecotone:event-store:verify-schema` | `legacyStream` |
| (framework-wide) | every console command | `header` — reserved by `ConsoleCommandConfiguration::HEADER_PARAMETER_NAME`, not user-declarable |

### Violations found

All 9 occurrences (5 distinct option names) are in **`upgrade-2.0.md:875-897`**, describing the two
`PdoEventSourcing` commands above:

| file:line | Written as | Real option |
|---|---|---|
| `upgrade-2.0.md:875` | `--batch-size=500`, `--from-no=`, `--dry-run` | `--batchSize=500`, `--fromNo=`, `--dryRun` |
| `upgrade-2.0.md:876` | `--skip-undeserializable` | `--skipUndeserializable` |
| `upgrade-2.0.md:881` | `--from-no`, `--batch-size` (x1 each) | `--fromNo`, `--batchSize` |
| `upgrade-2.0.md:885` | `--dry-run` | `--dryRun` |
| `upgrade-2.0.md:886` | `--skip-undeserializable` | `--skipUndeserializable` |
| `upgrade-2.0.md:892`, `:896` | `--legacy-stream=` (x2) | `--legacyStream=` |

Every mention of these two commands' options in the upgrade guide uses the kebab-case form — a user following this
migration guide verbatim would get "option does not exist" from Symfony/Laravel/Tempest's console layer. This is
the exact failure mode the rule was written from (`5fa0024b2`), recurring in the document most likely to be
followed literally by an upgrading application.

Two things checked and confirmed **not** violations: `upgrade-2.0.md`'s `--header "tenant:a"` example (`header` is
the framework-reserved, already-camelCase-compatible option, correct as written); and
`docs/coding-conventions.md:822`'s own `--skip-undeserializable`, which is the rule's deliberate worked example of
the wrong form, not an accidental violation. A further dozen kebab-case `--flag` hits across `packages/*/tests`
and root docs (`--start-from`, `--gap-size`, `--no-warmup`, `--prefer-lowest`, `--no-coverage`, etc.) were checked
and excluded as flags belonging to a standalone Symfony `Application` test fixture, Symfony's own `cache:clear`,
or PHPUnit/Composer — none are `#[ConsoleParameterOption]`-based Ecotone options.

### Fix cost

Mechanical: 5 find-and-replace pairs across 7 lines in one file (`upgrade-2.0.md`), no code change, no breaking
change — this only corrects prose describing existing, unchanged behaviour.

### Recommendation

Enforce and fix now. The mechanism is already unviolable in code; the only work is correcting `upgrade-2.0.md`,
and the rule's own history (`5fa0024b2`) treats exactly this class of mistake as a bug worth a dedicated fix, not
accepted debt.
