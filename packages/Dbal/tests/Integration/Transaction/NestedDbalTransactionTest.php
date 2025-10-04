<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration\Transaction;

use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Enqueue\Dbal\DbalConnectionFactory;
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

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [NestedDbalHandlers::class],
            [
                new NestedDbalHandlers(),
                DbalConnectionFactory::class => $this->getConnectionFactory(),
                'logger' => $logger,
            ],
            ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE, ModulePackageList::CORE_PACKAGE]))
                ->withExtensionObjects([
                    DbalConfiguration::createWithDefaults()
                        ->withTransactionOnCommandBus(true),
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
            addInMemoryStateStoredRepository: false
        );

        // Prepare schema
        $ecotone->sendCommandWithRoutingKey('nested.prepare');
        $logger->reset();

        // Execute nested call: outer sends inner; both should be within a single DBAL transaction
        $ecotone->sendCommandWithRoutingKey('nested.outer');

        // Exactly one transaction should be started and committed
        self::assertSame(1, $logger->getStartedCount(), 'Expected only one DB transaction to start for nested command bus calls');
        self::assertSame(1, $logger->getCommittedCount(), 'Expected only one DB transaction to commit for nested command bus calls');

        // Sanity check: the inner handler executed and persisted one row
        self::assertSame(1, $ecotone->sendQueryWithRouting('nested.count'));
    }
}
