<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration\DbalBusinessMethod;

use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\Dbal\DbalConnection;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Enqueue\Dbal\DbalConnectionFactory;
use Test\Ecotone\Dbal\DbalMessagingTestCase;
use Test\Ecotone\Dbal\Fixture\DbalBusinessInterface\PersonNameDTOConverter;
use Test\Ecotone\Dbal\Fixture\DbalBusinessInterface\PersonNameDTO;
use Test\Ecotone\Dbal\Fixture\DbalBusinessInterface\PersonQueryApi;
use Test\Ecotone\Dbal\Fixture\DbalBusinessInterface\PersonWriteApi;
use Test\Ecotone\Dbal\Fixture\DbalBusinessInterface\PersonRole;
use Test\Ecotone\Dbal\Fixture\DbalBusinessInterface\PersonRoleConverter;
use Test\Ecotone\Dbal\Fixture\ORM\Person\Person;

final class DbalQueryBusinessMethodTest extends DbalMessagingTestCase
{
    /**
     * @TODO
     * - allow to convert to camelCase
     * - storing whole object as single parameter
     * - Add expression language
     * - changed parameter name
     * - returning first row of first column or false (union)
     * - returning in given Media Type
     * - serializing with camel or snake case
     */


    public function test_fetching_data_from_database()
    {
        $ecotoneLite = $this->bootstrapEcotone();
        /** @var PersonWriteApi $personWriteGateway */
        $personWriteGateway = $ecotoneLite->getGateway(PersonWriteApi::class);
        $personWriteGateway->insert(1, 'John');

        $personQueryGateway = $ecotoneLite->getGateway(PersonQueryApi::class);
        $this->assertSame(
            [['person_id' => 1, 'name' => 'John']],
            $personQueryGateway->getNameList(1, 0)
        );
    }

    public function test_fetching_list_of_scalar_types()
    {
        $ecotoneLite = $this->bootstrapEcotone();
        /** @var PersonWriteApi $personWriteGateway */
        $personWriteGateway = $ecotoneLite->getGateway(PersonWriteApi::class);
        $personWriteGateway->insert(1, 'John');
        $personWriteGateway->insert(2, 'Marco');

        $personQueryGateway = $ecotoneLite->getGateway(PersonQueryApi::class);
        $this->assertSame(
            [['person_id' => 1], ['person_id' => 2]],
            $personQueryGateway->getPersonIds(2, 0)
        );
    }

    public function test_fetching_using_first_column_mode()
    {
        $ecotoneLite = $this->bootstrapEcotone();
        /** @var PersonWriteApi $personWriteGateway */
        $personWriteGateway = $ecotoneLite->getGateway(PersonWriteApi::class);
        $personWriteGateway->insert(1, 'John');
        $personWriteGateway->insert(2, 'Marco');

        $personQueryGateway = $ecotoneLite->getGateway(PersonQueryApi::class);
        $this->assertSame(
            [1, 2],
            $personQueryGateway->getExtractedPersonIds(2, 0)
        );
    }

    public function test_fetching_using_first_column_mode_of_first_row()
    {
        $ecotoneLite = $this->bootstrapEcotone();
        /** @var PersonWriteApi $personWriteGateway */
        $personWriteGateway = $ecotoneLite->getGateway(PersonWriteApi::class);
        $personWriteGateway->insert(1, 'John');
        $personWriteGateway->insert(2, 'Marco');

        $personQueryGateway = $ecotoneLite->getGateway(PersonQueryApi::class);
        $this->assertSame(
            2,
            $personQueryGateway->countPersons()
        );
    }

    public function test_fetching_using_single_row_result()
    {
        $ecotoneLite = $this->bootstrapEcotone();
        /** @var PersonWriteApi $personWriteGateway */
        $personWriteGateway = $ecotoneLite->getGateway(PersonWriteApi::class);
        $personWriteGateway->insert(1, 'John');

        $personQueryGateway = $ecotoneLite->getGateway(PersonQueryApi::class);
        $this->assertEquals(
            new PersonNameDTO(1, 'John'),
            $personQueryGateway->getNameDTO(1)
        );
    }

    public function test_fetching_and_converting_list_to_dtos()
    {
        $ecotoneLite = $this->bootstrapEcotone();
        /** @var PersonWriteApi $personWriteGateway */
        $personWriteGateway = $ecotoneLite->getGateway(PersonWriteApi::class);
        $personWriteGateway->insert(1, 'John');

        $personQueryGateway = $ecotoneLite->getGateway(PersonQueryApi::class);
        $this->assertEquals(
            [new PersonNameDTO(1, 'John')],
            $personQueryGateway->getNameListDTO(1, 0)
        );
    }

    public function test_using_custom_dbal_parameter_conversion_media_type_with_value_objects()
    {
        $ecotoneLite = $this->bootstrapEcotone();
        /** @var PersonWriteApi $personGateway */
        $personGateway = $ecotoneLite->getGateway(PersonWriteApi::class);

        $personGateway->insert(1, 'John');
        $personGateway->changeRolesWithValueObjects(1, [new PersonRole('ROLE_ADMIN')]);

        $this->assertSame(
            ['ROLE_ADMIN'],
            $ecotoneLite->sendQueryWithRouting('person.getRoles', metadata: ['aggregate.id' => 1])
        );
    }

    private function bootstrapEcotone(): FlowTestSupport
    {
        $this->setupUserTable();

        return EcotoneLite::bootstrapFlowTesting(
            containerOrAvailableServices: array_merge(
                [
                    DbalConnectionFactory::class => $this->getORMConnectionFactory([__DIR__.'/../Fixture/ORM/Person']),
                    PersonRoleConverter::class => new PersonRoleConverter(),
                    PersonNameDTOConverter::class => new PersonNameDTOConverter(),
                ],
            ),
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE, ModulePackageList::JMS_CONVERTER_PACKAGE]))
                ->withNamespaces([
                    'Test\Ecotone\Dbal\Fixture\DbalBusinessInterface',
                    'Test\Ecotone\Dbal\Fixture\ORM\Person',
                ])
                ->withExtensionObjects([
                    DbalConfiguration::createWithDefaults()
                        ->withDoctrineORMRepositories(true, [Person::class]),
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
            addInMemoryStateStoredRepository: false
        );
    }
}