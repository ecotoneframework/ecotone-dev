# Ecotone demo

Two services talking over the Ecotone Distributed Bus on RabbitMQ, traced into Jaeger and monitored by Ecotone Pulse:

- **ticketing**, a Symfony 8 service (`demo/ticketing`)
- **storefront**, a Laravel 12 application (`demo/storefront`)

Both resolve `ecotone/*` from this repository's `packages/`, so they run the Ecotone you are working on.

## Run it

From the repository root:

```bash
docker compose -f demo/docker-compose.yml up -d --build
docker compose -f demo/docker-compose.yml exec storefront php artisan demo:ping
```

The first start installs each application's `vendor/` into the mounted checkout, so give it a few minutes; the stack is
ready when `docker compose -f demo/docker-compose.yml ps` shows every container healthy. `demo:ping` sends `demo.ping`
to ticketing over the Distributed Bus and prints `Reply arrived: pong from ticketing to storefront` once ticketing's
reply reaches the storefront (`--waitSeconds=10` waits longer).

| What | URL |
|---|---|
| Storefront | http://localhost:8000 |
| Ticketing | http://localhost:8001 |
| Ecotone Pulse | http://localhost:8080 |
| Jaeger | http://localhost:16686 |
| RabbitMQ management (guest / guest) | http://localhost:15672 |

Stop it with `docker compose -f demo/docker-compose.yml down` (add `-v` to drop the Postgres and RabbitMQ volumes as well).

## Ticketing

Ticketing owns the catalogue: venues and concerts are Doctrine ORM aggregates, managed at `/admin` on the ticketing
URL. Every start of the web container runs `bin/console demo:seed`, which creates Riverside Hall (rows A to F of 12
seats, rows A and B VIP) and three concerts once. Scheduling a concert, opening its sales and cancelling it is
announced to the storefront as `concert.scheduled`, `concert.sales_opened` and `concert.cancelled`: an event handler on
`ticketing_async` publishes each over the Distributed Bus after the command's transaction commits.

## Ports and parallel stacks

Every host port is an environment variable, and so is the stack's name:

```bash
COMPOSE_PROJECT_NAME=demo-two DEMO_STOREFRONT_PORT=9000 DEMO_TICKETING_PORT=9001 DEMO_PULSE_PORT=9080 \
DEMO_JAEGER_PORT=26686 DEMO_RABBITMQ_MANAGEMENT_PORT=25672 docker compose -f demo/docker-compose.yml up -d --build
```

The containers run as uid and gid 1000 so that `vendor/` stays owned by you; set `DEMO_UID` and `DEMO_GID` when your
user has other ids. The top of `docker-compose.yml` documents every variable.

## Licence

Distributed Bus, Pulse and flow tracking are Ecotone Enterprise features. Without a key the demo runs on Ecotone's
testing licence, and each container says so when it starts. To run on your own key:

```bash
ECOTONE_LICENCE_KEY=<your key> docker compose -f demo/docker-compose.yml up -d
```

## Working on Ecotone with the demo

Each application installs `ecotone/*` through a symlinked Composer path repository onto `../../packages/*`, with every
package pinned to `2.0.x-dev` in a generated `composer.pinned.json`. An edit under `packages/` is therefore live in the
web containers on the next request, without a rebuild. The workers are long-running `ecotone:run` processes: restart
them to load the edit (`docker compose -f demo/docker-compose.yml restart ticketing-worker storefront-worker`).

A change to a package's `composer.json` needs a fresh install: delete the application's `vendor/` and restart its
containers.

Pulse runs from your own `apps/Pulse` checkout, so the demo shares its `vendor/` and `var/` with the Pulse you develop
on the host. A `vendor/` you installed there differently, for example with `--copy` or other package versions, is the
one the demo runs, and each start of the `pulse` container clears `apps/Pulse/var/cache` on the host.

## Storefront

The storefront keeps customers and their orders as Eloquent aggregates, and a concert list fed by ticketing's
`concert.scheduled`, `concert.sales_opened` and `concert.cancelled` events. There is no login: register the demo
customers with `docker compose -f demo/docker-compose.yml exec storefront php artisan demo:seed` (safe to repeat), pick
one on `/whoami`, and browse `/concerts` and your orders on `/account`.

## Tests

Inside the containers, one application at a time:

```bash
docker compose -f demo/docker-compose.yml exec ticketing composer tests:ci
docker compose -f demo/docker-compose.yml exec storefront composer tests:ci
```

The tests use the `ticketing_test` and `storefront_test` databases of the demo's Postgres.
