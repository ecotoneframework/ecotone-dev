<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration\MultiTenant;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Attribute\Reference;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\ExtensionObject\MultiTenantConfiguration;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\TestConfiguration;
use Ecotone\Api\Gateway\DocumentStore;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\ConsoleCommandResultSet;
use Ecotone\Messaging\Gateway\ConsoleCommandRunner;
use Ecotone\Test\LicenceTesting;
use Test\Ecotone\Dbal\DbalMessagingTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class DatabaseSetupMultiTenantTest extends DbalMessagingTestCase
{
    public function test_database_setup_creates_the_tables_in_every_tenant_database(): void
    {
        $ecotone = $this->bootstrapEcotone();
        $this->executeConsoleCommand($ecotone, 'ecotone:migration:database:delete', ['force' => true]);

        $this->executeConsoleCommand($ecotone, 'ecotone:migration:database:setup', ['initialize' => true]);

        $ecotone->sendCommandWithRouting('note.add', 'note-a', metadata: ['tenant' => 'tenant_a']);
        $ecotone->sendCommandWithRouting('note.add', 'note-b1', metadata: ['tenant' => 'tenant_b']);
        $ecotone->sendCommandWithRouting('note.add', 'note-b2', metadata: ['tenant' => 'tenant_b']);
        $this->assertSame(1, $ecotone->sendQueryWithRouting('note.count', metadata: ['tenant' => 'tenant_a']));
        $this->assertSame(2, $ecotone->sendQueryWithRouting('note.count', metadata: ['tenant' => 'tenant_b']));
    }

    public function test_database_setup_reports_the_features_initialized_once_every_tenant_database_has_their_tables(): void
    {
        $ecotone = $this->bootstrapEcotone();
        $this->executeConsoleCommand($ecotone, 'ecotone:migration:database:delete', ['force' => true]);

        $this->assertSame(['No'], $this->initializationOf($ecotone));

        $this->executeConsoleCommand($ecotone, 'ecotone:migration:database:setup', ['initialize' => true]);

        $this->assertSame(['Yes'], $this->initializationOf($ecotone));
    }

    public function test_database_setup_returns_the_sql_of_every_tenant_database_labelled_with_its_connection(): void
    {
        $ecotone = $this->bootstrapEcotone();

        $sql = $this->executeConsoleCommand($ecotone, 'ecotone:migration:database:setup', ['sql' => true])->getRows()[0][0];

        $this->assertStringContainsString("-- connection 'tenant_a_connection'", $sql);
        $this->assertStringContainsString("-- connection 'tenant_b_connection'", $sql);
        $this->assertSame(2, substr_count($sql, 'ecotone_document_store'));
    }

    public function test_database_delete_drops_the_tables_in_every_tenant_database(): void
    {
        $ecotone = $this->bootstrapEcotone();
        $this->executeConsoleCommand($ecotone, 'ecotone:migration:database:setup', ['initialize' => true]);

        $this->executeConsoleCommand($ecotone, 'ecotone:migration:database:delete', ['force' => true]);

        foreach (['tenant_a', 'tenant_b'] as $tenant) {
            try {
                $ecotone->sendCommandWithRouting('note.add', 'note', metadata: ['tenant' => $tenant]);
                $this->fail("The document store table of {$tenant} survived the delete");
            } catch (ConfigurationException $exception) {
                $this->assertStringContainsString('ecotone_document_store', $exception->getMessage());
            }
        }
    }

    private function initializationOf(FlowTestSupport $ecotone): array
    {
        return array_values(array_unique(array_column(
            $this->executeConsoleCommand($ecotone, 'ecotone:migration:database:setup', [])->getRows(),
            3,
        )));
    }

    private function executeConsoleCommand(FlowTestSupport $ecotone, string $commandName, array $parameters): ConsoleCommandResultSet
    {
        return $ecotone->getGateway(ConsoleCommandRunner::class)->execute($commandName, $parameters);
    }

    private function bootstrapEcotone(): FlowTestSupport
    {
        $notes = new class () {
            #[CommandHandler('note.add')]
            public function add(string $note, #[Reference] DocumentStore $documentStore): void
            {
                $documentStore->addDocument('notes', $note, json_encode(['note' => $note]));
            }

            #[QueryHandler('note.count')]
            public function count(#[Reference] DocumentStore $documentStore): int
            {
                return $documentStore->countDocuments('notes');
            }
        };

        return $this->bootstrapFlowTesting(
            [$notes::class],
            [
                $notes,
                'tenant_a_connection' => $this->connectionForTenantA(),
                'tenant_b_connection' => $this->connectionForTenantB(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withLicenceKey(LicenceTesting::VALID_LICENCE)
                ->withEnabledModulePackageNames([ModulePackageList::DBAL_PACKAGE])
                ->withExtensionObjects([
                    MultiTenantConfiguration::create(
                        tenantHeaderName: 'tenant',
                        tenantToConnectionMapping: [
                            'tenant_a' => 'tenant_a_connection',
                            'tenant_b' => 'tenant_b_connection',
                        ],
                    ),
                    DbalConfiguration::createWithDefaults()
                        ->withDocumentStore(),
                ]),
            testConfiguration: TestConfiguration::createWithDefaults()->withDatabaseTablesSetUpOnBootstrap(false),
        );
    }
}
