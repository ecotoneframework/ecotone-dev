<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration\MultiTenant;

use Doctrine\DBAL\Connection;
use Ecotone\Dbal\Attribute\MultiTenantConnection;
use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\Dbal\MultiTenant\MultiTenantConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Attribute\ConsoleCommand;
use Ecotone\Messaging\Attribute\Parameter\Reference;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Handler\MethodInvocationException;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\CommandBus;
use Test\Ecotone\Dbal\DbalMessagingTestCase;

/**
 * Proves that a tenant header can be propagated into a `#[ConsoleCommand]` call
 * the same way it is propagated for inbound channel adapters (see
 * ScheduledTenantResolverTest), so that MultiTenantConnectionFactory resolves
 * the correct per-tenant connection when a console command is executed with
 * `--header="tenant:tenant_a"` (or, in tests, `runConsoleCommand(..., ['header' => ['tenant:tenant_a']])`).
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class ConsoleCommandTenantPropagationTest extends DbalMessagingTestCase
{
    public const MARKER_TABLE = 'tenant_marker';

    public function setUp(): void
    {
        parent::setUp();

        foreach ([$this->connectionForTenantA(), $this->connectionForTenantB()] as $connectionFactory) {
            $connection = $connectionFactory->createContext()->getDbalConnection();
            $connection->executeStatement('DROP TABLE IF EXISTS ' . self::MARKER_TABLE);
            $connection->executeStatement('CREATE TABLE ' . self::MARKER_TABLE . ' (marker INTEGER)');
        }
    }

    public function test_console_command_without_tenant_header_throws_when_no_default_connection(): void
    {
        $ecotoneLite = $this->bootstrapEcotone();

        $this->expectException(MethodInvocationException::class);
        $this->expectExceptionMessage('Lack of context about tenant in Message Headers');

        $ecotoneLite->runConsoleCommand('multi_tenant:record_marker', []);
    }

    public function test_console_command_tenant_header_routes_to_correct_tenant_connection(): void
    {
        $ecotoneLite = $this->bootstrapEcotone();

        $ecotoneLite->runConsoleCommand('multi_tenant:record_marker', ['header' => ['tenant:tenant_a']]);

        $this->assertSame(1, $this->countMarkerRows($this->connectionForTenantA()), 'tenant_a should have received the marker');
        $this->assertSame(0, $this->countMarkerRows($this->connectionForTenantB()), 'tenant_b must be untouched - console command routed to tenant_a only');

        $ecotoneLite->runConsoleCommand('multi_tenant:record_marker', ['header' => ['tenant:tenant_b']]);

        $this->assertSame(1, $this->countMarkerRows($this->connectionForTenantA()));
        $this->assertSame(1, $this->countMarkerRows($this->connectionForTenantB()));
    }

    public function test_tenant_header_from_console_command_propagates_to_command_bus_sub_flow(): void
    {
        $ecotoneLite = $this->bootstrapEcotone();

        $ecotoneLite->runConsoleCommand('multi_tenant:record_marker_via_command_bus', ['header' => ['tenant:tenant_b']]);

        $this->assertSame(0, $this->countMarkerRows($this->connectionForTenantA()));
        $this->assertSame(1, $this->countMarkerRows($this->connectionForTenantB()), 'tenant header must propagate from console command into the Command Bus sub-flow');
    }

    private function countMarkerRows(object $connectionFactory): int
    {
        return (int) $connectionFactory->createContext()->getDbalConnection()
            ->executeQuery('SELECT COUNT(*) FROM ' . self::MARKER_TABLE)
            ->fetchOne();
    }

    private function newTenantMarkerRecorder(): object
    {
        return new class () {
            #[ConsoleCommand('multi_tenant:record_marker')]
            public function record(#[MultiTenantConnection] Connection $connection): void
            {
                $connection->executeStatement('INSERT INTO ' . ConsoleCommandTenantPropagationTest::MARKER_TABLE . ' (marker) VALUES (1)');
            }

            #[ConsoleCommand('multi_tenant:record_marker_via_command_bus')]
            public function recordViaCommandBus(#[Reference] CommandBus $commandBus): void
            {
                $commandBus->sendWithRouting('multi_tenant.record_marker');
            }

            #[CommandHandler('multi_tenant.record_marker')]
            public function recordMarker(#[MultiTenantConnection] Connection $connection): void
            {
                $connection->executeStatement('INSERT INTO ' . ConsoleCommandTenantPropagationTest::MARKER_TABLE . ' (marker) VALUES (1)');
            }
        };
    }

    private function bootstrapEcotone(): FlowTestSupport
    {
        $recorder = $this->newTenantMarkerRecorder();

        return EcotoneLite::bootstrapFlowTesting(
            [$recorder::class],
            [
                $recorder,
                'tenant_a_connection' => $this->connectionForTenantA(),
                'tenant_b_connection' => $this->connectionForTenantB(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE]))
                ->withExtensionObjects([
                    MultiTenantConfiguration::create(
                        tenantHeaderName: 'tenant',
                        tenantToConnectionMapping: [
                            'tenant_a' => 'tenant_a_connection',
                            'tenant_b' => 'tenant_b_connection',
                        ],
                    ),
                    DbalConfiguration::createWithDefaults()
                        ->withDeduplication(false),
                ]),
        );
    }
}
