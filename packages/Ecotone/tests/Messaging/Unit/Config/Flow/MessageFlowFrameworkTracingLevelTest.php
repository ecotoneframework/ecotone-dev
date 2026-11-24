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
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class MessageFlowFrameworkTracingLevelTest extends TestCase
{
    public function test_the_framework_part_of_the_flow_dump_is_refused_at_the_application_tracing_level(): void
    {
        $ecotone = $this->application(TracingLevel::Application);

        $this->expectExceptionMessage('ecotone:flow:dump --framework shows what Ecotone itself registers (buses, interceptors, routing, channels, consumers) and needs the framework tracing level. Return TracingConfiguration::createWithDefaults()->withLevel(TracingLevel::Framework) from a #[ServiceContext] with #[Environment([...])] naming your development and test environments (Symfony dev, test; Laravel local, testing). At that level every step is also exported as a span, many more per message, so keep it out of production. Or run ecotone:flow:dump without --framework.');

        $ecotone->runConsoleCommand('ecotone:flow:dump', ['format' => 'json', 'framework' => true]);
    }

    public function test_the_flow_dump_without_its_framework_part_is_the_same_at_both_tracing_levels(): void
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

    public function test_the_framework_view_at_the_framework_tracing_level_reads_the_wiring_before_the_recording_wraps_it(): void
    {
        $ecotone = $this->application(TracingLevel::Framework);

        $ecotone->runConsoleCommand('ecotone:flow:dump', ['format' => 'json', 'framework' => true]);
        $dump = $ecotone->getInMemoryConsoleWriter()->getWrittenContent();

        self::assertStringContainsString('around interceptor Ecotone\\\\Tracing\\\\TracingInterceptor::traceHandlerEndpoint', $dump);
        self::assertStringNotContainsString('Ecotone\\\\Tracing\\\\Execution\\\\Recorded', $dump);
        self::assertStringNotContainsString('unrecognised layer', $dump);
    }

    public function test_an_application_outside_flow_testing_runs_at_the_framework_level_without_a_span_recorder(): void
    {
        $ordering = $this->ordering();
        $application = EcotoneLite::bootstrap(
            [],
            [$ordering],
            [SimpleMessageChannelBuilder::createQueueChannel('notifications'), TracingConfiguration::createWithDefaults()->withLevel(TracingLevel::Framework)],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $application->getCommandBus()->sendWithRouting('order.place', []);

        self::assertSame(['notifications'], $application->list());
    }

    public function test_the_framework_view_needs_no_span_recorder_that_keeps_spans(): void
    {
        $exporter = new class () implements SpanRecorder {
            public function record(Span $span): void
            {
            }
        };
        $ordering = $this->ordering();
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [],
            [$ordering, 'spanExporter' => $exporter],
            [SimpleMessageChannelBuilder::createQueueChannel('notifications'), TracingConfiguration::createWithDefaults()->withSpanRecorder('spanExporter')->withLevel(TracingLevel::Framework)],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotone->runConsoleCommand('ecotone:flow:dump', ['format' => 'json', 'framework' => true]);

        self::assertStringContainsString('"framework": {', $ecotone->getInMemoryConsoleWriter()->getWrittenContent());
    }

    public function test_without_an_enterprise_licence_the_framework_level_records_and_the_flow_dump_is_not_registered(): void
    {
        $ordering = $this->ordering();
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [],
            [$ordering],
            [SimpleMessageChannelBuilder::createQueueChannel('notifications'), TracingConfiguration::createWithDefaults()->withLevel(TracingLevel::Framework)],
            enabledModules: [],
        );

        $ecotone->sendCommandWithRouting('order.place', []);

        self::assertNotSame([], $ecotone->popExecutionRecording()->processes);
        $this->expectExceptionMessage('Console command `ecotone:flow:dump` is not registered.');

        $ecotone->runConsoleCommand('ecotone:flow:dump', ['format' => 'json']);
    }

    private function application(TracingLevel $level): FlowTestSupport
    {
        $ordering = $this->ordering();

        return EcotoneLite::bootstrapFlowTesting(
            [],
            [$ordering],
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
