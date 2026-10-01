<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration\MultiTenant;

use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\ExtensionObject\MultiTenantConfiguration;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Dbal\Database\DeduplicationTableManager;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Ecotone\Test\LicenceTesting;
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
            $connection = $connectionFactory->createContext()->getDbalConnection();
            $connection->executeStatement('DROP TABLE IF EXISTS ecotone_deduplication');
            (new DeduplicationTableManager('ecotone_deduplication', true, true, null))->createTable($connection);
        }
    }

    public function test_reproduces_667_cleanup_without_tenant_header_throws_when_no_default_connection(): void
    {
        $ecotoneLite = $this->bootstrapEcotone();

        $this->expectException(InvalidArgumentException::class);

        $ecotoneLite->runConsoleCommand('ecotone:deduplication:remove-expired-messages', []);
    }

    public function test_cleanup_with_tenant_header_lets_only_that_tenant_handle_an_expired_message_again(): void
    {
        $ecotoneLite = $this->bootstrapEcotone();

        $this->sendEmail($ecotoneLite, 'tenant_a', 'a-1');
        $this->sendEmail($ecotoneLite, 'tenant_b', 'b-1');
        $this->sendEmail($ecotoneLite, 'tenant_a', 'a-1');
        $this->sendEmail($ecotoneLite, 'tenant_b', 'b-1');

        $this->assertSame(2, $this->callCount($ecotoneLite));

        $ecotoneLite->runConsoleCommand('ecotone:deduplication:remove-expired-messages', ['header' => ['tenant:tenant_a']]);

        $this->sendEmail($ecotoneLite, 'tenant_a', 'a-1');

        $this->assertSame(3, $this->callCount($ecotoneLite));

        $this->sendEmail($ecotoneLite, 'tenant_b', 'b-1');

        $this->assertSame(3, $this->callCount($ecotoneLite));
    }

    private function sendEmail(FlowTestSupport $ecotoneLite, string $tenant, string $emailId): void
    {
        $ecotoneLite->sendCommandWithRouting(
            'email_event_handler.handle_with_custom_deduplication_header',
            metadata: ['tenant' => $tenant, 'emailId' => $emailId]
        );
    }

    private function callCount(FlowTestSupport $ecotoneLite): int
    {
        return $ecotoneLite->sendQueryWithRouting('email_event_handler.getCallCount', metadata: ['tenant' => 'tenant_a']);
    }

    private function bootstrapEcotone(): FlowTestSupport
    {
        return $this->bootstrapFlowTesting(
            [EmailCommandHandler::class],
            [
                new EmailCommandHandler(),
                'tenant_a_connection' => $this->connectionForTenantA(),
                'tenant_b_connection' => $this->connectionForTenantB(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withLicenceKey(LicenceTesting::VALID_LICENCE)
                ->withModulePackages([ModulePackageList::DBAL_PACKAGE])
                ->withExtensionObjects([
                    MultiTenantConfiguration::create(
                        tenantHeaderName: 'tenant',
                        tenantToConnectionMapping: [
                            'tenant_a' => 'tenant_a_connection',
                            'tenant_b' => 'tenant_b_connection',
                        ],
                    ),
                    DbalConfiguration::createWithDefaults()
                        ->withAutomaticTableInitialization(true)
                        ->withDeduplication(true, expirationTime: 1),
                    DbalBackedMessageChannelBuilder::create('email'),
                ]),
        );
    }
}
