# Cold-start findings: fresh checkout to running tests

This records a real cold-start of `dgafka/ecotone-2-0-work`, done in throwaway clones and
worktrees outside this checkout, following only what `AGENTS.md` and `CLAUDE.md` told a
newcomer to do, literally, in order. Every command and error below is a real transcript, not a
summary. This document went through a maintainer review round that corrected three parts of the
first draft; the corrected findings are the ones below (an earlier version proposed a `bin/setup`
script and a `.git`-mounting workaround — both were reverted; see gap 0 and gap 6 for why).

The two documented bootstrap commands are:

```bash
docker compose up -d
docker compose exec app composer install
```

Everything below is fixed directly in `docker-compose.yml` / `.docker/php/Dockerfile` /
`composer.json`, so these two commands — the ones every developer already knows — are all a
fresh checkout needs. No new script, no flags, no manual file creation.

## 0. Git is unreachable inside the container for a git-worktree checkout — investigated, not fixed, and correctly so

A `git worktree` checkout's `.git` is a **file**, not a directory, pointing at an absolute host
path outside the worktree itself:

```
$ cat .git
gitdir: /home/dgafka/development/simplycodedsoftware/ecotone-context/ecotone-dev/.git/worktrees/ecotone-2-0-dev-env-bootstrap
```

`docker-compose.yml` only bind-mounts the worktree directory itself (`"$PWD:/data/app"`), so that
target path does not exist inside the container, and every `git` command inside it fails hard:

```
$ docker compose exec app git status
fatal: not a git repository: /home/dgafka/development/simplycodedsoftware/ecotone-context/ecotone-dev/.git/worktrees/ecotone-2-0-dev-env-bootstrap
```

**First draft of this document treated this as a bug and mounted `GIT_COMMON_DIR` into the
container to fix it.** That was wrong, for exactly the reason the correction gave: it introduced
a configuration/mount purely to make `git` usable *inside the container*, and nothing in the
documented workflow calls `git` from inside the container. Checked directly:

```
$ grep -rln "exec('git\|\"git \|'git \|shell_exec.*git\|Process.*git" packages/*/src packages/*/tests   # no hits
$ grep -n "git" .php-cs-fixer.dist.php phpstan.neon bin/get-packages monorepo-builder.php   # no hits
```

And empirically, in this exact broken-git worktree, before any mount workaround existed:

```
$ docker compose exec app composer install
...
Generating autoload files
132 packages you are using are looking for funding.
```

204 packages installed clean, no git-related output at all (`COMPOSER_ROOT_VERSION: 'dev-main'`
means composer never needs to shell out to git to guess the root version). The full per-package
test command (`composer install && composer tests:ci`) also runs clean in this state — see gap 5.

So this is outcome 1 of the three the maintainer laid out: **nothing in the documented workflow
needs git inside the container.** We reverted the `GIT_COMMON_DIR` mount and the `bin/setup` step
that computed it. A `git worktree` checkout now behaves exactly like a plain clone from
`docker-compose.yml`'s point of view: no mount, no exported variable, nothing to configure.

The only real, narrower cost: an agent who tries `git status`/`git diff` *inside the container*
(instead of on the host, where it always works) hits a confusing `fatal: not a git repository`.
That is real, but it is not a bootstrap/test-workflow concern, so per outcome 3 of the maintainer's
decision tree we are noting it here and leaving it alone rather than engineering around it.

## 1. `bin/setup` was deleted — its jobs moved to files that fix themselves for everyone

The first draft added a `bin/setup` script to: copy `.env.dist` to `.env`, run
`git config --global --add safe.directory`, poll `pg_isready` in a loop, then run
`docker compose up -d` and `composer install`. The maintainer's correction: every one of those
either wasn't needed (see gap 0) or belongs in a file that fixes it for everyone, not only for
someone who knows to run a script. All four surviving jobs now live in `docker-compose.yml` /
`.docker/php/Dockerfile` directly:

- `.env.dist` copy → **deleted.** `env_file` on `app`/`app8_2` is `{path: .env, required: false}`
  (already true before this round), and the two variables it used to seed
  (`XDEBUG_ENABLED`, `PHP_IDE_CONFIG`) are now defaults directly in each service's `environment:`
  block. `.env` is now a pure, optional, local override file — nothing reads it unless a developer
  creates one on purpose.
- `git config --global --add safe.directory /data/app` → moved into `.docker/php/Dockerfile`:
  `RUN git config --system --add safe.directory /data/app`, baked into the image so it applies to
  every user, every time, with no per-checkout step.
- The 60-second `pg_isready` wait loop → replaced with real compose `healthcheck:` blocks on
  every service the suite races against (`database`, `database-mysql`, `database-mariadb`,
  `rabbitmq`, `kafka`, `localstack`, `redis` — see gap 3) plus `depends_on: condition:
  service_healthy` on `app`/`app8_2`. `docker compose up -d` itself now blocks until everything is
  actually ready; no external script needed.
- `docker compose up -d` and `composer install` → these are the two documented commands now.
  Nothing left to wrap.

`bin/setup` is deleted. Nothing survived that compose/the Dockerfile couldn't do declaratively.

## 2. `composer install` must NOT run as `-u root` — it leaves `vendor/` root-owned on the host

Found while verifying the above: `AGENTS.md` told people to enter the container with
`docker compose exec -u root app /bin/bash` and then run composer/tests there. `app`/`app8_2`
otherwise default to `user: "${USER_PID:-1000}:${USER_PID:-1000}"` (uid 1000, matching the host
user on a typical single-user Linux box). Running `composer install` as root writes `vendor/`
owned by root:

```
$ docker compose exec -T app rm -rf vendor
rm: cannot remove 'vendor/symplify/monorepo-builder/composer.json': Permission denied
...
```

— the default, non-root user (and therefore the host user, and any host-side tool: an editor,
`git`, a follow-up `rm`) can no longer touch `vendor/` at all once anything has run there as root.
`composer install` does not need root:

```
$ docker compose exec app id
uid=1000(deploy) gid=1000 groups=1000
$ docker compose exec app composer install    # no -u root
...
Generating autoload files
$ stat -c '%U %u' vendor vendor/autoload.php
dgafka 1000
dgafka 1000
```

**Fixed**: `AGENTS.md`/`CLAUDE.md` now document `composer install`/`composer tests:*` without
`-u root`, and call out explicitly that `-u root` is only for things that genuinely need elevated
OS-level access, never for composer or the test suites.

## 3. No `healthcheck`/`depends_on` gating — added for every service the suite races against

`docker-compose.yml` had zero `healthcheck:`/`depends_on:` entries anywhere. `docker compose up -d`
returns as soon as containers are *started*, not once the databases/brokers inside them are ready
to accept connections — a real race the first draft only partially covered with a Postgres-only
`pg_isready` loop in `bin/setup`. Since `bin/setup` is gone (gap 1), this needed a real, declarative
fix: `healthcheck:` on every service the suite talks to (`database`, `database-mysql`,
`database-mariadb`, `rabbitmq`, `kafka`, `localstack`, `redis`), plus `depends_on: condition:
service_healthy` on `app`/`app8_2`.

Getting the actual healthcheck commands right took two iterations — the two DB-flavoured
containers don't share one ping tool:

```
$ docker compose exec database-mariadb mysqladmin ping ...
sh: 1: mysqladmin: not found
```

`mariadb:11.4`'s image ships its own `/usr/local/bin/healthcheck.sh` instead of `mysqladmin`;
`mysql:8.0`'s image does have `mysqladmin`. Final, verified end-to-end:

```
$ docker compose up -d
 Container ...-database-1              Healthy
 Container ...-redis-1                 Healthy
 Container ...-rabbitmq-1              Healthy
 Container ...-kafka-1                 Healthy
 Container ...-database-mariadb-1      Healthy
 Container ...-localstack-1            Healthy
 Container ...-database-mysql-1        Healthy
 Container ...-app-1                   Starting
 Container ...-app8_2-1                Starting
 Container ...-app-1                   Started
 Container ...-app8_2-1                Started
docker compose up -d  0.20s user 0.04s system 2% cpu 11.469 total
```

`app`/`app8_2` only start once every dependency is healthy, and the whole thing takes ~11s on this
host. `docker compose up -d` alone is now the full "wait until ready" step; nothing external
needed. `jaeger`/`zipkin`/`collector`/`kafdrop` are pure observability/debug UIs the test suite
doesn't talk to synchronously, so they were left without healthchecks.

## 4. `.env` is gitignored but required — fixed at the compose level, not by generating a file

`.gitignore:18` ignores `.env`; `docker-compose.yml` declared `env_file: [".env"]` (required by
default) for both `app` and `app8_2`. On a fresh checkout this is the very first command an agent
runs, and it fails outright:

```
$ docker compose up -d
env file /tmp/.../cold-start/.env not found: stat /tmp/.../cold-start/.env: no such file or directory
```

**Fixed**: `env_file` is now `{path: ".env", required: false}` for both services, and the two
variables `.env`/`.env.dist` used to carry (`XDEBUG_ENABLED="0"`, `PHP_IDE_CONFIG="serverName=ecotone"`)
are now defaults directly in each service's `environment:` block (see gap 1 — this replaces the
first draft's `bin/setup`-copies-`.env.dist` approach). `.env`/`.env.dist` still exist for a
developer who wants to override a default locally (e.g. turn Xdebug on); nothing requires them.

## 5. `ext-sockets` missing from the `app` (PHP 8.5.3) image only

`AGENTS.md` → Running Tests used to jump straight from entering the container to
`composer tests:ci`, never mentioning `composer install`. Following it literally:

```
$ docker compose exec app composer tests:ci
sh: 1: vendor/bin/phpstan: not found
```

The natural next step, `composer install`, hits the actual gap:

```
$ docker compose exec app composer install
...
  Problem 1
    - Root composer.json requires enqueue/amqp-lib ^0.10.25 -> ...
    - php-amqplib/php-amqplib[...] require ext-sockets * -> it is missing from your system.
Alternatively, you can run Composer with `--ignore-platform-req=ext-sockets` to temporarily
ignore these required extensions.
```

`--ignore-platform-req=ext-sockets` is real folklore: it works, but is written down nowhere in
this repo. `app8_2` (PHP 8.2.31), checked separately, already has `ext-sockets` compiled in — only
the PHP 8.5.3 image lacks it. CI doesn't hit this either: `test-monorepo.yml`/`split-testing.yml`
use `shivammathur/setup-php@v2` on GitHub-hosted runners, whose default PHP already ships
`ext-sockets`; the two workflows that do pass `--ignore-platform-reqs` do so for unrelated
extensions (no `ext-amqp`/`ext-rdkafka` on those runners at all). Purely a local Docker dev-image
gap.

**Decision**: extend the published image locally. Verified the base image still has its build
toolchain (`gcc`, `make`, `phpize`, Debian 12 bookworm) and that `docker-php-ext-install sockets`
succeeds in seconds. `.docker/php/Dockerfile` extends the base image via `ARG BASE_IMAGE` +
`ARG INSTALL_SOCKETS` (`RUN if [ "$INSTALL_SOCKETS" = "1" ]; then docker-php-ext-install sockets; fi`),
wired into `docker-compose.yml`'s `build:` stanza per service (`INSTALL_SOCKETS: 1` for `app`,
default `0` for `app8_2`), tagged with new local names (`ecotone/php:8.5.3-dev`,
`ecotone/php:8.2.31-dev` — deliberately not reusing the upstream tags, so this never clobbers the
shared image cache other worktrees on this host rely on). This same Dockerfile also bakes in the
`git config --system --add safe.directory` fix from gap 6, for both images, in one place. Costs one
one-off local image build on first `up`; after that, `composer install` needs no flag at all.

## 6. `git`: "dubious ownership" on the first path-repo touch — fixed in the Dockerfile

The first `composer install` in a **plain clone** (not a worktree — see gap 0 for that case)
prints this before getting to any real error:

```
To add an exception for this directory, call:
  git config --global --add safe.directory /data/app
fatal: detected dubious ownership in repository at '/data/app'
```

A warning, not a hard failure (install continues), caused by the bind-mounted repo's host uid not
matching the container user's expected uid — but it reads exactly like a fatal error to anyone
scanning for the word "fatal". **Fixed**: baked into `.docker/php/Dockerfile` as
`RUN git config --system --add safe.directory /data/app`, applying container-wide regardless of
which uid ends up running as, with zero per-checkout step (see gap 5 for the same Dockerfile's
other job).

## 7. Root `composer tests:phpunit`/`tests:ci` hits Composer's own process timeout

Running the root suite killed itself at the 90% mark:

```
...........RR................................................ 3477 / 3856 ( 90%)
The following exception is caused by a process timeout
  The process "vendor/bin/phpunit --no-coverage" exceeded the timeout of 600 seconds.
```

Root `composer.json` already set `"process-timeout": 600` explicitly — the ~3856-test root suite
simply runs longer than that. CI already works around this with `COMPOSER_PROCESS_TIMEOUT: 3600`
in `test-monorepo.yml`, but nothing sets that locally.

**Fixed**: bumped `composer.json`'s `config.process-timeout` from `600` to `3600`, matching CI.

## 8. `packages/DataProtection/tests/before-tests.sh`: a silent, cwd-dependent fixture failure

Found by the maintainer's own gate run on this branch: a genuinely fresh checkout (no worktree
has ever run this before) hits a real failure that every *already-used* worktree masks, because
each one already has the 200MB fixture file from a prior run.

```sh
BIG_GENERATED_FILE=./tests/Fixture/files/big-generated-file
dd if=/dev/urandom "of=$BIG_GENERATED_FILE" bs=1M count=200
```

This only resolves correctly if the script's cwd happens to be `packages/DataProtection`. Run with
a different cwd (repo root is the one the maintainer hit; it is also what a direct
`packages/DataProtection/tests/before-tests.sh` invocation from the top of the repo would do):

```
$ dd if=/dev/urandom of=./tests/Fixture/files/big-generated-file bs=1M count=200
dd: failed to open './tests/Fixture/files/big-generated-file': No such file or directory
```

The composer scripts that are supposed to run this (root `tests:phpunit`'s
`(cd packages/DataProtection && tests/before-tests.sh)` wrapper, and
`packages/DataProtection/composer.json`'s own `tests:phpunit`) both already `cd` (or start) in the
right place, so the documented `composer tests:ci`/`tests:phpunit` path does not hit this in our
testing — but the failure mode the maintainer's gate hit is real and dangerous regardless of the
exact invocation that triggered it: the script trusted an assumed cwd with no guard, so any
invocation that doesn't match it (a direct call, a future refactor of the composer script wrapper,
a different working directory in someone's IDE test runner) fails to create the fixture — and does
so far from where it's needed: four `DataProtection` tests then error later with no message
pointing back at this script, on the one kind of checkout (freshly cloned, fixture never
generated) that a returning developer or an established worktree never exercises again.

**Fixed**: rewrote the script to resolve its own directory instead of trusting the caller's cwd,
and to fail loudly and non-zero if `dd` fails, regardless of shell/invocation quirks:

```sh
#!/bin/sh
set -e

cd "$(dirname "$0")/.."

BIG_GENERATED_FILE=./tests/Fixture/files/big-generated-file
if [ ! -e "$BIG_GENERATED_FILE" ] || [ "$(wc -c < "$BIG_GENERATED_FILE")" -ne "209715200" ]; then
    echo "Please wait while I create a large random test plaintext file..."
    if ! dd if=/dev/urandom "of=$BIG_GENERATED_FILE" bs=1M count=200; then
        echo "Failed to create $(pwd)/$BIG_GENERATED_FILE" >&2
        exit 1
    fi
fi
```

Verified from repo root (previously the failing case):

```
$ ./packages/DataProtection/tests/before-tests.sh; echo "exit=$?"
Please wait while I create a large random test plaintext file...
200+0 records in
200+0 records out
209715200 bytes (210 MB, 200 MiB) copied, 0.49 s, 425 MB/s
exit=0
```

**Where fixture generation belongs**: left it exactly where it already runs — as a composer test
script (`tests:phpunit` at both root and per-package level), never as part of bootstrap. The
200MB write only costs ~0.5s on local disk, but it is a `/dev/urandom` read either way, so it
should only be paid by whoever actually runs the `DataProtection` test suite, not by every
`composer install`/`docker compose up`. This isn't tribal knowledge: nobody needs to know the
script exists, because the documented test command already runs it as a side effect. Grepped
`packages/*` for the same shape (another `before-tests`/setup script, or another bare relative
path) — `before-tests.sh` is the only script of its kind in the monorepo; nothing else needed the
same fix.

## 9. Per-package `composer tests:ci` needs its own `composer install` — and `packages/Ecotone` can't run fully isolated at all

`AGENTS.md`'s "Running Tests" section used to say `cd packages/PackageName && composer tests:ci`
with no `composer install` step. Each package under `packages/*` has its own `composer.json` and
does not get a local `vendor/` from the root install:

```
$ cd packages/Ecotone && composer tests:ci
sh: 1: vendor/bin/phpstan: not found
```

**Fixed the doc gap**: `AGENTS.md` now shows `composer install` as an explicit step before
`composer tests:ci`, and notes the root `vendor/` is a *separate* thing the annotation finder
always also needs.

Having fixed that, we hit a **pre-existing, out-of-scope** problem trying to prove a fully green
isolated run of `packages/Ecotone` itself: its own `phpstan.neon` only scans its own `src/`, but
`packages/Ecotone/src/Messaging/Config/ModuleClassList.php` has real `use` imports of classes that
live in *other* packages (`Ecotone\Sqs\...`, `Ecotone\EventSourcing\...`, etc.), intentionally
**not** required by `packages/Ecotone/composer.json` (optional modules, guarded at runtime).
Analyzed in true isolation this always reports ~36 `class.notFound` errors; analyzed from the
**repo root** (which scans all packages' `src/` together) it reports zero. This is an
architectural characteristic of `ModuleClassList`, not something a Docker/`.env`/Composer
bootstrap fix can address — flagging it rather than fixing it. We validated the isolated
per-package flow end-to-end on `packages/Redis` instead, which has no such cross-package static
references, to prove the *bootstrap* fixes are sufficient once this orthogonal issue doesn't
apply — see the Acceptance section below for the full transcript.

## Checked and found fine (no action needed)

- **`phpunit.xml` gitignored, `phpunit.xml.dist` committed** — PHPUnit auto-discovers the `.dist`
  file when `phpunit.xml` is absent; confirmed for both the root suite and `packages/Redis`. No gap.
- **`composer.lock` gitignored (`.gitignore:11`)** — deliberate for this monorepo: `packages/*` are
  path repositories split into independent, separately-versioned repos on release, and root-level
  dependency resolution doesn't represent what any single published package resolves to for a
  consumer. Not changed.

## Not investigated further (out of scope / not reproducible here)

- **True cold image-pull timing** — not measured; every image used in this task was already
  present in the local Docker cache from other worktrees running on the same host.
- **`git` inside the container for a worktree checkout** (gap 0) — real, but nothing in the
  documented workflow needs it, so left alone rather than engineered around.
