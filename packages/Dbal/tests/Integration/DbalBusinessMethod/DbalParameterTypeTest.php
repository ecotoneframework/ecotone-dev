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
use Test\Ecotone\Dbal\Fixture\DbalBusinessInterface\ParameterDbalTypeConversion;
use Test\Ecotone\Dbal\Fixture\DbalBusinessInterface\PersonNameDTOConverter;
use Test\Ecotone\Dbal\Fixture\DbalBusinessInterface\PersonRoleConverter;
use Test\Ecotone\Dbal\Fixture\DbalBusinessInterface\PersonWriteApi;
use Test\Ecotone\Dbal\Fixture\ORM\Person\Person;

/**
 * @internal
 */
final class DbalParameterTypeTest extends DbalMessagingTestCase
{
    public function test_using_predefined_parameter_type()
    {
        $ecotoneLite = $this->bootstrapEcotone();
        /** @var PersonWriteApi $personWriteGateway */
        $personWriteGateway = $ecotoneLite->getGateway(PersonWriteApi::class);
        $personWriteGateway->insert(1, 'John');

        $personQueryGateway = $ecotoneLite->getGateway(ParameterDbalTypeConversion::class);
        $this->assertSame(
            [],
            $personQueryGateway->getPersonsWith([2])
        );
        $this->assertSame(
            [['person_id' => 1, 'name' => 'John']],
            $personQueryGateway->getPersonsWith([1])
        );
    }

    public function test_using_auto_resolved_parameter_type()
    {
        $ecotoneLite = $this->bootstrapEcotone();
        /** @var PersonWriteApi $personWriteGateway */
        $personWriteGateway = $ecotoneLite->getGateway(PersonWriteApi::class);
        $personWriteGateway->insert(1, 'John');

        $personQueryGateway = $ecotoneLite->getGateway(ParameterDbalTypeConversion::class);
        $this->assertSame(
            [],
            $personQueryGateway->getPersonsWithAutoresolve([2])
        );
        $this->assertSame(
            [['person_id' => 1, 'name' => 'John']],
            $personQueryGateway->getPersonsWithAutoresolve([1])
        );
    }

    public function test_using_predefined_parameter_type_on_method_level_attribute()
    {
        $ecotoneLite = $this->bootstrapEcotone();
        /** @var PersonWriteApi $personWriteGateway */
        $personWriteGateway = $ecotoneLite->getGateway(PersonWriteApi::class);
        $personWriteGateway->insert(1, 'John');

        $personQueryGateway = $ecotoneLite->getGateway(ParameterDbalTypeConversion::class);
        $this->assertSame(
            [['person_id' => 1, 'name' => 'John']],
            $personQueryGateway->getPersonsWithWithMethodLevelParameter()
        );
    }

    public function test_using_predefined_parameter_type_on_method_level_attribute_and_autoresolve()
    {
        $ecotoneLite = $this->bootstrapEcotone();
        /** @var PersonWriteApi $personWriteGateway */
        $personWriteGateway = $ecotoneLite->getGateway(PersonWriteApi::class);
        $personWriteGateway->insert(1, 'John');

        $personQueryGateway = $ecotoneLite->getGateway(ParameterDbalTypeConversion::class);
        $this->assertSame(
            [['person_id' => 1, 'name' => 'John']],
            $personQueryGateway->getPersonsWithMethodLevelParameterAndAutoresolve(['John'])
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
