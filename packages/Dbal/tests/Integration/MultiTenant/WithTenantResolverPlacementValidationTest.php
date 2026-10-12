<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration\MultiTenant;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\Scheduled;
use Ecotone\Api\Dbal\Attribute\WithTenantResolver;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\ExtensionObject\MultiTenantConfiguration;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\Message;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Support\MessageBuilder;
use Ecotone\Test\LicenceTesting;
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

    public function test_refusal_names_every_inbound_channel_adapter_the_resolver_may_move_to(): void
    {
        $service = new class () {
            #[CommandHandler('invalidPlacement')]
            #[WithTenantResolver(expression: "headers['source']")]
            public function handle(string $payload): void
            {
            }
        };

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            '#[WithTenantResolver] on ' . $service::class . '::handle is invalid: it may only be placed on an inbound channel adapter method — #[KafkaConsumer], #[RabbitConsumer] or #[Scheduled] — where messages arrive from outside the application without a tenant header. '
            . 'Internal Message Channels — including those used by synchronous and asynchronous #[CommandHandler] / #[EventHandler] / #[QueryHandler] / #[InternalHandler] handlers — already carry the tenant context propagated from the originating bus call, so there is no header to derive there. '
            . 'Remove #[WithTenantResolver] from ' . $service::class . '::handle; if the handler processes messages that arrive from outside the application, place it on the #[KafkaConsumer], #[RabbitConsumer] or #[Scheduled] method that receives them instead.'
        );

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
                ->withEnabledModulePackageNames([ModulePackageList::DBAL_PACKAGE, ])
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
