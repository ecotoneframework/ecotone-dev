<?php

declare(strict_types=1);

namespace Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\SyntaxErrorException;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Ecotone\DataProtection\Configuration\DataProtectionConfiguration;
use Ecotone\DataProtection\Encryption\Crypto;
use Ecotone\DataProtection\Encryption\Key;
use Ecotone\EventSourcing\EventSourcingConfiguration;
use Ecotone\JMSConverter\JMSConverterConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Modelling\Event;
use Ecotone\Test\LicenceTesting;
use Enqueue\Dbal\DbalConnectionFactory;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\DataProtection\Fixture\PersistingSensitiveEvents\AggregateEvent;
use Test\Ecotone\DataProtection\Fixture\PersistingSensitiveEvents\SomeAggregate;
use Test\Ecotone\DataProtection\Fixture\TestClass;
use Test\Ecotone\DataProtection\Fixture\TestEnum;

/**
 * @internal
 */
class EncryptStoredEventsTest extends TestCase
{
    private DbalConnectionFactory $connectionFactory;
    private Connection $connection;
    private string $streamName;

    protected function setUp(): void
    {
        $this->connectionFactory = new DbalConnectionFactory(getenv('DATABASE_DSN') ? getenv('DATABASE_DSN') : 'pgsql://ecotone:secret@127.0.0.1:5432/ecotone');
        $this->connection = $this->connectionFactory->establishConnection();

        self::clearDataTables($this->connection);
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    public function test_sensitive_events_will_be_stored_encrypted(): void
    {
        $encryptionKey = Key::createNewRandomKey();
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            containerOrAvailableServices: [
                DbalConnectionFactory::class => $this->connectionFactory,
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withLicenceKey(LicenceTesting::VALID_LICENCE)
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::EVENT_SOURCING_PACKAGE, ModulePackageList::DATA_PROTECTION_PACKAGE, ModulePackageList::JMS_CONVERTER_PACKAGE]))
                ->withNamespaces(['Test\Ecotone\DataProtection\Fixture\PersistingSensitiveEvents'])
                ->withExtensionObjects(
                    array_merge(
                        [
                            EventSourcingConfiguration::createWithDefaults(),
                            DataProtectionConfiguration::create('primary', $encryptionKey),
                            JMSConverterConfiguration::createWithDefaults()->withDefaultEnumSupport(true),
                        ],
                    )
                ),
            addInMemoryEventSourcedRepository: false,
        );
        $ecotone->withEventsFor(
            '123',
            SomeAggregate::class,
            $storedEvents = [
                new AggregateEvent('123', 'sensitive', TestEnum::FIRST, new TestClass('sensitive', TestEnum::FIRST)),
                new AggregateEvent('123', 'another sensitive', TestEnum::FIRST, new TestClass('another sensitive', TestEnum::FIRST)),
                new AggregateEvent('123', 'most sensitive', TestEnum::FIRST, new TestClass('most sensitive', TestEnum::FIRST)),
            ]
        );

        $this->streamName = $this->connection->fetchOne('select stream_name from event_streams where real_stream_name = ?', [SomeAggregate::class]);

        $storedPayloads = $this->connection->fetchFirstColumn(sprintf('select payload from %s', $this->streamName));
        foreach ($storedPayloads as $payload) {
            $payload = json_decode($payload, true);

            // decryption should not throw any exception
            Crypto::decrypt(base64_decode($payload['sensitiveValue']), $encryptionKey);
            Crypto::decrypt(base64_decode($payload['sensitiveEnum']), $encryptionKey);
            Crypto::decrypt(base64_decode($payload['sensitiveObject']), $encryptionKey);
        }

        self::assertEquals($storedEvents, array_map(static fn (Event $storedEvent) => $storedEvent->getPayload(), $ecotone->getEventStreamEvents(SomeAggregate::class)));
    }

    private static function clearDataTables(Connection $connection): void
    {
        foreach (self::getSchemaManager($connection)->listTableNames() as $tableName) {
            $sql = 'DROP TABLE ' . $tableName;

            $connection->executeQuery($sql);
        }
    }

    protected static function getSchemaManager(Connection $connection): AbstractSchemaManager
    {
        // Handle both DBAL 3.x (getSchemaManager) and 4.x (createSchemaManager)
        return method_exists($connection, 'getSchemaManager') ? $connection->getSchemaManager() : $connection->createSchemaManager();
    }
}
