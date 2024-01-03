<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration\DbalBusinessMethod;

use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Enqueue\Dbal\DbalConnectionFactory;
use Test\Ecotone\Dbal\DbalMessagingTestCase;
use Test\Ecotone\Dbal\Fixture\DbalBusinessInterface\PersonNameDTO;
use Test\Ecotone\Dbal\Fixture\DbalBusinessInterface\PersonNameDTOConverter;
use Test\Ecotone\Dbal\Fixture\DbalBusinessInterface\PersonQueryApi;
use Test\Ecotone\Dbal\Fixture\DbalBusinessInterface\PersonRoleConverter;
use Test\Ecotone\Dbal\Fixture\DbalBusinessInterface\PersonWriteApi;
use Test\Ecotone\Dbal\Fixture\ORM\Person\Person;

/**
 * @internal
 */
final class DbalQueryBusinessMethodTest extends DbalMessagingTestCase
{
    /**
     * - automatic paramter binding based on type
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

    public function test_fetching_using_single_row_result_allowing_nulls()
    {
        $ecotoneLite = $this->bootstrapEcotone();

        $personQueryGateway = $ecotoneLite->getGateway(PersonQueryApi::class);
        $this->assertNull($personQueryGateway->getNameDTOOrNull(1));
    }

    public function test_fetching_using_single_row_result_allowing_false()
    {
        $ecotoneLite = $this->bootstrapEcotone();

        $personQueryGateway = $ecotoneLite->getGateway(PersonQueryApi::class);
        $this->assertFalse($personQueryGateway->getNameDTOOrFalse(1));
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

    public function test_fetching_to_specific_format()
    {
        $ecotoneLite = $this->bootstrapEcotone();
        /** @var PersonWriteApi $personWriteGateway */
        $personWriteGateway = $ecotoneLite->getGateway(PersonWriteApi::class);
        $personWriteGateway->insert(1, 'John');

        $personQueryGateway = $ecotoneLite->getGateway(PersonQueryApi::class);
        $this->assertEquals(
            '{"person_id":1,"name":"John"}',
            $personQueryGateway->getNameDTOInJson(1)
        );
    }

    public function test_using_iterator_to_fetch_results()
    {
        $ecotoneLite = $this->bootstrapEcotone();
        /** @var PersonWriteApi $personWriteGateway */
        $personWriteGateway = $ecotoneLite->getGateway(PersonWriteApi::class);
        $personDTOs = [new PersonNameDTO(1, 'John1'), new PersonNameDTO(2, 'John2')];
        ;
        $personWriteGateway->insert(1, 'John1');
        $personWriteGateway->insert(2, 'John2');

        $personQueryGateway = $ecotoneLite->getGateway(PersonQueryApi::class);
        foreach($personQueryGateway->getPersonIdsIterator() as $key => $personDTO) {
            $this->assertEquals(
                $personDTOs[$key],
                $personDTO
            );
        }
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
