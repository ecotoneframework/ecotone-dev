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
use Test\Ecotone\Dbal\Fixture\DbalBusinessInterface\ClassLevelDbalParameterWriteApi;
use Test\Ecotone\Dbal\Fixture\DbalBusinessInterface\DateTimeToDayStringConverter;
use Test\Ecotone\Dbal\Fixture\DbalBusinessInterface\PersonName;
use Test\Ecotone\Dbal\Fixture\DbalBusinessInterface\PersonNameDTOConverter;
use Test\Ecotone\Dbal\Fixture\DbalBusinessInterface\PersonNameNormalizer;
use Test\Ecotone\Dbal\Fixture\DbalBusinessInterface\PersonRole;
use Test\Ecotone\Dbal\Fixture\DbalBusinessInterface\PersonRoleConverter;
use Test\Ecotone\Dbal\Fixture\DbalBusinessInterface\PersonService;
use Test\Ecotone\Dbal\Fixture\ORM\Person\Person;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class DbalWriteBusinessMethodTest extends DbalMessagingTestCase
{
    public function test_write_statement_with_no_return_and_automatic_parameter_binding()
    {
        $ecotoneLite = $this->bootstrapEcotone();
        /** @var PersonService $personGateway */
        $personGateway = $ecotoneLite->getGateway(PersonService::class);

        $personGateway->insert(1, 'John');

        $this->assertSame(
            'John',
            $ecotoneLite->sendQueryWithRouting('person.getName', metadata: ['aggregate.id' => 1])
        );
    }

    public function test_write_statement_with_no_return_and_manual_parameter_binding()
    {
        $ecotoneLite = $this->bootstrapEcotone();
        /** @var PersonService $personGateway */
        $personGateway = $ecotoneLite->getGateway(PersonService::class);

        $personGateway->insertWithParameterName(1, 'John');

        $this->assertSame(
            'John',
            $ecotoneLite->sendQueryWithRouting('person.getName', metadata: ['aggregate.id' => 1])
        );
    }

    public function test_write_statement_with_return_of_amount_of_changed_rows()
    {
        $ecotoneLite = $this->bootstrapEcotone();
        /** @var PersonService $personGateway */
        $personGateway = $ecotoneLite->getGateway(PersonService::class);

        $personGateway->insert(1, 'John');
        $this->assertSame(
            1,
            $personGateway->changeName(1, 'Johny Bravo')
        );

        $this->assertSame(
            'Johny Bravo',
            $ecotoneLite->sendQueryWithRouting('person.getName', metadata: ['aggregate.id' => 1])
        );
        ;
    }

    public function test_using_custom_dbal_parameter_conversion_media_type()
    {
        $ecotoneLite = $this->bootstrapEcotone();
        /** @var PersonService $personGateway */
        $personGateway = $ecotoneLite->getGateway(PersonService::class);

        $personGateway->insert(1, 'John');
        $personGateway->changeRoles(1, ['ROLE_ADMIN']);

        $this->assertSame(
            ['ROLE_ADMIN'],
            $ecotoneLite->sendQueryWithRouting('person.getRoles', metadata: ['aggregate.id' => 1])
        );
        ;
    }

    public function test_using_custom_dbal_parameter_conversion_media_type_with_value_objects()
    {
        $ecotoneLite = $this->bootstrapEcotone();
        /** @var PersonService $personGateway */
        $personGateway = $ecotoneLite->getGateway(PersonService::class);

        $personGateway->insert(1, 'John');
        $personGateway->changeRolesWithValueObjects(1, [new PersonRole('ROLE_ADMIN')]);

        $this->assertSame(
            ['ROLE_ADMIN'],
            $ecotoneLite->sendQueryWithRouting('person.getRoles', metadata: ['aggregate.id' => 1])
        );
    }

    public function test_using_expression_language_on_parameter_value()
    {
        $ecotoneLite = $this->bootstrapEcotone();
        /** @var PersonService $personGateway */
        $personGateway = $ecotoneLite->getGateway(PersonService::class);

        $personGateway->insertWithExpression(1, new PersonName('John'));

        $this->assertSame(
            'john',
            $ecotoneLite->sendQueryWithRouting('person.getName', metadata: ['aggregate.id' => 1])
        );
    }

    public function test_using_expression_language_using_service()
    {
        $ecotoneLite = $this->bootstrapEcotone();
        /** @var PersonService $personGateway */
        $personGateway = $ecotoneLite->getGateway(PersonService::class);

        $personGateway->insertWithServiceExpression(1, new PersonName('John'));

        $this->assertSame(
            'john',
            $ecotoneLite->sendQueryWithRouting('person.getName', metadata: ['aggregate.id' => 1])
        );
    }

    public function test_using_expression_language_with_method_level_dbal_parameter()
    {
        $ecotoneLite = $this->bootstrapEcotone();
        /** @var PersonService $personGateway */
        $personGateway = $ecotoneLite->getGateway(PersonService::class);

        $personGateway->registerAdmin(1, 'John');

        $this->assertSame(
            ['ROLE_ADMIN'],
            $ecotoneLite->sendQueryWithRouting('person.getRoles', metadata: ['aggregate.id' => 1])
        );
    }

    public function test_using_expression_language_with_method_level_dbal_parameter_and_parameters_in_expression()
    {
        $ecotoneLite = $this->bootstrapEcotone();
        /** @var PersonService $personGateway */
        $personGateway = $ecotoneLite->getGateway(PersonService::class);

        $personGateway->registerUsingMethodParameters(1, 'Johny');
        $this->assertSame(
            ['ROLE_ADMIN'],
            $ecotoneLite->sendQueryWithRouting('person.getRoles', metadata: ['aggregate.id' => 1])
        );

        $personGateway->registerUsingMethodParameters(2, 'Franco');
        $this->assertSame(
            [],
            $ecotoneLite->sendQueryWithRouting('person.getRoles', metadata: ['aggregate.id' => 2])
        );
    }

    public function test_using_expression_language_with_class_level_dbal_parameter()
    {
        $ecotoneLite = $this->bootstrapEcotone();
        /** @var ClassLevelDbalParameterWriteApi $personGateway */
        $personGateway = $ecotoneLite->getGateway(ClassLevelDbalParameterWriteApi::class);

        $personGateway->registerUsingMethodParameters(1, 'Johny');
        $this->assertSame(
            ['ROLE_ADMIN'],
            $ecotoneLite->sendQueryWithRouting('person.getRoles', metadata: ['aggregate.id' => 1])
        );

        $personGateway->registerUsingMethodParameters(2, 'Franco');
        $this->assertSame(
            [],
            $ecotoneLite->sendQueryWithRouting('person.getRoles', metadata: ['aggregate.id' => 2])
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
                    'converter' => new PersonNameNormalizer(),
                    DateTimeToDayStringConverter::class => new DateTimeToDayStringConverter(),
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
