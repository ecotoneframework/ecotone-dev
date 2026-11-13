<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration;

use Ecotone\Api\Attribute\Around;
use Ecotone\Api\Attribute\ConsoleCommand;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\Tracing\FlowTrackingConfiguration;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Interceptor\MethodInvocation;
use Ecotone\Api\Interceptor\Precedence;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class ConsoleCommandTransactionTest extends TestCase
{
    public function test_batched_cleanup_commands_run_as_console_commands_without_a_transaction(): void
    {
        $recorder = new class () {
            public DbalConnectionFactory $connectionFactory;
            public array $transactionActive = [];

            #[Around(precedence: Precedence::DATABASE_TRANSACTION_PRECEDENCE + 1, pointcut: ConsoleCommand::class)]
            public function record(MethodInvocation $invocation, ConsoleCommand $consoleCommand): mixed
            {
                $this->transactionActive[$consoleCommand->getName()] = $this->connectionFactory->createContext()->getDbalConnection()->isTransactionActive();

                return $invocation->proceed();
            }

            #[ConsoleCommand('app:import-orders')]
            public function importOrders(): void
            {
            }
        };
        $recorder->connectionFactory = new DbalConnectionFactory(getenv('DATABASE_DSN'));

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$recorder::class],
            containerOrAvailableServices: [$recorder, DbalConnectionFactory::class => $recorder->connectionFactory],
            extensionObjects: [FlowTrackingConfiguration::createWithDefaults()],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotone->runConsoleCommand('app:import-orders');
        $ecotone->runConsoleCommand('ecotone:deduplication:remove-expired-messages');
        $ecotone->runConsoleCommand('ecotone:tracing:remove-expired-traces');

        self::assertSame(['app:import-orders' => true, 'ecotone:deduplication:remove-expired-messages' => false, 'ecotone:tracing:remove-expired-traces' => false], $recorder->transactionActive);
    }
}
