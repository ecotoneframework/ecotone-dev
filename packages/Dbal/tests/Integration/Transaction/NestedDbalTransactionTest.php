<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration\Transaction;

use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Test\Ecotone\Dbal\DbalMessagingTestCase;
use Test\Ecotone\Dbal\Fixture\Transaction\Nested\NestedDbalHandlers;
use Test\Ecotone\Dbal\Fixture\Transaction\Nested\TestCountingLogger;

/**
 * @internal
 */
final class NestedDbalTransactionTest extends DbalMessagingTestCase
{
    public function test_avoids_nested_transactions_on_command_bus(): void
    {
        $logger = new TestCountingLogger();

        $ecotone = $this->bootstrapFlowTesting(
            [NestedDbalHandlers::class],
            [
                new NestedDbalHandlers(),
                DbalConnectionFactory::class => $this->getConnectionFactory(),
                'logger' => $logger,
            ],
            extensionObjects: [DbalConfiguration::createWithDefaults()->withTransactionOnCommandBus(true)],
            enabledModules: [ModulePackageList::DBAL_PACKAGE, ModulePackageList::CORE_PACKAGE],
            pathToRootCatalog: __DIR__ . '/../../',
            addInMemoryStateStoredRepository: false,
            environment: 'prod',
        );

        // Prepare schema
        $ecotone->sendCommandWithRouting('nested.prepare');
        $logger->reset();

        // Execute nested call: outer sends inner; both should be within a single DBAL transaction
        $ecotone->sendCommandWithRouting('nested.outer');

        // Exactly one transaction should be started and committed
        self::assertSame(1, $logger->getStartedCount(), 'Expected only one DB transaction to start for nested command bus calls');
        self::assertSame(1, $logger->getCommittedCount(), 'Expected only one DB transaction to commit for nested command bus calls');

        // Sanity check: the inner handler executed and persisted one row
        self::assertSame(1, $ecotone->sendQueryWithRouting('nested.count'));
    }
}
