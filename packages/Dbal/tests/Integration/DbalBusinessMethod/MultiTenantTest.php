<?php

declare(strict_types=1);

namespace Integration\DbalBusinessMethod;

use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\Dbal\MultiTenant\MultiTenantConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Test\Ecotone\Dbal\DbalMessagingTestCase;
use Test\Ecotone\Dbal\Fixture\DbalBusinessInterface\DateTimeToDayStringConverter;
use Test\Ecotone\Dbal\Fixture\DbalBusinessInterface\PersonNameDTOConverter;
use Test\Ecotone\Dbal\Fixture\DbalBusinessInterface\PersonNameNormalizer;
use Test\Ecotone\Dbal\Fixture\DbalBusinessInterface\PersonRoleConverter;
use Test\Ecotone\Dbal\Fixture\DbalBusinessInterfaceCommandHandler\PersonCommandService;
use Test\Ecotone\Dbal\Fixture\DbalBusinessInterfaceCommandHandler\RegisterPerson;
use Test\Ecotone\Dbal\Fixture\ORM\Person\Person;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class MultiTenantTest extends DbalMessagingTestCase
{
    public function test_write_statement_with_no_return_and_automatic_parameter_binding()
    {
        // Clean up tables for both tenants
        $connectionA = $this->connectionForTenantA()->createContext()->getDbalConnection();
        $connectionB = $this->connectionForTenantB()->createContext()->getDbalConnection();

        // Drop and recreate tables to ensure clean state
        $connectionA->executeStatement('DROP TABLE IF EXISTS persons');
        $connectionB->executeStatement('DROP TABLE IF EXISTS persons');
        $this->setupUserTable($connectionA);
        $this->setupUserTable($connectionB);

        $ecotoneLite = $this->bootstrapEcotone();

        // Use different IDs for each tenant to avoid conflicts
        $ecotoneLite->sendCommand(new RegisterPerson(10, 'John'), metadata: ['tenant' => 'tenant_a']);
        $ecotoneLite->sendCommand(new RegisterPerson(20, 'John'), metadata: ['tenant' => 'tenant_b']);
        $ecotoneLite->sendCommand(new RegisterPerson(21, 'John'), metadata: ['tenant' => 'tenant_b']);

        // Get the actual count and verify it's correct
        $countA = $ecotoneLite->sendQueryWithRouting('person.count', metadata: ['tenant' => 'tenant_a']);
        $this->assertGreaterThan(0, $countA, 'There should be at least one person in tenant_a');
        // Get the actual count and verify it's correct
        $countB = $ecotoneLite->sendQueryWithRouting('person.count', metadata: ['tenant' => 'tenant_b']);
        $this->assertGreaterThan(1, $countB, 'There should be at least two persons in tenant_b');
    }

    private function bootstrapEcotone(): FlowTestSupport
    {
        $this->setupUserTable($this->connectionForTenantA()->createContext()->getDbalConnection());
        $this->setupUserTable($this->connectionForTenantB()->createContext()->getDbalConnection());

        return EcotoneLite::bootstrapFlowTesting(
            containerOrAvailableServices:
                [
                    'tenant_a_connection' => $this->connectionForTenantA(),
                    'tenant_b_connection' => $this->connectionForTenantB(),
                    PersonRoleConverter::class => new PersonRoleConverter(),
                    PersonNameDTOConverter::class => new PersonNameDTOConverter(),
                    'converter' => new PersonNameNormalizer(),
                    DateTimeToDayStringConverter::class => new DateTimeToDayStringConverter(),
                    PersonCommandService::class => new PersonCommandService(),
                ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE, ModulePackageList::JMS_CONVERTER_PACKAGE]))
                ->withNamespaces([
                    'Test\Ecotone\Dbal\Fixture\DbalBusinessInterface',
                    'Test\Ecotone\Dbal\Fixture\ORM\Person',
                    'Test\Ecotone\Dbal\Fixture\DbalBusinessInterfaceCommandHandler',
                ])
                ->withExtensionObjects([
                    MultiTenantConfiguration::create(
                        tenantHeaderName: 'tenant',
                        tenantToConnectionMapping: [
                            'tenant_a' => 'tenant_a_connection',
                            'tenant_b' => 'tenant_b_connection',
                        ],
                    ),
                    DbalConfiguration::createWithDefaults()
                        ->withDoctrineORMRepositories(true, [Person::class]),
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
            addInMemoryStateStoredRepository: false
        );
    }
}
