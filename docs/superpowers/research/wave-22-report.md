# Wave 22 report — `Api/` is agent context: make it concise

Branch `dgafka/api-concise-wave22`, 16 commits on `11b2439b0`, not pushed, not merged.

| | before | after |
|---|---|---|
| `Api/` files | 218 | 218 |
| `Api/` lines | 14,049 | 11,448 (**-2,601**, -18.5%) |
| `Api/` docblock lines | 3,059 | 1,440 |
| `Api/` getters whose body is only `return $this->x;` | 323 | 178 |
| `src/` lines | — | net 0 (call sites rewritten one for one) |

Measured with the tokenizer over `git archive 11b2439b0 packages/*/Api` and the tree at HEAD
(`T_DOC_COMMENT` lines; getters by the regex `public function x(): T { return $this->y; }`).

## Increments

| commit | increment | `Api/` net |
|---|---|---|
| `e0df51fb8` | S1 — Enterprise notice in `Api/` is one sentence; guard + test | -546 |
| `a941832e9` | S2 — docblocks that restate the signature or the name | -1,032 |
| `e6a51408b` | S3 — attribute getters → `public readonly` | -125 |
| `c496fd8d5` | S3 — getters of freshly constructed classes → `public readonly` | -245 |
| `21d623297` … `f0cf7c635` | S4 — nine classes readonly, `with*()` rebuilds the instance (one commit each) | -653 |
| `62dc0420c` | S4a — `PollingMetadata::set*()` → `with*()` | 0 |
| `7e7951e42` | S5 — conventions rule 6b, `AGENTS.md` row 6b, rule 7a amended | — |

### S1

`bin/enterprise-licence-scope.php` is replaced by `bin/required-enterprise-licence-notice.php`, which answers which
notice a file must carry: the one-sentence `bin/enterprise-licence-api-notice.php` under `packages/<Package>/Api/`,
the full notice elsewhere. `bin/check-licence.php` and `bin/add-enterprise-licence.php` call two closures,
`bin/enterprise-licence-notice-violations.php` and `bin/enterprise-licence-notice-completion.php`, which
`Monorepo/CrossModuleTests/Tests/Licence/EnterpriseLicenceNoticeTest.php` runs against a fixture tree (7 tests: both
forms accepted, `src/` without the full notice refused, `Api/` with the bare marker refused, `Api/` with the full
notice refused, `src/Modelling/Api` keeps the full notice, the fixer rewrites every form both ways).

The check is stricter than the brief asked: an `Api/` file carrying the full notice is refused too, so the
convention S5 states is enforced rather than tolerated. The 42 files were rewritten by running
`php bin/add-enterprise-licence.php`, not by hand.

Mutation runs, verbatim:

```
$ sed -i '/Renaming the class or the namespace does not either/d' packages/Ecotone/src/EventSourcing/Tagging/MethodEventTagValueSource.php
$ php bin/check-licence.php
Fatal error: Uncaught RuntimeException: Incomplete Enterprise licence notice in file: /data/app/packages/Ecotone/src/EventSourcing/Tagging/MethodEventTagValueSource.php
The file is marked `licence Enterprise`, but the marker line is not followed by the full Enterprise notice, ...
exit=255

$ sed -i '/Use requires an Ecotone Enterprise licence (see LICENSE-ENTERPRISE/d' packages/Ecotone/Api/Attribute/EventTag.php
$ php bin/check-licence.php
Fatal error: Uncaught RuntimeException: Incomplete Enterprise licence notice in file: /data/app/packages/Ecotone/Api/Attribute/EventTag.php
The file is marked `licence Enterprise` inside a package's `Api/` directory, but the marker line is not followed by the one-sentence Enterprise notice. ...
exit=255

(restored)
$ php bin/check-licence.php
exit=0
```

### S2

A reflection-backed sweep removed every `@param`/`@return`/`@var` whose type set equals the declared one (class
names compared by short name, aliases and `self` resolved), every prose line, `@author`, `@package`, `@throws` and
`@inheritDoc`. It kept shapes, generics, `@template`, `@link`, `@internal`, and every annotation that differs from
or narrows the declaration (`@return T` on `object`, `int|TimeSpan` "treated as milliseconds", `@var` on an
untyped property). Removing three wrong `@return $this` on methods returning a new instance fixed three baselined
phpstan errors, whose entries were deleted from both baselines.

Prose the published documentation already covers became an `@link` (verified against the GitBook source, anchors
inferred from headings): final failure strategies, interceptor precedence, AMQP stream options, high throughput
publishing (5 channel builders, 5 publisher configurations, `publishDeferred`), the projection attributes, Tempest
connections, dynamic channels, deduplication clean-up, retries, the service map, channel initialization, polling
metadata, outbox forwarding, message headers.

**Kept, because no name, exception or page carries the fact:** the xdebug note on
`executionTimeLimitInMilliseconds` (three methods), the header mapper's pattern format (four publisher
configurations), `FlowTestSupport` recording only bus-sent messages (six methods), `Duration::toFloat()` being
seconds, `StateStoredRepository::save()`'s `0`/`null` version meaning, the Kafka `acks` levels, `setupForTesting`'s
`null`, `PollingMetadata`'s execution amount counting empty polls and its `0` = no time limit, and the units and
mutation semantics of eight `MessageHeaders` keys. Lost, judged acceptable: the AMQP/Kafka mechanics of the
`RELEASE` failure strategy, and the per-constant ordering notes of `Precedence` (the values encode the order).
`//` comments (24 lines, mostly librdkafka settings in `KafkaPublisherConfiguration`) were not touched.

### S3

Call sites were rewritten by type: phpstan's undefined-method report, plus a second phpstan pass over the
directories the root config does not analyse (`Symfony/SymfonyBundle`, `Symfony/App`, the `Redis`, `Sqs`,
`Tempest` and `DataProtection` sources, every `tests/`, the quickstarts), plus a read of each remaining call of a
shared name — `Event` arrays from `EventStore::load()` are untyped and phpstan does not see them. Excluded, with
reason: properties a method reassigns (`InMemoryConsoleWriter`, `InMemoryConsoleProgressBar`'s steps,
`MessageHeaders`, the AMQP/DBAL publisher configurations, `JMSConverterConfiguration`, `EventSourcingConfiguration`'s
mutable fields, `DecisionModelConcurrencyException`, `LoggingLevel::getLevel()`); `Key::$key_bytes` (snake_case);
`Duration::inMicroseconds()` (its siblings compute); `EventHandler::getOutputChannelName()` (read through a
`QueryHandler|CommandHandler|EventHandler` union shared with `InputOutputEndpointAnnotation` — a property would break
it, and phpstan level 3 does not flag a union). Two fixes fell out: `KafkaConsumer` assigned its promoted `groupId`
twice, and `SymfonyConnectionReference` redeclared the `connectionName` its parent now exposes.

### S4 and S4a

Per the coordinator's decision, no builder: promoted `readonly` properties and
`new self(...[...get_object_vars($this), 'field' => $value])`; `new static` with a `final private` constructor on the
three non-final classes (`DataProtectionConfiguration`, `ServiceConfiguration`, `DbalConfiguration`); keyed merges
with `array_replace()`. Each class has a round-trip test written first and seen red: every field populated, every
`with*()` changing only its fields, the original untouched (`ImmutableConfigurationCopyTest`, 8 classes,
`DbalConfigurationCopyTest`, `DataProtectionConfigurationCopyTest`).

- **Ten classes in the brief's table were not all clone-based.** `AmqpExchange` and `AmqpQueue` mutate and return
  `$this`; excluded by decision (converting would silently drop `HeadersExchangeDelayStrategy`'s delay-queue TTL and
  dead-letter exchange). Nine classes were converted.
- **`ServiceConfiguration` mixed both contracts** — 9 copy methods, 4 mutators. Converted; the one caller relying on
  mutation (`MessagingSystemConfiguration`, the default connection retry template) now assigns, and
  `PollingConsumerAckAndRetryTest::test_a_consumer_without_its_own_connection_retry_recovers_from_failed_receives_with_the_default_one`
  is red with the statement form and green with the assignment. The 26 other statement-form hits pass the
  configuration as an argument. `upgrade-2.0.md` has its own entry for the four.
- **`MetadataMatcher`'s constructor was public by omission** and is called across `src`; it stays public and now does
  the validation `create()` did.
- `PollingMetadata`: `polledChannelName` became the constructor's last parameter; `withSignalInterceptors` stays a
  declared property the constructor resolves. Every `DynamicMessageChannel` receive now builds one array via
  `get_object_vars()` — known, accepted.
- S4a: 19 methods, 237 call sites, none discarding a copy, so no bug-fix commit; names declared nowhere else
  (vendor included), renamed tree-wide including the `.claude` skills and `docs/conventions-outstanding.md`.

## Verification

Every increment: `composer dump-autoload`, root phpstan `[OK] No errors` (no baseline additions; three entries
removed), `bin/check-licence.php` exit 0, `bin/check-suite-parity.php` exit 0, php-cs-fixer. Full 15-suite runs after
S3 (twice), `ExecutionPollingMetadata`, `ServiceConfiguration`, `PollingMetadata`, S4a and `DbalConfiguration`;
targeted package suites for the smaller S4 classes. Final run, at `f0cf7c635` (S5 is documentation only):

```
Core tests | exit=0 | Tests: 1681, Assertions: 3052, Skipped: 1.
DataProtection | exit=0 | OK (1396 tests, 207749 assertions)
Dbal tests | exit=2 | Tests: 491, Assertions: 1103, Errors: 4, Skipped: 1.
Event Sourcing tests | exit=0 | Tests: 643, Assertions: 1302, Skipped: 20.
Jms Converter tests | exit=0 | OK (54 tests, 115 assertions)
Laravel tests | exit=0 | Tests: 56, Assertions: 109, Skipped: 1, Risky: 19.
Tempest tests | exit=0 | Tests: 71, Assertions: 140, Risky: 3.
Symfony tests | exit=0 | OK (63 tests, 118 assertions)
Enqueue tests | exit=0 | OK (1 test, 3 assertions)
Amqp tests | exit=0 | Tests: 150, Assertions: 222, Skipped: 34.
SQS tests | exit=0 | Tests: 42, Assertions: 95, Deprecations: 2.
Redis tests | exit=0 | Tests: 25, Assertions: 38, Deprecations: 5.
Monorepo tests | exit=0 | Tests: 104, Assertions: 4063, Deprecations: 6, Skipped: 3, Risky: 15.
OpenTelemetry tests | exit=0 | OK (38 tests, 292 assertions)
Kafka tests | exit=0 | OK (60 tests, 140 assertions)
```

**What was not verified, and why.** MySQL cannot start on this host (`io_setup() failed with EAGAIN`,
`fs.aio-nr` 65295/65536, 17 other compose stacks running). Every run therefore used
`docker compose exec -e SECONDARY_DATABASE_DSN=<MariaDB DSN>` — the documented per-run DSN override, no compose or
engine-gate change; PostgreSQL stayed primary. The four Dbal errors in every run are the MySQL-only tests
`MySqlDeduplicationConcurrencyTest` (2) and `MySqlTransactionRollbackTest` (2), which connect to `database-mysql`
directly: unrun, not passing. Two-connection tests ran against PostgreSQL + MariaDB, not PostgreSQL + MySQL. The
`app8_2` (PHP 8.2) container and per-package `composer tests:ci` runs were not exercised; the round-trip mechanics on
8.2.29 were verified by the coordinator. php-cs-fixer could not run on the host (no `php`); it ran in the container
with an explicit file list from the host's `git diff` and `--path-mode=intersection`, so it never walked the whole
tree. DataProtection needed `tests/before-tests.sh` (the 200 MB fixture) in the fresh worktree.

One self-inflicted fault: `DataProtectionConfigurationCopyTest` first carried a `licence Enterprise` header, which
the new S1 guard rightly refused; I had not re-run `bin/check-licence.php` before that commit. Fixed in its own
commit with a non-interactive autosquash on this local branch (dates kept at 08:00), so every commit passes the
guard; the rewritten tree differs from the pre-rebase one by that line only.

## Brief claims, checked

| claim | measured |
|---|---|
| 218 files, 14,049 lines | confirmed |
| 3,062 docblock lines (21.8%) | 3,059 |
| 42 Enterprise `Api` files, 18-line notice, ~-546 | confirmed, exactly -546 |
| `LICENSE-ENTERPRISE` is two lines, ships in all 14 packages | three lines (two non-blank); 14 copies, identical |
| exclude "any path with an `Api/` directory segment" | would exempt `packages/Ecotone/src/Modelling/Api` (`src`); anchored to `packages/<Package>/Api/` |
| `add-enterprise-licence.php` cannot replace an existing notice | it replaced a superseded notice ending in the same last line, could not write a short form; `update-licence-enterprise.php` only swaps `Apache-2.0` for `Enterprise`. Extended the fixer instead of a one-off |
| S2: 179 plain-scalar `@param`/`@return` of 457 | 221 by my type test; S2 removed -1,032 lines, not ~-530 (class-type restatements, frames, `@throws`/`@author` counted) |
| `EcotoneLite.php` has 70 prose docblock lines | 4 prose lines and 5 `@param` descriptions; the largest prose block was `MessageHeaders` (25) |
| 324 trivial getters, 166 convertible, 104 fresh in 56 files | 323; 161 sole-owner non-interface; 99 fresh in 53 files |
| `Api/Attribute/`: 21 files, 27 getters | 24 getters in 20 classes converted; one excluded as duck-typed |
| fresh-construct means convertible | no — many fresh classes reassign their properties; excluded |
| S3 ~-520 | -370 |
| a green phpstan catches the call sites | the root config skips `SymfonyBundle`, `Symfony/App`, four package `src` dirs and every `tests/`; a wider pass was needed |
| twelve `clone $this` classes | nine; `AmqpExchange`/`AmqpQueue` mutate; `ServiceConfiguration` mixed |
| S4 ~-310 `Api` / ~+450 `src` | -653 `Api` / 0 `src` (no builder) |
| S4a: 19 `set*()`, 21 `createCopy()`, one statement-start site, no live defect | confirmed; 237 call sites, none discarding |

## Left for the maintainer

- `AmqpQueue`/`AmqpExchange`: `with*()` mutates — a rule-4 rename (`setArgument()`) is a public-API decision.
- Adding `final` to the three non-final S4 classes (recorded by the coordinator as a follow-up).
- The four MySQL-only Dbal tests, once the host's AIO limit is raised.
- `Api` `//` comments (24 lines) and the excluded getters above.
