<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Dbal\Attribute\DbalWrite;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\ManagerRegistryProvider;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ConfigurationException;
use Fiber;
use Test\Ecotone\Dbal\DbalMessagingTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class ManagerRegistrySharedByExecutionsTest extends DbalMessagingTestCase
{
    private const REFUSAL = 'Database connection `default` of a Doctrine ManagerRegistry is used by two executions of this process at once (coroutines or fibers running concurrently, or one a handler started). '
        . 'A ManagerRegistry keeps a single connection for the whole process, so the statements of both messages would run on it: their writes are not atomic, a transaction of one includes what the other wrote, and MySQL refuses their concurrent queries. '
        . 'Ecotone gives every concurrent execution its own connection when it can open one itself: register the connection from a DSN (new Ecotone\\Api\\Dbal\\DbalConnectionFactory($dsn)), in Symfony register the DBAL connection with SymfonyConnectionReference::defaultConnection() or ::createForConnection(), which work on copies of that connection, or keep Doctrine ORM on a connection per execution with Ecotone\\Api\\Dbal\\ManagerRegistryProvider::fromDsnAndConfig($dsn, $pathsToMapping). '
        . 'To keep the ManagerRegistry, handle one message at a time per process: run consumers without the `coroutines` option of ecotone:run and PollingMetadata::withCoroutines(), set `enable_coroutine` to false on a Swoole or OpenSwoole server, and do not use the connection from a coroutine or fiber a handler starts.';

    public function test_manager_registry_connection_used_without_a_transaction_while_a_suspended_execution_uses_it_is_refused(): void
    {
        $ecotone = $this->bootstrapPersonRegistration();
        $suspended = new Fiber(static fn () => $ecotone->sendCommandWithRouting('person.register', ['personId' => 1, 'name' => 'accepted', 'suspend' => true]));
        $suspended->start();

        try {
            $ecotone->sendCommandWithRouting('person.register', ['personId' => 2, 'name' => 'interleaved']);
            $this->fail();
        } catch (ConfigurationException $exception) {
            $this->assertSame(self::REFUSAL, $exception->getMessage());
        } finally {
            $suspended->resume();
        }

        $this->assertSame(['accepted'], $ecotone->sendQueryWithRouting('person.registered'));
    }

    public function test_consumer_using_a_manager_registry_connection_without_a_transaction_while_a_suspended_execution_uses_it_is_refused(): void
    {
        $ecotone = $this->bootstrapPersonRegistration();
        $ecotone->sendCommandWithRouting('person.registerLater', ['personId' => 2, 'name' => 'interleaved']);
        $suspended = new Fiber(static fn () => $ecotone->sendCommandWithRouting('person.register', ['personId' => 1, 'name' => 'accepted', 'suspend' => true]));
        $suspended->start();

        try {
            $ecotone->run('registrations', ExecutionPollingMetadata::createWithFinishWhenNoMessages());
            $this->fail();
        } catch (ConfigurationException $exception) {
            $this->assertSame(self::REFUSAL, $exception->getMessage());
        } finally {
            $suspended->resume();
        }

        $this->assertSame(['accepted'], $ecotone->sendQueryWithRouting('person.registered'));
    }

    public function test_manager_registry_connection_is_used_by_one_execution_after_another_without_a_transaction(): void
    {
        $ecotone = $this->bootstrapPersonRegistration();

        (new Fiber(static fn () => $ecotone->sendCommandWithRouting('person.register', ['personId' => 1, 'name' => 'first'])))->start();
        (new Fiber(static fn () => $ecotone->sendCommandWithRouting('person.register', ['personId' => 2, 'name' => 'second'])))->start();
        $ecotone->sendCommandWithRouting('person.register', ['personId' => 3, 'name' => 'third']);

        $this->assertSame(['first', 'second', 'third'], $ecotone->sendQueryWithRouting('person.registered'));
    }

    public function test_manager_registry_connection_is_free_again_once_the_fiber_that_used_it_has_finished_though_the_application_keeps_the_fiber(): void
    {
        $ecotone = $this->bootstrapPersonRegistration();

        $first = new Fiber(static fn () => $ecotone->sendCommandWithRouting('person.register', ['personId' => 1, 'name' => 'first']));
        $first->start();
        $second = new Fiber(static fn () => $ecotone->sendCommandWithRouting('person.register', ['personId' => 2, 'name' => 'second']));
        $second->start();
        $ecotone->sendCommandWithRouting('person.register', ['personId' => 3, 'name' => 'third']);

        $this->assertTrue($first->isTerminated() && $second->isTerminated());
        $this->assertSame(['first', 'second', 'third'], $ecotone->sendQueryWithRouting('person.registered'));
    }

    private function bootstrapPersonRegistration(): FlowTestSupport
    {
        $this->setupUserTable();
        $registration = new class () {
            private array $registered = [];

            #[CommandHandler('person.register')]
            public function register(array $command, PersonsOfManagerRegistry $persons): void
            {
                $persons->register($command['personId'], $command['name']);
                if ($command['suspend'] ?? false) {
                    Fiber::suspend();
                }
                $this->registered[] = $command['name'];
            }

            #[Asynchronous('registrations')]
            #[CommandHandler('person.registerLater', endpointId: 'person.registerLater.endpoint')]
            public function registerLater(array $command, PersonsOfManagerRegistry $persons): void
            {
                $this->register($command, $persons);
            }

            #[QueryHandler('person.registered')]
            public function registered(): array
            {
                return $this->registered;
            }
        };

        return $this->bootstrapFlowTesting(
            classesToResolve: [$registration::class, PersonsOfManagerRegistry::class],
            containerOrAvailableServices: [$registration, DbalConnectionFactory::class => ManagerRegistryProvider::create($this->getConnection())],
            extensionObjects: [
                DbalConfiguration::createWithDefaults()->withTransactionOnCommandBus(false)->withTransactionOnAsynchronousEndpoints(false),
                SimpleMessageChannelBuilder::createQueueChannel('registrations'),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
        );
    }
}

interface PersonsOfManagerRegistry
{
    #[DbalWrite('INSERT INTO persons (person_id, name) VALUES (:personId, :name)')]
    public function register(int $personId, string $name): void;
}
