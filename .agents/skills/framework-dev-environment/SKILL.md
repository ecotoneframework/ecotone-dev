---
name: framework-dev-environment
description: >-
  Ecotone monorepo mechanics: docker compose containers, running one package's suite or
  one test, database DSNs, phpstan, licence headers, php-cs-fixer, and what CI runs. Use
  when running or debugging tests, installing dependencies, running static analysis or
  code style, or when a CI check is red in the ecotone-dev repository.
---

# Ecotone dev environment

## Applies when

- Running a test, a package suite, phpstan, the licence check or php-cs-fixer
- Installing dependencies, starting containers, choosing a database engine
- A CI check is red, flaky, or an install step failed

## Rules

### 21. Tests run inside the container, one package at a time
The packages share the compose services, so a parallel run (including two runs of the same suite) produces false
failures. Start with `docker compose up -d` and `docker compose exec app composer install`.
- **Expected:** the agent runs `docker compose exec app ...` for tests, one package suite at a time, never two
  suites concurrently and never phpunit on the host.
- **Verified by:** `eval:run-tests`

### E1. A single test runs with `--no-coverage`
`docker compose exec -T app vendor/bin/phpunit --no-coverage --filter test_method_name` — a plain run trips
PHPUnit 12's coverage requirement. A package's full suite is
`docker compose exec app bash -lc "cd packages/<Name> && composer install && composer tests:ci"`.
- **Expected:** a single-test command includes `--no-coverage`; a package suite installs that package's own
  `vendor/` first.
- **Verified by:** `eval:run-tests`

### E2. Never `-u root`
`composer` or a test script run with `-u root` leaves `vendor/` root-owned on the host.
- **Expected:** no suggested command passes `-u root`.
- **Verified by:** `eval:run-tests`

### 20. php-cs-fixer runs on the host
`git` is unavailable in the container, so the fixer loses its file filter and rewrites the whole repository. Run
`vendor/bin/php-cs-fixer fix` on the host (or in the container only with explicit `packages/...` paths and
`--config`).
- **Expected:** the agent runs php-cs-fixer on the host, or in the container only with explicit paths.
- **Verified by:** `eval:run-tests`

### E3. A failed check is reproduced, never rerun
A test that passes on a second run has a timing assumption, an order dependency or a shared resource in it.
Reproduce it (1 ms execution limit, `--order-by=reverse`, the job's engine and PHP version, the per-package
install Split Testing does), find the cause, push the fix. An install or infrastructure failure in CI is fixed in
the workflow, which is code in this repository.
- **Expected:** faced with a red CI check that passed locally, the agent proposes a reproduction and a fix, not a
  rerun.
- **Verified by:** `eval:red-ci-check`

### E4. phpstan's baseline only shrinks
phpstan is level 3 over `src` and `Api/`, over a baseline. A new error is fixed, never added to
`phpstan-baseline.neon`. A green phpstan does not prove a rename is complete: attribute arguments resolve lazily,
so grep the old FQCN.
- **Expected:** a new phpstan error is fixed in code; the baseline is not regenerated; a rename is followed by a
  grep for the old FQCN.
- **Verified by:** `eval:red-ci-check`

## References

- [references/quick-start.md](references/quick-start.md) — bootstrap commands, PHP 8.5 and 8.2 containers.
- [references/workflow.md](references/workflow.md) — every command, what `composer tests:ci` runs, root vs
  per-package parity, database DSNs, dependency ranges, CI jobs, *When a check is red*. Load when running anything.
- [references/tooling-and-landmines.md](references/tooling-and-landmines.md) — cs-fixer house rules, gates,
  landmines (rules 20, 21).
- [references/cold-start-findings.md](references/cold-start-findings.md) — gaps a fresh checkout used to hit.
  Load when bootstrapping a new worktree fails.
