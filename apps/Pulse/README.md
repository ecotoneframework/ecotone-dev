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
* The **replay target** of a dead letter is a `<select>` of the handler input channels the service reports in its snapshot's `channels` (the explicit routing keys of its `#[CommandHandler]`s, aggregate ones included, and the input channels of its `#[InternalHandler]`s; handlers of the framework's own `Ecotone\` namespace are left out). A command handler routed by its command class has no channel to replay to, so it is not offered, empty meaning the original channel. The message runs that handler; an `#[Asynchronous]` handler runs once its channel is consumed. A filter replay offers the select only when a single service is chosen; a channel the service does not report is refused. Asynchronous queues are not offered: the dead letter refuses to replay to them.
* **worker** runs two processes that stop together: `bin/console ecotone:run <PULSE_CHANNEL>`, which consumes the services' replies and writes them to the cache, and `bin/console pulse:refresh`, which every `PULSE_REFRESH_INTERVAL` seconds (default 20) requests a snapshot from every service, so pages normally open on a snapshot younger than the 60 second freshness limit without a page view triggering a request. A tick skips a service whose request is outstanding and has not yet passed `PULSE_REPLY_TIMEOUT`; it goes through the same locked bookkeeping as page views, so the worker and the web never double-request. A service that never answers is requested again by the first tick after the timeout, so it shows Unreachable for the rest of each interval and Waiting right after a tick.
  The refresh is a plain console loop and not an Ecotone `#[Scheduled]` endpoint: the interval is a deployment setting and an attribute argument is a constant, the loop needs no extra channel or consumer, and `pulse:refresh --once` is one tick for tests and cron. The entrypoint supervises both processes: when either exits (for example `ecotone:run` reaching its time or memory limit) it stops the other and the container exits, so a restart policy brings both back; SIGTERM stops both cleanly.
* Web and worker **must share `PULSE_CACHE_DIR`** (a volume).

Service status:

| Status | Meaning |
|---|---|
| Connected | A snapshot was received within the last 60 seconds |
| Waiting | A request is outstanding and `PULSE_REPLY_TIMEOUT` has not passed (also right after Refresh, showing the previous snapshot and its age) |
| Unreachable | The request is older than `PULSE_REPLY_TIMEOUT` with no reply, or it could not be sent (the reason is shown). The last seen time and last snapshot are kept |

The **Refresh** button on the Services page requests new snapshots regardless of their age.

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
