<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration\Pulse;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Cache\QueryCacheProfile;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use DateTimeZone;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DatabaseSetupManager;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\MediaType;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\TestConfiguration;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Pulse\PulseConfiguration;
use Ecotone\Test\LicenceTesting;
use Test\Ecotone\Dbal\DbalMessagingTestCase;

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 *
 * @internal
 */
final class PulseSectionFailureTest extends DbalMessagingTestCase
{
    private object $pulse;
    private FlowTestSupport $orderService;
    private FlowTestSupport $pulseService;

    public function setUp(): void
    {
        parent::setUp();
        DeadLetterTableGoneConnection::$deadLetterTableGone = false;
        DeadLetterTableGoneConnection::$failTheNextSchemaRead = false;
    }

    public function test_a_dead_letter_section_whose_statement_fails_inside_the_transaction_of_the_request_is_reported_and_the_reply_arrives(): void
    {
        $this->bootstrap();
        DeadLetterTableGoneConnection::$deadLetterTableGone = true;

        $snapshot = $this->requestSnapshot();

        self::assertSame(['deadLetters'], array_keys($snapshot['sectionErrors']));
        self::assertTrue(array_column($snapshot['setup']['tables'], null, 'feature')['message_queue']['initialized']);
        self::assertSame('order_service', $snapshot['serviceName']);
    }

    public function test_a_setup_read_that_fails_inside_the_transaction_of_the_request_does_not_fail_the_sections_after_it(): void
    {
        $this->bootstrap();
        DeadLetterTableGoneConnection::$failTheNextSchemaRead = true;

        $snapshot = $this->requestSnapshot();

        self::assertArrayNotHasKey('sectionErrors', $snapshot);
        self::assertSame(0, $snapshot['deadLetters']['total']);
        self::assertContains(null, array_column($snapshot['setup']['tables'], 'initialized'));
    }

    private function bootstrap(): void
    {
        $orders = new class () {
            #[Asynchronous('orders')]
            #[CommandHandler('order.place', endpointId: 'order.place.endpoint')]
            public function place(string $order): void
            {
            }
        };
        $this->pulse = new class () {
            public array $snapshots = [];

            #[InternalHandler('ecotone.pulse.snapshot_received')]
            public function received(string $snapshot): void
            {
                $this->snapshots[] = $snapshot;
            }
        };
        $channels = [SimpleMessageChannelBuilder::createQueueChannel('order_channel'), SimpleMessageChannelBuilder::createQueueChannel('pulse_channel')];
        $connectionFactory = new DbalConnectionFactory(['dsn' => getenv('DATABASE_DSN') ?: 'pgsql://ecotone:secret@localhost:5432/ecotone', 'connection' => ['wrapperClass' => DeadLetterTableGoneConnection::class]]);
        $this->orderService = $this->bootstrapFlowTesting(
            classesToResolve: [$orders::class],
            containerOrAvailableServices: [$orders, DbalConnectionFactory::class => $connectionFactory],
            extensionObjects: [
                ...$channels,
                DbalBackedMessageChannelBuilder::create('orders'),
                DbalConfiguration::createWithDefaults()->withDeadLetter(true)->withTransactionOnAsynchronousEndpoints(true),
                DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'ecotone_pulse', channelName: 'pulse_channel'),
                ServiceConfiguration::createWithDefaults()->withServiceName('order_service'),
                PulseConfiguration::createWithDefaults('ecotone_pulse'),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
            testConfiguration: TestConfiguration::createWithDefaults()->withTimeAt(new DateTimeImmutable('2026-10-20 12:00:00', new DateTimeZone('UTC'))),
        );
        $this->pulseService = $this->bootstrapFlowTesting(
            classesToResolve: [$this->pulse::class],
            containerOrAvailableServices: [$this->pulse],
            extensionObjects: [
                ...$channels,
                DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'order_service', channelName: 'order_channel'),
                ServiceConfiguration::createWithDefaults()->withServiceName('ecotone_pulse'),
            ],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    private function requestSnapshot(): array
    {
        $this->pulseService->getDistributedBus()->sendMessage('order_service', 'ecotone.pulse.snapshot', '{}', MediaType::APPLICATION_JSON, replyRoutingKey: 'ecotone.pulse.snapshot_received');
        $this->orderService->run('order_channel', ExecutionPollingMetadata::createWithTestingSetup());
        $this->pulseService->run('pulse_channel', ExecutionPollingMetadata::createWithTestingSetup());
        self::assertCount(1, $this->pulse->snapshots);

        return json_decode($this->pulse->snapshots[0], true, flags: JSON_THROW_ON_ERROR);
    }
}

final class DeadLetterTableGoneConnection extends Connection
{
    public static bool $deadLetterTableGone = false;
    public static bool $failTheNextSchemaRead = false;

    public function executeQuery(string $sql, array $params = [], array $types = [], ?QueryCacheProfile $qcp = null): Result
    {
        return parent::executeQuery($this->asIfTheTableWereGone($sql), $params, $types, $qcp);
    }

    private function asIfTheTableWereGone(string $sql): string
    {
        if (self::$failTheNextSchemaRead && $this->isTransactionActive() && (stripos($sql, 'table_name') !== false || stripos($sql, 'SHOW TABLES') === 0)) {
            self::$failTheNextSchemaRead = false;

            return 'SELECT * FROM information_schema_that_is_gone';
        }

        return self::$deadLetterTableGone && str_contains($sql, 'ecotone_error_messages') && ! str_starts_with($sql, 'CREATE') ? str_replace('ecotone_error_messages', 'ecotone_error_messages_gone', $sql) : $sql;
    }
}
