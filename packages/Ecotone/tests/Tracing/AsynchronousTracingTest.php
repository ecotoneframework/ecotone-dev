<?php

declare(strict_types=1);

namespace Test\Ecotone\Tracing;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Attribute\Poller;
use Ecotone\Api\Attribute\Scheduled;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Gateway\EventBus;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Api\Tracing\InMemorySpanRecorder;
use Ecotone\Api\Tracing\Span;
use Ecotone\Api\Tracing\SpanKind;
use Symfony\Component\Uid\Uuid;

/**
 * licence Apache-2.0
 * @internal
 */
final class AsynchronousTracingTest extends TracingTestCase
{
    public function test_tracing_with_asynchronous_handler(): void
    {
        $recorder = new InMemorySpanRecorder();
        $users = $this->asynchronousUsers();

        EcotoneLite::bootstrapFlowTesting(
            [$users::class],
            [$users, ...self::recordingSpansInto($recorder)],
            [self::tracingConfiguration(), SimpleMessageChannelBuilder::createQueueChannel('async_channel')],
        )
            ->sendCommandWithRouting('user.register', '1')
            ->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup());

        self::compareTreesByDetails(
            [
                [
                    'details' => ['name' => 'Command Bus'],
                    'children' => [
                        [
                            'details' => ['name' => 'Sending to Channel: async_channel', 'kind' => SpanKind::Producer],
                            'children' => [
                                [
                                    'details' => ['name' => 'Receiving from channel: async_channel', 'kind' => SpanKind::Consumer],
                                    'children' => [
                                        ['details' => ['name' => 'Command Handler: ' . $users::class . '::register'], 'children' => []],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            self::buildTree($recorder->recordedSpans())
        );
    }

    public function test_the_consumer_continues_the_trace_of_the_sender_across_the_queue(): void
    {
        $recorder = new InMemorySpanRecorder();
        $users = $this->asynchronousUsers();

        EcotoneLite::bootstrapFlowTesting(
            [$users::class],
            [$users, ...self::recordingSpansInto($recorder)],
            [self::tracingConfiguration(), SimpleMessageChannelBuilder::createQueueChannel('async_channel')],
        )
            ->sendCommandWithRouting('user.register', '1')
            ->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup());

        $traceIds = array_unique(array_map(fn (Span $span): string => $span->traceId, $recorder->recordedSpans()));
        $this->assertCount(1, $traceIds);
    }

    public function test_tracing_same_message_for_asynchronous_scenario(): void
    {
        $recorder = new InMemorySpanRecorder();
        $users = $this->asynchronousUsers();
        $messageId = Uuid::v7()->toRfc4122();
        $correlationId = Uuid::v7()->toRfc4122();

        EcotoneLite::bootstrapFlowTesting(
            [$users::class],
            [$users, ...self::recordingSpansInto($recorder)],
            [self::tracingConfiguration(), SimpleMessageChannelBuilder::createQueueChannel('async_channel')],
        )
            ->sendCommandWithRouting('user.register', '1', metadata: [
                MessageHeaders::MESSAGE_ID => $messageId,
                MessageHeaders::MESSAGE_CORRELATION_ID => $correlationId,
            ])
            ->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup());

        $node = self::getNodeAtTargetedSpan(
            [
                'details' => ['name' => 'Command Bus'],
                'child' => [
                    'details' => ['name' => 'Sending to Channel: async_channel'],
                    'child' => ['details' => ['name' => 'Receiving from channel: async_channel']],
                ],
            ],
            self::buildTree($recorder->recordedSpans())
        );

        $this->assertSame($correlationId, $node['details']['attributes'][MessageHeaders::MESSAGE_CORRELATION_ID]);
        $this->assertSame($messageId, $node['details']['attributes'][MessageHeaders::MESSAGE_ID]);
        $child = $node['children'][array_key_first($node['children'])];
        $this->assertSame($correlationId, $child['details']['attributes'][MessageHeaders::MESSAGE_CORRELATION_ID]);
        $this->assertSame($messageId, $child['details']['attributes'][MessageHeaders::MESSAGE_ID]);
    }

    public function test_two_traces_with_asynchronous_handlers(): void
    {
        $recorder = new InMemorySpanRecorder();
        $users = $this->asynchronousUsers();

        EcotoneLite::bootstrapFlowTesting(
            [$users::class],
            [$users, ...self::recordingSpansInto($recorder)],
            [self::tracingConfiguration(), SimpleMessageChannelBuilder::createQueueChannel('async_channel')],
        )
            ->sendCommandWithRouting('user.register', '1', metadata: ['flowId' => '1'])
            ->sendCommandWithRouting('user.register', '2', metadata: ['flowId' => '2'])
            ->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 2));

        $expectedFlow = fn (string $flowId): array => [
            'details' => ['name' => 'Command Bus', 'attributes' => ['flowId' => $flowId]],
            'children' => [
                [
                    'details' => ['name' => 'Sending to Channel: async_channel', 'attributes' => ['flowId' => $flowId]],
                    'children' => [
                        [
                            'details' => ['name' => 'Receiving from channel: async_channel', 'attributes' => ['flowId' => $flowId]],
                            'children' => [
                                ['details' => ['name' => 'Command Handler: ' . $users::class . '::register', 'attributes' => ['flowId' => $flowId]], 'children' => []],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        self::compareTreesByDetails([$expectedFlow('1'), $expectedFlow('2')], self::buildTree($recorder->recordedSpans()));
        $this->assertCount(2, array_unique(array_map(fn (Span $span): string => $span->traceId, $recorder->recordedSpans())));
    }

    public function test_user_headers_travel_with_the_message_into_every_span(): void
    {
        $recorder = new InMemorySpanRecorder();
        $users = $this->asynchronousUsers();

        EcotoneLite::bootstrapFlowTesting(
            [$users::class],
            [$users, ...self::recordingSpansInto($recorder)],
            [self::tracingConfiguration(), SimpleMessageChannelBuilder::createQueueChannel('async_channel')],
        )
            ->sendCommandWithRouting('user.register', '1', metadata: ['user_id' => '123'])
            ->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup());

        foreach (['Command Bus', 'Sending to Channel: async_channel', 'Receiving from channel: async_channel', 'Command Handler: ' . $users::class . '::register'] as $spanName) {
            $spans = array_values(array_filter($recorder->recordedSpans(), fn (Span $span): bool => $span->name === $spanName));
            $this->assertCount(1, $spans, $spanName);
            $this->assertSame('123', $spans[0]->attributes['user_id'], $spanName);
        }
    }

    public function test_the_trace_carrier_header_is_not_reported_as_a_user_attribute(): void
    {
        $recorder = new InMemorySpanRecorder();
        $users = $this->asynchronousUsers();

        EcotoneLite::bootstrapFlowTesting(
            [$users::class],
            [$users, ...self::recordingSpansInto($recorder)],
            [self::tracingConfiguration(), SimpleMessageChannelBuilder::createQueueChannel('async_channel')],
        )
            ->sendCommandWithRouting('user.register', '1')
            ->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup());

        foreach ($recorder->recordedSpans() as $span) {
            $this->assertArrayNotHasKey(MessageHeaders::TRACEPARENT, $span->attributes, $span->name);
        }
    }

    public function test_an_event_collected_during_the_asynchronous_handler_is_sent_under_the_consumer_span_in_the_same_trace(): void
    {
        $recorder = new InMemorySpanRecorder();
        $users = new class () {
            #[Asynchronous('async_channel')]
            #[CommandHandler('user.register', endpointId: 'user.register.endpoint')]
            public function register(string $userId, EventBus $eventBus): void
            {
                $eventBus->publishWithRouting('user.registered', $userId);
            }
        };
        $notifier = new class () {
            #[Asynchronous('async_channel')]
            #[EventHandler('user.registered', endpointId: 'user.registered.endpoint')]
            public function notify(string $userId): void
            {
            }
        };

        EcotoneLite::bootstrapFlowTesting(
            [$users::class, $notifier::class],
            [$users, $notifier, ...self::recordingSpansInto($recorder)],
            [self::tracingConfiguration(), SimpleMessageChannelBuilder::createQueueChannel('async_channel')],
        )
            ->sendCommandWithRouting('user.register', '1')
            ->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 2));

        self::getNodeAtTargetedSpan(
            [
                'details' => ['name' => 'Command Bus'],
                'child' => [
                    'details' => ['name' => 'Sending to Channel: async_channel'],
                    'child' => [
                        'details' => ['name' => 'Receiving from channel: async_channel'],
                        'child' => [
                            'details' => ['name' => 'Sending to Channel: async_channel'],
                            'child' => [
                                'details' => ['name' => 'Receiving from channel: async_channel'],
                                'child' => ['details' => ['name' => 'Event Handler: ' . $notifier::class . '::notify']],
                            ],
                        ],
                    ],
                ],
            ],
            self::buildTree($recorder->recordedSpans())
        );
        self::getNodeAtTargetedSpan(
            [
                'details' => ['name' => 'Command Bus'],
                'child' => [
                    'details' => ['name' => 'Sending to Channel: async_channel'],
                    'child' => [
                        'details' => ['name' => 'Receiving from channel: async_channel'],
                        'child' => [
                            'details' => ['name' => 'Command Handler: ' . $users::class . '::register'],
                            'child' => ['details' => ['name' => 'Event Bus']],
                        ],
                    ],
                ],
            ],
            self::buildTree($recorder->recordedSpans())
        );
        $this->assertCount(1, array_unique(array_map(fn (Span $span): string => $span->traceId, $recorder->recordedSpans())));
    }

    public function test_tracing_scheduled_handler(): void
    {
        $recorder = new InMemorySpanRecorder();
        $scheduled = new class () {
            #[Scheduled(endpointId: 'scheduled_handler')]
            #[Poller(fixedRateInMilliseconds: 1)]
            public function handle(): void
            {
            }
        };

        EcotoneLite::bootstrapFlowTesting(
            [$scheduled::class],
            [$scheduled, ...self::recordingSpansInto($recorder)],
            [self::tracingConfiguration()],
        )
            ->run('scheduled_handler', ExecutionPollingMetadata::createWithDefaults()->withExecutionAmountLimit(2));

        self::compareTreesByDetails(
            [
                ['details' => ['name' => 'Receiving from inbound channel adapter: nullChannel', 'kind' => SpanKind::Consumer], 'children' => []],
                ['details' => ['name' => 'Receiving from inbound channel adapter: nullChannel', 'kind' => SpanKind::Consumer], 'children' => []],
            ],
            self::buildTree($recorder->recordedSpans())
        );
        $this->assertCount(2, array_unique(array_map(fn (Span $span): string => $span->traceId, $recorder->recordedSpans())));
    }

    public function test_tracing_workflow_scheduled_handler(): void
    {
        $recorder = new InMemorySpanRecorder();
        $workflow = $this->workflowScheduledHandler();

        EcotoneLite::bootstrapFlowTesting(
            [$workflow::class],
            [$workflow, ...self::recordingSpansInto($recorder)],
            [self::tracingConfiguration()],
        )
            ->run('scheduled_handler', ExecutionPollingMetadata::createWithDefaults()->withExecutionAmountLimit(2));

        $expectedRun = [
            'details' => ['name' => 'Receiving from inbound channel adapter: add'],
            'children' => [
                ['details' => ['name' => 'Message Handler: ' . $workflow::class . '::add'], 'children' => []],
            ],
        ];
        self::compareTreesByDetails([$expectedRun, $expectedRun], self::buildTree($recorder->recordedSpans()));
    }

    public function test_no_tracing_when_there_is_nothing_to_trace(): void
    {
        $recorder = new InMemorySpanRecorder();
        $workflow = $this->workflowScheduledHandler();

        EcotoneLite::bootstrapFlowTesting(
            [$workflow::class],
            [$workflow, ...self::recordingSpansInto($recorder)],
            [self::tracingConfiguration()],
        )
            ->run('scheduled_handler_without_result', ExecutionPollingMetadata::createWithDefaults()->withExecutionAmountLimit(1));

        $this->assertSame([], $recorder->recordedSpans());
    }

    private function asynchronousUsers(): object
    {
        return new class () {
            #[Asynchronous('async_channel')]
            #[CommandHandler('user.register', endpointId: 'user.register.endpoint')]
            public function register(string $userId): void
            {
            }
        };
    }

    private function workflowScheduledHandler(): object
    {
        return new class () {
            #[Scheduled(requestChannelName: 'add', endpointId: 'scheduled_handler')]
            #[Poller(fixedRateInMilliseconds: 1)]
            public function produce(): int
            {
                return 1;
            }

            #[Scheduled(requestChannelName: 'add', endpointId: 'scheduled_handler_without_result')]
            #[Poller(fixedRateInMilliseconds: 1)]
            public function produceNothing(): ?int
            {
                return null;
            }

            #[InternalHandler(inputChannelName: 'add')]
            public function add(): void
            {
            }
        };
    }
}
