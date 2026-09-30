# Conventions audit — group c: tests, docs and conventions of expression

Measured against `docs/coding-conventions.md` at HEAD `b255fd5e9d9f821da0c55427f3ead6633e6f787c`
(branch `dgafka/ecotone-2-0-conventions-audit-c`, base `dgafka/ecotone-2-0-work`). Inventory only — no
production code, tests, or documentation other than this file were changed to produce it.

Scope: `packages/*/src` and `packages/*/Api` for production rules (6, 7, 14); `packages/*/tests`,
`Monorepo/`, `quickstart-examples/` for test rules (10, 11). `find packages -maxdepth 2 -name vendor -type d`
returned nothing at audit time, so no per-package build-artifact `vendor/` directories needed excluding from
any count below. `bin/check-licence.php` could not be run natively (no `php` binary, no root `vendor/`
installed, no running containers in this worktree); rule 7's coverage numbers are a grep-based approximation
of its regex, cross-checked against the rule doc's own stated baseline (see rule 7).

Five rules were audited in parallel by independent sub-agents, each given the rule text, the scope, and the
"violation / exempt / rule-is-wrong" framing below. Numbers in this report are exactly what those audits
measured; nothing has been rounded up or padded to look more decisive than the underlying method supports.

## Summary — what to do

| Rule | Violations (headline) | Exempt | Fix cost | Recommendation |
|---|---|---|---|---|
| **6** — names carry meaning, no comments/prose docblocks | 271 genuine inline comments + 789 genuine prose docblocks (Ecotone-authored, non-legacy, non-ported); only **15** of those (13 docblocks + 2 comments) were added in the last 10 days | ~180 `//`/docblock hits sit in vendored third-party ports (Enqueue transports, cron-expression, illuminate/database, DataProtection's `Encryption/`); 613 of 922 docblock hits pre-date 2025 and are grandfathered by the rule's own text | Legacy: leave alone (mechanical delete only if touching the file anyway). The 15 fresh hits: needs judgement — several carry real design rationale that needs a home (name / exception message / spec doc). `FinalFailureStrategy.php`: needs a design decision (content has nowhere else to live yet) | **Enforce for new code only.** Fix the 15 fresh hits and the `FinalFailureStrategy.php` `Api/`-move violation now; do not sweep the 613 legacy or ~180 ported hits |
| **6a** — one word per concept | 2 concrete, current drifts found in a partial sample: `expectedVersion`/`capturedVersion` (the exact pair the rule claims is "settled" — it isn't, as of 2026-09-29) and `confirmationTimeout`/`confirmationTimeoutInMilliseconds` (a unit qualifier silently dropped) | Sample too small (11 files touched, 8 read in full, of a planned 15–20) to certify the rest of the codebase clean | Mechanical — both are 1–2 line renames | **Fix the 2 found now**; treat as a weak signal, not a verdict — a real sample would need to cover far more of the ~15 packages |
| **7** — Enterprise features live in separate files | **2** confirmed mixed-licence-branch violations (`FetchAggregateConverter.php`, `EcotoneProjectorExecutor.php` — an `if hasEnterpriseLicence()` inside an ordinary class instead of two classes behind one interface); **272 of 2011** test files (13.5%) are missing any licence header (convention only — not CI-gated) | `packages/*/src` (1134 files) and `packages/*/Api` (170 files): **0 missing headers** — fully clean, and Apache-2.0/Enterprise totals (1009 / 227) reproduce the rule doc's stated baseline **exactly** | Mixed-branching: needs a design decision (is a single-header-key difference worth an interface split?). Header gap: mechanical bulk-backfill, but the existing `bin/add-*-licence.php` scripts are hard-coded to `src/` only and would need repointing | **Decide** the 2 branch violations (split or explicitly scope the rule to exclude trivial branches); **bulk-fix** the 272 test headers or extend `check-licence.php` to gate `tests/` too |
| **10** — tests validate at userland level only | **30 clear violations**: 2 raw-SQL assertions on Ecotone's own tables, **26** internal-container-reference uses instead of a gateway (25 of them one systematic pattern in `packages/Ecotone/tests`), 2 reflection violations (both in `Tempest/tests/Hardening`) | Statement-counting: **0** anywhere (the rule's own claimed removal holds exactly); most raw-SQL/reflection grep hits are legitimate (gateway stream-name arguments, or unit tests of the reflection-producing layer itself) | The 26 container-ref swaps: **mechanical**, already proven by sibling tests in the same files. The 2 raw-SQL and 2 reflection cases: needs judgement (one may need a small API addition, one may need outright deletion) | **Fix the 26 swaps now** — free, zero-risk, closes the largest gap. Judge the remaining 4 case-by-case; no dedicated worktree needed |
| **10a** — a guarantee is proved on every path | Illustrative 2-feature sample only (not a count): `#[WithoutDatabaseTransaction]` is well covered across every entry point; the tag-version concurrency save-guard is tested only via class-routed `sendCommand()`, never `sendCommandWithRouting()` — the exact split that already caused one shipped bug for a different guarantee | n/a — not mechanically auditable at scale | One test file addition, small | Add one `sendCommandWithRouting()` test to `AggregateSaveTagGuardDbalTest.php`; do not treat this sample as a full audit |
| **11** — test shape (inline fixtures) | **100 of 141** (70.9%) test files added since 1.x now declare a named fixture — **up from the rule's own 92/141 (65.2%) baseline**, concentrated almost entirely in the DCB/DecisionModel suites the rule was mined from. Of 713 extra fixture classes in those 100 files, **338 (47.4%)** are likely genuine violations. Plus: 183 assertion messages, 10 stateful static properties, 705 disallowed docblocks (mostly legacy) | **375 of 713 (52.6%)** fixture classes are exempt (forced type declarations); **19** shared `Fixture/` directories (~1,500 files) are pre-2.0 legacy structure, not new drift | Named-fixture→anonymous: mechanical for single-use classes, needs judgement where a class also serves as a type declaration. Assertion messages: mechanical, zero risk. Shared `Fixture/` dirs: needs a design decision (the rule has no answer for genuinely-reused fixtures) | **Enforce for new code**, scoped to the DCB/DecisionModel suites where the drift is concentrated. **Fix the 183 assertion messages now** (mechanical). **Revise the rule** on shared `Fixture/` directories, stateless static helpers, and legacy docblocks in tests — as written it bans patterns its own cited rationale doesn't object to |
| **14** — console option name = PHP parameter name | **0** in production code — the `#[ConsoleParameterOption]` attribute has no constructor and cannot carry a name override, so the rule is structurally unviolable from PHP. **9 occurrences (5 distinct option names)** written in kebab-case in `upgrade-2.0.md`, describing real `PdoEventSourcing` commands | n/a | **Mechanical** — 5 find-and-replace pairs across 7 lines, pure documentation fix, no behaviour change | **Enforce and fix now** — this is exactly the failure mode (`5fa0024b2`) the rule was written from, recurring in the 2.0 upgrade guide itself |

---

## Rule 6 — Names carry the meaning; no comments, no descriptive docblocks

**Scope**: `packages/*/src` and `packages/*/Api` only — tests have their own comment rule under rule 11.

### Detection method

```bash
find packages -maxdepth 2 -name vendor -type d                         # confirm no stray build dirs (none)
grep -rn '^\s*//' --include='*.php' packages/<Pkg>/src packages/<Pkg>/Api   # per package, per kind
```
Descriptive docblocks were found with an ad hoc Python script walking every `/** ... */` block, skipping
licence blocks, and flagging (a) any non-empty line before the first `@`-tag, and (b) any `@param` line with
text after the `$variable` name. Every flagged hit was dated with `git blame -L <line>,<line> --porcelain`.

Exclusions applied: `@codeCoverageIgnoreStart/End` (tool directives, 2 hits), `licence Apache-2.0`/`Enterprise`
docblocks (rule 7's concern), and 32 files that are explicit ports of third-party libraries (MIT/BSD headers
or an explicit "modified version of" note) — these are counted separately, not folded into "Ecotone's own"
debt. `// TODO` markers (9 hits) were counted but flagged as a different flavour (task markers, not
"what this code does" prose).

### Counts per package (raw baseline)

| Package | `//` (raw) | Docblocks (raw) | of which in ported files | Allowed docblocks (context, not counted) |
|---|---|---|---|---|
| Ecotone (src) | 219 | 599 | 63 (cron-expression library) | ~300+ |
| Ecotone (Api) | 0 | 55 | 0 | small |
| Dbal (src) | 18 | 62 | 26 (enqueue-dbal transport port) | few |
| Dbal (Api) | 2 | 16 | 0 | few |
| DataProtection (src) | 105 | 54 | 0* | — |
| Amqp (src) | 36 | 32 | 2 (enqueue-amqp port) | few |
| Amqp (Api) | 0 | 6 | 0 | — |
| Laravel (src) | 6 | 42 | 39 (illuminate/database PDO port) | — |
| Kafka (src / Api) | 5 / 8 | 14 / 8 | 0 | several legitimate `@link` to confluent/librdkafka |
| Sqs (src / Api) | 7 / 0 | 3 / 3 | 2 (enqueue-sqs port) | — |
| Redis (src / Api) | 1 / 0 | 1 / 3 | 1 (enqueue-redis port) | — |
| PdoEventSourcing (src) | 9 | 7 | 0 | — |
| Tempest (src / Api) | 16 / 0 | 2 / 2 | 0 | — |
| Symfony (Api) | 0 | 2 | 0 | — |
| JmsConverter | 0 | 0 / 1 | 0 | — |
| OpenTelemetry (src) | 1 | 2 | 0 | — |
| Enqueue (src) | 0 | 8 | 0 | — |

\* DataProtection carries no explicit port marker, but its entire `Encryption/` subtree (105 of its 105 `//`
hits) reads, by class names and comment style, as a verbatim port of a known PHP encryption library.

**Totals**: 434 raw `//` matches (2 tool directives, 47 in explicitly-marked ports, 105 more in the
unmarked-but-clearly-ported DataProtection `Encryption/` subtree, 9 TODO markers) → **~271 genuinely
Ecotone-authored explanatory comments**. 922 raw docblock blocks (133 in explicitly-marked ports) → **~789
genuinely Ecotone's own**.

Docblock age histogram (all 922 raw hits, by `git blame`):

| Year added | Count |
|---|---|
| 2022 | 468 |
| 2023 | 49 |
| 2024 | 96 |
| 2025 | 143 |
| 2026 (through 09-29) | 166 |
| — of which 2026-09-20 through 09-29 | **13** |

613 of 922 (66%) pre-date 2025 and are exactly what the rule's own text excuses ("Legacy prose docblocks
remain in older files... do not add new ones"). Only **13** docblocks and **2** `//` comments were added in
the 10 days ending at HEAD — the same window the rule text cites as evidence of active enforcement
(`5c3a9ed2c`, `9ca15e6ea`, `75e943982`). Notably: the one docblock form the rule explicitly *encourages*
(`@link https://docs.ecotone.tech/...` on an `Api` class) has **zero** occurrences anywhere in the repo —
every existing `@link` points at an external spec or is an internal `{@link ClassName}` cross-reference.

### Worst examples

1. **`packages/Ecotone/Api/ExtensionObject/FinalFailureStrategy.php:10-14`** — moved into `Api/` in the
   commit at HEAD (`8828abc1e`, 2026-09-30), carrying a four-sentence prose docblock written 2025-07-13
   describing every enum case's per-transport redelivery semantics. `Api/` is the rule's strictest zone
   (`@link` only) and the file was touched in the very commit that landed it there.
2. **`packages/Ecotone/src/EventSourcing/Tagging/TagResolver.php:92-95, 109-111`** — added 2026-09-29, the
   same day as the commits the rule cites as evidence of enforcement (`5c3a9ed2c`, `9ca15e6ea`, `75e943982`).
3. **`packages/Ecotone/src/EventSourcing/EventSourcedRepositoryAdapter.php:155-158`** — added 2026-09-29
   (`f8035b25f`), a design-rationale paragraph about snapshot fold-shape compatibility.
4. **`packages/PdoEventSourcing/src/Dbal/DbalEventStore.php:267-268`** — added 2026-09-24, inline `//`
   explaining a PostgreSQL aborted-transaction workaround.
5. **`packages/Ecotone/Api/EventSourcing/DecisionModelConcurrencyException.php:41`** — added 2026-09-29, a
   redundant `@param` description in `Api/` (also the live rule-6a example, below).
6. **`packages/Ecotone/src/Messaging/Precedence.php`** (whole file, 2022) — a genuinely old, grandfathered
   example: every interface constant carries a one-line docblock a rename would make redundant.
7. **`packages/DataProtection/src/Encryption/File.php`** — 41 inline comments; ported third-party crypto code
   (2026-02-11), not Ecotone's own authorial voice.

### Fix cost

- **Mechanical, but should mostly be left alone**: the 613 true-legacy hits (grandfathered by the rule
  itself) and the ~185 ported-library hits (not Ecotone's code to begin with).
- **Needs judgement**: the 13 fresh hits — several carry real design rationale that the rule says should move
  to a name, an exception message, or a spec doc, not just be deleted.
- **Needs a design decision**: `FinalFailureStrategy.php` documents per-transport behaviour that has nowhere
  else to live yet (no per-transport behaviour doc exists under `docs/`) — the content needs a new home, not
  a deletion.

### Recommendation

**Enforce for new code only.** The 613 pre-2025 hits and ~185 ported-library hits are not worth a sweep — the
rule explicitly grandfathers the former, and the latter was never Ecotone's authorial voice. Fix the 13 fresh
hits and the `FinalFailureStrategy` `Api/`-move violation as a short, targeted follow-up — they are new, in
the exact area the rule governs, and one is in the strictest zone of the API surface.

A mechanical sweep for this rule cannot be fully automated: a lint script matching the docblock-shape heuristic
above would need to special-case the ~32 explicitly-ported files and the unmarked DataProtection `Encryption/`
subtree, or it will false-positive on ~180 hits that were never the rule's target.

---

## Rule 6a — One word per concept

### Method

Two passes: (1) **targeted** — re-checked the exact terms the rule's own text names as historically
problematic (`version`, `expectedVersion`, `capturedVersion`, `tagVersion`, `sequence`) to verify the
"settled" claim (`a8f23212c`) still holds; (2) **blind sample** — read 8 files in full and grepped a further
3-file call chain across Dbal, Amqp, Kafka, Sqs (11 files touched of a planned 15–20 — short of target,
stated plainly rather than padded).

### Findings

**Targeted pass (concrete, not a sample)**: in `packages/Ecotone/Api/EventSourcing/DecisionModelConcurrencyException.php`
(last touched 2026-09-29), the same concurrency-check value is stored as `$expectedVersion`, exposed via
`expectedVersion()`, keyed under `CONFLICT_EXPECTED_VERSION_FIELD` — but taken as a constructor/factory
parameter named `$capturedVersion` in two places (lines 44, 65), then renamed back to `$expectedVersion`
internally (line 124, 128). The same `$capturedVersion` name for the same value also appears in
`packages/PdoEventSourcing/src/Dbal/Tag/DbalTagVersionRegister.php:103-125`, called with a value the producing
method calls `expectedVersion()` one hop earlier — this predates and survives the "settling" commit
(`a8f23212c`), i.e. it was never actually fixed everywhere.

**Blind sample**: one concrete example, a different flavour (a unit qualifier dropped, not a synonym) —
`confirmationTimeoutInMilliseconds` (parameter) → `confirmationTimeout` (property, `SqsMessagePublisherConfiguration.php`,
`SqsOutboundChannelAdapterBuilder.php`) → `confirmationTimeout` with no unit in the name at all
(`SqsOutboundChannelAdapter.php:40`), which then divides by 1000 at line 184 — a reader has to trace back two
classes to learn the value is still milliseconds. Everything else read in the sample (7 further files across
Dbal, Amqp, Kafka) showed ordinary, consistent naming.

Also noted in passing, not counted (a spelling typo, not a two-words-one-concept case):
`packages/Dbal/src/DbaBusinessMethod/` is misspelled `Dba` instead of `Dbal` while a sibling file in the same
directory (`DbalBusinessMethodHandler.php`) has the correct spelling.

### Fix cost

Mechanical — both are 1–2 line renames (`capturedVersion` → `expectedVersion` in two files; consistently
append/strip "InMilliseconds" for the SQS timeout).

### Recommendation

**Fix the 2 found now** — both sit in the exact code paths the rule's own worked example is about, so neither
should wait for a general sweep. The sample (11 files touched, 8 read in full) is too small and too narrow
(Dbal/Amqp/Kafka/Sqs only) to say whether this kind of drift is common elsewhere; treat this as a real but
weak signal, not a verdict on the rest of the ~15 packages.

---

## Rule 7 — Enterprise features live in separate files

### Detection method

`bin/check-licence.php` was read (confirmed read-only: `file_get_contents`/`preg_match` only) but could not
be executed — no `php` binary on host, no root `vendor/autoload.php` installed, no running containers in this
worktree. Its regex was approximated with grep and cross-checked against the rule doc's own stated baseline
(1009 Apache-2.0 / 227 Enterprise) — the approximation reproduces that number **exactly**, which is strong
evidence the regex equivalence is correct, though it is not the CI gate itself.

```bash
find packages -maxdepth 2 -name vendor -type d     # confirm no stray build dirs (none)
grep -rLzE '\*[[:space:]]*(@licence|licence)[[:space:]]+(Enterprise|MIT|Apache-2\.0|BSD-3-Clause)' \
    --include='*.php' packages/*/src packages/*/Api packages/*/tests
grep -rn 'hasEnterpriseLicence\|hasLicence\|LicenceDecider' --include='*.php' packages/*/src packages/*/Api
```
Every `hasEnterpriseLicence`/`LicenceDecider` hit was read in context and classified as legitimate
container-wiring (the sanctioned chokepoint) or a runtime branch inside an otherwise-ordinary class (the
anti-pattern the rule names).

### Licence header coverage — per package

| Package | src Apache-2.0 | src Enterprise | src missing (gated) | Api missing (not gated) | tests missing (convention only) |
|---|---|---|---|---|---|
| Amqp | 22 | 8 | 0 | 0 | 3 |
| DataProtection | 14 | 20 | 0 | 0 | 33 |
| Dbal | 53 | 4 | 0 | 0 | 25 |
| Ecotone | 663 | 117 | 0 | 0 | 53 |
| Enqueue | 14 | 0 | 0 | 0 | 0 |
| JmsConverter | 7 | 0 | 0 | 0 | 6 |
| Kafka | 0 | 15 | 0 | 0 | 4 |
| Laravel | 18 | 0 | 0 | 0 | 66 |
| OpenTelemetry | 15 | 0 | 0 | 0 | 0 |
| PdoEventSourcing | 31 | 24 | 0 | 0 | 59 |
| Redis | 7 | 0 | 0 | 0 | 0 |
| Sqs | 10 | 2 | 0 | 0 | 0 |
| Symfony | 0 | 0 | 0 | 0 | 21 |
| Tempest | 22 | 0 | 0 | 0 | 2 |
| **Total** | **876** | **190** | **0** | **0** | **272 / 2011** |

`src` Apache-2.0 (876) + `Api` Apache-2.0 (133) = 1009; `src` Enterprise (190) + `Api` Enterprise (37) = 227 —
**exactly** the rule doc's stated baseline. No drift in the CI-gated or `Api` population despite the
intervening `8828abc1e` Api-move commit. The 272-file test gap is real but explicitly not CI-gated per the
rule's own text ("a convention rather than a gate: `bin/check-licence.php` only walks `packages/*/src`").

### Mixed-branching violations

19 total `hasEnterpriseLicence`/`LicenceDecider` hits in `src`+`Api`; 17 are legitimate container-wiring
(the `LicenceDecider` class itself, or `LicenceDecider::prepareDefinition(...)` calls at compile time). **2
files, 3 call sites are genuine violations**:

- **`packages/Ecotone/src/Messaging/Handler/Processor/MethodInvoker/Converter/FetchAggregateConverter.php:37`**
  (carries `licence Enterprise`):
  ```php
  if (! $this->licenceDecider->hasEnterpriseLicence()) {
      throw LicensingException::create('FetchAggregate attribute is available as part of Ecotone Enterprise.');
  }
  ```
  One class does both jobs and asks at runtime, instead of two classes (open-core throwing, Enterprise
  implementing) chosen once by `LicenceDecider` — the rule's own canonical `AggregateMethodInvoker` pattern.
- **`packages/Ecotone/src/Projecting/EcotoneProjectorExecutor.php:42` and `:129`** (carries `licence Apache-2.0`):
  a single open-core class conditionally adds an Enterprise-only metadata header at runtime via a licence
  check, duplicated across two call sites in the same file.

### Fix cost

- **272 missing test headers**: mechanical, high-volume, but `bin/add-apache-licence.php` and
  `bin/add-enterprise-licence.php` are both hard-coded to `packages/*/src` only — they would need repointing
  (or a one-off copy) before use against `tests/`.
- **2 mixed-branching files**: needs a design decision — splitting `EcotoneProjectorExecutor` behind an
  interface for a single metadata header may reasonably be judged not worth the ceremony, in tension with the
  rule's literal "never inside one class" wording. Not this audit's call.

### Recommendation

CI gate and `Api` convention are both fully clean — no action needed there. For the 272-file test gap: either
extend `bin/check-licence.php` to walk `packages/*/tests` (turning the convention into a gate), or run a
`tests`-targeted backfill once. For the 2 mixed-branching files: a maintainer decision — split them per the
canonical pattern, or explicitly scope the rule's wording to exclude single-field/metadata-only branches, since
as written both currently violate the rule's opening sentence.

Not mechanically checkable at all: "open-core behaviour stays byte-for-byte unchanged" is a behavioural
invariant across every Enterprise feature ever added, not a lexical property of any one file — out of scope
for a grep-based audit.

---

## Rule 10 — Tests validate at the userland level only

### Detection method

```bash
grep -rn "CREATE TABLE" --include='*.php' packages/*/src packages/*/Api          # find internal table names
grep -rln "'ecotone_" --include='*.php' packages/*/src packages/*/Api
grep -rln "ecotone_" --include='*.php' packages/*/tests Monorepo/CrossModuleTests/Tests
grep -rn "RAW_REFERENCE" --include='*.php' packages/*/tests                       # 0 hits
grep -rc "getServiceFromContainer(" --include='*.php' packages/*/tests            # 44 files, each arg classified
grep -rn 'new ReflectionClass|new ReflectionMethod|new ReflectionProperty|->getProperty(|::getMethod(' \
    --include='*.php' packages/*/tests Monorepo/CrossModuleTests/Tests
grep -rln "QueryCountingDbalConnection|assertQueryCount|statementCount|getExecutedQueries" packages    # 0 hits
```
Every hit for internal table names, container references and reflection was read in context and classified as
a genuine violation, a borderline setup/backfill use, or a legitimate gateway/self-test use (e.g. a stream
name passed as a public `EventStore::appendTo()` argument is not raw SQL; a unit test of `TypeResolver` using
`ReflectionClass` to build fixture input is testing the reflection-producing layer itself, not leaking
reflection into a userland test). Internal-collaborator-named test classes were found by grepping all 465
unique `*Test.php` basenames for internal-sounding tokens (`Register`, `Resolver`, `Guard`, etc.) and checking
each surviving hit's body and directory (`Unit/` white-box test of that exact class = accepted; `Integration/`
test driving the internal class directly instead of through its effect = violation) — this is a full census of
test-file names, not a sub-sample.

### Counts per package

| Package | Raw SQL on internal tables | Internal container ref instead of gateway | Reflection | Statement counting |
|---|---|---|---|---|
| Dbal | 1 clear | 0 (setup-only judged acceptable) | 0 | 0 |
| Ecotone | 0 (stream-name-as-gateway-argument only) | **25** (17 files) | 0 (2 grep hits are self-tests) | 0 |
| Kafka | 0 clear, 1 borderline (cleanup DELETE) | 0 | 0 | 0 |
| Laravel / Symfony | 0 clear, borderline table drop in test bootstrap (setup, not assertion) | 0 | 0 | 0 |
| PdoEventSourcing | 1 clear + 3 borderline (backfill simulation) | 1 clear | 0 | 0 |
| Tempest | 0 | 0 | 2 clear | 0 |
| All others | 0 | 0 | 0 | 0 |

**Totals: 2 clear raw-SQL-assertion violations, 26 clear internal-container-reference violations (25 in one
systematic pattern + 1 isolated), 2 clear reflection violations, 0 statement counting** (the rule's own
claimed removal, `04e831b55`, holds exactly — nothing similar has crept back in anywhere in the monorepo).

### Worst examples

1. **The systematic one** — `packages/Ecotone/tests/Modelling/AggregateBoundary/FetchedAggregateReadOnlyTest.php:49`:
   ```php
   $ecotone->getServiceFromContainer(EventStore::class)->loadByCriteria(...)
   ```
   vs. the sanctioned form used one directory over for the DBAL-backed equivalent
   (`packages/PdoEventSourcing/tests/Integration/Tagging/CouponAggregateWalkthroughDbalTest.php:65`,
   `$ecotone->getGateway(EventStore::class)`) — proven to work for the same in-memory bootstrap by a sibling
   file (`AppendStrategyTest.php:32`). 17 files, 25 occurrences, all in `packages/Ecotone/tests` — the in-memory
   suite the historical `implement-blackbox-tests` worktree evidently never back-ported.
2. **Isolated instance inside the "already fixed" package** —
   `packages/PdoEventSourcing/tests/Integration/EventStreamAggregateQueryTest.php:80`:
   `getServiceFromContainer(EventStoreReference::EVENT_STORE_INSTANCE)`.
3. **Raw SQL assertion (clear)** — `packages/Dbal/tests/Integration/MultiTenant/DeduplicationCleanupMultiTenantTest.php:73-78`,
   `SELECT COUNT(*) FROM ecotone_deduplication` asserted directly — exactly rule 10's own "wrong" example.
4. **Raw SQL on internal storage format (clear)** —
   `packages/PdoEventSourcing/tests/Integration/Tagging/AggregateBackedDecisionModelSnapshotDbalTest.php:100-107`:
   a test whose entire premise is asserting on the internal envelope field names (`covered_position`, `state`)
   of the snapshot storage format.
5. **Reflection into a generated proxy (clear)** — `packages/Tempest/tests/Hardening/ConsoleProxyArgumentMappingTest.php:91-96`
   bypasses the proxy's constructor and sets private properties directly.
6. **Reflection into a private driver property (clear, but a defensible API gap)** —
   `packages/Tempest/tests/Hardening/DynamicDriverTransactionPinningTest.php:99-103` — `PDOConnection` exposes
   no public accessor for the underlying `PDO`, so the fix may be an API addition, not just a deletion.
7. **Borderline, needs a maintainer call** — `TagBackfillConsoleCommandTest.php:200-213` and two siblings insert
   directly into `ecotone_event_stream` to simulate pre-feature legacy data that cannot otherwise exist (the
   live append path always computes tags now) — possibly a legitimate narrow exception, not a violation.

### Fix cost

- **Mechanical**: the 26 `getServiceFromContainer` → `getGateway` swaps — already proven to work in the same
  bootstrap shape by sibling tests in the same files. Highest-value, lowest-risk fix in this audit.
- **Needs judgement**: the deduplication SQL test needs an observable-behaviour replacement; the snapshot
  envelope test may need outright deletion (its stated purpose has no userland-only equivalent, similar to how
  `04e831b55` deleted a statement-counting test rather than keep it); the two Tempest reflection cases need a
  small public API addition (constructor/factory seam, or a connection accessor); the 3 backfill-simulation
  raw INSERTs are a maintainer design call (accepted narrow exception, or not).

### Recommendation

Fix the 26 container-ref swaps first — free and proven. Then take one maintainer decision on the
backfill-simulation exception. The remaining 4 (2 SQL, 2 reflection) are few enough to fix in one pass each
with judgement; the total (30 clear violations against ~2,000 test files) does not warrant a dedicated
worktree.

---

## Rule 10a — A guarantee is proved on every path

Not mechanically auditable at scale — it requires reading whether each behavioural guarantee is exercised
through more than one dispatch path (direct call, class-routed send, routed send, gateway, console command,
reconstruction path). This is an **illustrative 2-feature sample**, not a count.

- **`#[WithoutDatabaseTransaction]` honoured** (the guarantee the rule's own evidence table cites,
  `57c076084` → `9ae4e3fd3`): well covered — `packages/Dbal/tests/Integration/Transaction/TransactionTest.php`
  exercises routed send, a real console-command dispatch, and class-routed `CommandBus::send()` in one file,
  including a named test for exactly the path the second fix commit had to add.
- **Tagged-append optimistic-lock guard** (the rule's own row 1, `c09bac1e0`): a plausible gap —
  `AggregateSaveTagGuardDbalTest.php` and `AggregateBackedDecisionModelConcurrencyTest.php` drive every
  scenario exclusively through class-routed `sendCommand()`; neither file, nor any other file in the same
  directories, has a `sendCommandWithRouting()` call exercising this specific guard. This is the same
  structural split (`BusRoutingKeyResolver` resolves routed sends differently from class-routed ones) that
  already caused one shipped bug for the `WithoutDatabaseTransaction` guarantee.

**Recommendation**: add one `sendCommandWithRouting()`-based test to `AggregateSaveTagGuardDbalTest.php`
before extending the tag-scoped concurrency guard further. A fuller pass would need to repeat this exercise
for the ~30–40 other DCB/tagging test files and any non-DCB feature reachable through more than one bus path —
out of scope here.

---

## Rule 11 — Test shape

### Detection method

A Python brace-depth tokenizer (`count_types.py`) counts top-level `class`/`interface`/`trait`/`enum`
declarations per file across all 2011 PHP files under `packages/*/tests`. Restricting to `*Test.php` files
(518 of 2011 — the remainder live in shared `Fixture/` directories, a separate finding below), a file with
`count > 1` is the mechanical proxy for "declares a named fixture below the `TestCase`."

The rule's own "92 of 141" baseline was re-derived, not assumed:
```bash
git merge-base main HEAD                                                    # c440b6d4c, 1.x release 1.326.2
git diff --diff-filter=A --name-only $(git merge-base main HEAD)...HEAD -- 'packages/*/tests/*Test.php'
```
returns **exactly 141** files, confirming "added since 1.x" means "added since the 2.0 branch point," and
that all 141 still exist at HEAD.

For every one of the 713 extra fixture classes across the 100 currently-offending files, a regex heuristic
checked for exception 1 (used as a typed parameter, `catch()` clause, or return type) and exception 2 (named
inside another fixture's attribute argument, within a 200-character look-back window). This is a full sweep
of that population, not a sample, though it is heuristic, not a full PHP parse — manually verified against
~10 files / ~130 classes and found directionally accurate, with one documented near-miss on exception 2's
narrow look-back window.

Other shape checks (final classes, snake_case methods, comments/docblocks, assertion-message arguments, static
members) used similar grep/script passes described inline below each table; the assertion-message detector
was corrected mid-audit after an initial naive pass over-counted ~2x by conflating a trailing message string
with a 2-argument assertion's own expected-value string.

### The core deliverable: named-fixture counts, re-measured against the rule's own baseline

**The 141 files added since 1.x** (the rule's own scope), re-measured at HEAD:

| Package | Added since 1.x | Named fixture (current) | % |
|---|---|---|---|
| Amqp | 2 | 1 | 50.0% |
| Dbal | 6 | 1 | 16.7% |
| Ecotone | 86 | 70 | 81.4% |
| JmsConverter | 2 | 0 | 0.0% |
| Laravel | 2 | 0 | 0.0% |
| OpenTelemetry | 1 | 0 | 0.0% |
| PdoEventSourcing | 36 | 28 | 77.8% |
| Redis | 1 | 0 | 0.0% |
| Sqs | 1 | 0 | 0.0% |
| Symfony | 3 | 0 | 0.0% |
| Tempest | 1 | 0 | 0.0% |
| **Total** | **141** | **100** | **70.9%** |

**The rule's baseline has not held — it has gotten worse**: 92/141 (65.2%) → **100/141 (70.9%)**, with all 8
new offenders in `Ecotone` (DecisionModel tests) and `PdoEventSourcing` (Tagging integration tests) — the
exact DCB feature the rule was mined from.

**The current full tree** (a different, larger, mostly-pre-2.0 population — do not conflate with the figure
above):

| Package | Total `*Test.php` files | Files with a named fixture | % |
|---|---|---|---|
| Amqp | 25 | 5 | 20.0% |
| DataProtection | 14 | 0 | 0.0% |
| Dbal | 51 | 2 | 3.9% |
| Ecotone | 231 | 98 | 42.4% |
| Enqueue | 1 | 0 | 0.0% |
| JmsConverter | 5 | 0 | 0.0% |
| Kafka | 12 | 2 | 16.7% |
| Laravel | 14 | 1 | 7.1% |
| OpenTelemetry | 4 | 0 | 0.0% |
| PdoEventSourcing | 98 | 39 | 39.8% |
| Redis | 5 | 0 | 0.0% |
| Sqs | 8 | 0 | 0.0% |
| Symfony | 19 | 0 | 0.0% |
| Tempest | 31 | 1 | 3.2% |
| **Total** | **518** | **148** | **28.6%** |

`Monorepo/CrossModuleTests` (10 files) and `quickstart-examples` (18 files under `tests/`) are both clean —
single-class, no named fixtures.

### Exception classification (full sweep of the 713 extra classes, not a sample)

| Bucket | Count | % |
|---|---|---|
| Exception 1 (type declaration) | 375 | 52.6% |
| Exception 2 (attribute-argument class-string) | 0* | 0.0% |
| Likely genuine violation | 338 | 47.4% |

\* The heuristic's 200-character look-back window missed at least one real exception-2 case (manually
confirmed); treat 47.4% as a conservative upper bound, possibly a couple of points high.

Manual spot-checks show two distinct patterns: **validation/boundary test files**
(`DecisionModelValidationTest`, `DecisionBoundaryTest`) are dominated by genuine violations — single-purpose
handler/model classes used exactly once, passed by class-string or a local variable, matching the rule's own
`BasketTest` anonymous-class pattern exactly. **Domain-walkthrough test files**
(`AggregateBackedDecisionModelDbalTest` and similar) lean the other way — most extra classes are legitimately
exempt (command/event parameter types, or the aggregate named in a `#[DecisionModel(aggregate: X::class)]`
attribute argument).

### The other half of the rule: shared `Fixture/` directories

Rule 11 bans two things — named fixtures below the `TestCase`, **and** "never a shared `Fixture/` directory."
The second is the larger population: **19 shared `Fixture/` directories across 12 packages**, accounting for
the bulk of the ~1,500 non-`*Test.php` PHP files under `tests/`. This is pre-2.0 legacy structure predating
rule 11 in every package — not new drift — but it dwarfs the named-fixture-below-`TestCase` count and is the
more consequential of the two things the rule forbids.

### Other rule-11 shape violations per package

| Package | Non-final test classes | camelCase methods | Disallowed docblocks | Assertion messages |
|---|---|---|---|---|
| Amqp | 0 | 0 | 63 | 16 |
| DataProtection | 14 | 0 | 6 | 0 |
| Dbal | 5 | 0 | 97 | 41 |
| Ecotone | 67 | 0 | 391 | 77 |
| JmsConverter | 2 | 0 | 1 | 0 |
| Kafka | 0 | 0 | 23 | 17 |
| Laravel | 2 | 0 | 27 | 5 |
| OpenTelemetry | 0 | 0 | 16 | 0 |
| PdoEventSourcing | 13 | 0 | 46 | 3 |
| Redis | 0 | 0 | 7 | 1 |
| Sqs | 0 | 0 | 4 | 1 |
| Symfony | 3 | 0 | 18 | 3 |
| Tempest | 0 | 0 | 6 | 19 |
| **Total** | **106** | **0** | **705** | **183** |

`snake_case` method names are fully clean (php-cs-fixer enforcement holds, 0 violations). Disallowed docblocks
are concentrated, not uniform: 209/518 files have at least one, but 111 of those have exactly one (usually a
cosmetic duplicated `@internal` header, not prose); the top 15 files account for 42% of the total, and 43
files carry an `@author` tag, a reliable pre-2.0 marker.

**Static members**: 38 class-level static declarations found; 10 are PHPUnit-required `#[DataProvider]`
methods (exempt), 18 are stateless private static helpers (a literal but not a rationale violation — they
hold no state), and **10 are genuine stateful static properties** — the pattern rule 3's cited rationale
actually targets. Worst: `packages/PdoEventSourcing/tests/Projecting/GapAwarePositionIntegrationTest.php:41-47`,
seven `private static` properties on the test class itself.

### Worst examples

- **Genuine violations**: `packages/Ecotone/tests/Modelling/DecisionModel/DecisionModelValidationTest.php:321-609`
  (~22 single-use fixtures, each referenced only via `classesToResolve: [X::class]`);
  `packages/Ecotone/tests/Modelling/DecisionModel/DecisionBoundaryTest.php` (18 of 19 extra classes never used
  as a type declaration); `packages/Dbal/tests/Integration/Deduplication/DeduplicationExpressionFailureTest.php`
  (one class, instantiated and passed by class-string — nothing forces it to be named).
- **Legitimately exempt**: `packages/PdoEventSourcing/tests/Integration/Tagging/AggregateBackedDecisionModelDbalTest.php`
  — line 97's `catch (InsufficientFundsForAggregateBackedDbalTest)` (exception 1), line 447's
  `#[DecisionModel(aggregate: WalletForAggregateBackedDbalTest::class)]` (exception 2).
- **Assertion messages**: `packages/Amqp/tests/Integration/AmqpChannelAdapterTest.php:98,366,520,568,572,610`
  and `AmqpStreamChannelTest.php` (six each) — trailing message strings on `assertNotNull`/`assertEquals`.
- **Static properties**: `GapAwarePositionIntegrationTest.php` (seven, see above);
  `packages/DataProtection/tests/Unit/Encryption/FileTest.php:22-23` (path values declared as static
  properties rather than `const`).

### Fix cost

- **Named fixture → anonymous**: mechanical for single-use classes; needs judgement where the same class also
  serves as a type declaration elsewhere in the file — no blanket scripted rewrite is safe.
- **Shared `Fixture/` directories → inline**: needs a design decision per package; the rule gives no answer
  for a fixture genuinely reused across many test files, and inlining would duplicate it N times.
- **Assertion messages**: mechanical, zero risk — the same argument-counting logic used to detect them could
  drive a scripted removal for nearly all 183.
- **Static properties**: needs judgement — usually movable to `setUp()`/a `bootstrap()` helper, but
  `GapAwarePositionIntegrationTest`'s heavy per-class setup (DB connection, clock) may be a deliberate
  performance choice, not an oversight.
- **Legacy docblocks in tests**: for `@author`-tagged files, the rule's own carve-out already answers this
  (leave alone); the 273 unmarked files need a human pass per file.

### Recommendation

- **Named fixtures**: enforce for new code, scoped specifically to the DCB/DecisionModel suites where the
  drift is concentrated and growing — not the wider 1.x-era tree.
- **Shared `Fixture/` directories**: revise the rule's silence on genuinely-reused fixtures, or accept the 19
  directories as legacy and only enforce "no new ones" going forward.
- **Assertion messages**: enforce and fix — cheap, mechanical, zero risk.
- **Static properties**: enforce for new code; leave the 10 existing instances alone unless already touching
  those files.
- **Stateless static helpers**: revise the rule to say what it actually means ("no static *state*," not "no
  `static` keyword") — 18 of 38 static-member instances are harmless pure helpers the cited rationale doesn't
  object to.
- **Legacy docblocks in tests**: revise the rule to explicitly extend rule 6's legacy carve-out to test files
  — it is currently silent on this for tests specifically, and 705 instances is too large to leave unaddressed
  by the rule text.

---

## Rule 14 — A console option's name is its PHP parameter name, verbatim

### Detection method

```bash
grep -rn "ConsoleParameterOption" --include='*.php' packages/*/src packages/*/Api   # every usage + parameter name
cat packages/Ecotone/Api/Attribute/ConsoleParameterOption.php                       # the attribute's own definition
grep -rnoE -- '--[a-zA-Z][a-zA-Z]*-[a-zA-Z-]+' --include='*.php' packages/*/src packages/*/Api packages/*/tests
grep -rnoE -- '--[a-zA-Z][a-zA-Z]*-[a-zA-Z-]+' docs/*.md *.md
```
followed by cross-referencing every kebab-case `--flag` hit against the real registered camelCase option
names (below) to see whether removing the hyphens and camelCasing it matches a real option.

### The attribute cannot be misused

`packages/Ecotone/Api/Attribute/ConsoleParameterOption.php:7-13`:
```php
#[Attribute(Attribute::TARGET_PARAMETER)]
class ConsoleParameterOption
{
}
```
No constructor, no properties, no name argument — `ConsoleCommandModule.php:132` confirms the module reads
the decorated PHP parameter's own name to build the option. **This makes rule 14 structurally unviolable from
inside PHP**; every possible violation lives in prose that names an option independently of the attribute.

### All registered console options

| Package | Console command | Options (= PHP parameter names, verbatim) |
|---|---|---|
| Dbal | `ecotone:migration:database:setup` | `feature`, `initialize`, `sql`, `onlyUsed`, `missing`, `connection` |
| Dbal | `ecotone:migration:database:delete` | `feature`, `force`, `onlyUsed`, `connection` |
| Ecotone | async endpoint run command | `handledMessageLimit`, `executionTimeLimit`, `memoryLimit`, `cron`, `stopOnFailure`, `finishWhenNoMessages` |
| Ecotone | channel delete / setup | `channel`, `force` / `channel`, `initialize` |
| Ecotone | projection init | `all` |
| PdoEventSourcing | `ecotone:event-store:backfill-tags` | `stream`, `event`, `batchSize`, `fromNo`, `dryRun`, `skipUndeserializable` |
| PdoEventSourcing | `ecotone:event-store:verify-schema` | `legacyStream` |
| (framework-wide) | every console command | `header` — reserved by `ConsoleCommandConfiguration::HEADER_PARAMETER_NAME` |

### Violations found

All 9 occurrences (5 distinct option names) are in **`upgrade-2.0.md:875-897`**, describing the two
PdoEventSourcing commands:

| Written as | Real option |
|---|---|
| `--batch-size=500` | `--batchSize=500` |
| `--from-no=` | `--fromNo=` |
| `--dry-run` | `--dryRun` |
| `--skip-undeserializable` | `--skipUndeserializable` |
| `--legacy-stream=` | `--legacyStream=` |

This is every mention of these two commands' options in the file — the entire `backfill-tags`/`verify-schema`
section consistently uses the kebab-case form, so a user following the migration guide verbatim would hit
"option does not exist." This is the exact failure mode rule 14 was written from (`5fa0024b2`), recurring in
the document most likely to be followed verbatim during a 2.0 upgrade.

Checked and **not** violations: `upgrade-2.0.md`'s `--header "tenant:a"` example (the framework-reserved,
already camelCase-compatible option, correct as written); `docs/coding-conventions.md:822`'s own
`--skip-undeserializable` (the rule's deliberate worked example of the wrong form, not an accident).

### Excluded / not violations

Roughly a dozen further kebab-case `--flag` hits were filtered out as unrelated to
`#[ConsoleParameterOption]`: a standalone Symfony `Application` built directly in a test fixture
(`PdoEventSourcing/tests/Projecting/App/console.php`), Symfony's own built-in `cache:clear --no-warmup`, a
bespoke `$argv`-parsing script (`bin/update-licence-enterprise.php --dry-run`), and PHPUnit/Composer's own
flags (`--no-coverage`, `--prefer-lowest`, `--ignore-platform-req(s)`) across `AGENTS.md`/`CLAUDE.md`/workflow
docs.

### Fix cost

**Mechanical.** A find-and-replace of 5 token pairs across 7 lines in `upgrade-2.0.md:875-897`. No code
changes, no breaking change — this only corrects prose describing existing, unchanged behaviour.

### Recommendation

**Enforce and fix now.** The rule is already structurally enforced in code (no ongoing risk there); the only
fix needed is the one documentation file, and given the rule's own history this should not be left as
accepted debt.

---

## Cross-rule observations

- **Debt concentrates in the newest code, not the oldest.** Rules 6, 6a and 11 each found their clearest,
  most-actionable violations in code added in the last 5–10 days, in the exact DCB/DecisionModel/EventSourcing
  area the rules were mined from — not in 1.x-era legacy. The rules are being stated and then not applied
  retroactively to the feature that produced them.
- **`packages/Ecotone/tests`** carries the single largest concentration of debt found across this whole audit:
  25 of rule 10's 26 clear container-reference violations, and the large majority of rule 11's named-fixture
  growth, both live there.
- **Two rules (6, 11) explicitly ban patterns that a meaningful share of the codebase follows for a stated or
  inferable reason** — ported third-party code under rule 6, and shared `Fixture/` directories plus stateless
  static helpers under rule 11. Both are flagged above as "revise the rule" rather than "backlog to burn
  down."
