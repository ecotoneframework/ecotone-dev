<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration\Tracing;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\Tracing\FlowStepCompleted;
use Ecotone\Api\Dbal\Tracing\FlowTrackingConfiguration;
use Ecotone\Api\Dbal\Tracing\OnFlowStepCompleted;
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
final class FlowStepCompletedTracingLevelTest extends DbalMessagingTestCase
{
    public function test_the_flow_step_completed_notification_starts_its_own_trace_at_both_tracing_levels(): void
    {
        foreach ([TracingLevel::Application, TracingLevel::Framework] as $level) {
            $recorder = new InMemorySpanRecorder();
            $users = new class () {
                #[CommandHandler('user.register')]
                public function register(string $userId): void
                {
                }

                #[OnFlowStepCompleted]
                public function whenStepCompleted(FlowStepCompleted $event): void
                {
                }
            };
            $ecotone = $this->bootstrapFlowTesting(
                [$users::class],
                [$users, InMemorySpanRecorder::class => $recorder, DbalConnectionFactory::class => $this->getConnectionFactory()],
                [FlowTrackingConfiguration::createWithDefaults()->withInMemoryStorage(true), TracingConfiguration::createWithDefaults()->withSpanRecorder(InMemorySpanRecorder::class)->withLevel($level)],
                enabledModules: [ModulePackageList::DBAL_PACKAGE],
                licenceKey: LicenceTesting::VALID_LICENCE,
            );

            $ecotone->sendCommandWithRouting('user.register', 'user-1');
            $traced = array_values(array_filter($recorder->recordedSpans(), fn (Span $span): bool => ! ($span->attributes[SpanAttribute::EXECUTION_STEP] ?? false)));
            [$command] = array_values(array_filter($traced, fn (Span $span): bool => $span->name === 'send command'));
            $notifications = array_values(array_filter($traced, fn (Span $span): bool => $span->name === 'send ' . OnFlowStepCompleted::CHANNEL));

            self::assertNotSame([], $notifications);
            foreach ($notifications as $notification) {
                self::assertNull($notification->parentSpanId);
                self::assertNotSame($command->traceId, $notification->traceId);
            }
        }
    }
}
