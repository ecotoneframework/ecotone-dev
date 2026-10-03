# Ecotone dev workflow

The mechanics, and the process around a unit of work. [AGENTS.md](../AGENTS.md) is the guide and summarizes what is
here; [docs/coding-conventions.md](./coding-conventions.md) holds the rules for the code itself. This file holds
which container, which command, which environment variable — and, from
[§ Design, briefs and review](#design-briefs-and-review) on, what a design task, a task brief and a review pass owe
each other.

Every command below was run against this tree unless it is marked otherwise.

---

## Containers

```bash
docker compose up -d                          # builds app's image on first run, waits for every healthcheck
docker compose exec app composer install      # root dependencies
docker compose exec app /bin/bash             # PHP 8.5.3 — the default container
docker compose exec app8_2 /bin/bash          # PHP 8.2.29 — the supported floor
```

`app` is PHP 8.5.3, `app8_2` is PHP 8.2.29, and both mount the working tree at `/data/app`. They are not
interchangeable for database work: **`app8_2` points `DATABASE_DSN` at MySQL, not PostgreSQL**, so the same suite
run in `app8_2` also changes which engine it exercises. Reach for it when a change has to hold on the 8.2 floor —
a language feature, a dependency constraint, or a signature phpstan will not catch.

`.env` is optional (`env_file: required: false` in `docker-compose.yml`), so a fresh checkout needs nothing
created. Copy `.env.dist` to `.env` only to override a default, such as turning Xdebug on.

**`git` is not available inside either container.** Anything that shells out to git — most importantly
`php-cs-fixer` — has to run on the host.

---

## Running tests

One package at a time, inside the container, **never in parallel**: the packages share the compose services and a
parallel run produces false failures.

```bash
# A package's full CI — each package has its own vendor/, so install it first
docker compose exec app bash -lc "cd packages/JmsConverter && composer install && composer tests:ci"

# One file
docker compose exec -T app vendor/bin/phpunit --no-coverage packages/Ecotone/tests/Modelling/AggregateBoundary/WithoutOptimisticLockTest.php

# One method — fastest feedback
docker compose exec -T app vendor/bin/phpunit --no-coverage --filter test_an_excluded_state_stored_aggregate_keeps_last_write_wins

# One directory
docker compose exec -T app vendor/bin/phpunit --no-coverage packages/Ecotone/tests/Modelling
```

**`--no-coverage` is not optional.** Without it PHPUnit 12 aborts before running anything:

```
1) XDEBUG_MODE=coverage (environment variable) or xdebug.mode=coverage (PHP configuration setting) has to be set
No tests executed!
```

The monorepo **root** `vendor/` is loaded even for a single package's run, because Ecotone's annotation finder
requires the root `vendor/autoload.php`. Keep the root install in sync with the container you are testing in.

Do not pass `-u root` to `composer` or to a test script: it leaves `vendor/` and phpunit's cache root-owned on the
host, which then blocks your own user and your editor from writing to them.

A **new** Laravel test fixture directory needs `storage/logs/.gitignore` and `bootstrap/cache/.gitkeep` copied from
a sibling under `packages/Laravel/tests/` before anything runs against it, or the first run commits its own
`laravel.log`. Two of the three DCB friction reports found this independently: `f575d7c60` and `ff8dab584` do
nothing else, and `ff8dab584` strips a tracked 177-line log out of three fixture directories.

### What `composer tests:ci` runs

It is not the same script in every package. Four of them do something extra:

| Package | `tests:ci` |
|---|---|
| Amqp | phpstan, then phpunit **twice** — `AMQP_IMPLEMENTATION=ext`, then `AMQP_IMPLEMENTATION=lib` |
| DataProtection | phpstan, then `tests/before-tests.sh` (generates a 200 MB fixture), then phpunit |
| Symfony | phpstan, phpunit, then `bin/console ecotone:list` as a smoke test |
| all others | phpstan, then phpunit |
| **monorepo root** | `bin/check-suite-parity.php`, phpstan, `packages/DataProtection/tests/before-tests.sh`, phpunit, then `quickstart-examples` |

`composer tests:local` at the root is the same as the root `tests:ci` without the quickstart examples.

### The root and per-package runs must agree

A package's tests run twice: in the package's own `composer tests:ci`, which is also what its split repository runs,
and in the root full suite. A green result from one runner is no evidence for the other — three defects existed
only in the gap between them, among them `c47252bd2`, a test-app namespace registered in the package's
`composer.json` and not in the root one. `php bin/check-suite-parity.php` (`composer tests:suite-parity`) fails
on any divergence and names the file, the expected entry and the fix:

| Check (`bin/suite-parity/`) | What must agree |
|---|---|
| `psr4-autoload` | every package `psr-4` entry is in the root `composer.json` under `packages/<Package>/`, and every root entry into a package resolves in that package |
| `testsuite-directories` | the root testsuite for a package lists exactly the package's own testsuite directories |
| `php-environment` | each package's `<php>` block and `backupGlobals`/`backupStaticProperties`/`processIsolation` equal the root's. A deliberate difference goes in its `$acceptedDivergences` with the reason; an accepted entry that stops diverging fails too |
| `stray-package-vendor` | no `packages/*/vendor` exists |
| `phpunit-configuration-name` | every package has a `phpunit.xml.dist`, the name `split-testing.yml` selects packages by |

A per-package `composer install` leaves `packages/<Package>/vendor` behind. A test that hands EcotoneLite its
package directory then requires that autoloader inside the root run, replacing the root's dependency versions —
an uncatchable `Premature end of PHP process` in an unrelated test — and its autoload map, hiding a missing root
namespace. The root `phpunit.xml.dist` therefore bootstraps through `bin/phpunit-root-bootstrap.php`, which refuses
to start while one exists. Remove them before a root run:

```bash
find packages -maxdepth 2 -name vendor -type d -prune -exec rm -rf {} +
```

There is **no `tests:behat` script and no behat suite** — behat was dropped over `074f37209`, `a3a52b970` and
finally `3a9618280`. Only an empty `packages/Symfony/tests/Behat/features/.gitkeep` survives.

### Databases

The container exports a DSN per engine. Override `DATABASE_DSN` to point a suite at another one. A change to event
sourcing, the event store or DBAL needs PostgreSQL, MySQL and MariaDB, because their DDL and locking behaviour
differ.

```bash
vendor/bin/phpunit --no-coverage packages/PdoEventSourcing/tests/                                   # PostgreSQL, the default
DATABASE_DSN="$DATABASE_MYSQL"   vendor/bin/phpunit --no-coverage packages/PdoEventSourcing/tests/  # MySQL
DATABASE_DSN="$DATABASE_MARIADB" vendor/bin/phpunit --no-coverage packages/PdoEventSourcing/tests/  # MariaDB
```

Set in the `app` container (`docker-compose.yml`), reachable by service name only from inside it:

| Variable | Value |
|---|---|
| `DATABASE_DSN` | `pgsql://ecotone:secret@database:5432/ecotone?serverVersion=16` |
| `DATABASE_MYSQL` | `mysql://ecotone:secret@database-mysql:3306/ecotone?serverVersion=8.0` |
| `DATABASE_MARIADB` | `mysql://ecotone:secret@database-mariadb:3306/ecotone?serverVersion=11.4.5-MariaDB` |
| `SECONDARY_DATABASE_DSN` | `mysql://ecotone:secret@database-mysql:3306/ecotone?serverVersion=8.0` — a genuinely separate database, for two-connection tests |
| `SQLITE_DATABASE_DSN` | `sqlite:////tmp/ecotone_test.db` |
| `RABBIT_HOST` | `amqp://rabbitmq:5672` |
| `SQS_DSN` | `sqs:?key=key&secret=secret&region=us-east-1&endpoint=http://localstack:4566&version=latest` |
| `REDIS_DSN` | `redis://redis:6379` |
| `KAFKA_DSN` | `kafka:9092` |

In `app8_2`, `DATABASE_DSN` and `SECONDARY_DATABASE_DSN` are swapped: MySQL primary, PostgreSQL secondary.

The host ports are ephemeral by default (`${POSTGRES_PORT:-0}`), so connect from the host only after setting the
matching `*_PORT` variable.

### Dependency ranges

CI resolves each package three ways (`.github/workflows/split-testing.yml`): PHP 8.2, PHP 8.5, and PHP 8.2 with
`--prefer-lowest`. The lowest-dependency run is the one that catches a constraint that is too loose.

```bash
# in the package you changed
docker compose exec app8_2 bash -lc "cd packages/Dbal && composer update --prefer-lowest && composer tests:ci"
docker compose exec app    bash -lc "cd packages/Dbal && composer update && composer tests:ci"
```

Both forms are the shape CI uses; they were not executed while writing this file, because they rewrite the
package's lock resolution.

---

## Static analysis, licence headers, code style

```bash
# phpstan — level 3, over a baseline. The bare form is what composer tests:phpstan runs; `analyse` is its default subcommand
docker compose exec -T app vendor/bin/phpstan
docker compose exec -T app bash -lc "cd packages/Dbal && vendor/bin/phpstan"

# Licence headers. check-licence.php is silent and exits 0 when every file has one
docker compose exec -T app php bin/check-licence.php
docker compose exec -T app php bin/add-apache-licence.php      # open source
docker compose exec -T app php bin/add-enterprise-licence.php  # Enterprise modules
```

`phpstan.neon` is level 3 over `src` for Ecotone, Enqueue, Dbal, Amqp, JmsConverter, PdoEventSourcing, Laravel,
OpenTelemetry, Kafka, plus `packages/Symfony/DependencyInjection`, `Monorepo`, and the `Api/` of all twelve packages
that have one. Each package's own `phpstan.neon` is level 3 too and lists its `Api` beside its source directories.
The root does **not** cover `tests/`, or the `src` of the Tempest, Redis, Sqs and DataProtection packages — only their
own configs do.

Every config includes a `phpstan-baseline.neon` beside it: the errors already in the tree when the level rose, which
is the list to work down. Fix a new error rather than regenerating the baseline over it. The root baseline fails on
an entry that no longer matches, so fixing an error means deleting its entry. The per-package configs set
`reportUnmatchedIgnoredErrors: false`, because CI analyses each package against its own `vendor/` on PHP 8.2,
8.5 and `--prefer-lowest`, and an entry one of those does not reproduce must not fail the others.

phpstan cannot catch a wrong class name in an attribute argument, because attribute arguments resolve lazily through
reflection, so a green run does not prove a rename or a move is complete.

```bash
# php-cs-fixer — ON THE HOST, never in the container. git is unavailable there, so the
# fixer loses its file filter and rewrites the whole repository.
vendor/bin/php-cs-fixer fix
vendor/bin/php-cs-fixer fix --dry-run --diff
```

Config is `.php-cs-fixer.dist.php`: `@PSR12`, `@PSR12:risky` and `@PHP80Migration` over `packages/` only, plus the
house rules listed in [conventions rule 20](./coding-conventions.md#20-mechanics-php-cs-fixer-enforces). It does
not add licence headers.

---

## What CI actually runs

| Workflow | Trigger | What it does |
|---|---|---|
| `test-monorepo.yml` | pull request | One unified job: `bin/check-suite-parity.php` first, then PHP 8.5, `--prefer-stable`, `composer validate --strict`, root phpstan, then phpunit against PostgreSQL with MySQL as the secondary connection |
| `split-testing.yml` | pull request | Per-package `composer tests:ci` for every package with a `phpunit.xml.dist`, on PHP 8.2, PHP 8.5, and PHP 8.2 `--prefer-lowest`. Tempest is excluded — it needs PHP 8.5 and a monorepo-tied boot harness, so the unified job covers it |
| `file-licence.yml` | every push **and** pull request | `php bin/check-licence.php` over `packages/*/src` |
| `contribution-check.yml` | PR opened or edited | Fails unless the PR body has the CLA checkbox ticked |
| `quickstart-examples.yml` | pull request | The quickstart applications, as their own package |
| `benchmark-pr.yml` | pull request | phpbench on PHP 8.5, for Ecotone, Laravel and Symfony |

Every one of them is `fail-fast: true`, so the first failure cancels the rest.


---

## Design, briefs and review

Everything above is what to run. This is what a design task, a task brief and a review pass owe each other. All of
it comes from three independent friction reports over the DCB effort
(`docs/superpowers/research/dcb-friction-{a,b,c}/report.md`), where each gap below was measured in commits somebody
had to write twice.

### A design justifies new storage against the read paths that already exist

Before proposing an index, a backfill job, or write-path state to make a value queryable, name the existing index or
read path you checked and why it is not enough — in the design document, before the proposal. `c40c483c7` proposed
indexing every event of every event-sourced aggregate as tag rows in `ecotone_tagged_events`, plus a daily backfill
to populate it. The next commit on the same branch, `f84eb145b`, is headed "Revision 2 — maintainer redirect" and
quotes the correction:

> there should be simpler solution, we already have the events in event stream. Therefore DecisionModel backed by
> aggregate type attribute plus ability to point what matches aggregate id would be sufficient to build the model

The replacement queries the stream's own `(aggregate_type, aggregate_id, no)` index — no new index, no backfill, no
daily population job — and the document's own comparison calls it smaller than revision 1 "by an order of
magnitude". It cost only document text because a human read the proposal before implementation started. Nothing in
the process caught it.

Keep the discarded analysis in the document rather than deleting it: `design-dcb-aggregate-full-tag` keeps
revision 1's cost arithmetic "because its cost arithmetic is now the justification for not building it", and
`research-dcb-single-read-snapshot` marks its own corrections inline with a literal **"Corrected:"** prefix instead
of silently rewriting the earlier text. Both make the next decision of the same shape cheaper to make correctly.

### A design document's claims are the unit's acceptance criteria

All three friction reports found this independently; it is the largest process gap they agree on. When a design
document claims something specific — "one read regardless of how many models a handler injects", "a MariaDB 1020
maps to `ConcurrencyException`", "counters first means a lost condition writes nothing", "the console commands route
by tenant header" — the commit that implements it adds a test named after the claim. A claim with no such test is
not shipped; it is waiting for a review pass to re-derive it from the document:

- `c6e670fc4` — the design promised one batched read. `DecisionModelConverter` was a plain per-parameter converter,
  so a handler with N injected `#[DecisionModel]`s paid N round trips. Retrofitting the promise took five commits, a
  new `DecisionModelBatchLoader` and two supporting registry/collector classes, across `DecisionModelModule`,
  `DecisionModelConverterBuilder` and `DecisionModelConverter`.
- `ef6df5468`, `0161ed16b`, `4e510a054`, `70a7dd4bf`, `94715ee33` — five review-fix commits that add nothing but
  tests. The code was already correct in every case; nobody could tell without reading the design document and the
  tree side by side.
- `1d5477356` — 313 lines of test and no source change, proving two console commands route by tenant header. They
  always had. Nothing said so until a numbered review item asked.

Where the claim is about cost rather than behaviour — a statement count, a round-trip count — a test is the wrong
instrument and review holds it instead; see
[conventions rule 10](./coding-conventions.md#10-tests-validate-at-the-userland-level-only), and `6b30eb5cd`
followed by `04e831b55` for the one that was written and then deleted. The behaviour still needs its test:
[rule 10a](./coding-conventions.md#10a-a-guarantee-is-proved-on-every-path-that-has-to-hold-it).

### What a task brief has to settle before the work starts

Three of the seven units in one friction group needed a blocking round-trip to the coordinator because the brief had
left a boundary open (`docs/superpowers/specs/2026-09-29-expression-failure-context-report.md` § "What the direction
did not settle"; `docs/superpowers/specs/2026-09-29-missing-table-instructions-audit.md` §1 "Scope decision"). Every
one was answered correctly, and every one cost a stop. A brief states, up front:

- **Whether an exclusion covers tests as well as production code.** "`#[Deduplicated]` is excluded because it lives
  in `packages/Dbal`" was read both ways.
- **Whether "handle X in DCB handlers" reaches every code path X touches**, including the ones shared with non-DCB
  features, or only the DCB-exclusive ones. The `#[Fetch]` *load* half turned out not to be separable from the
  ordinary aggregate-load path at all, which the brief had not anticipated.
- **The engines the change must be verified on** when it touches transactions, DDL or the event store — MySQL and
  MariaDB by name, not "run the suite" (see [step 7](#before-opening-a-pull-request)).
- **The conventions rules the unit will be checked against**, quoted rather than referenced, when it writes tests or
  constructors. Rules 2 and 10 were each swept by a worktree of its own after the fact rather than applied while the
  code was written — 6 and 22 commits, no new behaviour in either — and each of rule 10a's five missed paths was a
  later corrective commit.

### A review that numbers its findings commits the numbered list

DCB commit subjects carry finding IDs — `(B1)`, `(M1)`, `(M5)`, `(N9)`–`(N13)` — and two of the three friction
reports independently went looking for the document that assigned them, across all 26 DCB worktrees, and could not
find it committed anywhere. Only the resolutions survive, so no fix can be read against what was actually asked. The
readability review is the counter-example and the shape to copy:
`docs/superpowers/specs/2026-09-28-dcb-readability-review.md` is committed with its full ranked list, and every item
it raised can still be traced to the commit that closed it.

---

### A move of several co-imported types reverts newest-first

One commit per moved type keeps a large rename reviewable, but those commits do not revert independently. A file
that imports two of the moved types has both `use` lines next to each other, before the move and after php-cs-fixer
re-sorts them. Each commit edits one of the two adjacent lines, and git treats edits to adjacent lines as a
conflict, so reverting the earlier commit while the later one stands stops on that file. The order of the commits
does not change this: whichever comes first, it is the one that cannot revert alone.

What does hold is the suffix property: **the last commit reverts cleanly alone, and commit *k* reverts cleanly once
commits *k*+1 to *n* are reverted** — so the unit is undone newest-first. The `EcotoneLite` move measured it
(`df4e0f085`, `67161065f`, `f24c1c211`, `09ad57779`): reverting the `MessageChannel` commit alone was clean and left
phpstan at 0 errors, while reverting `EcotoneLite` alone conflicted in 89 files, `FlowTestSupport` in 14 and
`ConfiguredMessagingSystem` in 8, every hunk inside a `use` block or the namespace-map rows. Two types landing in
one namespace add a second, logical dependency: `ConfiguredMessagingSystem` and `MessageChannel` both moved into
`Ecotone\Api\Messaging`, php-cs-fixer dropped the now same-namespace import, and reverting the first alone would
leave it naming a `MessageChannel` that no longer resolves.

The maintainer kept the four commits rather than squash them, because a tree with only some of the types moved is
one nobody would want, and one commit of 400 files is harder to review than four named ones. So for a unit like
this: run the revert check (`git revert --no-commit <sha>` against the unit's head, then `git revert --abort`), say
in the report that the unit reverts as a suffix and why, and do not contort sorted files such as
`upgrade/namespace-map-2.0.csv` to get around it.

## Practices worth repeating

Recorded as deliberately as the mistakes, because each of these demonstrably stopped an error.

- **Survey first, then one commit per line item.** `implement-dcb-read-path-hygiene` wrote its missing-table audit
  table (`✓`/`→`/`~` per call site) before touching code; `implement-no-nullable-services` worked a pre-written list
  of sites. Both landed single-purpose commits with no walk-backs, and both mapped 1:1 onto the list. This is the
  default shape for any "sweep the codebase for X" unit.
- **Review in the order a newcomer reads, rank by size, name the files.**
  `docs/superpowers/specs/2026-09-28-dcb-readability-review.md` read attributes → `EventStore` → modules → flows →
  collaborators → tests, ranked each of its ten proposals S/M/L, and named exactly which files each one touches. Six
  were implemented in the same branch; the four touching public `Api/` or maintainer-decided names were deferred
  explicitly rather than rushed in. Both halves are the desired behaviour.
- **Control the host's noise before believing a number.** `docs/superpowers/specs/2026-09-29-dcb-fold-cost.md` §1.2
  runs each configuration in its own process, shuffles the order of (shape, ablation) pairs within every pass so a
  slow period of the shared compose host cannot land on one configuration, wires null controls that change nothing,
  states the resulting noise floor per shape (±2% to ±43%), and declines to conclude anything at all for the two
  shapes whose floor is too coarse. Any performance claim measured on this host follows it.
- **A measurement pass finds defects, not just numbers.** `4f6d6e22b` measured three *proposed* read optimisations
  against the shipped code and found two real defects on the way — a `tableExists` probe run on every call, and an
  empty extra page whenever an aggregate's event count is an exact multiple of the load batch size — both cheaper to
  fix than any of the three optimisations were to build.
- **Answer a genuinely ambiguous rule once, in writing, named as a decision.** `823dd41c3`, `c48e1b7c7`,
  `6ef6e48df`, `24edcf663` and `bd255a13a` each cite a dated maintainer call instead of folding a guess into the
  diff, so the same question was not answered differently by the next implementer.
- **Write the commit message so the defect can be reconstructed without the diff.** `050e760f1`, `9ae4e3fd3` and
  `36783f980` are the standard: what broke, why, and what changed. It is what made a six-branch, seventy-commit
  retrospective possible in a single pass.
- **Tabulate the error surface in the report.** The expression-failure-context unit listed every producer's exact
  exception message beside the named test asserting it, which made verification mechanical instead of a re-derivation.
  The shape for any unit that changes an error surface.

---

## Before opening a pull request

1. The new or changed test passes: `vendor/bin/phpunit --no-coverage --filter test_name`
2. The changed package's full CI passes: `cd packages/<PackageName> && composer install && composer tests:ci`
3. A Core change also runs the packages downstream of it — everything depends on `packages/Ecotone`
4. `php bin/check-licence.php` and `php bin/check-suite-parity.php` are clean
5. `vendor/bin/php-cs-fixer fix` on the host, with nothing outside your change rewritten
6. `vendor/bin/phpstan` is clean
7. Anything touching event sourcing, the event store, DBAL, transaction wrapping or DDL ran on **MySQL and
   MariaDB** too, not only the PostgreSQL default. They implicitly commit on DDL
   ([rule 16](./coding-conventions.md#16-a-table-is-created-on-the-message-path-only-through-the-auto-create-gate)), so nothing a PostgreSQL or
   SQLite run does will show it, and the omission has cost two whole worktrees weeks apart —
   `implement-mysql-mariadb-green` (merged at `22fe48dbc`) and `implement-dbal-mysql-green` (`57c076084`,
   `9ae4e3fd3`), both chasing the same engine-specific failure
8. The 8.2 floor still holds, if the change could care: `docker compose exec app8_2 ...`
9. `Monorepo/*/Symfony/config/reference.php` is not in the diff — those drift on their own
