<?php

declare(strict_types=1);

namespace App\Tests\Settings;

use App\Settings\PulseConfigurationException;
use App\Settings\PulseSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class ConnectionsTest extends TestCase
{
    private const BASE = [
        'ECOTONE_LICENCE_KEY' => 'key',
        'PULSE_TRANSPORT_DSN' => 'amqp://guest:guest@rabbitmq:5672',
    ];

    public function test_a_service_can_name_another_connection_and_the_queue_it_consumes(): void
    {
        $settings = PulseSettings::fromEnvironment([
            ...self::BASE,
            'PULSE_CONNECTION_LEGACY_DSN' => 'amqp://guest:guest@other:5672',
            'PULSE_SERVICES' => 'orders,billing:billing_channel@legacy,shipping:shipping#shipping_queue',
        ], __DIR__);

        self::assertSame(['default', 'legacy'], array_keys($settings->connections));
        self::assertSame('orders', $settings->routes['orders']->destination);
        self::assertSame('default', $settings->routes['orders']->connection);
        self::assertSame('legacy', $settings->routes['billing']->connection);
        self::assertSame('billing_channel', $settings->routes['billing']->channel);
        self::assertSame('shipping', $settings->routes['shipping']->channel);
        self::assertSame('shipping_queue', $settings->routes['shipping']->destination);
        self::assertSame(['orders' => 'orders', 'billing' => 'billing_channel', 'shipping' => 'shipping'], $settings->serviceChannels);
    }

    public function test_pulse_reads_replies_on_every_connection_under_a_name_that_cannot_clash(): void
    {
        $settings = PulseSettings::fromEnvironment([
            ...self::BASE,
            'PULSE_CONNECTION_LEGACY_DSN' => 'kafka://kafka:9092',
            'PULSE_SERVICES' => 'orders',
        ], __DIR__);

        self::assertSame(['ecotone_pulse' => 'ecotone_pulse', 'ecotone_pulse.legacy' => 'ecotone_pulse'], $settings->replyChannels());
    }

    public function test_a_second_connection_on_a_transport_that_ties_the_channel_to_its_name_needs_its_own_reply_channel(): void
    {
        $settings = PulseSettings::fromEnvironment([
            ...self::BASE,
            'PULSE_TRANSPORT_DSN' => 'pgsql://ecotone:secret@database:5432/one',
            'PULSE_CONNECTION_LEGACY_DSN' => 'pgsql://ecotone:secret@database:5432/two',
            'PULSE_CONNECTION_LEGACY_REPLY_CHANNEL' => 'ecotone_pulse_legacy',
            'PULSE_SERVICES' => 'orders,billing@legacy',
        ], __DIR__);

        self::assertSame(['ecotone_pulse' => 'ecotone_pulse', 'ecotone_pulse_legacy' => 'ecotone_pulse_legacy'], $settings->replyChannels());
    }

    public function test_a_redis_dsn_is_read_with_the_phpredis_extension(): void
    {
        $settings = PulseSettings::fromEnvironment([...self::BASE, 'PULSE_TRANSPORT_DSN' => 'redis://redis:6379', 'PULSE_SERVICES' => 'orders'], __DIR__);

        self::assertSame(['dsn' => 'redis://redis:6379', 'scheme_extensions' => ['phpredis']], $settings->connections['default']->clientConfiguration());
    }

    #[DataProvider('realistic_destinations')]
    public function test_a_destination_may_use_the_characters_a_broker_allows(string $dsn, string $entry, string $destination): void
    {
        $settings = PulseSettings::fromEnvironment([...self::BASE, 'PULSE_TRANSPORT_DSN' => $dsn, 'PULSE_SERVICES' => 'billing,' . $entry], __DIR__);

        self::assertSame($destination, $settings->routes['orders']->destination);
        self::assertSame('billing', $settings->routes['billing']->destination);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function realistic_destinations(): iterable
    {
        $amqp = 'amqp://guest:guest@rabbitmq:5672';
        yield 'dots and dashes' => [$amqp, 'orders#orders.v1-new', 'orders.v1-new'];
        yield 'colons and slashes' => [$amqp, 'orders#legacy:orders/high', 'legacy:orders/high'];
        yield 'quoted with a comma and an at sign' => [$amqp, 'orders#"a,b@c"', 'a,b@c'];
        yield 'quoted before a connection' => ['amqp://guest:guest@rabbitmq:5672', 'orders#"q,1"@default', 'q,1'];
        yield 'kafka topic' => ['kafka://kafka:9092', 'orders#orders.v1_new-2', 'orders.v1_new-2'];
    }

    /**
     * @param array<string, string> $environment
     */
    #[DataProvider('refused')]
    public function test_a_connection_mistake_is_refused_with_the_fix(array $environment, string $message): void
    {
        $this->expectException(PulseConfigurationException::class);
        $this->expectExceptionMessage($message);

        PulseSettings::fromEnvironment([...self::BASE, ...$environment], __DIR__);
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function refused(): iterable
    {
        yield 'unknown connection' => [['PULSE_SERVICES' => 'orders@legacy'], 'PULSE_SERVICES entry "orders@legacy" names connection "legacy", which is not configured. Configured connections: default. Define it with PULSE_CONNECTION_LEGACY_DSN, for example PULSE_CONNECTION_LEGACY_DSN=pgsql://user:secret@host:5432/db, or remove "@legacy".'];
        yield 'default is not a name to configure' => [['PULSE_CONNECTION_DEFAULT_DSN' => 'amqp://x', 'PULSE_SERVICES' => 'orders'], 'PULSE_CONNECTION_DEFAULT_DSN is not allowed, because the default connection is PULSE_TRANSPORT_DSN. Give the connection another name, for example PULSE_CONNECTION_LEGACY_DSN.'];
        yield 'destination on a transport without one' => [['PULSE_TRANSPORT_DSN' => 'redis://redis:6379', 'PULSE_SERVICES' => 'orders#orders_queue'], 'PULSE_SERVICES entry "orders#orders_queue" names destination "orders_queue", but Redis ties the channel to its name. Name the channel after the destination instead: "orders:orders_queue".'];
        yield 'kafka topic outside the topic charset' => [['PULSE_TRANSPORT_DSN' => 'kafka://kafka:9092', 'PULSE_SERVICES' => 'orders#orders/high'], 'PULSE_SERVICES entry "orders#orders/high" names destination "orders/high", which is not a Kafka topic name. A topic uses only letters, digits, ".", "_" and "-", up to 249 characters, and is not "." or "..".'];
        yield 'amqp queue longer than the broker allows' => [['PULSE_SERVICES' => 'orders#' . str_repeat('q', 256)], 'which is longer than the 255 bytes RabbitMQ allows for a queue name'];
        yield 'unterminated quote' => [['PULSE_SERVICES' => 'orders#"queue'], 'PULSE_SERVICES entry "orders#"queue" is malformed.'];
        yield 'empty destination' => [['PULSE_SERVICES' => 'orders#'], 'PULSE_SERVICES entry "orders#" is malformed.'];
        yield 'empty connection' => [['PULSE_SERVICES' => 'orders@'], 'PULSE_SERVICES entry "orders@" is malformed.'];
        yield 'same channel on two connections' => [['PULSE_CONNECTION_LEGACY_DSN' => 'amqp://guest:guest@other:5672', 'PULSE_SERVICES' => 'orders:shared,billing:shared@legacy'], 'PULSE_SERVICES maps services "orders" and "billing" to the same channel "shared"'];
        yield 'two services on one destination of a connection' => [['PULSE_SERVICES' => 'orders#queue,billing#queue'], 'PULSE_SERVICES maps services "orders" and "billing" to the same destination "queue" on connection "default"'];
        yield 'reply channel clash on a transport without destinations' => [['PULSE_TRANSPORT_DSN' => 'pgsql://u:p@db:5432/one', 'PULSE_CONNECTION_LEGACY_DSN' => 'pgsql://u:p@db:5432/two', 'PULSE_SERVICES' => 'orders,billing@legacy'], 'Pulse would read replies from a channel named "ecotone_pulse" on connections "default" and "legacy", and Pulse registers a channel name once. Give "legacy" its own reply channel with PULSE_CONNECTION_LEGACY_REPLY_CHANNEL=ecotone_pulse_legacy, and configure its services with PulseConfiguration::withReplyChannel(\'ecotone_pulse_legacy\').'];
        yield 'two kafka connections' => [['PULSE_TRANSPORT_DSN' => 'kafka://a:9092', 'PULSE_CONNECTION_LEGACY_DSN' => 'kafka://b:9092', 'PULSE_SERVICES' => 'orders'], 'Pulse supports one Kafka connection, but "default" and "legacy" are both Kafka.'];
        yield 'invalid connection dsn' => [['PULSE_CONNECTION_LEGACY_DSN' => 'ftp://x', 'PULSE_SERVICES' => 'orders'], 'PULSE_CONNECTION_LEGACY_DSN "ftp://x" does not start with a supported scheme'];
    }
}
