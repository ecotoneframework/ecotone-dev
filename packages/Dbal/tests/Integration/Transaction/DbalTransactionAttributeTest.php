<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration\Transaction;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Attribute\Reference;
use Ecotone\Api\Dbal\Attribute\DbalTransaction;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\ExtensionObject\DbalConnectionReference;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Interop\Queue\ConnectionFactory;
use RuntimeException;
use Test\Ecotone\Dbal\DbalMessagingTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class DbalTransactionAttributeTest extends DbalMessagingTestCase
{
    public function test_handler_marked_with_dbal_transaction_commits_its_write(): void
    {
        $this->setupUserTable();
        $ecotone = $this->bootstrapWithoutBusTransaction(new class () {
            #[DbalTransaction]
            #[CommandHandler('person.register')]
            public function register(int $personId, #[Reference(DbalConnectionReference::DEFAULT)] ConnectionFactory $connectionFactory): void
            {
                $connectionFactory->createContext()->getDbalConnection()->insert('persons', ['person_id' => $personId, 'name' => 'John']);
            }

            #[QueryHandler('persons.count')]
            public function count(#[Reference(DbalConnectionReference::DEFAULT)] ConnectionFactory $connectionFactory): int
            {
                return (int) $connectionFactory->createContext()->getDbalConnection()->fetchOne('SELECT COUNT(*) FROM persons');
            }
        });

        $ecotone->sendCommandWithRouting('person.register', 1);

        self::assertSame(1, $ecotone->sendQueryWithRouting('persons.count'));
    }

    public function test_handler_marked_with_dbal_transaction_rolls_back_its_write_when_it_fails(): void
    {
        $this->setupUserTable();
        $ecotone = $this->bootstrapWithoutBusTransaction(new class () {
            #[DbalTransaction]
            #[CommandHandler('person.register')]
            public function register(int $personId, #[Reference(DbalConnectionReference::DEFAULT)] ConnectionFactory $connectionFactory): void
            {
                $connectionFactory->createContext()->getDbalConnection()->insert('persons', ['person_id' => $personId, 'name' => 'John']);

                throw new RuntimeException('Failure after write.');
            }

            #[QueryHandler('persons.count')]
            public function count(#[Reference(DbalConnectionReference::DEFAULT)] ConnectionFactory $connectionFactory): int
            {
                return (int) $connectionFactory->createContext()->getDbalConnection()->fetchOne('SELECT COUNT(*) FROM persons');
            }
        });

        try {
            $ecotone->sendCommandWithRouting('person.register', 1);
            self::fail('Expected the handler to fail.');
        } catch (RuntimeException $exception) {
            self::assertSame('Failure after write.', $exception->getMessage());
        }

        self::assertSame(0, $ecotone->sendQueryWithRouting('persons.count'));
    }

    public function test_dbal_transaction_on_the_class_rolls_back_its_handlers_write_when_it_fails(): void
    {
        $this->setupUserTable();
        $ecotone = $this->bootstrapWithoutBusTransaction(new #[DbalTransaction] class () {
            #[CommandHandler('person.register')]
            public function register(int $personId, #[Reference(DbalConnectionReference::DEFAULT)] ConnectionFactory $connectionFactory): void
            {
                $connectionFactory->createContext()->getDbalConnection()->insert('persons', ['person_id' => $personId, 'name' => 'John']);

                throw new RuntimeException('Failure after write.');
            }

            #[QueryHandler('persons.count')]
            public function count(#[Reference(DbalConnectionReference::DEFAULT)] ConnectionFactory $connectionFactory): int
            {
                return (int) $connectionFactory->createContext()->getDbalConnection()->fetchOne('SELECT COUNT(*) FROM persons');
            }
        });

        try {
            $ecotone->sendCommandWithRouting('person.register', 1);
            self::fail('Expected the handler to fail.');
        } catch (RuntimeException) {
        }

        self::assertSame(0, $ecotone->sendQueryWithRouting('persons.count'));
    }

    public function test_dbal_transaction_runs_only_on_the_connection_it_names(): void
    {
        $this->setupUserTable();
        $this->setupUserTable($this->connectionForTenantB()->createContext()->getDbalConnection());
        $handler = new class () {
            #[DbalTransaction(connectionReferenceNames: ['secondary'])]
            #[CommandHandler('person.register')]
            public function register(
                int $personId,
                #[Reference(DbalConnectionReference::DEFAULT)] ConnectionFactory $defaultConnection,
                #[Reference('secondary')] ConnectionFactory $secondaryConnection,
            ): void {
                $defaultConnection->createContext()->getDbalConnection()->insert('persons', ['person_id' => $personId, 'name' => 'John']);
                $secondaryConnection->createContext()->getDbalConnection()->insert('persons', ['person_id' => $personId, 'name' => 'John']);

                throw new RuntimeException('Failure after write.');
            }

            #[QueryHandler('persons.count')]
            public function count(#[Reference(DbalConnectionReference::DEFAULT)] ConnectionFactory $connectionFactory): int
            {
                return (int) $connectionFactory->createContext()->getDbalConnection()->fetchOne('SELECT COUNT(*) FROM persons');
            }

            #[QueryHandler('persons.countOnSecondary')]
            public function countOnSecondary(#[Reference('secondary')] ConnectionFactory $connectionFactory): int
            {
                return (int) $connectionFactory->createContext()->getDbalConnection()->fetchOne('SELECT COUNT(*) FROM persons');
            }
        };
        $ecotone = $this->bootstrapFlowTesting(
            [$handler::class],
            [
                $handler,
                DbalConnectionReference::DEFAULT => $this->getConnectionFactory(),
                'secondary' => $this->connectionForTenantB(),
            ],
            extensionObjects: [
                DbalConfiguration::createWithDefaults()
                    ->withTransactionOnCommandBus(false)
                    ->withDefaultConnectionReferenceNames([DbalConnectionReference::DEFAULT, 'secondary'])
                    ->withDeduplication(false)
                    ->withDeadLetter(false)
                    ->withDocumentStore(false),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE, ModulePackageList::CORE_PACKAGE],
            addInMemoryStateStoredRepository: false,
        );

        try {
            $ecotone->sendCommandWithRouting('person.register', 1);
            self::fail('Expected the handler to fail.');
        } catch (RuntimeException) {
        }

        self::assertSame(1, $ecotone->sendQueryWithRouting('persons.count'));
        self::assertSame(0, $ecotone->sendQueryWithRouting('persons.countOnSecondary'));
    }

    private function bootstrapWithoutBusTransaction(object $handler): FlowTestSupport
    {
        return $this->bootstrapFlowTesting(
            [$handler::class],
            [$handler, DbalConnectionReference::DEFAULT => $this->getConnectionFactory()],
            extensionObjects: [DbalConfiguration::createWithDefaults()->withTransactionOnCommandBus(false)],
            enabledModules: [ModulePackageList::DBAL_PACKAGE, ModulePackageList::CORE_PACKAGE],
            addInMemoryStateStoredRepository: false,
        );
    }
}
