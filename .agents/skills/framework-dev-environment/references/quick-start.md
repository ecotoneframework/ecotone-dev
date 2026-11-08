## Quick Start

```bash
# Bootstrap a fresh checkout — two commands, no .env to create, no flags to remember
docker compose up -d
docker compose exec app composer install

# Run one package's full test suite — inside the container, one package at a time, never in parallel
docker compose exec app bash -lc "cd packages/PackageName && composer install && composer tests:ci"

# Run one test — --no-coverage, or PHPUnit 12's coverage requirement aborts the run
docker compose exec -T app vendor/bin/phpunit --no-coverage --filter test_method_name

# php-cs-fixer runs ON THE HOST, never in the container: git is unavailable there, so the
# fixer loses its file filter and rewrites the whole repository
vendor/bin/php-cs-fixer fix
```

Do not pass `-u root` to `composer` or the test scripts — it leaves `vendor/` root-owned on the host.
[the dev workflow](workflow.md) has the rest;
[cold-start-findings.md](cold-start-findings.md) lists the gaps a fresh checkout used to hit.

## Development Environment

```bash
# Start all containers — waits for every dependency's healthcheck before app/app8_2 start
docker compose up -d

# Install root dependencies
docker compose exec app composer install

# Enter a dev container — app is PHP 8.5.3, app8_2 is the PHP 8.2 floor
docker compose exec app /bin/bash
docker compose exec app8_2 /bin/bash
```

`docker compose up -d` builds a local image with `ext-sockets` baked in for the `app` service the
first time it runs — the published `simplycodedsoftware/php:8.5.3` image doesn't have it — and
blocks until every database/broker dependency reports healthy, so there is nothing to wait for
manually afterwards. `.env` is optional: it is only read if present (`env_file: required: false`),
so nothing needs to be created for a fresh checkout; copy `.env.dist` to `.env` only if you want to
override a default (e.g. turn Xdebug on).

The root `vendor/` is separate from each package's own `vendor/`, but it is never unused: Ecotone's
annotation finder always loads the monorepo root `vendor/autoload.php`, so keep the root install in sync
even when you only intend to run one package's tests.
