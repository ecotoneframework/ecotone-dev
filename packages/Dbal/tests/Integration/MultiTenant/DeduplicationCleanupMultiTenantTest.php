<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration\MultiTenant;

use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\Dbal\MultiTenant\MultiTenantConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Test\Ecotone\Dbal\DbalMessagingTestCase;
use Test\Ecotone\Dbal\Fixture\DeduplicationCommandHandler\EmailCommandHandler;

/**
 * @internal
 *
 * Reproduces and verifies the fix path for issue #667:
 * deduplication cleanup under multi-tenant connections with no default connection.
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class DeduplicationCleanupMultiTenantTest extends DbalMessagingTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        foreach ([$this->connectionForTenantA(), $this->connectionForTenantB()] as $connectionFactory) {
            $connectionFactory->createContext()->getDbalConnection()
                ->executeStatement('DROP TABLE IF EXISTS ecotone_deduplication');
        }
    }

    public function test_reproduces_667_cleanup_without_tenant_header_throws_when_no_default_connection(): void
    {
        $ecotoneLite = $this->bootstrapEcotone();

        $this->expectException(InvalidArgumentException::class);

        $ecotoneLite->runConsoleCommand('ecotone:deduplication:remove-expired-messages', []);
    }

    public function test_cleanup_with_tenant_header_routes_to_correct_tenant_connection(): void
    {
        $ecotoneLite = $this->bootstrapEcotone();

        $ecotoneLite->sendCommandWithRoutingKey(
            'email_event_handler.handle_with_custom_deduplication_header',
            metadata: ['tenant' => 'tenant_a', 'emailId' => 'a-1']
        );
        $ecotoneLite->sendCommandWithRoutingKey(
            'email_event_handler.handle_with_custom_deduplication_header',
            metadata: ['tenant' => 'tenant_b', 'emailId' => 'b-1']
        );

        $this->assertSame(1, $this->countDeduplicationRows($this->connectionForTenantA()), 'tenant_a should have one tracked message before cleanup');
        $this->assertSame(1, $this->countDeduplicationRows($this->connectionForTenantB()), 'tenant_b should have one tracked message before cleanup');

        $ecotoneLite->runConsoleCommand('ecotone:deduplication:remove-expired-messages', ['header' => ['tenant:tenant_a']]);

        $this->assertSame(0, $this->countDeduplicationRows($this->connectionForTenantA()), 'tenant_a expired message should be removed');
        $this->assertSame(1, $this->countDeduplicationRows($this->connectionForTenantB()), 'tenant_b must be untouched - cleanup routed to tenant_a only');
    }

    private function countDeduplicationRows(object $connectionFactory): int
    {
        return (int) $connectionFactory->createContext()->getDbalConnection()
            ->executeQuery('SELECT COUNT(*) FROM ecotone_deduplication')
            ->fetchOne();
    }

    private function bootstrapEcotone(): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            [EmailCommandHandler::class],
            [
                new EmailCommandHandler(),
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
                        ->withDeduplication(true, expirationTime: 1),
                ]),
        );
    }
}
