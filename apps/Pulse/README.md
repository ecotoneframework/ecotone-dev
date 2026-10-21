# Ecotone Pulse

Ecotone Pulse is a dashboard for Ecotone services. Pulse asks every service for one snapshot over the
Distributed Bus, the service replies, and Pulse keeps the reply for 60 seconds in its own internal SQLite database.

Pulse is an **Ecotone Enterprise** application. It does not start without `ECOTONE_LICENCE_KEY`.

> **There is no authentication.** Anyone who can reach Pulse can see every service it manages. Run it behind your own
> network protection or ingress authentication (VPN, private network, reverse proxy with SSO).
>
> Every `POST` is refused with `403` when the browser's `Sec-Fetch-Site` is not `same-origin`/`none`, or its `Origin` host differs from the host Pulse is served on, so another site cannot make a visitor's browser replay or delete dead letters; requests carrying neither header (scripts) are served. When `Sec-Fetch-Site` is present it decides alone; `Origin` is compared with the request host only when it is absent. Behind a reverse proxy set `TRUSTED_PROXIES` (comma-separated proxy addresses or CIDRs, or `REMOTE_ADDR`) so Pulse reads `X-Forwarded-Host`/`-Proto`/`-Port`, and optionally `TRUSTED_HOSTS` (a host regular expression such as `^pulse\.example\.com$`; default: any).

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
(`bin/console ecotone:run <PULSE_CHANNEL>`) and the periodic refresh (`bin/console pulse:refresh`).

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
* `GET /healthz` answers `ok` without asking any service; the image `HEALTHCHECK` calls it. It says the web server is up; a consumer or refresh that dies takes the container down through the supervisor, so the health check does not need to watch them.

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

| Variable | Default | |
|---|---|---|
| `ECOTONE_LICENCE_KEY` | required | Ecotone Enterprise licence key |
| `PULSE_TRANSPORT_DSN` | required | The broker the services use. The scheme selects the transport (see below) |
| `PULSE_SERVICES` | required | Services to manage as `service_name:channel_name`, comma separated: `orders:orders_channel,billing:billing_channel`. The channel is the one the service consumes |
| `PULSE_SERVICE_NAME` | `ecotone_pulse` | Pulse's own service name. Services accept requests only from this name |
| `PULSE_CHANNEL` | `ecotone_pulse` | The channel Pulse consumes replies from |
| `PULSE_REPLY_TIMEOUT` | `10` | Seconds after which a request without reply is Unreachable |
| `PULSE_REFRESH_INTERVAL` | `20` | Seconds between the periodic snapshot requests. A whole number from 1 to 59, below the 60 second freshness limit |
| `APP_SECRET` | required in production | Symfony secret. Generate one with `openssl rand -hex 32` |

`PULSE_TRANSPORT_DSN` schemes:

| Scheme | Transport |
|---|---|
| `amqp://`, `amqps://` | RabbitMQ |
| `redis://` | Redis |
| `sqs:` | Amazon SQS |
| `kafka://` | Kafka. Several brokers: `kafka://one:9092,two:9092` |
| `pgsql://`, `mysql://`, `sqlite://` | Database (DBAL). The entrypoint creates the tables Pulse's own channels need on that database (the message queue and deduplication) before it starts, skipping existing tables. The services create the tables of their own channels |

## Connect a service

Every service Pulse manages needs Ecotone Enterprise and one `#[ServiceContext]`. The service must have a name, and its
`DistributedServiceMap` maps Pulse's service name (`PULSE_SERVICE_NAME`) to Pulse's channel (`PULSE_CHANNEL`), so the reply
reaches Pulse:

```php
use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
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
    public function pulseServiceMap(): DistributedServiceMap
    {
        return DistributedServiceMap::initialize()
            ->withCommandMapping(targetServiceName: 'ecotone_pulse', channelName: 'ecotone_pulse');
    }
}
```

The service consumes its own channel (`orders_channel` in the example above) like any asynchronous channel.
Pulse's side is `PULSE_SERVICES=orders:orders_channel`.

## Run it

One image, one container:

```bash
docker build -f apps/Pulse/Dockerfile -t ecotone-pulse .   # context is the repository root

docker run --env-file pulse.env -p 8080:8080 ecotone-pulse
```

`docker-compose.example.yml` runs that single service against RabbitMQ.

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
pages Pulse renders. Inside the repository's `app` container the `DATABASE_DSN` of the compose file is used.

The Mermaid library for the diagram views is installed with `bin/console importmap:install` (the Docker build does it), so
the running application never reaches out to a CDN.
