<?php

declare(strict_types=1);

namespace App\Settings;

use App\Snapshot\Snapshots;

final readonly class PulseSettings
{
    public const TRANSPORT_CONNECTION_REFERENCE = 'pulse_transport';

    /**
     * @param array<string, string> $serviceChannels service name => channel the service consumes
     */
    public function __construct(
        public string $serviceName,
        public string $channel,
        public string $transportDsn,
        public Transport $transport,
        public array $serviceChannels,
        public int $replyTimeoutSeconds,
        public int $renderWaitSeconds,
        public int $refreshIntervalSeconds,
        public string $cacheDir,
        public string $internalDatabasePath,
    ) {
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
        $renderWait = self::seconds($read('PULSE_RENDER_WAIT', '2'), 'PULSE_RENDER_WAIT', 0);

        $refreshInterval = self::seconds($read('PULSE_REFRESH_INTERVAL', '20'), 'PULSE_REFRESH_INTERVAL', 1);
        if ($refreshInterval >= Snapshots::FRESHNESS_SECONDS) {
            throw new PulseConfigurationException(sprintf('PULSE_REFRESH_INTERVAL "%d" is not shorter than the %d seconds after which a snapshot stops counting as fresh, so pages would open on stale snapshots and the worker would not be refreshing ahead of them. Set it to a whole number of seconds between 1 and %d, for example 20, which is the default.', $refreshInterval, Snapshots::FRESHNESS_SECONDS, Snapshots::FRESHNESS_SECONDS - 1));
        }

        $cacheDir = $read('PULSE_CACHE_DIR', 'var/pulse-cache');
        if (! str_starts_with($cacheDir, '/')) {
            $cacheDir = $projectDir . '/' . $cacheDir;
        }

        $internalDatabasePath = $read('PULSE_INTERNAL_DATABASE_PATH', 'var/internal/pulse.sqlite');
        if (! str_starts_with($internalDatabasePath, '/')) {
            $internalDatabasePath = $projectDir . '/' . $internalDatabasePath;
        }

        $transport = Transport::fromDsn($dsn);
        $serviceChannels = self::serviceChannels($read('PULSE_SERVICES'), $serviceName, $channel);

        return new self($serviceName, $channel, $dsn, $transport, $serviceChannels, $replyTimeout, $renderWait, $refreshInterval, $cacheDir, $internalDatabasePath);
    }

    public function internalDatabaseDsn(): string
    {
        return 'sqlite:///' . $this->internalDatabasePath;
    }

    public function transportConnectionReference(): string
    {
        return self::TRANSPORT_CONNECTION_REFERENCE;
    }

    /**
     * @return list<string>
     */
    public function kafkaBootstrapServers(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) preg_replace('#^kafka://#i', '', $this->transportDsn)))));
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
     * @return array<string, string>
     */
    private static function serviceChannels(?string $services, string $pulseServiceName, string $pulseChannel): array
    {
        $expectedFormat = 'PULSE_SERVICES has to list the services Pulse manages as "service_name:channel_name" pairs separated by commas, for example PULSE_SERVICES=orders:orders_channel,billing:billing_channel.';
        if ($services === null) {
            throw new PulseConfigurationException('PULSE_SERVICES is not set, so Pulse has no services to ask for snapshots. ' . $expectedFormat);
        }

        $serviceChannels = [];
        foreach (explode(',', $services) as $pair) {
            $parts = explode(':', trim($pair));
            if (count($parts) !== 2 || trim($parts[0]) === '' || trim($parts[1]) === '') {
                throw new PulseConfigurationException(sprintf('PULSE_SERVICES entry "%s" is malformed. %s', trim($pair), $expectedFormat));
            }

            $service = self::name(trim($parts[0]), 'PULSE_SERVICES service');
            $serviceChannel = self::name(trim($parts[1]), 'PULSE_SERVICES channel');
            if (isset($serviceChannels[$service])) {
                throw new PulseConfigurationException(sprintf('PULSE_SERVICES lists service "%s" twice, so Pulse cannot tell which channel it consumes. Keep one "%s:channel_name" entry.', $service, $service));
            }
            if ($service === $pulseServiceName) {
                throw new PulseConfigurationException(sprintf('PULSE_SERVICES lists "%s", which is the name of Pulse itself (PULSE_SERVICE_NAME). Remove it from PULSE_SERVICES, or give Pulse another name with PULSE_SERVICE_NAME.', $service));
            }
            if ($serviceChannel === $pulseChannel) {
                throw new PulseConfigurationException(sprintf('PULSE_SERVICES maps service "%s" to channel "%s", which is Pulse\'s own channel (PULSE_CHANNEL), so Pulse would read its own requests. Use the channel "%s" consumes, or change PULSE_CHANNEL.', $service, $serviceChannel, $service));
            }
            $sharing = array_search($serviceChannel, $serviceChannels, true);
            if ($sharing !== false) {
                throw new PulseConfigurationException(sprintf('PULSE_SERVICES maps services "%s" and "%s" to the same channel "%s", so their replies could not be told apart. Give each service the channel it consumes.', $sharing, $service, $serviceChannel));
            }
            $serviceChannels[$service] = $serviceChannel;
        }

        return $serviceChannels;
    }
}
