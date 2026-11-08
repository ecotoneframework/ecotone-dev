## 7. Enterprise features live in separate files

Never `if ($this->licence->hasEnterpriseLicence())` inside one class. Two classes behind one interface, chosen once
in the container.

The canonical set, all in `packages/Ecotone/src/Modelling/EventSourcingExecutor/`:

- `AggregateMethodInvoker` — the interface, the seam
- `OpenCoreAggregateMethodInvoker` — `licence Apache-2.0`; throws the message in rule 1 when asked for the
  Enterprise behaviour
- `EnterpriseAggregateMethodInvoker` — `licence Enterprise`; the real implementation
- wired in `ApplyMethodExecutorBuilder.php:72` with
  `LicenceDecider::prepareDefinition(AggregateMethodInvoker::class, ...)`

### The licence header

Every PHP file under `packages/*/src` and `packages/*/Api` carries a licence docblock. There are two of them, and
the only difference is the word:

```php
/**
 * licence Apache-2.0
 */
```

```php
/**
 * licence Enterprise
 */
```

It goes immediately above the `class`/`interface`/`trait` line — so, in an attribute class, *after* the
`#[Attribute(...)]`:

```php
#[Attribute(Attribute::TARGET_METHOD)]
/**
 * licence Apache-2.0
 */
class CommandHandler extends InputOutputEndpointAnnotation
```

That placement is not a preference: `bin/add-apache-licence.php` inserts the block directly before the first line
matching `abstract class|final class|class|interface|trait`, and `bin/add-enterprise-licence.php` is the same script
with the other word. Run them rather than typing the header, and `bin/check-licence.php` to check
(silent, exit 0 when every file has one — it is the CI gate, rule 21).

Some older files carry the same text as a `/* ... */` block at the top of the file, before
`declare(strict_types=1)` — `packages/Ecotone/Api/Projecting/PartitionProvider.php` is one.
`bin/check-licence.php` accepts that form, so do not sweep them, but write the docblock form in new files.

Across `packages/*/src` and `packages/*/Api`, 1014 files carry `licence Apache-2.0` and 228 carry `licence Enterprise`. **When in doubt, `Apache-2.0`** — a
feature is Enterprise by decision, not by which namespace it landed in, and the maintainer will say so.

Test files carry `/** licence Apache-2.0 @internal */` too (php-cs-fixer adds the `@internal`, not the licence),
but they are a convention rather than a gate: `bin/check-licence.php` only walks `packages/*/src`.

**Write it in the Enterprise file from the first commit; extracting it later is the expensive path.** `a7f08f6a7`
("extract `DbalEventStore`'s DCB tag logic into Enterprise classes"), `7bf1082a2` and `3a6134d18` are what that
costs. `DbalEventStore` and `InMemoryEventStore`, both `licence Apache-2.0`, had grown the entire DCB tag protocol
inline — `appendEventsWithTagCondition`, `loadByCriteria`'s capture and read, `backfillTagsForStream`, the
snapshot-hazard bookkeeping, the SQL-state mapping — and a review pass put it plainly: *"That is Enterprise logic
living in an Apache-2.0 file."* Unpicking it took five to six new classes per store family
(`DbalTagVersionRegister`, `DbalTagConditionalAppender`, `DbalTaggedEventReader`, `DbalTagBackfiller`,
`EnterpriseDbalTagCollaborator`/`OpenCoreDbalTagCollaborator`, and the in-memory mirrors) wired through
`LicenceDecider` — the pattern `EnterpriseAppendStrategy` was already using in this same codebase while the tag
protocol was being written inline. Before writing behaviour a licence gates, read the licence header of the file
you are in.

**Split the implementation, never the interface.** Licence is not an axis to split a type on: it was tried once,
for the event store, and reversed. `TaggedEventStore` and `AggregateEventStore` were folded back into a single
`EventStore` with an `AppendStrategy` chosen by `LicenceDecider` (`983db0c5a`, `5a5f08572`, `ade73e6c5`,
`1be829b95`, with the maintainer call recorded in `bd255a13a`) — after the split shape had already propagated into
every repository, the gateway registration, the integration tests and `DcbSmokeTest`. One interface, two
implementations, chosen in the container: rule 5's seam.

**Open-core behaviour stays byte-for-byte unchanged.** An Enterprise feature may not alter what an application
without a licence observes. This was a standing check in every DCB brief, and is why the licence seam is a
separate class rather than a branch.

---

## 7a. The Enterprise notice governs copying out, not work in this repository

Every `licence Enterprise` file under `src/` carries the full proprietary notice, generated from
`bin/enterprise-licence-notice.php` and enforced by `bin/check-licence.php`; a file under a package's `Api/` carries
the one-sentence form of rule 6b instead. The full notice states that the file is proprietary, that use requires an Ecotone
Enterprise licence, that copying or reimplementing it as a derivative or "inspired" version is unlawful rather than
merely a breach of terms, that keeping the notice or renaming the class does not make a copy permitted, and that if
you cannot confirm a licence you should implement the behaviour from your own functional requirements instead.

**This is the Ecotone core repository, so edit those files here like any other file.** The notice is not addressed to
work done here: fixing, refactoring and testing an Enterprise file in this repository is ordinary work, and changes
belong here as a pull request. Read as a warning to yourself, the notice stalls every fix to an Enterprise file,
which is the failure this rule exists to prevent.

**Outside this repository the notice binds.** Never copy an Enterprise file into another codebase, in whole or in
part, never adapt one, and never reimplement one from having read it. Renaming the class or the namespace does not
change that, and neither does keeping the notice in the copy.

Evidence for the wording: 24 evaluated agent runs against a scaffold application carrying this notice — four models,
two vectors, three runs each. Every run that asked a question refused. The one run that copied, at 0.989 token
overlap, renamed the namespace and the class, kept the proprietary notice and never asked — which is why the notice
names renaming and notice-keeping explicitly, and why it now states the lawful alternative rather than only a
prohibition.

---

