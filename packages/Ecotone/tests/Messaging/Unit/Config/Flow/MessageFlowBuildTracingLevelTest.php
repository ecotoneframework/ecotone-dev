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
use Ecotone\Api\Tracing\Span;
use Ecotone\Api\Tracing\SpanRecorder;
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

        $this->expectExceptionMessage('ecotone:flow:dump --build shows what Ecotone itself registers for the application (buses, interceptors, routing, channels, consumers) and is available only at the framework tracing level. Return TracingConfiguration::createWithDefaults()->withLevel(TracingLevel::Framework) from a #[ServiceContext]; at that level Ecotone also records every step it runs for each message as a span sent to the span recorders registered with TracingConfiguration::withSpanRecorder(), and nowhere when none is registered; the spans an OpenTelemetry tracer exports stay the same and only gain the attributes ecotone.execution.order and ecotone.execution.process or ecotone.execution.parent. Or run ecotone:flow:dump without --build for the message flow the application declares.');

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

        $this->expectExceptionMessage('The build wiring Ecotone registers for this application was asked for, but it is read only at the framework tracing level. Switch it on with TracingConfiguration::createWithDefaults()->withLevel(TracingLevel::Framework).');

        $ecotone->runConsoleCommand('ecotone:build-flow:record', ['scenario' => 'Placing an order', 'location' => sys_get_temp_dir() . '/ecotone-build-flow-refused']);
    }

    public function test_an_application_outside_flow_testing_runs_at_the_framework_level_without_a_span_recorder(): void
    {
        $ordering = $this->ordering();
        $application = EcotoneLite::bootstrap(
            [$ordering::class],
            [$ordering],
            [SimpleMessageChannelBuilder::createQueueChannel('notifications'), TracingConfiguration::createWithDefaults()->withLevel(TracingLevel::Framework)],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $application->getCommandBus()->sendWithRouting('order.place', []);

        self::assertSame(['notifications'], $application->list());
    }

    public function test_the_build_view_needs_no_span_recorder_that_keeps_spans(): void
    {
        $exporter = new class () implements SpanRecorder {
            public function record(Span $span): void
            {
            }
        };
        $ordering = $this->ordering();
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [$ordering::class],
            [$ordering, 'spanExporter' => $exporter],
            [SimpleMessageChannelBuilder::createQueueChannel('notifications'), TracingConfiguration::createWithDefaults()->withSpanRecorder('spanExporter')->withLevel(TracingLevel::Framework)],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotone->runConsoleCommand('ecotone:flow:dump', ['format' => 'json', 'build' => true]);

        self::assertStringContainsString('"build": {', $ecotone->getInMemoryConsoleWriter()->getWrittenContent());
    }

    public function test_without_an_enterprise_licence_the_framework_level_records_and_the_flow_dump_is_not_registered(): void
    {
        $ordering = $this->ordering();
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [$ordering::class],
            [$ordering],
            [SimpleMessageChannelBuilder::createQueueChannel('notifications'), TracingConfiguration::createWithDefaults()->withLevel(TracingLevel::Framework)],
            enabledModules: [],
        );

        $ecotone->sendCommandWithRouting('order.place', []);

        self::assertNotSame([], $ecotone->popExecutionRecording()->processes);
        $this->expectExceptionMessage('Console command `ecotone:flow:dump` is not registered.');

        $ecotone->runConsoleCommand('ecotone:flow:dump', ['format' => 'json']);
    }

    public function test_recording_a_build_flow_snapshot_without_an_enterprise_licence_is_refused(): void
    {
        $ordering = $this->ordering();
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [$ordering::class, BuildFlowConsoleCommands::class],
            [$ordering, new BuildFlowConsoleCommands()],
            [SimpleMessageChannelBuilder::createQueueChannel('notifications'), TracingConfiguration::createWithDefaults()->withLevel(TracingLevel::Framework)],
            enabledModules: [],
        );

        $this->expectExceptionMessage('The build wiring Ecotone registers for this application was asked for, but it is read only with an Ecotone Enterprise licence. Provide a licence key; see https://docs.ecotone.tech/enterprise for details.');

        $ecotone->runConsoleCommand('ecotone:build-flow:record', ['scenario' => 'Placing an order', 'location' => sys_get_temp_dir() . '/ecotone-build-flow-unlicensed']);
    }

    private function application(TracingLevel $level): FlowTestSupport
    {
        $ordering = $this->ordering();

        return EcotoneLite::bootstrapFlowTesting(
            [$ordering::class, BuildFlowConsoleCommands::class],
            [$ordering, new BuildFlowConsoleCommands()],
            [SimpleMessageChannelBuilder::createQueueChannel('notifications'), TracingConfiguration::createWithDefaults()->withLevel($level)],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    private function ordering(): object
    {
        return new class () {
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
    }
}
