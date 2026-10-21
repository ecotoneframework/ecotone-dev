<?php

declare(strict_types=1);

namespace App\Settings;

use App\Snapshot\Snapshots;
use PDO;

final readonly class PulseSettings
{
    public const TRANSPORT_CONNECTION_REFERENCE = 'pulse_transport';
    public const INTERNAL_DATABASE_BUSY_TIMEOUT_SECONDS = 5;

    /**
     * @param array<string, Connection> $connections
     * @param array<string, ServiceRoute> $routes
     * @param array<string, string> $serviceChannels service name => channel the service consumes
     */
    public function __construct(
        public string $serviceName,
        public string $channel,
        public array $connections,
        public array $routes,
        public array $serviceChannels,
        public int $replyTimeoutSeconds,
        public int $awaitTimeoutSeconds,
        public int $refreshIntervalSeconds,
        public string $internalDatabasePath,
    ) {
    }

    public static function fromProcessEnvironment(string $projectDir): self
    {
        return self::fromEnvironment(PulseEnvironment::read(), $projectDir);
    }

    /**
     * @param array<string, mixed> $environment
     */
    public static function fromEnvironment(array $environment, string $projectDir): self
    {
        $read = static function (string $name, ?string $default = null) use ($environment): ?string {
            $value = $environment[$name] ?? null;

            return is_string($value) && trim($value) !== '' ? trim($value) : $default;
        };

        if ($read('ECOTONE_LICENCE_KEY') === null) {
            throw new PulseConfigurationException('ECOTONE_LICENCE_KEY is not set, but Ecotone Pulse is an Ecotone Enterprise application and does not start without a licence. Set ECOTONE_LICENCE_KEY to your Enterprise licence key (trial: https://ecotone.tech/pricing).');
        }

        $serviceName = self::name($read('PULSE_SERVICE_NAME', 'ecotone_pulse'), 'PULSE_SERVICE_NAME');
        $channel = self::name($read('PULSE_CHANNEL', 'ecotone_pulse'), 'PULSE_CHANNEL');

        $dsn = $read('PULSE_TRANSPORT_DSN');
        if ($dsn === null) {
            throw new PulseConfigurationException(sprintf(
                'PULSE_TRANSPORT_DSN is not set, so Pulse does not know which broker the services are reachable on. Set it to the broker of your services, for example amqp://guest:guest@rabbitmq:5672, redis://redis:6379, sqs:?region=eu-west-1, kafka://kafka:9092 or pgsql://user:secret@database:5432/db. The scheme selects the transport: %s.',
                'amqp://, redis://, sqs:, kafka://, pgsql://, mysql://, sqlite://'
            ));
        }

        $replyTimeout = self::seconds($read('PULSE_REPLY_TIMEOUT', '10'), 'PULSE_REPLY_TIMEOUT', 1);
        $awaitTimeout = self::seconds($read('PULSE_AWAIT_TIMEOUT', '20'), 'PULSE_AWAIT_TIMEOUT', 0);

        $refreshInterval = self::seconds($read('PULSE_REFRESH_INTERVAL', '20'), 'PULSE_REFRESH_INTERVAL', 1);
        if ($refreshInterval >= Snapshots::FRESHNESS_SECONDS) {
            throw new PulseConfigurationException(sprintf('PULSE_REFRESH_INTERVAL "%d" is not shorter than the %d seconds after which a snapshot stops counting as fresh, so pages would open on stale snapshots and the worker would not be refreshing ahead of them. Set it to a whole number of seconds between 1 and %d, for example 20, which is the default.', $refreshInterval, Snapshots::FRESHNESS_SECONDS, Snapshots::FRESHNESS_SECONDS - 1));
        }

        $internalDatabasePath = $read('PULSE_INTERNAL_DATABASE_PATH', 'var/internal/pulse.sqlite');
        if (! str_starts_with($internalDatabasePath, '/')) {
            $internalDatabasePath = $projectDir . '/' . $internalDatabasePath;
        }

        $connections = self::connections($environment, $dsn, $channel);
        $routes = self::routes($read('PULSE_SERVICES'), $serviceName, $channel, $connections);
        $serviceChannels = array_map(static fn (ServiceRoute $route) => $route->channel, $routes);

        return new self($serviceName, $channel, $connections, $routes, $serviceChannels, $replyTimeout, $awaitTimeout, $refreshInterval, $internalDatabasePath);
    }

    /**
     * @return array{dsn: string, connection: array{driverOptions: array<int, int>}}
     */
    public function internalDatabaseConfiguration(): array
    {
        return [
            'dsn' => 'sqlite:///' . $this->internalDatabasePath,
            'connection' => ['driverOptions' => [PDO::ATTR_TIMEOUT => self::INTERNAL_DATABASE_BUSY_TIMEOUT_SECONDS]],
        ];
    }

    public function transportConnectionReference(): string
    {
        return self::TRANSPORT_CONNECTION_REFERENCE;
    }

    public function defaultConnection(): Connection
    {
        return $this->connections[Connection::DEFAULT];
    }

    /**
     * @return array<string>
     */
    public function modulePackages(): array
    {
        return array_values(array_unique(array_merge(...array_map(static fn (Connection $connection) => $connection->transport->modulePackages(), array_values($this->connections)))));
    }

    public function transport(): Transport
    {
        return $this->defaultConnection()->transport;
    }

    public function connectionOf(string $service): Connection
    {
        return $this->connections[$this->routes[$service]->connection];
    }

    /**
     * @return array<string, string> Ecotone channel name => destination on the broker
     */
    public function replyChannels(): array
    {
        $channels = [];
        foreach ($this->connections as $connection) {
            $channels[self::replyChannelName($connection, $this->channel)] = $connection->replyDestination;
        }

        return $channels;
    }

    public static function replyChannelName(Connection $connection, string $pulseChannel): string
    {
        if ($connection->isDefault()) {
            return $pulseChannel;
        }

        return $connection->transport->hasDestinationOfItsOwn() ? $connection->replyDestination . '.' . $connection->name : $connection->replyDestination;
    }

    private static function name(string $value, string $variable): string
    {
        if (preg_match('/^[A-Za-z0-9_.-]+$/', $value) !== 1) {
            throw new PulseConfigurationException(sprintf('%s "%s" is not a valid name, because names are used as broker channel names. Use only letters, digits, "_", "." and "-", for example ecotone_pulse.', $variable, $value));
        }

        return $value;
    }

    private static function seconds(string $value, string $variable, int $minimum): int
    {
        if (preg_match('/^\d+$/', $value) !== 1 || (int) $value < $minimum) {
            throw new PulseConfigurationException(sprintf('%s "%s" is not a number of seconds, so Pulse cannot tell how long to wait. Set it to a whole number of seconds, %d or more.', $variable, $value, $minimum));
        }

        return (int) $value;
    }

    /**
     * @param array<string, mixed> $environment
     * @return array<string, Connection>
     */
    private static function connections(array $environment, string $defaultDsn, string $pulseChannel): array
    {
        $replyOf = static fn (string $variable): string => self::name(is_string($environment[$variable] ?? null) && trim($environment[$variable]) !== '' ? trim($environment[$variable]) : $pulseChannel, $variable);

        $connections = [Connection::DEFAULT => new Connection(Connection::DEFAULT, $defaultDsn, Transport::fromDsn($defaultDsn), 'PULSE_TRANSPORT_DSN', $pulseChannel)];
        foreach ($environment as $variable => $value) {
            if (preg_match('/^PULSE_CONNECTION_([A-Z0-9]+(?:_[A-Z0-9]+)*)_DSN$/', (string) $variable, $matches) !== 1 || ! is_string($value) || trim($value) === '') {
                continue;
            }
            $name = strtolower($matches[1]);
            if ($name === Connection::DEFAULT) {
                throw new PulseConfigurationException('PULSE_CONNECTION_DEFAULT_DSN is not allowed, because the default connection is PULSE_TRANSPORT_DSN. Give the connection another name, for example PULSE_CONNECTION_LEGACY_DSN.');
            }
            $connections[$name] = new Connection($name, trim($value), Transport::fromDsn(trim($value), $variable), $variable, $replyOf(sprintf('PULSE_CONNECTION_%s_REPLY_CHANNEL', $matches[1])));
        }

        $kafka = array_keys(array_filter($connections, static fn (Connection $connection) => $connection->transport === Transport::Kafka));
        if (count($kafka) > 1) {
            throw new PulseConfigurationException(sprintf('Pulse supports one Kafka connection, but "%s" and "%s" are both Kafka. Point the services at one Kafka connection, listing every broker in its DSN (kafka://one:9092,two:9092), or run a second Pulse for the other cluster.', $kafka[0], $kafka[1]));
        }

        $replyNames = [];
        foreach ($connections as $connection) {
            $replyName = self::replyChannelName($connection, $pulseChannel);
            if (isset($replyNames[$replyName])) {
                $other = $connections[$replyNames[$replyName]];

                throw new PulseConfigurationException(sprintf('Pulse would read replies from a channel named "%s" on connections "%s" and "%s", and Pulse registers a channel name once. Give "%s" its own reply channel with PULSE_CONNECTION_%s_REPLY_CHANNEL=%s_%s, and configure its services with PulseConfiguration::withReplyChannel(\'%s_%s\').', $replyName, $other->name, $connection->name, $connection->name, strtoupper($connection->name), $pulseChannel, $connection->name, $pulseChannel, $connection->name));
            }
            $replyNames[$replyName] = $connection->name;
        }

        return $connections;
    }

    /**
     * @param array<string, Connection> $connections
     * @return array<string, ServiceRoute>
     */
    private static function routes(?string $services, string $pulseServiceName, string $pulseChannel, array $connections): array
    {
        $expectedFormat = 'PULSE_SERVICES has to list the services Pulse manages by name, optionally with the channel the service consumes, as "service_name" or "service_name:channel_name" separated by commas, for example PULSE_SERVICES=orders,billing or PULSE_SERVICES=orders:orders_channel,billing:billing_channel. A service on another broker adds "@connection" (defined with PULSE_CONNECTION_<NAME>_DSN) and a queue or topic that differs from the channel name adds "#destination": "service_name:channel_name#destination@connection".';
        if ($services === null) {
            throw new PulseConfigurationException('PULSE_SERVICES is not set, so Pulse has no services to ask for snapshots. ' . $expectedFormat);
        }

        $routes = [];
        foreach (explode(',', $services) as $entry) {
            $entry = trim($entry);
            if (preg_match('/^([^:#@]+)(?::([^:#@]+))?(?:#([^:#@]+))?(?:@([^:#@]+))?$/', $entry, $parts) !== 1) {
                throw new PulseConfigurationException(sprintf('PULSE_SERVICES entry "%s" is malformed. %s', $entry, $expectedFormat));
            }

            $service = self::name(trim($parts[1]), 'PULSE_SERVICES service');
            $channel = self::name(trim(($parts[2] ?? '') !== '' ? $parts[2] : $service), 'PULSE_SERVICES channel');
            $destination = ($parts[3] ?? '') !== '' ? self::name(trim($parts[3]), 'PULSE_SERVICES destination') : null;
            $connectionName = ($parts[4] ?? '') !== '' ? strtolower(trim($parts[4])) : Connection::DEFAULT;

            if (! isset($connections[$connectionName])) {
                throw new PulseConfigurationException(sprintf('PULSE_SERVICES entry "%s" names connection "%s", which is not configured. Configured connections: %s. Define it with PULSE_CONNECTION_%s_DSN, for example PULSE_CONNECTION_%s_DSN=pgsql://user:secret@host:5432/db, or remove "@%s".', $entry, $connectionName, implode(', ', array_keys($connections)), strtoupper($connectionName), strtoupper($connectionName), $parts[4]));
            }
            $connection = $connections[$connectionName];
            if ($destination !== null && ! $connection->transport->hasDestinationOfItsOwn()) {
                throw new PulseConfigurationException(sprintf('PULSE_SERVICES entry "%s" names destination "%s", but %s ties the channel to its name. Name the channel after the destination instead: "%s:%s".', $entry, $destination, $connection->transport->name, $service, $destination));
            }
            $destination ??= $channel;

            if (isset($routes[$service])) {
                throw new PulseConfigurationException(sprintf('PULSE_SERVICES lists service "%s" twice, so Pulse cannot tell which channel it consumes. Keep one "%s" or "%s:channel_name" entry.', $service, $service, $service));
            }
            if ($service === $pulseServiceName) {
                throw new PulseConfigurationException(sprintf('PULSE_SERVICES lists "%s", which is the name of Pulse itself (PULSE_SERVICE_NAME). Remove it from PULSE_SERVICES, or give Pulse another name with PULSE_SERVICE_NAME.', $service));
            }
            if ($channel === $pulseChannel || $destination === $connection->replyDestination) {
                throw new PulseConfigurationException(sprintf('PULSE_SERVICES maps service "%s" to channel "%s", which is Pulse\'s own channel (PULSE_CHANNEL), so Pulse would read its own requests. Use the channel "%s" consumes, or change PULSE_CHANNEL.', $service, $channel, $service));
            }
            foreach ($routes as $other) {
                if ($other->channel === $channel) {
                    throw new PulseConfigurationException(sprintf('PULSE_SERVICES maps services "%s" and "%s" to the same channel "%s", so their replies could not be told apart. Give each service the channel it consumes.', $other->service, $service, $channel));
                }
                if ($other->connection === $connectionName && $other->destination === $destination) {
                    throw new PulseConfigurationException(sprintf('PULSE_SERVICES maps services "%s" and "%s" to the same destination "%s" on connection "%s", so their requests would reach one consumer. Give each service the queue or topic it consumes.', $other->service, $service, $destination, $connectionName));
                }
            }
            $routes[$service] = new ServiceRoute($service, $channel, $destination, $connectionName);
        }

        return $routes;
    }
}
