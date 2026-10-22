# Ecotone Pulse

Ecotone Pulse is a dashboard for Ecotone services. Pulse asks every service for one snapshot over the
Distributed Bus, the service replies, and Pulse keeps the reply for 60 seconds in its own internal SQLite database.

Pulse is an **Ecotone Enterprise** application. It does not start without `ECOTONE_LICENCE_KEY`.

> **There is no authentication.** Anyone who can reach Pulse can see every service it manages. Run it behind your own
> network protection or ingress authentication (VPN, private network, reverse proxy with SSO).
>
> Every `POST` is refused with `403` when the browser's `Sec-Fetch-Site` is not `same-origin`/`none`, or its `Origin` host differs from the host Pulse is served on, so another site cannot make a visitor's browser replay or delete dead letters; requests carrying neither header (scripts) are served. When `Sec-Fetch-Site` is present it decides alone; `Origin` is compared with the request host only when it is absent. Behind a reverse proxy set `TRUSTED_PROXIES` (comma-separated proxy addresses or CIDRs, or `REMOTE_ADDR`) so Pulse reads `X-Forwarded-Host`/`-Proto`/`-Port`, and optionally `TRUSTED_HOSTS` (a host regular expression such as `^pulse\.example\.com$`; default: any).

## Quick start (2 minutes)

You need Docker and an Ecotone Enterprise licence key (trial: https://ecotone.tech/pricing). From the repository root:

```bash
ECOTONE_LICENCE_KEY=<your key> docker compose -f apps/Pulse/docker-compose.example.yml --profile demo up --build
```

Open http://localhost:8080. No file is edited. After about 40 seconds the **Services** page shows `orders` and `billing`
as **Connected**; the demo services run a few orders in the background, so **Dead letters** holds failed messages you can
replay or delete, and **Traces** lists the flows of both services (`order.place`, `invoice.create`). Stop it with
`docker compose -f apps/Pulse/docker-compose.example.yml --profile demo down -v`.

To manage your own services, run Pulse with three settings (the broker of your services and their names):

```bash
docker build -f apps/Pulse/Dockerfile -t ecotone-pulse .     # from the repository root, once
docker run -p 8080:8080 --network <the network your broker is on> -e ECOTONE_LICENCE_KEY=<your key> \
    -e PULSE_TRANSPORT_DSN=amqp://guest:guest@rabbitmq:5672 -e PULSE_SERVICES=orders,billing ecotone-pulse
```

`rabbitmq` is the broker host as that network sees it; use the DSN your services use. Without `ECOTONE_LICENCE_KEY` the container stops with a message that names it, and `down` of the compose example works without it.

Then open **Connect** in the navigation (`/connect?service=orders`): it generates the `#[ServiceContext]` class for Ecotone Lite, Symfony
and Laravel with this Pulse's names and the channel builder of your transport, and the `PULSE_SERVICES` entry. A service that
does not answer shows **Unreachable**; its page (`/services/orders`) lists what `pulse:doctor` found (broker reachable, login,
channel) and the same snippet.

## How it works

```
 browser ──▶ Pulse (web) ──request + tracing id──▶ broker ──▶ service
                │                                              │ reply (ecotone.pulse.snapshot_received)
                │ waits up to 20s (TracingGateway)             ▼
                │                                            broker
 Pulse (consumer, same container) ◀── Pulse channel ──────────┘
                │
                └──▶ internal SQLite (snapshots, query cache, flow tracking)
```

One container runs everything: the web server ([FrankenPHP](https://frankenphp.dev)), the reply consumer
(`bin/console ecotone:run <PULSE_CHANNEL>`, one per connection) and the periodic refresh (`bin/console pulse:refresh`).

### Internal storage

Pulse keeps its own state (the latest snapshot of every service with its request bookkeeping, the cache of trace queries
and its flow tracking spans) in an **SQLite database inside the container**, at `/app/var/internal/pulse.sqlite`. It is
created when the container starts (`pulse:setup` creates the file and Pulse's tables, `ecotone:migration:database:setup --initialize`
creates the Ecotone tables, existing tables are skipped), it is **not a volume**, and it lives and dies with the container:
a restart of the same container keeps it, replacing the container (a redeploy) starts it empty, and the next page view
asks the services again. Do not mount a volume on `/app/var/internal`.

The database runs in WAL mode and waits up to 5 seconds for a lock held by another of the three processes; Pulse's own writes are
Doctrine transactions that take the write lock first, so they wait instead of failing with "database is locked".

The internal database is the default DBAL connection of Pulse. When `PULSE_TRANSPORT_DSN` is a database (`pgsql://`,
`mysql://`, `sqlite://`), the Distributed Bus channels use a separate connection, so the queue and deduplication tables
are created on that database and never in the internal one. Flow tracking in Pulse keeps its spans for 1 hour.

### Awaited replies

Every request a browser triggers is sent with a fresh tracing id and the request then waits for the reply that carries it
back, with `TracingGateway::isCompletedWithin($tracingId, Duration::seconds(20), destination: <Pulse's reply handler>)`:
the Refresh button, a dead letter replay or delete, a trace list or trace detail (query), and a page view that finds a
snapshot older than 60 seconds. The page renders the **fresh** result in the same response, with no Waiting state and no
auto-refresh. Several services share one 20 second budget: they are asked at the same time, each request has its own tracing id, and the
wait ends when every reply was processed, so a fast service never makes the page stop waiting for a slow one (a framework call that
names a destination counts it reached on the first processed reply, which is why Pulse never shares a tracing id between requests). A reply that has not arrived within 20 seconds renders as before: the service is Waiting, the page
refreshes itself until the reply is in, and after `PULSE_REPLY_TIMEOUT` it is Unreachable. The reply continues Pulse's
trace whether or not the service tracks its own flows.

* A page view sends a snapshot request to every service whose snapshot is older than 60 seconds. The check and the
  bookkeeping of the request run in one SQLite transaction before the request is sent, so concurrent page views send one
  request per service. A request that timed out is sent again by the next view; within 60 seconds of a Connected snapshot
  no new request is sent. While waiting for a reply, the page keeps showing the service's previous snapshot.
* A replay sends one more snapshot request about 5 seconds later (the replayed message may fail again): the next page view at or after that time sends it even when the last snapshot is younger than 60 seconds, and the page keeps refreshing until it is answered.
* The **replay target** of a dead letter is a `<select>` of the asynchronous channels the service reports in its snapshot's `channels`: each one is `{name, kind}`, kind `async` for a channel an `#[Asynchronous]` handler consumes and `queue` for a pollable channel no handler is bound to. The message goes through that channel with its routing slip, so once the channel is consumed the original handler runs; empty means the channel it was polled from. A filter replay offers the select only when a single service is chosen; a channel the service does not report is refused.
* Every `PULSE_REFRESH_INTERVAL` seconds (default 20) `pulse:refresh` requests a snapshot from every service, so pages normally open on a snapshot younger than the 60 second freshness limit without a page view triggering a request. A tick skips a service whose request is outstanding and has not yet passed `PULSE_REPLY_TIMEOUT`; it goes through the same transactional bookkeeping as page views, so the refresh and the web never double-request. A service whose request timed out is requested again by the first tick after the timeout and stays Unreachable (shown as "Unreachable — retrying", with its last seen time and last snapshot) until a reply arrives, so a dead service does not flip between Waiting and Unreachable.
  The refresh is a plain console loop rather than an Ecotone `#[Scheduled]` endpoint, because `ecotone:run` consumes one endpoint per process; its tick (`pulse:refresh --ticks=1`) runs in tests on the pinned clock. Ticks are scheduled on a deadline, so the cadence is `PULSE_REFRESH_INTERVAL` seconds, not the interval plus the tick's own run time.
* The container entrypoint is a **bash** script (`#!/bin/bash`; the image is Debian based and ships bash) that supervises the three processes with `wait -n`. The reply consumer stops at `PULSE_WORKER_TIME_LIMIT_MS` (default one hour) or `PULSE_WORKER_MEMORY_LIMIT` megabytes (default 256); the supervisor starts it again in the same container, and so it does the refresh loop, so the internal database is not lost at a limit. A process that exits cleanly within `PULSE_RESTART_MIN_UPTIME_SECONDS` (default 10) of starting is restarted after 1, 2, 4 ... up to 30 seconds, and after `PULSE_RESTART_MAX_RAPID` (default 5) such exits in a row the container stops with a log line naming the process. The container also exits when the web server stops or the consumer or the refresh exits with an error (non-zero), with that exit code, so run it with `restart: unless-stopped` or `always`. SIGTERM (`docker stop`) stops all three and exits 0.
* `GET /healthz` answers `ok` without asking any service: the web server is up. The image `HEALTHCHECK` calls it every 5 seconds (30 second start period), so the container is `healthy` a few seconds after it starts. A consumer or refresh that dies takes the container down through the supervisor.
* `GET /readyz` answers `200 {"status":"ready"}` or `503 {"status":"not_ready", "checks":[...]}` with one line per failing check and what to do about it: the internal SQLite database is writable, every reply consumer reported within the last `max(3 x PULSE_REFRESH_INTERVAL, 15)` seconds, and the refresh reported within `3 x PULSE_REFRESH_INTERVAL`. `pulse:refresh` records its own heartbeat at every tick and, in the same tick, one for each reply consumer the supervisor started that is still running and not stopped (it reads the process ids the supervisor wrote), both in the internal database; no extra process is started for it. Neither probe depends on the services being Connected. Use `/healthz` for liveness and `/readyz` for readiness (Kubernetes: `livenessProbe` and `readinessProbe`; `Deployment` with `replicas: 1`, `strategy: Recreate`, no volume).

Every service has a page, `/services/{name}`, with breadcrumbs: its health and last seen time, versions, dead letters, flow types
(linked to their traces) and a link to its slice of the flow graph. A snapshot carries `deadLetter.enabled` (`true` or `false`, from the
service's `DbalConfiguration`), so Pulse says "dead letter storage is enabled, none stored" for an empty store and "not configured" for a
service that does not store dead letters; a service older than this field is told apart by whether its snapshot has the `deadLetters` section.

Service status:

| Status | Meaning |
|---|---|
| Connected | A snapshot was received within the last 60 seconds |
| Waiting | A request is outstanding and `PULSE_REPLY_TIMEOUT` has not passed (also right after Refresh, showing the previous snapshot and its age) |
| Unreachable | The request is older than `PULSE_REPLY_TIMEOUT` with no reply, or it could not be sent (the reason is shown). While a new request is outstanding after that, it stays Unreachable and reads "Unreachable — retrying". The last seen time and last snapshot are kept |

The **Refresh** button on the Services page requests new snapshots regardless of their age.

## Traces

The **Traces** pages need flow tracking in the service (`FlowTrackingConfiguration::createWithDefaults()` returned from a
`#[ServiceContext]`). A service without it is listed as "flow tracking not enabled" and is never asked for traces.

The **Projections** page (`/services/{name}/projections`, per tenant) starts two operations after a confirmation: **Rebuild**
(reset and replay) and **Backfill** (catch up from where the projection stopped, `ecotone:projection:backfill`). It never
initializes a projection, because initialization can create tables and a request to the service runs inside a transaction:
the page shows "Initialized" or "Not initialized yet", and for a projection with `manualKickOff` the command to run,
`ecotone:projection:init <name>`, in the syntax of the service's framework. A projection without a state row is not offered
Rebuild or Backfill, and the service refuses them with the same instruction; one kicked off from the console and not run yet
is offered both. A projection without `manualKickOff` reads "Waiting for its first event". A streaming projection keeps no
position, so it is not offered Backfill. Hint boxes on the page explain these states and that a Rebuild or Backfill of a
projection that is not partitioned runs as one transaction with no live progress, so the service may show as Waiting or
Unreachable until it ends.
Each operation is one row in the service's own tracking table (category `ecotone-pulse`, type `projection-rebuild` or
`projection-backfill`), so its progress and outcome survive a restart of Pulse; rows of any other type are ignored. A running
rebuild or backfill shows as info on the home board.

* `/traces` lists the flow types of every service from the `tracing` section of its snapshot, with runs, runs with errors
  recorded, error rate, p50/p95/max entry duration over the latest runs, and the last run. A flow type is named by how the
  flow entered *that service*, so the same business flow has a different flow type in each service. A failed asynchronous
  step is not counted as an error: it is found among the dead letters.
* `/traces/{service}/{flowType}?page=N` pages the traces of one flow type, 25 per page, newest first.
* `/traces/trace/{traceId}` (also reached by the search box and by **View trace** on a dead letter) asks every Connected
  service with flow tracking for the spans of that trace id and merges them into one waterfall. A receiving service's spans
  are nested under the sender's `send` span, each row names its service and a hop to another service is marked. The queue
  wait of an asynchronous step or a hop (send end to consume start) is shown apart from the handler duration. A step sent
  and not processed yet is *pending*; a pending step whose message is among a service's dead letters is shown as *failed*
  with the dead letter's exception and a link to it. At most 300 steps are shown, and a service that cut its reply at 1000
  spans says so.
* `/flow?trace={traceId}` highlights the trace on the cross-service flow graph: passed nodes (green, with the longest
  duration of the step), pending steps (amber, dashed), failed steps (red) and the distributed command and event edges
  between services the trace crossed.

The queries use the Distributed Bus with a query id and a tracing id (`ecotone.pulse.traces.list` and `ecotone.pulse.traces.trace`, replies on
`ecotone.pulse.query_result_received`). The consumer stores each reply in the internal database under the service and the query; a reply
is reused for 60 seconds, the request waits up to 20 seconds for it, and a service that has not replied within
`PULSE_REPLY_TIMEOUT` is shown as not answering for that query.

## Configuration

Every setting is an environment variable, read when the container is built at start. **Changing a setting needs a restart** of the container: the entrypoint rebuilds the Symfony container from the current environment on every start. Do not mount `var/cache` as a volume. An invalid value is refused when the application boots, with a message naming
what is wrong and how to fix it.

Three values start Pulse:

```bash
docker run -p 8080:8080 \
  -e ECOTONE_LICENCE_KEY=... \
  -e PULSE_TRANSPORT_DSN=amqp://guest:guest@rabbitmq:5672 \
  -e PULSE_SERVICES=orders,billing \
  ecotone-pulse
```

| Variable | |
|---|---|
| `ECOTONE_LICENCE_KEY` | Ecotone Enterprise licence key (or `ECOTONE_LICENCE_KEY_FILE`, or a file at `/run/secrets/ecotone_licence_key`) |
| `PULSE_TRANSPORT_DSN` | The broker the services use, the default connection (or `PULSE_TRANSPORT_DSN_FILE`). The scheme selects the transport (see below) |
| `PULSE_SERVICES` | Services to manage, comma separated: `orders,billing`. See [Services and connections](#services-and-connections) for the other broker and queue name forms |

**Advanced**, all optional:

| Variable | Default | |
|---|---|---|
| `PULSE_CONNECTION_<NAME>_DSN` | | Another broker for some services, see below (also `_FILE`) |
| `PULSE_CONNECTION_<NAME>_REPLY_CHANNEL` | `PULSE_CHANNEL` | The destination Pulse reads that connection's replies from |
| `PULSE_VERSION` | `dev` (set by the Docker image's `PULSE_VERSION` build argument) | The version of this Pulse. Shown in the page markup, and the first time a browser opens Pulse for a version it sees a short splash, the Ecotone logo with a beating heart, before the dashboard (any click or key skips it; reduced motion shortens it). Required: Pulse refuses to start without it when run outside the image |
| `PULSE_SERVICE_NAME` | `ecotone_pulse` | Pulse's own service name. Services accept requests only from this name |
| `PULSE_CHANNEL` | `ecotone_pulse` | The channel Pulse consumes replies from |
| `PULSE_REPLY_TIMEOUT` | `10` | Seconds after which a request without reply is Unreachable |
| `PULSE_REFRESH_INTERVAL` | `20` | Seconds between the periodic snapshot requests. A whole number from 1 to 59, below the 60 second freshness limit |
| `PULSE_DOCTOR_BUDGET_SECONDS` | `8` | Seconds the service page (`/services/{name}`) of an Unreachable service spends on broker checks. Probes that do not fit are skipped and the page says so; `0` skips them all. |
| `PULSE_QUEUE_WARNING` | `1000` | Ready messages from which a channel shows a warning. A whole number, optionally with per-channel overrides: `orders:invoices=500,*=1000` |
| `PULSE_QUEUE_CRITICAL` | `10000` | Ready messages from which a channel shows as critical. Same form as `PULSE_QUEUE_WARNING`, and always above it |
| `PULSE_PROJECTION_STALLED_AFTER` | `300` | Seconds a projection that is behind may keep the same position across Pulse's snapshots before the projections page (`/services/{name}/projections`) calls it stalled. Whole number, `1` or more. |
| `PULSE_CHANNEL_STALLED_AFTER` | `300` | Seconds a channel may have messages ready without its ready count going down across Pulse's snapshots before the service page and the home board call it not consuming. Pulse derives this from its own stored snapshots; the service reports only which consumers are expected to run the channel. Whole number, `1` or more. |
| `PULSE_TRACKING_DAYS` | `7` | How many days back Pulse looks for tracked projection operations (rebuild, initialization, backfill), on the projections page (`/services/{name}/projections`) and in its recent operations list. Whole number from `1` to `365`. |
| `PULSE_HTTP_PORT` | `8080` | |
| `TRUSTED_PROXIES`, `TRUSTED_HOSTS` | | Behind a reverse proxy, see above |
| `APP_SECRET` (or `APP_SECRET_FILE`) | generated | Pulse signs nothing with it. When unset the entrypoint generates one for the lifetime of the container and logs one line saying so |

A file variable (`ECOTONE_LICENCE_KEY_FILE`, `PULSE_TRANSPORT_DSN_FILE`, `PULSE_CONNECTION_<NAME>_DSN_FILE`, `APP_SECRET_FILE`) holds the path of a file with the value, which is how Docker and Kubernetes secrets are mounted; the variable itself wins when both are set. A file that cannot be read stops the container with a message naming the variable.

`PULSE_TRANSPORT_DSN` schemes:

| Scheme | Transport |
|---|---|
| `amqp://`, `amqps://` | RabbitMQ |
| `redis://` | Redis, read with the `phpredis` extension the image ships (no `predis/predis`: the extension is already there, and the services' own client is independent of Pulse's) |
| `sqs:` | Amazon SQS |
| `kafka://` | Kafka. Several brokers: `kafka://one:9092,two:9092` |
| `pgsql://`, `mysql://`, `sqlite://` | Database (DBAL). The entrypoint creates the tables Pulse's own channels need on that database (the message queue and deduplication) before it starts, skipping existing tables. The services create the tables of their own channels |

### Services and connections

Every entry of `PULSE_SERVICES` is `service[:channel][#destination][@connection][{tenants=a,b}]`:

| Part | Meaning | Default |
|---|---|---|
| `service` | The service's name (its `ServiceConfiguration` service name) | |
| `:channel` | The Ecotone channel name Pulse sends the request to | the service name |
| `#destination` | The actual queue (AMQP) or topic (Kafka) when it differs from the channel name | the channel name |
| `@connection` | A connection defined with `PULSE_CONNECTION_<NAME>_DSN` | the default connection, `PULSE_TRANSPORT_DSN` |
| `{tenants=a,b}` | Narrows a multi-tenant service to these tenants. Pulse needs no tenant configuration: the service advertises its tenants. A tenant named here that the service does not advertise is shown as *not advertised by the service* and never asked for | every tenant the service advertises |

```
PULSE_SERVICES=orders,billing:billing_channel@legacy,shipping:shipping#shipping_queue
PULSE_CONNECTION_LEGACY_DSN=pgsql://user:secret@legacy-db:5432/db
```

`orders` is channel `orders` on the default connection; `billing` is channel `billing_channel` on the `legacy` connection; `shipping` is channel `shipping` whose AMQP queue is `shipping_queue`. A destination takes the characters the broker allows, so `legacy:orders/high` and `orders.v1-new` work; put it in double quotes when it contains a comma or an `@` (`orders#"a,b@c"`). A Kafka topic must use only letters, digits, `.`, `_` and `-` (up to 249 characters) and an AMQP queue name is at most 255 bytes; anything else is refused at start. A destination can only be named on a transport that has one apart from the channel name (AMQP, Kafka); on Redis, SQS and the database the channel is the queue, so name it `shipping:shipping_queue`.

A multi-tenant service (one with a `MultiTenantConfiguration`) is monitored per tenant: Pulse asks it for its tenants first, then for each tenant separately, and the dead letters, traces and every replay, delete or trace query reach the database of that tenant. The service must keep the Pulse channels off its multi-tenant connection: a DBAL Pulse channel on a dedicated connection reference, or a broker transport. A single-tenant service shows no tenant interface.

Pulse sends requests only to a destination that already exists, and it never creates one: a service that has not started its consumer is shown as *channel missing* with the destination it looked for. On Kafka the topic has to exist before Pulse asks (the broker creates it when the service, or anything else, produces to it or a topic administrator creates it); a consumer alone does not create it.

Pulse reads replies on **every** connection, one reply consumer per connection, all started by the supervisor: a service replies through the reply channel its `PulseConfiguration` derives on its own broker. The reply channel is `PULSE_CHANNEL` on each connection. A channel name is registered once, so a second connection on a transport where the channel is the queue (Redis, SQS, database) cannot also be called `PULSE_CHANNEL`: Pulse refuses it at start, naming `PULSE_CONNECTION_<NAME>_REPLY_CHANNEL`, which gives that connection another reply channel; configure its services with `PulseConfiguration::withReplyChannel(...)` to match. On AMQP and Kafka the destination stays `PULSE_CHANNEL` and only the Ecotone channel name is made unique. At most one connection can be Kafka.

A mistake in these entries (an unknown connection, a malformed entry, a service listed twice, two services on one channel or one destination) is refused at start with a message that names the entry and the fix.

### Doctor

`bin/console pulse:doctor` checks, and says what to do about every failure in one line: the settings, for each connection the host and port (with the milliseconds it took), the broker login, and for each service that its queue or topic exists (on Redis and the database it cannot be detected, so it says that and shows the configured name), the internal database, the licence and which reply channel Pulse reads. It exits `0` when everything passed and `1` otherwise; `--format=json` prints the same checks as `{"ok": bool, "checks": [{"name", "status": "ok|info|fail", "message", "fix"}]}`.

The entrypoint prints a three line banner (transport, services, UI port and health paths) and runs the doctor at every start. It never blocks: a service that is down is what Pulse exists to show, and a wrong DSN is in the doctor output right above the first request error.

## Connect a service

Every service Pulse manages needs Ecotone Enterprise, a service name, and one `PulseConfiguration`. The reply mapping to
Pulse is implied, and so is the reply channel: the service replies to a channel named after the Pulse service
(`PULSE_SERVICE_NAME`, `ecotone_pulse` by default), registered for it with the same builder class and connection as the
channel Pulse sends its requests to, which is the channel named after the service (`orders` below). Only the builder
class and the connection are followed, never a queue name, topic or table, so the reply channel is exactly what
`Builder::create('ecotone_pulse', sameConnection)` would register:

```php
use Ecotone\Api\Amqp\AmqpBackedMessageChannelBuilder;
use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Pulse\PulseConfiguration;

final class PulseSetup
{
    #[ServiceContext]
    public function serviceName(): ServiceConfiguration
    {
        return ServiceConfiguration::createWithDefaults()->withServiceName('orders');
    }

    #[ServiceContext]
    public function pulse(): PulseConfiguration
    {
        return PulseConfiguration::createWithDefaults(pulseServiceName: 'ecotone_pulse');
    }

    #[ServiceContext]
    public function channels(): array
    {
        return [
            AmqpBackedMessageChannelBuilder::create('orders'),
        ];
    }
}
```

The service consumes its request channel (`orders`, named after the service) like any asynchronous channel, and Pulse's
side is `PULSE_SERVICES=orders`. Use another channel name with `PULSE_SERVICES=orders:orders_channel` and
`PulseConfiguration::createWithDefaults(...)->withRequestChannel('orders_channel')`. Use another reply channel with
`->withReplyChannel('pulse_replies')`, and set `PULSE_CHANNEL` to match. A reply channel you register yourself wins and is
not registered twice. Bootstrap is refused, naming `withRequestChannel` and the builder to add, when the request channel is
not registered, and for a Kafka request channel (its reply channel is registered explicitly with
`KafkaMessageChannelBuilder::create('ecotone_pulse')`). A `DistributedServiceMap` that already maps the Pulse service keeps
working; a mapping that contradicts `withReplyChannel` is refused at bootstrap.

A request channel is shared with anything else that reads it, because Pulse's requests queue up behind the messages
already on it. Give the service a dedicated, mostly empty request channel for Pulse and consume it with a poller of its own.

## Run it

One image, one container:

```bash
docker build -f apps/Pulse/Dockerfile -t ecotone-pulse .   # context is the repository root

docker run --env-file pulse.env -p 8080:8080 ecotone-pulse
```

`docker-compose.example.yml` has two profiles: `demo` (Pulse, a PostgreSQL database and two demo services, see the quick start) and `amqp` (Pulse and RabbitMQ, for your own services). `apps/Pulse/docker/demo-smoke.sh` runs the demo profile and checks it.

* HTTP is served on port `8080` (`PULSE_HTTP_PORT`).
* With a missing or invalid setting the container exits at start with the refusal message.
* The web server is [FrankenPHP](https://frankenphp.dev) (Caddy and PHP in one process): no php-fpm and nginx pair to keep in step, and HTTP/2 and compression included. Pulse needs no worker mode, so it is plain `php_server`.
* Run it behind your own authentication and with `restart: unless-stopped`. The internal database is gone when the container is replaced; that is intended.

## Development

```bash
cd apps/Pulse
composer update          # resolves ecotone/* from ../../packages through path repositories
composer tests:ci        # phpstan + phpunit
```

The tests boot Pulse as a Symfony application next to real Ecotone services, over a shared database channel, and read the
pages Pulse renders. Inside the repository's `app` container the `DATABASE_DSN` of the compose file is used. The tests need PostgreSQL: they reset a dedicated database per test, so `DATABASE_DSN` must be a `pgsql://` DSN such as `pgsql://ecotone:secret@127.0.0.1:5432/ecotone?serverVersion=16`.

The Mermaid library for the diagram views is installed with `bin/console importmap:install` (the Docker build does it), so
the running application never reaches out to a CDN.

### Diagram and accessibility checks

Two checks need a browser engine, so they run on the host (Node 22 in CI; 20 or newer works), not in the PHP container.

* `tests/Mermaid` parses with the real Mermaid parser every diagram the test suite renders (`/flow`, `/flow?trace=…`, `/topology`).
  The PHP tests write the diagrams to `var/mermaid`; then `cd tests/Mermaid && npm ci && node parse.mjs ../../var/mermaid`.
  CI runs it after `composer tests:ci`. A diagram whose `subgraph` is not closed passes every text assertion and still renders as
  "Syntax error in text", which is why it is parsed rather than matched.
* `tests/Accessibility` crawls a running Pulse from its navigation, then runs axe-core on every page (two per page kind), in light
  and dark, at 1440px and 390px, and fails on a serious or critical violation or a horizontal page scroll:
  `cd tests/Accessibility && npm ci && node axe.mjs http://localhost:8080` (`CHROME_PATH` selects the Chrome binary). It also audits the
  first-visit splash. `PULSE_EXPECTED_VERSION=<the running PULSE_VERSION> node intro.mjs http://localhost:8080` checks the splash itself:
  shown on the first visit, gone on reload, shown again for a new version, skippable, reduced motion and unavailable storage respected.
