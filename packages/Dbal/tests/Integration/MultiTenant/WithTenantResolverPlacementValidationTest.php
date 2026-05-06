<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration\MultiTenant;

use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\Dbal\MultiTenant\MultiTenantConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Enqueue\Dbal\DbalConnectionFactory;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\Dbal\Fixture\MultiTenant\FakeConnectionFactory;
use Test\Ecotone\Dbal\Fixture\MultiTenant\InvalidPlacement\AsynchronousHandlerWithTenantResolver;
use Test\Ecotone\Dbal\Fixture\MultiTenant\InvalidPlacement\CommandHandlerWithTenantResolver;
use Test\Ecotone\Dbal\Fixture\MultiTenant\InvalidPlacement\EventHandlerWithTenantResolver;
use Test\Ecotone\Dbal\Fixture\MultiTenant\Scheduled\ExternalEventPoller;
use Test\Ecotone\Dbal\Fixture\MultiTenant\Scheduled\ExternalEventReceiver;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class WithTenantResolverPlacementValidationTest extends TestCase
{
    public function test_throws_when_tenant_resolver_placed_on_synchronous_command_handler(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(CommandHandlerWithTenantResolver::class . '::handle');
        $this->expectExceptionMessage('inbound channel adapter');
        $this->expectExceptionMessage('Internal Message Channels');

        $this->bootstrap([CommandHandlerWithTenantResolver::class], [new CommandHandlerWithTenantResolver()]);
    }

    public function test_throws_when_tenant_resolver_placed_on_event_handler(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(EventHandlerWithTenantResolver::class . '::on');

        $this->bootstrap([EventHandlerWithTenantResolver::class], [new EventHandlerWithTenantResolver()]);
    }

    public function test_throws_when_tenant_resolver_placed_on_asynchronous_handler(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(AsynchronousHandlerWithTenantResolver::class . '::handle');
        $this->expectExceptionMessage('inbound channel adapter');

        $this->bootstrap([AsynchronousHandlerWithTenantResolver::class], [new AsynchronousHandlerWithTenantResolver()]);
    }

    public function test_does_not_throw_when_tenant_resolver_placed_on_inbound_channel_adapter(): void
    {
        $ecotone = $this->bootstrap(
            [ExternalEventPoller::class, ExternalEventReceiver::class],
            [new ExternalEventPoller(), new ExternalEventReceiver()],
        );

        $this->assertNotNull($ecotone, 'Bootstrap should succeed when WithTenantResolver is placed on a #[Scheduled] inbound adapter.');
    }

    /**
     * @param class-string[] $classes
     * @param object[] $services
     */
    private function bootstrap(array $classes, array $services): \Ecotone\Lite\Test\FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            $classes,
            array_merge($services, ['tenant_a_connection' => new FakeConnectionFactory()]),
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    MultiTenantConfiguration::createWithDefaultConnection(
                        'tenant',
                        ['tenant_a' => 'tenant_a_connection'],
                        'tenant_a_connection',
                        DbalConnectionFactory::class,
                    ),
                    DbalConfiguration::createWithDefaults()
                        ->withTransactionOnCommandBus(false)
                        ->withTransactionOnAsynchronousEndpoints(false)
                        ->withClearAndFlushObjectManagerOnCommandBus(false)
                        ->withDeduplication(false),
                ]),
        );
    }
}
