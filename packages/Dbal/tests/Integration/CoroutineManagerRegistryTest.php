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
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Test\CoroutineTesting;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;

/**
 * licence Apache-2.0
 * @internal
 */
#[RequiresMethod(Coroutine::class, 'create')]
final class CoroutineManagerRegistryTest extends TestCase
{
    public function test_consumer_coroutines_using_one_manager_registry_connection_at_once_are_refused(): void
    {
        $ecotone = $this->bootstrapMembers();
        $ecotone->sendCommandWithRouting('member.enrolLater', ['memberId' => 1, 'name' => 'first']);
        $ecotone->sendCommandWithRouting('member.enrolLater', ['memberId' => 2, 'name' => 'second']);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            'Database connection `default` of a Doctrine ManagerRegistry is used by two executions of this process at once (coroutines or fibers running concurrently, or one a handler started). '
            . 'A ManagerRegistry keeps a single connection for the whole process, so the statements of both messages would run on it: their writes are not atomic, a transaction of one includes what the other wrote, and MySQL refuses their concurrent queries. '
            . 'Ecotone gives every concurrent execution its own connection when it can open one itself: register the connection from a DSN (new Ecotone\\Api\\Dbal\\DbalConnectionFactory($dsn)), in Symfony register the DBAL connection with SymfonyConnectionReference::defaultConnection() or ::createForConnection(), which work on copies of that connection, or keep Doctrine ORM on a connection per execution with Ecotone\\Api\\Dbal\\ManagerRegistryProvider::fromDsnAndConfig($dsn, $pathsToMapping). '
            . 'To keep the ManagerRegistry, handle one message at a time per process: run consumers without the `coroutines` option of ecotone:run and PollingMetadata::withCoroutines(), set `enable_coroutine` to false on a Swoole or OpenSwoole server, and do not use the connection from a coroutine or fiber a handler starts.'
        );

        $ecotone->run('enrolments', ExecutionPollingMetadata::createWithFinishWhenNoMessages()->withCoroutines(2));
    }

    public function test_coroutines_using_one_manager_registry_connection_one_after_another_are_handled(): void
    {
        $ecotone = $this->bootstrapMembers();

        CoroutineTesting::runAtOnce(static fn () => $ecotone->sendCommandWithRouting('member.enrol', ['memberId' => 1, 'name' => 'first']));
        CoroutineTesting::runAtOnce(static fn () => $ecotone->sendCommandWithRouting('member.enrol', ['memberId' => 2, 'name' => 'second']));

        $this->assertSame(['first', 'second'], $ecotone->sendQueryWithRouting('member.enrolled'));
    }

    private function bootstrapMembers(): FlowTestSupport
    {
        $connection = (new DbalConnectionFactory((string) getenv('DATABASE_MYSQL')))->createContext()->getDbalConnection();
        $connection->executeStatement('DROP TABLE IF EXISTS coroutine_members');
        $connection->executeStatement('CREATE TABLE coroutine_members (member_id INTEGER PRIMARY KEY, name VARCHAR(255))');
        $members = new class () {
            private array $enrolled = [];

            #[CommandHandler('member.enrol')]
            public function enrol(array $command, CoroutineMembers $members): void
            {
                $members->enrol($command['memberId'], $command['name']);
                usleep(50_000);
                $this->enrolled[] = $command['name'];
            }

            #[Asynchronous('enrolments')]
            #[CommandHandler('member.enrolLater', endpointId: 'member.enrolLater.endpoint')]
            public function enrolLater(array $command, CoroutineMembers $members): void
            {
                $this->enrol($command, $members);
            }

            #[QueryHandler('member.enrolled')]
            public function enrolled(): array
            {
                return $this->enrolled;
            }
        };

        return EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$members::class, CoroutineMembers::class],
            containerOrAvailableServices: [$members, DbalConnectionFactory::class => ManagerRegistryProvider::create($connection)],
            extensionObjects: [
                DbalConfiguration::createWithDefaults()->withTransactionOnCommandBus(false)->withTransactionOnAsynchronousEndpoints(false),
                SimpleMessageChannelBuilder::createQueueChannel('enrolments'),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
        );
    }
}

interface CoroutineMembers
{
    #[DbalWrite('INSERT INTO coroutine_members (member_id, name) VALUES (:memberId, :name)')]
    public function enrol(int $memberId, string $name): void;
}
