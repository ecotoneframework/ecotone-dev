<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Console\ConsoleCommandResultSet;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\TestConfiguration;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Gateway\ConsoleCommandRunner;
use Test\Ecotone\Dbal\DbalMessagingTestCase;

/**
 * @internal
 */
final class NonDefaultConnectionQueueSetupTest extends DbalMessagingTestCase
{
    private const OTHER_CONNECTION = 'other_connection';

    public function setUp(): void
    {
        parent::setUp();
        $this->dropEnqueueTables();
    }

    public function tearDown(): void
    {
        $this->dropEnqueueTables();
        parent::tearDown();
    }

    public function test_setup_offers_the_queue_table_on_the_connection_the_channel_uses(): void
    {
        $ecotone = $this->bootstrapEcotone();

        $result = $this->executeConsoleCommand($ecotone, ['feature' => ['message_queue']]);

        self::assertContains(['message_queue', self::OTHER_CONNECTION, 'Yes', 'No'], $result->rows);
    }

    public function test_a_channel_on_another_connection_works_once_setup_ran_for_that_connection(): void
    {
        $ecotone = $this->bootstrapEcotone();

        $this->executeConsoleCommand($ecotone, ['initialize' => true]);
        $ecotone->sendCommandWithRouting('queue.place', 'first');
        $ecotone->run('other_orders', \Ecotone\Api\ExtensionObject\ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame(['first'], $ecotone->sendQueryWithRouting('queue.placed'));
    }

    public function test_using_the_channel_before_setup_names_the_connection_to_set_up(): void
    {
        $ecotone = $this->bootstrapEcotone();

        try {
            $ecotone->sendCommandWithRouting('queue.place', 'first');
            self::fail('Expected the missing queue table to be refused');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString("does not exist on connection '" . self::OTHER_CONNECTION . "'", $exception->getMessage());
        }
    }

    private function bootstrapEcotone(): FlowTestSupport
    {
        $handler = new class () {
            /** @var list<string> */
            private array $placed = [];

            #[Asynchronous('other_orders')]
            #[CommandHandler('queue.place', 'queue.place.endpoint')]
            public function place(string $order): void
            {
                $this->placed[] = $order;
            }

            /**
             * @return list<string>
             */
            #[QueryHandler('queue.placed')]
            public function placed(): array
            {
                return $this->placed;
            }
        };

        return EcotoneLite::bootstrapFlowTesting(
            [$handler::class],
            [
                $handler,
                DbalConnectionFactory::class => $this->getConnectionFactory(),
                self::OTHER_CONNECTION => $this->connectionForTenantB(),
            ],
            extensionObjects: [DbalBackedMessageChannelBuilder::create('other_orders', self::OTHER_CONNECTION)],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            pathToRootCatalog: __DIR__ . '/../../',
            testConfiguration: TestConfiguration::createWithDefaults()->withDatabaseTablesSetUpOnBootstrap(false),
            environment: 'prod',
        );
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function executeConsoleCommand(FlowTestSupport $ecotone, array $parameters): ConsoleCommandResultSet
    {
        /** @var ConsoleCommandRunner $runner */
        $runner = $ecotone->getGateway(ConsoleCommandRunner::class);

        return $runner->execute('ecotone:migration:database:setup', $parameters);
    }

    private function dropEnqueueTables(): void
    {
        foreach ([$this->getConnection(), $this->connectionForTenantB()->createContext()->getDbalConnection()] as $connection) {
            if (self::checkIfTableExists($connection, 'enqueue')) {
                $connection->executeStatement('DROP TABLE enqueue');
            }
        }
    }
}
