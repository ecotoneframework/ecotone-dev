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
final class MultiTenantTest extends DbalMessagingTestCase
{
    public function test_write_statement_with_no_return_and_automatic_parameter_binding()
    {
        $ecotoneLite = $this->bootstrapEcotone();

        $ecotoneLite->sendCommand(new RegisterPerson(1, 'John'), metadata: ['tenant' => 'tenant_a']);
        $ecotoneLite->sendCommand(new RegisterPerson(1, 'John'), metadata: ['tenant' => 'tenant_b']);
        $ecotoneLite->sendCommand(new RegisterPerson(2, 'John'), metadata: ['tenant' => 'tenant_b']);

        $this->assertSame(
            1,
            $ecotoneLite->sendQueryWithRouting('person.count', metadata: ['tenant' => 'tenant_a'])
        );
        $this->assertSame(
            2,
            $ecotoneLite->sendQueryWithRouting('person.count', metadata: ['tenant' => 'tenant_b'])
        );
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
