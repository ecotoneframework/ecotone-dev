# Ecotone Pulse

Ecotone Pulse is a dashboard for Ecotone services. It keeps no database: Pulse asks every service for one snapshot over the
Distributed Bus, the service replies, and Pulse caches the reply for 60 seconds.

Pulse is an **Ecotone Enterprise** application. It does not start without `ECOTONE_LICENCE_KEY`.

> **There is no authentication.** Anyone who can reach Pulse can see every service it manages. Run it behind your own
> network protection or ingress authentication (VPN, private network, reverse proxy with SSO).
>
> Every `POST` is refused with `403` when the browser's `Sec-Fetch-Site` is not `same-origin`/`none`, or its `Origin` host differs from the host Pulse is served on, so another site cannot make a visitor's browser replay or delete dead letters; requests carrying neither header (scripts) are served. When `Sec-Fetch-Site` is present it decides alone; `Origin` is compared with the request host only when it is absent. Behind a reverse proxy set `TRUSTED_PROXIES` (comma-separated proxy addresses or CIDRs, or `REMOTE_ADDR`) so Pulse reads `X-Forwarded-Host`/`-Proto`/`-Port`, and optionally `TRUSTED_HOSTS` (a host regular expression such as `^pulse\.example\.com$`; default: any).

## How it works

```
 web  ──snapshot request──▶  broker  ──▶  service
 web  ◀── page from cache ─┐              │ reply (ecotone.pulse.snapshot_received)
                           │              ▼
 worker ◀── Pulse channel ─┴───────────  broker
 worker ──▶ filesystem cache (PULSE_CACHE_DIR, shared with web)
```

* **web** renders pages. A page view sends a snapshot request to every service whose cached snapshot is older than 60 seconds
  and optionally waits up to `PULSE_RENDER_WAIT` seconds for the replies. The check and the bookkeeping of the request
  run under a lock (Symfony Lock, file locks in `PULSE_CACHE_DIR/locks`) before the request is sent, so concurrent page views
  send one request per service. A request that timed out is sent again by the next view; within 60 seconds of a Connected
  snapshot no new request is sent. While waiting for a reply, the page keeps showing the service's previous snapshot.
  The wait observes replies in the cache; it does not depend on the application clock.
* A replay sends one more snapshot request about 5 seconds later (the replayed message may fail again): the next page view at or after that time sends it even when the last snapshot is younger than 60 seconds, and the page keeps refreshing until it is answered.
* The **replay target** of a dead letter is a `<select>` of the asynchronous channels the service reports in its snapshot's `channels`: each one is `{name, kind}`, kind `async` for a channel an `#[Asynchronous]` handler consumes and `queue` for a pollable channel no handler is bound to. The message goes through that channel with its routing slip, so once the channel is consumed the original handler runs; empty means the channel it was polled from. A filter replay offers the select only when a single service is chosen; a channel the service does not report is refused.
* **worker** runs two processes that stop together: `bin/console ecotone:run <PULSE_CHANNEL>`, which consumes the services' replies and writes them to the cache, and `bin/console pulse:refresh`, which every `PULSE_REFRESH_INTERVAL` seconds (default 20) requests a snapshot from every service, so pages normally open on a snapshot younger than the 60 second freshness limit without a page view triggering a request. A tick skips a service whose request is outstanding and has not yet passed `PULSE_REPLY_TIMEOUT`; it goes through the same locked bookkeeping as page views, so the worker and the web never double-request. A service whose request timed out is requested again by the first tick after the timeout and stays Unreachable (shown as "Unreachable — retrying", with its last seen time and last snapshot) until a reply arrives, so a dead service does not flip between Waiting and Unreachable.
  The refresh is a plain console loop rather than an Ecotone `#[Scheduled]` endpoint. A scheduled endpoint would not remove the second process, because `ecotone:run` consumes one endpoint per process, so the worker would be supervised either way; the loop is a small command whose tick (`pulse:refresh --ticks=1`) runs in tests on the pinned clock, and it needs no extra channel or consumer. Ticks are scheduled on a deadline, so the cadence is `PULSE_REFRESH_INTERVAL` seconds, not the interval plus the tick's own run time.
  The worker entrypoint is a **bash** script (`#!/bin/bash`; the image is Debian based and ships bash), because it supervises both processes with `wait -n`: when either exits, it stops the other and the container exits with the exit code of the one that exited first, so run the worker with `restart: unless-stopped` or `always` (also after a clean exit at the time or memory limit). SIGTERM (`docker stop`) stops both and exits 0.
* Web and worker **must share `PULSE_CACHE_DIR`** (a volume).

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

The queries use the Distributed Bus with a query id (`ecotone.pulse.traces.list` and `ecotone.pulse.traces.trace`, replies on
`ecotone.pulse.query_result_received`). The worker stores each reply in the cache under the service and the query; a reply
is reused for 60 seconds, while it is awaited the page refreshes itself, and a service that has not replied within
`PULSE_REPLY_TIMEOUT` is shown as not answering for that query.

## Configuration

Every setting is an environment variable, read when the container is built at start. **Changing a setting needs a restart** of `web` and `worker`: the entrypoint rebuilds the container from the current environment on every start, and the cache directory of the container is keyed by the settings, so a container built for other settings is never reused. Do not mount `var/cache` as a shared volume. An invalid value is refused when the application boots, with a message naming
what is wrong and how to fix it.

| Variable | Default | |
|---|---|---|
| `ECOTONE_LICENCE_KEY` | required | Ecotone Enterprise licence key |
| `PULSE_TRANSPORT_DSN` | required | The broker the services use. The scheme selects the transport (see below) |
| `PULSE_SERVICES` | required | Services to manage as `service_name:channel_name`, comma separated: `orders:orders_channel,billing:billing_channel`. The channel is the one the service consumes |
| `PULSE_SERVICE_NAME` | `ecotone_pulse` | Pulse's own service name. Services accept requests only from this name |
| `PULSE_CHANNEL` | `ecotone_pulse` | The channel Pulse consumes replies from |
| `PULSE_REPLY_TIMEOUT` | `10` | Seconds after which a request without reply is Unreachable |
| `PULSE_REFRESH_INTERVAL` | `20` | Seconds between the worker's snapshot requests. A whole number from 1 to 59, below the 60 second freshness limit |
| `PULSE_RENDER_WAIT` | `2` | Seconds a page waits for replies before rendering. `0` renders immediately |
| `PULSE_CACHE_DIR` | `var/pulse-cache` | Cache directory shared by `web` and `worker` |
| `APP_SECRET` | required in production | Symfony secret. Generate one with `openssl rand -hex 32` |

`PULSE_TRANSPORT_DSN` schemes:

| Scheme | Transport |
|---|---|
| `amqp://`, `amqps://` | RabbitMQ |
| `redis://` | Redis |
| `sqs:` | Amazon SQS |
| `kafka://` | Kafka. Several brokers: `kafka://one:9092,two:9092` |
| `pgsql://`, `mysql://`, `sqlite://` | Database (DBAL). The entrypoint creates Pulse's own message table before `web` and `worker` start (`ecotone:migration:database:setup --feature=message_queue --initialize --missing`, existing tables are skipped). The services create the tables of their own channels |

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

One image, two roles chosen by the command:

```bash
docker build -f apps/Pulse/Dockerfile -t ecotone-pulse .   # context is the repository root

docker run --env-file pulse.env -p 8080:8080 -v pulse-cache:/app/var/pulse-cache ecotone-pulse web
docker run --env-file pulse.env -v pulse-cache:/app/var/pulse-cache ecotone-pulse worker
```

`docker-compose.example.yml` runs both roles (the worker role also runs the periodic refresh) with a shared cache volume against RabbitMQ.

* `web` serves HTTP on port `8080` (`PULSE_HTTP_PORT`).
* `worker` stops after `PULSE_WORKER_TIME_LIMIT_MS` (default one hour) or when it reaches `PULSE_WORKER_MEMORY_LIMIT`
  megabytes (default 256) and exits cleanly, so run it with a restart policy (`restart: unless-stopped`).
* With a missing or invalid setting both roles exit at start with the refusal message.

The web role is [FrankenPHP](https://frankenphp.dev) (Caddy and PHP in one process): one binary and one container for the web
role, no php-fpm and nginx pair to keep in step, and HTTP/2 and compression included. Pulse needs no worker mode, so it is
plain `php_server`.

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

### Diagram and accessibility checks

Two checks need a browser engine, so they run on the host (Node 22 in CI; 20 or newer works), not in the PHP container.

* `tests/Mermaid` parses with the real Mermaid parser every diagram the test suite renders (`/flow`, `/flow?trace=…`, `/topology`).
  The PHP tests write the diagrams to `var/mermaid`; then `cd tests/Mermaid && npm ci && node parse.mjs ../../var/mermaid`.
  CI runs it after `composer tests:ci`. A diagram whose `subgraph` is not closed passes every text assertion and still renders as
  "Syntax error in text", which is why it is parsed rather than matched.
* `tests/Accessibility` crawls a running Pulse from its navigation, then runs axe-core on every page (two per page kind), in light
  and dark, at 1440px and 390px, and fails on a serious or critical violation or a horizontal page scroll:
  `cd tests/Accessibility && npm ci && node axe.mjs http://localhost:8080` (`CHROME_PATH` selects the Chrome binary).
