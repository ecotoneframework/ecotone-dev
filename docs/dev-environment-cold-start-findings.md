# Cold-start findings: fresh checkout to running tests

This records a real cold-start of `dgafka/ecotone-2-0-work`, done in a throwaway clone
(`git clone` of this repo into `/tmp/.../cold-start`, no reuse of any existing worktree's
`.env`/`vendor`), following only what `AGENTS.md` and `CLAUDE.md` told a newcomer to do,
literally, in order. Every command and error below is a real transcript, not a summary.

The bootstrap gaps found here are fixed by `bin/setup` plus the `docker-compose.yml`,
`composer.json` and `.docker/php/Dockerfile` changes in this same change. Gaps that are
pre-existing code/architecture issues, not environment-bootstrap issues, are called out as
out of scope.

## 0. Git worktree checkouts: `git` is completely broken inside the containers

Found while bootstrapping *this task's own worktree* (an Orca-managed `git worktree`, not a
plain clone) — not part of the throwaway-clone transcript above, because a plain `git clone`
doesn't hit it. This is very likely part of why the two prior worktree-based coding-agent runs
that motivated this task failed invisibly: everything else (containers, composer, tests) works
fine, but `git` itself is unusable inside the container, which is exactly the kind of failure an
agent trusts without checking.

A worktree's `.git` is a **file**, not a directory, pointing at an absolute host path outside the
worktree itself:

```
$ cat .git
gitdir: /home/dgafka/development/simplycodedsoftware/ecotone-context/ecotone-dev/.git/worktrees/ecotone-2-0-dev-env-bootstrap
```

`docker-compose.yml` only bind-mounts the worktree directory itself (`"$PWD:/data/app"`), so that
target path does not exist inside the container at all:

```
$ docker compose exec -u root app git config --global --add safe.directory /data/app
fatal: not a git repository: /home/dgafka/development/simplycodedsoftware/ecotone-context/ecotone-dev/.git/worktrees/ecotone-2-0-dev-env-bootstrap
$ docker compose exec -u root app git status
fatal: not a git repository: /home/dgafka/development/simplycodedsoftware/ecotone-context/ecotone-dev/.git/worktrees/ecotone-2-0-dev-env-bootstrap
```

Every `git` command inside the container fails this way — not a warning, a hard `fatal`. `composer
install` and the test suites still work (the root version is pinned via `COMPOSER_ROOT_VERSION:
'dev-main'`, so composer never needs to shell out to git for version guessing at the root), so
this does not block reaching a green test run — but any agent that tries `git status`/`git diff`
from inside the container to check its own work hits a wall that looks like repo corruption.

**Fixed**: `docker-compose.yml` now also mounts `${GIT_COMMON_DIR:-$PWD/.git}` onto itself (same
absolute host path as source and destination) for both `app` and `app8_2`. `bin/setup` computes
and exports `GIT_COMMON_DIR` (via `git rev-parse --git-common-dir`, resolved to an absolute path)
before calling `docker compose up -d`. For a worktree, this mirrors the real, shared `.git`
directory (which contains `worktrees/<name>`) at the exact absolute path the worktree's `.git`
file already points to, so git resolves it correctly. For a plain clone, `GIT_COMMON_DIR`
defaults to `$PWD/.git`, which is already inside the primary mount — a harmless no-op. Verified
after the fix:

```
$ docker compose exec -u root app git status
fatal: detected dubious ownership in repository at '/data/app'
```

— now just the ordinary ownership warning from gap 4 below, not a hard failure. `bin/setup`
already runs the `safe.directory` fix, after which:

```
$ docker compose exec -u root app git status
On branch dgafka/ecotone-2-0-dev-env-bootstrap
Changes not staged for commit: ...
```

## 1. `docker compose up -d` fails: `.env` not found

`AGENTS.md` → Development Environment says to run:

```
docker compose up -d
```

On a fresh checkout this is the very first command and it fails outright:

```
$ docker compose -p coldstart479e up -d
env file /tmp/.../cold-start/.env not found: stat /tmp/.../cold-start/.env: no such file or directory
```

`.gitignore:18` ignores `.env`, but both `app` and `app8_2` in `docker-compose.yml` declared
`env_file: [".env"]` (required by default). No containers start, so nothing downstream is even
reachable — this is the single most expensive gap, because it blocks the very first command an
agent runs.

A tracked template, `.env.dist`, already exists with the real content (`XDEBUG_ENABLED="0"` and
`PHP_IDE_CONFIG="serverName=ecotone"`), but nothing copies it to `.env`, and no doc mentions it.

**Fixed**: `bin/setup` copies `.env.dist` → `.env` if missing (idempotent — never overwrites an
existing `.env`, so personal overrides survive). Belt-and-suspenders: `env_file` is now
`{path: .env, required: false}` for both `app` and `app8_2`, so even a bare `docker compose up -d`
run without `bin/setup` no longer hard-fails if `.env` is absent.

We deliberately did **not** commit `.env` directly and drop it from `.gitignore` — keeping it
gitignored means a developer's local overrides (e.g. turning Xdebug on) never show up as a git
diff or get committed by accident.

## 2. `ext-sockets` missing from the `app` (PHP 8.5.3) image only

After creating `.env`, `docker compose up -d` succeeds (containers start in under a second on
this host — image pull time couldn't be measured because the images were already cached from
other worktrees running concurrently on the same host).

`AGENTS.md` → Running Tests then jumps straight from entering the container to
`composer tests:ci`, **never mentioning `composer install`**. Following it literally:

```
$ docker compose exec -u root app composer tests:ci
sh: 1: vendor/bin/phpstan: not found
Script vendor/bin/phpstan handling the tests:phpstan event returned with error code 127
```

The natural next step — `composer install` — hits the documented ext-sockets problem:

```
$ docker compose exec -u root app composer install
...
  Problem 1
    - Root composer.json requires enqueue/amqp-lib ^0.10.25 -> ...
    - php-amqplib/php-amqplib[...] require ext-sockets * -> it is missing from your system.
Alternatively, you can run Composer with `--ignore-platform-req=ext-sockets` to temporarily
ignore these required extensions.
```

`--ignore-platform-req=ext-sockets` is real folklore: it works, but is written down nowhere in
this repo, so every fresh agent has to independently discover and choose to trust it.

We checked **`app8_2` (PHP 8.2.31) separately — it already has `ext-sockets` compiled in.**
Only the PHP 8.5.3 image lacks it. This narrows the fix to one image.

We also checked whether CI hits this: `.github/workflows/test-monorepo.yml` (the main CI job)
and `split-testing.yml` use `shivammathur/setup-php@v2` on GitHub-hosted Ubuntu runners, whose
default PHP builds already ship `ext-sockets`, so neither hits this problem — the two workflows
that do pass `--ignore-platform-reqs` (`benchmark-pr.yml`, `file-licence.yml`) do so for
unrelated reasons (no `ext-amqp`/`ext-rdkafka` on those runners at all). So this is purely a
local Docker dev-image gap, not something CI already solved for us.

**Decision**: extend the published image locally rather than making the flag official. We
verified the base image still has its build toolchain (`gcc`, `make`, `phpize`, Debian 12
bookworm) and that `docker-php-ext-install sockets` succeeds in seconds:

```
$ docker compose exec -u root app docker-php-ext-install sockets
...
Installing shared extensions: /usr/local/lib/php/extensions/no-debug-non-zts-20250925/
$ docker compose exec -u root app php -m | grep sockets
sockets
```

So `.docker/php/Dockerfile` extends `simplycodedsoftware/php:8.5.3` (`ARG BASE_IMAGE` +
`RUN docker-php-ext-install sockets`), wired into `docker-compose.yml` for the `app` service only
via a `build:` stanza, tagged as the new local name `ecotone/php:8.5.3-sockets` (deliberately
*not* reusing the upstream tag, so this doesn't clobber the shared image cache other worktrees on
this host rely on). `app8_2` is left pointing at the published image unchanged. This costs one
one-off local image build (a few seconds, sockets only) on first `up`, and after that
`composer install` needs no flag at all:

```
$ docker compose exec -u root app composer install
...
Generating autoload files
$ echo done in 33s, no --ignore-platform-req
```

## 3. Root `composer tests:phpunit` / `tests:ci` hits Composer's own process timeout

Not one of the two known gaps, but found while proving the fix: running the root suite
(`composer tests:phpunit`, and therefore `tests:ci`) killed itself at the 90% mark:

```
$ composer tests:phpunit
...
...........RR................................................ 3477 / 3856 ( 90%)
The following exception is caused by a process timeout
Check https://getcomposer.org/doc/06-config.md#process-timeout for details
In Process.php line 1205:
  The process "vendor/bin/phpunit --no-coverage" exceeded the timeout of 600 seconds.
```

Root `composer.json` already sets `"process-timeout": 600` explicitly (so this isn't the
Composer default of 300s biting silently) — the full ~3856-test root suite simply runs longer
than that. CI already works around this with `COMPOSER_PROCESS_TIMEOUT: 3600` in
`test-monorepo.yml`, but nothing sets that locally, so a developer/agent running the exact
command CI documents gets a false failure that looks like a hang, not a timeout misconfiguration.

**Fixed**: bumped `composer.json`'s `config.process-timeout` from `600` to `3600`, matching what
CI already needs. Low risk, no behavior change for anyone whose run already finished in under
600s.

## 4. `git`: "dubious ownership" warning on the first path-repo touch

While diagnosing gap 2, the very first `composer install` also printed this before getting to
the real error:

```
To add an exception for this directory, call:
  git config --global --add safe.directory /data/app
The repository at "/data/app/packages/Tempest/" does not have the correct ownership and git
refuses to use it:
fatal: detected dubious ownership in repository at '/data/app'
```

This is a **warning, not a hard failure** — installation continued and only actually failed
later on the real `ext-sockets` problem — but it reads exactly like a fatal error to anyone
scanning output for the word "fatal", and it is caused by the bind-mounted repo's host-uid not
matching the container user's expected uid (`user: "${USER_PID:-1000}:${USER_PID:-1000}"`),
which varies by host. It is real, but not deterministic across hosts/CI, so we did not change the
compose ownership model. We did make it disappear by default: `bin/setup` runs
`git config --global --add safe.directory /data/app` inside both `app` and `app8_2` once, up
front, so this red herring never shows up for anyone who used the documented entry point.

## 5. Per-package `composer tests:ci` needs its own `composer install` — and one package can't run fully isolated at all

`AGENTS.md`'s existing "Running Tests" section says:

```
cd packages/PackageName
composer tests:ci
```

with no `composer install` in between. On a fresh checkout (root vendor installed, but no
package-local vendor yet):

```
$ docker compose exec -u root -w /data/app/packages/Ecotone app composer tests:ci
sh: 1: vendor/bin/phpstan: not found
```

Each package under `packages/*` has its **own** `composer.json`, and does not get a local
`vendor/` from the root install — it needs its own `composer install` run inside the package
directory. We fixed the doc gap: `AGENTS.md` now shows `composer install` as an explicit step
before `composer tests:ci`, and notes that the root `vendor/` (from `bin/setup`) is a *separate*
thing the annotation finder always also needs, so neither install alone is enough on its own for
every workflow.

Having fixed that, we then hit a **pre-existing, out-of-scope** problem while trying to prove a
fully green isolated run of `packages/Ecotone` itself: its own `phpstan.neon` only scans its own
`src/`, but `packages/Ecotone/src/Messaging/Config/ModuleClassList.php` has real `use` imports of
classes that live in *other* packages (`Ecotone\Sqs\...`, `Ecotone\EventSourcing\...`,
`Ecotone\Laravel\...`, etc.) which are intentionally **not** required by
`packages/Ecotone/composer.json` (they're optional modules, guarded at runtime, not real
dependencies). Analyzed in true isolation this always reports ~36 `class.notFound` errors:

```
$ cd packages/Ecotone && composer install && composer tests:ci
...
179  Class Ecotone\Sqs\Configuration\SqsMessageConsumerModule not found.
184  Class Ecotone\EventSourcing\Config\EventSourcingModule not found.
...
[ERROR] Found 36 errors
```

Running PHPStan from the **repo root** (which scans all packages' `src/` together — see the
root `phpstan.neon`) reports **zero errors**, because the cross-package classes resolve when
everything is scanned together. This is an architectural characteristic of `ModuleClassList`
(the core package statically imports every optional module's entry-point class), not something a
Docker/`.env`/Composer bootstrap fix can address — it needs either a `phpstan.neon` change (e.g.
scanning sibling `src/` dirs, or an `ignoreErrors` rule) or a `ModuleClassList` redesign, both of
which are out of scope for this task. **We are flagging this rather than fixing it**: it means
the documented isolated-per-package flow does not currently produce a green PHPStan run for
`packages/Ecotone` specifically, whether or not the environment is bootstrapped correctly.

We validated the isolated per-package flow end-to-end on a package that has no such
cross-package static references, `packages/Redis`, to prove the *bootstrap* fixes are sufficient
once this orthogonal issue doesn't apply:

```
$ cd packages/Redis && composer install   # 34 packages, ~3s
$ composer tests:ci
 [OK] No errors                                                    (phpstan)
OK (15 tests, 28 assertions)                                        (phpunit)
```

## Checked and found fine (no action needed)

- **`phpunit.xml` gitignored, `phpunit.xml.dist` committed** — PHPUnit auto-discovers the
  `.dist` file when `phpunit.xml` is absent; confirmed working for both the root suite and
  `packages/Redis` (`Configuration: /data/app/packages/Redis/phpunit.xml.dist` in the output).
  No gap.
- **`packages/DataProtection/tests/before-tests.sh` discoverability** — already wired into the
  `tests:phpunit` composer script both at the root (`(cd packages/DataProtection && tests/before-tests.sh)`)
  and inside `packages/DataProtection/composer.json` itself. An agent running the documented
  `composer tests:ci`/`tests:phpunit` command never needs to know this script exists; it just
  runs. No gap.
- **`composer.lock` gitignored (`.gitignore:11`)** — deliberate for this monorepo: `packages/*`
  are path repositories that get split into independent, separately-versioned repos on release,
  and root-level dependency resolution doesn't represent what any single published package
  actually resolves to for a consumer. We did not change this.

## Not investigated further (out of scope / not reproducible here)

- **No `depends_on`/`healthcheck` gating in `docker-compose.yml`** — confirmed by grep: none of
  the 13 services declare a healthcheck or `depends_on`. On this host, containers were usable
  within ~1s of `up -d` returning (images were pre-cached from concurrent worktrees), so we could
  not reproduce a "service up but not ready" failure to confirm real impact. `bin/setup` waits
  for the default Postgres database (`pg_isready`) before running `composer install`, which
  covers the most common default-DSN case, but MySQL/MariaDB/Kafka/RabbitMQ/LocalStack/Redis are
  not waited on. If this turns into a real flake for a given package's tests, add a
  matching wait (or proper compose healthchecks) at that point — we did not do it preemptively
  for all 13 services since we could not observe the failure to size the fix.
- **True cold image-pull timing** — not measured; every image used in this task was already
  present in the local Docker cache from other worktrees running on the same host.
