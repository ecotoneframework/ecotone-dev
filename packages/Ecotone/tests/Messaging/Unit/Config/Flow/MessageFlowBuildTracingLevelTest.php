<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Config\Flow;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Gateway\EventBus;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Tracing\InMemorySpanRecorder;
use Ecotone\Api\Tracing\TracingConfiguration;
use Ecotone\Api\Tracing\TracingLevel;
use Ecotone\Test\Flow\Snapshot\BuildFlowConsoleCommands;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class MessageFlowBuildTracingLevelTest extends TestCase
{
    public function test_the_build_part_of_the_flow_dump_is_refused_at_the_application_tracing_level(): void
    {
        $ecotone = $this->application(TracingLevel::Application);

        $this->expectExceptionMessage('ecotone:flow:dump --build was asked for what Ecotone registers for the application, which is read only at the framework tracing level. Switch it on with TracingConfiguration::createWithDefaults()->withSpanRecorder(InMemorySpanRecorder::class)->withLevel(TracingLevel::Framework), or run ecotone:flow:dump without --build for the message flow the application declares.');

        $ecotone->runConsoleCommand('ecotone:flow:dump', ['format' => 'json', 'build' => true]);
    }

    public function test_the_flow_dump_without_its_build_part_is_the_same_at_both_tracing_levels(): void
    {
        $dumps = [];
        foreach (TracingLevel::cases() as $level) {
            foreach (['mermaid', 'json'] as $format) {
                $ecotone = $this->application($level);
                $ecotone->runConsoleCommand('ecotone:flow:dump', ['format' => $format]);
                $dumps[$level->value][$format] = $ecotone->getInMemoryConsoleWriter()->getWrittenContent();
            }
        }

        self::assertSame($dumps['application'], $dumps['framework']);
    }

    public function test_the_build_view_at_the_framework_tracing_level_reads_the_wiring_before_the_recording_wraps_it(): void
    {
        $ecotone = $this->application(TracingLevel::Framework);

        $ecotone->runConsoleCommand('ecotone:flow:dump', ['format' => 'json', 'build' => true]);
        $dump = $ecotone->getInMemoryConsoleWriter()->getWrittenContent();

        self::assertStringContainsString('around interceptor Ecotone\\\\Tracing\\\\TracingInterceptor::traceHandlerEndpoint', $dump);
        self::assertStringNotContainsString('Ecotone\\\\Tracing\\\\Execution\\\\Recorded', $dump);
        self::assertStringNotContainsString('unrecognised layer', $dump);
    }

    public function test_recording_a_build_flow_snapshot_is_refused_at_the_application_tracing_level(): void
    {
        $ecotone = $this->application(TracingLevel::Application);

        $this->expectExceptionMessage('The build wiring Ecotone registers for this application was asked for, but it is read only at the framework tracing level. Switch it on with TracingConfiguration::createWithDefaults()->withSpanRecorder(InMemorySpanRecorder::class)->withLevel(TracingLevel::Framework).');

        $ecotone->runConsoleCommand('ecotone:build-flow:record', ['scenario' => 'Placing an order', 'location' => sys_get_temp_dir() . '/ecotone-build-flow-refused']);
    }

    private function application(TracingLevel $level): FlowTestSupport
    {
        $ordering = new class () {
            #[CommandHandler('order.place')]
            public function place(array $order, EventBus $eventBus): void
            {
                $eventBus->publishWithRouting('order.placed', $order);
            }

            #[Asynchronous('notifications')]
            #[EventHandler('order.placed', endpointId: 'notify')]
            public function notify(array $order): void
            {
            }
        };
        $tracing = TracingConfiguration::createWithDefaults()->withSpanRecorder(InMemorySpanRecorder::class);

        return EcotoneLite::bootstrapFlowTesting(
            [$ordering::class, BuildFlowConsoleCommands::class],
            [$ordering, new BuildFlowConsoleCommands(), InMemorySpanRecorder::class => new InMemorySpanRecorder()],
            [SimpleMessageChannelBuilder::createQueueChannel('notifications'), $tracing->withLevel($level)],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }
}
