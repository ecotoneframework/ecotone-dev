<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration;

use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\ExtensionObject\DbalConnectionReference;
use Ecotone\Api\Dbal\Gateway\DeadLetterGateway;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\TestConfiguration;
use Ecotone\Api\Gateway\DocumentStore;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\Messaging\Config\ConfigurationException;
use Test\Ecotone\Dbal\DbalMessagingTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class SetupDatabaseTablesTest extends DbalMessagingTestCase
{
    public function test_the_test_bootstrap_sets_up_the_tables_the_test_uses(): void
    {
        $ecotone = $this->bootstrapFlowTesting(
            containerOrAvailableServices: [DbalConnectionFactory::class => $this->getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::DBAL_PACKAGE])
                ->withExtensionObjects([DbalConfiguration::createWithDefaults()]),
        );

        $ecotone->getGateway(DocumentStore::class)->addDocument('orders', 'o-1', '{"product":"milk"}');

        self::assertJsonStringEqualsJsonString('{"product":"milk"}', $ecotone->getGateway(DocumentStore::class)->getDocument('orders', 'o-1'));
        self::assertSame(0, $ecotone->getGateway(DeadLetterGateway::class)->count());
    }

    public function test_a_test_that_turned_the_bootstrap_set_up_off_is_refused_until_it_sets_the_tables_up(): void
    {
        $ecotone = $this->bootstrapFlowTesting(
            containerOrAvailableServices: [DbalConnectionFactory::class => $this->getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::DBAL_PACKAGE])
                ->withExtensionObjects([DbalConfiguration::createWithDefaults()]),
            testConfiguration: TestConfiguration::createWithDefaults()->withDatabaseTablesSetUpOnBootstrap(false),
        );

        try {
            $ecotone->getGateway(DocumentStore::class)->addDocument('orders', 'o-1', '{"product":"milk"}');
            self::fail('Expected the missing document store table to be refused');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString("required by the 'document_store' feature does not exist", $exception->getMessage());
        }

        $ecotone->setupDatabaseTables();
        $ecotone->getGateway(DocumentStore::class)->addDocument('orders', 'o-1', '{"product":"milk"}');

        self::assertJsonStringEqualsJsonString('{"product":"milk"}', $ecotone->getGateway(DocumentStore::class)->getDocument('orders', 'o-1'));
    }

    public function test_the_bootstrap_leaves_out_a_connection_the_test_does_not_register(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            configuration: ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::DBAL_PACKAGE])
                ->withExtensionObjects([DbalConfiguration::createWithDefaults()]),
        );

        self::assertSame([], $ecotone->popRecordedEvents());
    }

    public function test_setting_up_tables_names_the_connection_a_used_feature_needs_and_the_test_does_not_register(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            configuration: ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::DBAL_PACKAGE])
                ->withExtensionObjects([DbalConfiguration::createWithDefaults()]),
        );

        try {
            $ecotone->setupDatabaseTables();
            self::fail('Expected the unregistered connection to be refused');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString('setupDatabaseTables() cannot create the tables of', $exception->getMessage());
            self::assertStringContainsString("'document_store'", $exception->getMessage());
            self::assertStringContainsString("on connection '" . DbalConnectionReference::DEFAULT . "'", $exception->getMessage());
            self::assertStringContainsString('withDocumentStore(inMemoryDocumentStore: true)', $exception->getMessage());
        }
    }
}
