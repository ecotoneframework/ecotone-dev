<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration\MultiTenant;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\ExtensionObject\MultiTenantConfiguration;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Gateway\EventBus;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Test\LicenceTesting;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;
use Test\Ecotone\Dbal\DbalMessagingTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class TenantInLogContextTest extends DbalMessagingTestCase
{
    public function test_command_handler_log_carries_the_tenant(): void
    {
        $logger = $this->recordingLogger();
        $ecotone = $this->bootstrapEcotone($logger, 'tenant');

        $ecotone->sendCommandWithRouting('tenant_log.place', 'order-1', metadata: ['tenant' => 'tenant_a']);

        self::assertSame('tenant_a', $logger->contextOf('Executing Command Handler')['tenant']);
    }

    public function test_event_handler_log_carries_the_tenant_propagated_from_the_command(): void
    {
        $logger = $this->recordingLogger();
        $ecotone = $this->bootstrapEcotone($logger, 'tenant');

        $ecotone->sendCommandWithRouting('tenant_log.place', 'order-1', metadata: ['tenant' => 'tenant_b']);

        self::assertSame('tenant_b', $logger->contextOf('Executing Event Handler')['tenant']);
    }

    public function test_asynchronous_consumption_log_carries_the_tenant(): void
    {
        $logger = $this->recordingLogger();
        $ecotone = $this->bootstrapEcotone($logger, 'tenant');

        $ecotone->sendCommandWithRouting('tenant_log.place_async', 'order-1', metadata: ['tenant' => 'tenant_a']);
        $ecotone->run('tenant_log_async', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame('tenant_a', $logger->contextOf('received from Message Channel `tenant_log_async`')['tenant']);
    }

    public function test_tenant_is_logged_under_the_configured_header_name(): void
    {
        $logger = $this->recordingLogger();
        $ecotone = $this->bootstrapEcotone($logger, 'customer');

        $ecotone->sendCommandWithRouting('tenant_log.place', 'order-1', metadata: ['customer' => 'tenant_a']);

        $context = $logger->contextOf('Executing Command Handler');
        self::assertSame('tenant_a', $context['customer']);
        self::assertArrayNotHasKey('tenant', $context);
    }

    public function test_log_context_carries_no_tenant_without_multi_tenant_configuration(): void
    {
        $logger = $this->recordingLogger();
        $handler = $this->orderHandler();
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [$handler::class],
            [$handler, 'logger' => $logger],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('tenant_log_async')],
            enabledModules: [],
        );

        $ecotone->sendCommandWithRouting('tenant_log.place', 'order-1', metadata: ['tenant' => 'tenant_a']);

        self::assertArrayNotHasKey('tenant', $logger->contextOf('Executing Command Handler'));
    }

    private function bootstrapEcotone(object $logger, string $tenantHeaderName): FlowTestSupport
    {
        $handler = $this->orderHandler();

        return $this->bootstrapFlowTesting(
            [$handler::class],
            [
                $handler,
                'logger' => $logger,
                'tenant_a_connection' => $this->connectionForTenantA(),
                'tenant_b_connection' => $this->connectionForTenantB(),
            ],
            extensionObjects: [
                MultiTenantConfiguration::create(tenantHeaderName:$tenantHeaderName, tenantToConnectionMapping:['tenant_a' => 'tenant_a_connection', 'tenant_b' => 'tenant_b_connection', ], ),
                DbalConfiguration::createWithDefaults()->withDeduplication(false),
                SimpleMessageChannelBuilder::createQueueChannel('tenant_log_async'),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    private function orderHandler(): object
    {
        return new class () {
            #[CommandHandler('tenant_log.place')]
            public function place(string $orderId, EventBus $eventBus): void
            {
                $eventBus->publishWithRouting('tenant_log.placed', $orderId);
            }

            #[EventHandler('tenant_log.placed')]
            public function whenPlaced(string $orderId): void
            {
            }

            #[Asynchronous('tenant_log_async')]
            #[CommandHandler('tenant_log.place_async', endpointId: 'tenant_log.place_async.endpoint')]
            public function placeAsync(string $orderId): void
            {
            }
        };
    }

    private function recordingLogger(): object
    {
        return new class () extends AbstractLogger {
            /** @var array<int, array{message: string, context: array<string, mixed>}> */
            private array $records = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = ['message' => (string) $message, 'context' => $context];
            }

            /** @return array<string, mixed> */
            public function contextOf(string $messageFragment): array
            {
                foreach ($this->records as $record) {
                    if (str_contains($record['message'], $messageFragment)) {
                        return $record['context'];
                    }
                }

                throw new RuntimeException("No log record containing `{$messageFragment}`");
            }
        };
    }
}
