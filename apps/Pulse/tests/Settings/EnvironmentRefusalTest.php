<?php

declare(strict_types=1);

namespace App\Tests\Settings;

use App\Settings\PulseConfigurationException;
use App\Settings\PulseSettings;
use App\Tests\Support\PulseEnvironmentTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * @internal
 */
final class EnvironmentRefusalTest extends KernelTestCase
{
    use PulseEnvironmentTrait;

    public function test_pulse_boots_with_a_valid_environment(): void
    {
        $this->configurePulse();

        self::bootKernel();

        self::assertTrue(self::$booted);
    }

    public function test_the_configured_version_is_read(): void
    {
        $settings = PulseSettings::fromEnvironment(['ECOTONE_LICENCE_KEY' => 'key', 'PULSE_VERSION' => '2.0.1', 'PULSE_TRANSPORT_DSN' => 'amqp://guest:guest@rabbitmq:5672', 'PULSE_SERVICES' => 'orders'], __DIR__);

        self::assertSame('2.0.1', $settings->version);
    }

    public function test_services_listed_by_name_consume_a_channel_named_like_the_service(): void
    {
        $settings = PulseSettings::fromEnvironment(['ECOTONE_LICENCE_KEY' => 'key', 'PULSE_VERSION' => 'test', 'PULSE_TRANSPORT_DSN' => 'amqp://guest:guest@rabbitmq:5672', 'PULSE_SERVICES' => 'orders, billing:billing_requests'], __DIR__);

        self::assertSame(['orders' => 'orders', 'billing' => 'billing_requests'], $settings->serviceChannels);
    }

    public function test_a_leftover_database_setup_variable_is_ignored_for_any_transport(): void
    {
        $this->configurePulse(['PULSE_SETUP_DATABASE' => 'yes', 'PULSE_TRANSPORT_DSN' => 'amqp://guest:guest@rabbitmq:5672']);

        self::bootKernel();

        self::assertTrue(self::$booted);
    }

    #[DataProvider('supported_transports')]
    public function test_every_supported_transport_boots(string $dsn): void
    {
        $this->configurePulse(['PULSE_TRANSPORT_DSN' => $dsn]);

        self::bootKernel();

        self::assertTrue(self::$booted);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function supported_transports(): iterable
    {
        yield 'amqp' => ['amqp://guest:guest@rabbitmq:5672'];
        yield 'redis' => ['redis://redis:6379'];
        yield 'sqs' => ['sqs:?key=key&secret=secret&region=us-east-1&endpoint=http://localstack:4566&version=latest'];
        yield 'kafka' => ['kafka://kafka:9092'];
        yield 'kafka with several brokers' => ['kafka://one:9092,two:9092'];
        yield 'sqs from the README' => ['sqs:?region=eu-west-1'];
        yield 'scheme in upper case' => ['AMQP://guest:guest@rabbitmq:5672'];
        yield 'postgres' => ['pgsql://ecotone:secret@database:5432/ecotone'];
        yield 'mysql' => ['mysql://ecotone:secret@database-mysql:3306/ecotone'];
        yield 'sqlite' => ['sqlite:///:memory:'];
    }

    /**
     * @param array<string, string|null> $environment
     */
    #[DataProvider('invalid_environments')]
    public function test_an_invalid_environment_is_refused_at_boot(array $environment, string $message): void
    {
        $this->configurePulse($environment);

        $this->expectException(PulseConfigurationException::class);
        $this->expectExceptionMessage($message);

        self::bootKernel();
    }

    /**
     * @return iterable<string, array{array<string, string|null>, string}>
     */
    public static function invalid_environments(): iterable
    {
        yield 'missing licence key' => [['ECOTONE_LICENCE_KEY' => null], 'ECOTONE_LICENCE_KEY is not set, but Ecotone Pulse is an Ecotone Enterprise application and does not start without a licence. Set ECOTONE_LICENCE_KEY to your Enterprise licence key (trial: https://ecotone.tech/pricing).'];
        yield 'missing version' => [['PULSE_VERSION' => null], 'PULSE_VERSION is not set, so Pulse cannot tell which image version a browser has already seen the intro for. The Docker image sets it from its build argument: build with --build-arg PULSE_VERSION=<tag>, or when running outside the image set PULSE_VERSION to any non-empty value such as dev.'];
        yield 'missing transport' => [['PULSE_TRANSPORT_DSN' => null], 'PULSE_TRANSPORT_DSN is not set, so Pulse does not know which broker the services are reachable on.'];
        yield 'unknown transport scheme' => [['PULSE_TRANSPORT_DSN' => 'ftp://host'], 'PULSE_TRANSPORT_DSN "ftp://host" does not start with a supported scheme (matched case-insensitively), so Pulse cannot tell which broker to connect to. Use one of: amqp:// (Amqp), amqps:// (Amqp), redis:// (Redis), sqs: (Sqs), kafka:// (Kafka), pgsql:// (Dbal), mysql:// (Dbal), sqlite:// (Dbal).'];
        yield 'missing services' => [['PULSE_SERVICES' => null], 'PULSE_SERVICES is not set, so Pulse has no services to ask for snapshots. PULSE_SERVICES has to list the services Pulse manages by name, optionally with the channel the service consumes, as "service_name" or "service_name:channel_name" separated by commas, for example PULSE_SERVICES=orders,billing or PULSE_SERVICES=orders:orders_channel,billing:billing_channel.'];
        yield 'malformed services' => [['PULSE_SERVICES' => 'orders:a:b'], 'PULSE_SERVICES entry "orders:a:b" is malformed. PULSE_SERVICES has to list the services Pulse manages by name, optionally with the channel the service consumes, as "service_name" or "service_name:channel_name" separated by commas, for example PULSE_SERVICES=orders,billing or PULSE_SERVICES=orders:orders_channel,billing:billing_channel.'];
        yield 'empty service name' => [['PULSE_SERVICES' => ':a'], 'PULSE_SERVICES entry ":a" is malformed. PULSE_SERVICES has to list the services Pulse manages by name, optionally with the channel the service consumes, as "service_name" or "service_name:channel_name" separated by commas, for example PULSE_SERVICES=orders,billing or PULSE_SERVICES=orders:orders_channel,billing:billing_channel.'];
        yield 'empty channel name' => [['PULSE_SERVICES' => 'orders:'], 'PULSE_SERVICES entry "orders:" is malformed. PULSE_SERVICES has to list the services Pulse manages by name, optionally with the channel the service consumes, as "service_name" or "service_name:channel_name" separated by commas, for example PULSE_SERVICES=orders,billing or PULSE_SERVICES=orders:orders_channel,billing:billing_channel.'];
        yield 'empty entry between commas' => [['PULSE_SERVICES' => 'orders,,billing'], 'PULSE_SERVICES entry "" is malformed. PULSE_SERVICES has to list the services Pulse manages by name, optionally with the channel the service consumes, as "service_name" or "service_name:channel_name" separated by commas, for example PULSE_SERVICES=orders,billing or PULSE_SERVICES=orders:orders_channel,billing:billing_channel.'];
        yield 'service listed twice' => [['PULSE_SERVICES' => 'orders:a,orders:b'], 'PULSE_SERVICES lists service "orders" twice, so Pulse cannot tell which channel it consumes. Keep one "orders" or "orders:channel_name" entry.'];
        yield 'service listed twice without channels' => [['PULSE_SERVICES' => 'orders,orders'], 'PULSE_SERVICES lists service "orders" twice, so Pulse cannot tell which channel it consumes. Keep one "orders" or "orders:channel_name" entry.'];
        yield 'pulse listed as its own service' => [['PULSE_SERVICES' => 'ecotone_pulse:a'], 'PULSE_SERVICES lists "ecotone_pulse", which is the name of Pulse itself (PULSE_SERVICE_NAME). Remove it from PULSE_SERVICES, or give Pulse another name with PULSE_SERVICE_NAME.'];
        yield 'service reading the pulse channel' => [['PULSE_SERVICES' => 'orders:ecotone_pulse'], 'PULSE_SERVICES maps service "orders" to channel "ecotone_pulse", which is Pulse\'s own channel (PULSE_CHANNEL), so Pulse would read its own requests. Use the channel "orders" consumes, or change PULSE_CHANNEL.'];
        yield 'service named like the pulse channel' => [['PULSE_SERVICES' => 'orders', 'PULSE_CHANNEL' => 'orders'], 'PULSE_SERVICES maps service "orders" to channel "orders", which is Pulse\'s own channel (PULSE_CHANNEL), so Pulse would read its own requests. Use the channel "orders" consumes, or change PULSE_CHANNEL.'];
        yield 'two services on one channel' => [['PULSE_SERVICES' => 'orders:shared,billing:shared'], 'PULSE_SERVICES maps services "orders" and "billing" to the same channel "shared", so their replies could not be told apart. Give each service the channel it consumes.'];
        yield 'default channel taken by another service' => [['PULSE_SERVICES' => 'billing:orders,orders'], 'PULSE_SERVICES maps services "billing" and "orders" to the same channel "orders", so their replies could not be told apart. Give each service the channel it consumes.'];
        yield 'invalid reply timeout' => [['PULSE_REPLY_TIMEOUT' => 'soon'], 'PULSE_REPLY_TIMEOUT "soon" is not a number of seconds, so Pulse cannot tell how long to wait. Set it to a whole number of seconds, 1 or more.'];
        yield 'invalid await timeout' => [['PULSE_AWAIT_TIMEOUT' => '-1'], 'PULSE_AWAIT_TIMEOUT "-1" is not a number of seconds, so Pulse cannot tell how long to wait. Set it to a whole number of seconds, 0 or more.'];
        yield 'refresh interval that is not a number' => [['PULSE_REFRESH_INTERVAL' => 'often'], 'PULSE_REFRESH_INTERVAL "often" is not a number of seconds, so Pulse cannot tell how long to wait. Set it to a whole number of seconds, 1 or more.'];
        yield 'refresh interval of zero' => [['PULSE_REFRESH_INTERVAL' => '0'], 'PULSE_REFRESH_INTERVAL "0" is not a number of seconds, so Pulse cannot tell how long to wait. Set it to a whole number of seconds, 1 or more.'];
        yield 'refresh interval as long as the freshness window' => [['PULSE_REFRESH_INTERVAL' => '60'], 'PULSE_REFRESH_INTERVAL "60" is not shorter than the 60 seconds after which a snapshot stops counting as fresh, so pages would open on stale snapshots and the worker would not be refreshing ahead of them. Set it to a whole number of seconds between 1 and 59, for example 20, which is the default.'];
        yield 'invalid projection stalled after' => [['PULSE_PROJECTION_STALLED_AFTER' => 'long'], 'PULSE_PROJECTION_STALLED_AFTER "long" is not a number of seconds, so Pulse cannot tell how long to wait. Set it to a whole number of seconds, 1 or more.'];
        yield 'projection stalled after of zero' => [['PULSE_PROJECTION_STALLED_AFTER' => '0'], 'PULSE_PROJECTION_STALLED_AFTER "0" is not a number of seconds, so Pulse cannot tell how long to wait. Set it to a whole number of seconds, 1 or more.'];
        yield 'invalid channel stalled after' => [['PULSE_CHANNEL_STALLED_AFTER' => 'long'], 'PULSE_CHANNEL_STALLED_AFTER "long" is not a number of seconds, so Pulse cannot tell how long to wait. Set it to a whole number of seconds, 1 or more.'];
        yield 'tracking days that is not a number' => [['PULSE_TRACKING_DAYS' => 'week'], 'PULSE_TRACKING_DAYS "week" is not a number of days, so Pulse cannot tell how far back to look for tracked rebuilds. Set it to a whole number of days from 1 to 365, for example 7, which is the default.'];
        yield 'tracking days of zero' => [['PULSE_TRACKING_DAYS' => '0'], 'PULSE_TRACKING_DAYS "0" is not a number of days, so Pulse cannot tell how far back to look for tracked rebuilds. Set it to a whole number of days from 1 to 365, for example 7, which is the default.'];
        yield 'tracking days beyond a year' => [['PULSE_TRACKING_DAYS' => '366'], 'PULSE_TRACKING_DAYS "366" is not a number of days, so Pulse cannot tell how far back to look for tracked rebuilds. Set it to a whole number of days from 1 to 365, for example 7, which is the default.'];
        yield 'invalid service name' => [['PULSE_SERVICE_NAME' => 'my pulse'], 'PULSE_SERVICE_NAME "my pulse" is not a valid name, because names are used as broker channel names. Use only letters, digits, "_", "." and "-", for example ecotone_pulse.'];
    }
}
