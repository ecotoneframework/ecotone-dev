<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration\MultiTenant;

use Ecotone\Dbal\Api\Attribute\WithTenantResolver;
use Ecotone\Dbal\Api\ExtensionObject\DbalConfiguration;
use Ecotone\Dbal\Api\ExtensionObject\MultiTenantConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\Scheduled;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\Support\MessageBuilder;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Test\LicenceTesting;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use PHPUnit\Framework\TestCase;
use stdClass;
use Test\Ecotone\Dbal\Fixture\MultiTenant\FakeConnectionFactory;

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
        $service = new class () {
            #[CommandHandler('invalidPlacement')]
            #[WithTenantResolver(expression: "headers['source']")]
            public function handle(string $payload): void
            {
            }
        };

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage($service::class . '::handle');
        $this->expectExceptionMessage('inbound channel adapter');
        $this->expectExceptionMessage('Internal Message Channels');

        $this->bootstrap([$service::class], [$service]);
    }

    public function test_throws_when_tenant_resolver_placed_on_event_handler(): void
    {
        $service = new class () {
            #[EventHandler]
            #[WithTenantResolver(expression: "headers['source']")]
            public function on(stdClass $event): void
            {
            }
        };

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage($service::class . '::on');

        $this->bootstrap([$service::class], [$service]);
    }

    public function test_throws_when_tenant_resolver_placed_on_asynchronous_handler(): void
    {
        $service = new class () {
            #[Asynchronous('async_invalid_channel')]
            #[CommandHandler('asyncInvalidPlacement', endpointId: 'asyncInvalidPlacementEndpoint')]
            #[WithTenantResolver(expression: "headers['source']")]
            public function handle(string $payload): void
            {
            }
        };

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage($service::class . '::handle');
        $this->expectExceptionMessage('inbound channel adapter');

        $this->bootstrap([$service::class], [$service]);
    }

    public function test_does_not_throw_when_tenant_resolver_placed_on_inbound_channel_adapter(): void
    {
        $service = new class () {
            #[Scheduled(requestChannelName: 'externalEventArrived', endpointId: 'externalEventPoller')]
            #[WithTenantResolver(expression: "headers['source']")]
            public function poll(): ?Message
            {
                return MessageBuilder::withPayload('payload')->setHeader('source', 'tenant_a')->build();
            }
        };

        $ecotone = $this->bootstrap([$service::class], [$service]);

        $this->assertNotNull($ecotone, 'Bootstrap should succeed when WithTenantResolver is placed on a #[Scheduled] inbound adapter.');
    }

    /**
     * @param class-string[] $classes
     * @param object[] $services
     */
    private function bootstrap(array $classes, array $services): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            $classes,
            array_merge($services, ['tenant_a_connection' => new FakeConnectionFactory()]),
            ServiceConfiguration::createWithDefaults()
                ->withLicenceKey(LicenceTesting::VALID_LICENCE)
                ->withModulePackages([ModulePackageList::DBAL_PACKAGE,])
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
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }
}
