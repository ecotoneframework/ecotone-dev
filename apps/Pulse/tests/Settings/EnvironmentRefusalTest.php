<?php

declare(strict_types=1);

namespace App\Tests\Settings;

use App\Settings\PulseConfigurationException;
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
        yield 'missing transport' => [['PULSE_TRANSPORT_DSN' => null], 'PULSE_TRANSPORT_DSN is not set, so Pulse does not know which broker the services are reachable on.'];
        yield 'unknown transport scheme' => [['PULSE_TRANSPORT_DSN' => 'ftp://host'], 'PULSE_TRANSPORT_DSN "ftp://host" does not start with a supported scheme (matched case-insensitively), so Pulse cannot tell which broker to connect to. Use one of: amqp:// (Amqp), amqps:// (Amqp), redis:// (Redis), sqs: (Sqs), kafka:// (Kafka), pgsql:// (Dbal), mysql:// (Dbal), sqlite:// (Dbal).'];
        yield 'missing services' => [['PULSE_SERVICES' => null], 'PULSE_SERVICES is not set, so Pulse has no services to ask for snapshots. PULSE_SERVICES has to list the services Pulse manages as "service_name:channel_name" pairs separated by commas, for example PULSE_SERVICES=orders:orders_channel,billing:billing_channel.'];
        yield 'malformed services' => [['PULSE_SERVICES' => 'orders'], 'PULSE_SERVICES entry "orders" is malformed. PULSE_SERVICES has to list the services Pulse manages as "service_name:channel_name" pairs separated by commas, for example PULSE_SERVICES=orders:orders_channel,billing:billing_channel.'];
        yield 'service listed twice' => [['PULSE_SERVICES' => 'orders:a,orders:b'], 'PULSE_SERVICES lists service "orders" twice, so Pulse cannot tell which channel it consumes. Keep one "orders:channel_name" entry.'];
        yield 'pulse listed as its own service' => [['PULSE_SERVICES' => 'ecotone_pulse:a'], 'PULSE_SERVICES lists "ecotone_pulse", which is the name of Pulse itself (PULSE_SERVICE_NAME). Remove it from PULSE_SERVICES, or give Pulse another name with PULSE_SERVICE_NAME.'];
        yield 'service reading the pulse channel' => [['PULSE_SERVICES' => 'orders:ecotone_pulse'], 'PULSE_SERVICES maps service "orders" to channel "ecotone_pulse", which is Pulse\'s own channel (PULSE_CHANNEL), so Pulse would read its own requests. Use the channel "orders" consumes, or change PULSE_CHANNEL.'];
        yield 'two services on one channel' => [['PULSE_SERVICES' => 'orders:shared,billing:shared'], 'PULSE_SERVICES maps services "orders" and "billing" to the same channel "shared", so their replies could not be told apart. Give each service the channel it consumes.'];
        yield 'invalid reply timeout' => [['PULSE_REPLY_TIMEOUT' => 'soon'], 'PULSE_REPLY_TIMEOUT "soon" is not a number of seconds, so Pulse cannot tell how long to wait. Set it to a whole number of seconds, 1 or more.'];
        yield 'invalid render wait' => [['PULSE_RENDER_WAIT' => '-1'], 'PULSE_RENDER_WAIT "-1" is not a number of seconds, so Pulse cannot tell how long to wait. Set it to a whole number of seconds, 0 or more.'];
        yield 'invalid service name' => [['PULSE_SERVICE_NAME' => 'my pulse'], 'PULSE_SERVICE_NAME "my pulse" is not a valid name, because names are used as broker channel names. Use only letters, digits, "_", "." and "-", for example ecotone_pulse.'];
    }
}
