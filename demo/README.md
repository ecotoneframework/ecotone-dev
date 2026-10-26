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

## Storefront checkout

Pick a customer on `/whoami`, open a concert that is on sale and click free seats: each click asks ticketing for a hold
with `tickets.hold` over the Distributed Bus, and the page polls `/orders/{orderId}/state.json` every second to mark the
seat yours (with a countdown to the hold's expiry), held by someone, or refused with ticketing's reason. Click one of
yours to release it. Every request to ticketing (`tickets.hold`, `tickets.release`, `tickets.confirm`) and every refund
is queued on `storefront_async`, the database queue in the same transaction as the order change behind it, so it leaves
only when that change commits. The `storefront-worker` container sends it within a fraction of a second, so a hold still
shows on the page's next poll; with the worker stopped, clicks wait in the queue. The checkout charges the card on
`storefront_async` through a payment stub: a card ending `0000` is declined and the order fails, one ending `9999` hits
a provider outage on its first attempt, so the charge lands in the dead letters until you replay it (`php artisan
ecotone:deadletter:replay <messageId>` in the storefront). Only transient database errors are retried on the spot, so a
provider outage reaches the dead letters instead of being retried away, and a payment reference is charged once however
often its charge runs. A paid order sends `tickets.confirm`; `tickets.issued` shows the tickets with their entry codes
on the order page and emails them, and the email to Linus Bounce (`@bounce.example`) always lands in the dead letters.

## Simulate a rush

The page of a concert on sale, and the page of an order for it, has a "Simulate a rush" panel: it sends a crowd of
synthetic buyers after the seats while you pick yours (the same from the console: `docker compose -f
demo/docker-compose.yml exec storefront php artisan demo:rush <concertId> --buyers=30 --declinedShare=30
--outageShare=10 --bounceShare=10 --seatsPerBuyer=2`). The whole rush is one `AdvanceRush` message on
`storefront_async`, sent again every 2 seconds (every 5 while every buyer waits on ticketing): each tick lets 20 more
buyers join and moves up to 10 buyers one step, the buyers that first asked ticketing earliest before the ones yet to
ask, so the crowd's holds and charges never queue far ahead of yours. A buyer starts an order and asks ticketing for
free seats one at a time, exactly as the seat picker does, tries another seat when one is refused (three refusals and it
gives up), and pays once its seats are held: the declined share with a card ending `0000`, the outage share with one
ending `9999`, the bounce share with an `@bounce.example` address. A buyer whose seats are not held 120 seconds after it
asked ticketing for the first one gives up and fails its order (`RUSH_BUYER_DEADLINE_SECONDS`), and a rush lets every
buyer still picking go 600 seconds after it started (`RUSH_DEADLINE_SECONDS`), so a rush ends on its own even when
ticketing never answers. The
status line under the panel polls `/rushes/{rushId}/state.json` every second, stops once every buyer has had a go,
and counts the buyers created and then each buyer once, at the stage its order is at now: held (seats held, not yet
paying), paying, paid, issued (tickets issued), failed (declined or refused by ticketing) and gave up. What to watch:

- the seat map greys out the seats the crowd holds, so your own clicks start to meet "someone was faster"
- Ecotone Pulse lists the dead letters by exception: `PaymentProviderUnavailable` for the outage share (replay them and
  the orders are paid) and `EmailBounced` for the bounce share once ticketing issues their tickets
- Jaeger traces the storefront's side of the crowd (each `RushSimulator::advance` tick, the `tickets.hold` requests
  and the charges), and ticketing's traces show where its seat conflicts refused a hold

Rush buyers are synthetic customers: they are not listed on `/whoami`, so their account pages are not browsable. A rush
is safe to replay from the dead letters; a tick handled twice is skipped, and a buyer that already joined is not
created twice. A tick in the dead letters pauses its rush until it is replayed.

## Tests

Inside the containers, one application at a time:

```bash
docker compose -f demo/docker-compose.yml exec ticketing composer tests:ci
docker compose -f demo/docker-compose.yml exec storefront composer tests:ci
```

The tests use the `ticketing_test` and `storefront_test` databases of the demo's Postgres.
