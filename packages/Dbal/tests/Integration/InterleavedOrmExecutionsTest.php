<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Dbal\Attribute\DbalQuery;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\FetchMode;
use Ecotone\Api\Dbal\ManagerRegistryProvider;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\TestConfiguration;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Test\LicenceTesting;
use Fiber;
use RuntimeException;
use Test\Ecotone\Dbal\DbalMessagingTestCase;
use Test\Ecotone\Dbal\Fixture\ORM\Person\Person;
use Test\Ecotone\Dbal\Fixture\ORM\Person\RegisterPerson;

/**
 * licence Apache-2.0
 * @internal
 */
final class InterleavedOrmExecutionsTest extends DbalMessagingTestCase
{
    public function test_entity_stored_by_a_suspended_execution_that_fails_is_not_stored_with_an_interleaved_one(): void
    {
        $this->skipWhenTwoConnectionsCannotWriteAtOnce();

        $ecotone = $this->bootstrapPersonRegistration();

        $suspended = new Fiber(function () use ($ecotone): void {
            try {
                $ecotone->sendCommandWithRouting('person.registerEntity', ['personId' => 1, 'name' => 'refused', 'suspend' => true, 'fail' => true]);
            } catch (RuntimeException) {
            }
        });
        $suspended->start();
        $ecotone->sendCommandWithRouting('person.registerEntity', ['personId' => 2, 'name' => 'accepted']);
        $suspended->resume();

        $this->assertSame(['accepted'], $ecotone->getGateway(RegisteredPersons::class)->names());
    }

    public function test_entity_stored_by_an_interleaved_execution_that_fails_is_not_stored_with_the_suspended_one(): void
    {
        $this->skipWhenTwoConnectionsCannotWriteAtOnce();

        $ecotone = $this->bootstrapPersonRegistration();

        $suspended = new Fiber(fn () => $ecotone->sendCommandWithRouting('person.registerEntity', ['personId' => 1, 'name' => 'accepted', 'suspend' => true]));
        $suspended->start();
        try {
            $ecotone->sendCommandWithRouting('person.registerEntity', ['personId' => 2, 'name' => 'refused', 'fail' => true]);
        } catch (RuntimeException) {
        }
        $suspended->resume();

        $this->assertSame(['accepted'], $ecotone->getGateway(RegisteredPersons::class)->names());
    }

    private function bootstrapPersonRegistration(): FlowTestSupport
    {
        $this->setupUserTable();
        $registration = new class () {
            #[CommandHandler('person.registerEntity')]
            public function registerEntity(array $command, CommandBus $commandBus): void
            {
                $commandBus->send(new RegisterPerson($command['personId'], $command['name']));

                if ($command['suspend'] ?? false) {
                    Fiber::suspend();
                }
                if ($command['fail'] ?? false) {
                    throw new RuntimeException('Registration was refused');
                }
            }
        };

        return $this->bootstrapFlowTesting(
            classesToResolve: [$registration::class, RegisteredPersons::class],
            containerOrAvailableServices: [
                $registration,
                DbalConnectionFactory::class => ManagerRegistryProvider::fromDsnAndConfig(self::databaseDsn(), [__DIR__ . '/../Fixture/ORM/Person']),
            ],
            extensionObjects: [DbalConfiguration::createWithDefaults()->withDoctrineORMRepositories(true, [Person::class])],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            pathToRootCatalog: __DIR__ . '/../../',
            addInMemoryStateStoredRepository: false,
            testConfiguration: TestConfiguration::createWithDefaults()->withDefaultInstantRetries(false),
            licenceKey: LicenceTesting::VALID_LICENCE,
            namespaces: ['Test\Ecotone\Dbal\Fixture\ORM\Person'],
            environment: 'prod',
        );
    }
}

interface RegisteredPersons
{
    #[DbalQuery('SELECT name FROM persons ORDER BY person_id', fetchMode: FetchMode::FIRST_COLUMN)]
    public function names(): array;
}
