<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration\MultiTenant;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Header;
use Ecotone\Api\Dbal\ExtensionObject\MultiTenantConfiguration;
use Ecotone\Api\Dbal\Tracing\FlowTrackingConfiguration;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Tracing\InMemorySpanRecorder;
use Ecotone\Api\Tracing\Span;
use Ecotone\Api\Tracing\SpanAttribute;
use Ecotone\Api\Tracing\TracingConfiguration;
use Ecotone\Api\Tracing\TracingLevel;
use Ecotone\Test\LicenceTesting;
use Test\Ecotone\Dbal\DbalMessagingTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class MultiTenantFrameworkTracingLevelTest extends DbalMessagingTestCase
{
    public function test_a_multi_tenant_application_boots_and_records_its_execution_at_the_framework_level(): void
    {
        $this->assertPlacesAnOrderForTheTenantAndRecordsTheExecution([]);
    }

    public function test_a_multi_tenant_application_with_flow_tracking_boots_and_records_its_execution_at_the_framework_level(): void
    {
        $this->assertPlacesAnOrderForTheTenantAndRecordsTheExecution([FlowTrackingConfiguration::createWithDefaults()]);
    }

    private function assertPlacesAnOrderForTheTenantAndRecordsTheExecution(array $extensionObjects): void
    {
        $orders = new class () {
            public array $placed = [];

            #[CommandHandler('order.place')]
            public function place(string $order, #[Header('tenant')] string $tenant): void
            {
                $this->placed[] = [$tenant, $order];
            }
        };
        $recorder = new InMemorySpanRecorder();
        $application = $this->bootstrapFlowTesting(
            [$orders::class],
            [
                $orders,
                InMemorySpanRecorder::class => $recorder,
                'tenant_a_connection' => $this->connectionForTenantA(),
                'tenant_b_connection' => $this->connectionForTenantB(),
            ],
            [
                MultiTenantConfiguration::create('tenant', ['tenant_a' => 'tenant_a_connection', 'tenant_b' => 'tenant_b_connection']),
                TracingConfiguration::createWithDefaults()->withSpanRecorder(InMemorySpanRecorder::class)->withLevel(TracingLevel::Framework),
                ...$extensionObjects,
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $application->runConsoleCommand('ecotone:migration:database:setup', ['initialize' => true]);
        $application->getCommandBus()->sendWithRouting('order.place', 'espresso', metadata: ['tenant' => 'tenant_b']);

        self::assertSame([['tenant_b', 'espresso']], $orders->placed);
        self::assertNotSame([], array_filter($recorder->recordedSpans(), fn (Span $span): bool => (bool) ($span->attributes[SpanAttribute::EXECUTION_STEP] ?? false)));
    }
}
