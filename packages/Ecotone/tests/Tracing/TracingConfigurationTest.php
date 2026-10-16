<?php

declare(strict_types=1);

namespace Test\Ecotone\Tracing;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Headers;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Api\Tracing\InMemorySpanRecorder;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Tracing\Config\TracerReference;

/**
 * licence Apache-2.0
 * @internal
 */
final class TracingConfigurationTest extends TracingTestCase
{
    public function test_without_a_configured_recorder_nothing_is_recorded_and_no_trace_carrier_is_stamped(): void
    {
        $recorder = new InMemorySpanRecorder();
        $users = new class () {
            public array $headers = [];

            #[Asynchronous('async_channel')]
            #[CommandHandler('user.register', endpointId: 'user.register.endpoint')]
            public function register(string $userId, #[Headers] array $headers): void
            {
                $this->headers = $headers;
            }
        };

        EcotoneLite::bootstrapFlowTesting(
            [$users::class],
            [$users, ...self::recordingSpansInto($recorder)],
            [SimpleMessageChannelBuilder::createQueueChannel('async_channel')],
        )
            ->sendCommandWithRouting('user.register', '1')
            ->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup());

        $this->assertSame([], $recorder->recordedSpans());
        $this->assertArrayNotHasKey(MessageHeaders::TRACEPARENT, $users->headers);
    }

    public function test_with_a_configured_recorder_the_trace_carrier_reaches_the_asynchronous_handler(): void
    {
        $recorder = new InMemorySpanRecorder();
        $users = new class () {
            public array $headers = [];

            #[Asynchronous('async_channel')]
            #[CommandHandler('user.register', endpointId: 'user.register.endpoint')]
            public function register(string $userId, #[Headers] array $headers): void
            {
                $this->headers = $headers;
            }
        };

        EcotoneLite::bootstrapFlowTesting(
            [$users::class],
            [$users, ...self::recordingSpansInto($recorder)],
            [self::tracingConfiguration(), SimpleMessageChannelBuilder::createQueueChannel('async_channel')],
        )
            ->sendCommandWithRouting('user.register', '1')
            ->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup());

        $this->assertMatchesRegularExpression('/^00-[0-9a-f]{32}-[0-9a-f]{16}-01$/', $users->headers[MessageHeaders::TRACEPARENT]);
    }

    public function test_two_tracers_are_refused_at_bootstrap(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Only one Tracer can be active, yet these were registered: first.tracer, second.tracer. Enable a single tracing package.');

        EcotoneLite::bootstrapFlowTesting(
            [],
            [],
            [new TracerReference('first.tracer'), new TracerReference('second.tracer')],
        );
    }
}
