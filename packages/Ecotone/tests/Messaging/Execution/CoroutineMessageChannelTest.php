<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Execution;

use Closure;
use DateTimeImmutable;
use Ecotone\Api\Attribute\Aggregate;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\Collected;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\ConsoleCommand;
use Ecotone\Api\Attribute\Delayed;
use Ecotone\Api\Attribute\DelayedRetry;
use Ecotone\Api\Attribute\ErrorChannel;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\Gathered;
use Ecotone\Api\Attribute\Header;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Attribute\MessageGateway;
use Ecotone\Api\Attribute\OnConsumerStop;
use Ecotone\Api\Attribute\Priority;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Attribute\Reference;
use Ecotone\Api\Attribute\Saga;
use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Api\Attribute\Splitter;
use Ecotone\Api\Attribute\TimeToLive;
use Ecotone\Api\ExtensionObject\CoroutineMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\InstantRetryConfiguration;
use Ecotone\Api\ExtensionObject\PollingMetadata;
use Ecotone\Api\ExtensionObject\RetryTemplateBuilder;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Gateway\EcotoneClockInterface;
use Ecotone\Api\Gateway\GatheredGroups;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\CollectedMessages;
use Ecotone\Api\Messaging\CollectedReleaseReason;
use Ecotone\Api\Messaging\ErrorContext;
use Ecotone\Api\Messaging\GatheredParts;
use Ecotone\Api\Messaging\GatheredReleaseReason;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Api\Projecting\FromStream;
use Ecotone\Api\Projecting\Projection;
use Ecotone\Api\Projecting\ProjectionRebuild;
use Ecotone\Api\Scheduling\Duration;
use Ecotone\Api\Scheduling\SleepInterface;
use Ecotone\Api\Tracing\TracingConfiguration;
use Ecotone\Api\Tracing\TracingLevel;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Endpoint\PollingConsumer\RejectMessageException;
use Ecotone\Test\CoroutineTesting;
use Ecotone\Test\LicenceTesting;
use Fiber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;
use Swoole\Coroutine;
use Swoole\Event;

/**
 * licence Apache-2.0
 * @internal
 */
#[RequiresMethod(Coroutine::class, 'create')]
final class CoroutineMessageChannelTest extends TestCase
{
    public function test_handler_runs_on_a_coroutine_of_the_sending_process(): void
    {
        $handler = new class () {
            public array $handled = [];

            #[Asynchronous('async')]
            #[CommandHandler('handle', endpointId: 'handle.endpoint')]
            public function handle(string $payload): void
            {
                $this->handled[] = [$payload, Coroutine::getCid(), getmypid()];
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting([], [$handler], extensionObjects: [CoroutineMessageChannelBuilder::create('async')], licenceKey: LicenceTesting::VALID_LICENCE);
        $sender = 0;

        CoroutineTesting::runAtOnce(static function () use ($ecotone, &$sender): void {
            $sender = Coroutine::getCid();
            $ecotone->sendCommandWithRouting('handle', 'payload');
        });

        $this->assertCount(1, $handler->handled);
        $this->assertSame('payload', $handler->handled[0][0]);
        $this->assertNotSame($sender, $handler->handled[0][1]);
        $this->assertSame(getmypid(), $handler->handled[0][2]);
    }

    public function test_handler_on_the_channel_is_recorded_when_framework_tracing_records_the_execution(): void
    {
        $handler = new class () {
            public array $handled = [];

            #[Asynchronous('async')]
            #[CommandHandler('handle', endpointId: 'handle.endpoint')]
            public function handle(string $payload): void
            {
                $this->handled[] = $payload;
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting([], [$handler], extensionObjects: [CoroutineMessageChannelBuilder::create('async'), TracingConfiguration::createWithDefaults()->withLevel(TracingLevel::Framework)], licenceKey: LicenceTesting::VALID_LICENCE);

        CoroutineTesting::runAtOnce(static fn () => $ecotone->sendCommandWithRouting('handle', 'payload'));

        $this->assertSame(['payload'], $handler->handled);
        $recorded = array_map(trim(...), $ecotone->popExecutionRecording()->lines);
        $this->assertContains('process 1: send command handle', $recorded);
        $this->assertContains('sub-flow process 2: receive async, class@anonymous::handle', $recorded);
        $this->assertContains('handler class@anonymous::handle', $recorded);
        $this->assertContains('↳ taken by: process 2, on a coroutine of the sending process', $recorded);
    }

    #[DataProvider('headers_of_a_dead_letter_replay')]
    public function test_send_outside_a_coroutine_carrying_the_headers_of_a_dead_letter_replay_is_refused(array $headers): void
    {
        $handler = new class () {
            public array $handled = [];

            #[Asynchronous('async')]
            #[CommandHandler('handle', endpointId: 'handle.endpoint')]
            public function handle(string $payload): void
            {
                $this->handled[] = $payload;
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting([], [$handler], extensionObjects: [CoroutineMessageChannelBuilder::create('async')], licenceKey: LicenceTesting::VALID_LICENCE);

        $refusal = null;
        try {
            $ecotone->sendCommandWithRouting('handle', 'payload', metadata: $headers);
        } catch (ConfigurationException $exception) {
            $refusal = $exception->getMessage();
        }

        $this->assertStringStartsWith('Coroutine message channel `async` cannot send outside a Swoole or OpenSwoole coroutine.', (string) $refusal);
        $this->assertSame([], $handler->handled);
    }

    #[DataProvider('headers_of_a_dead_letter_replay')]
    public function test_send_inside_a_coroutine_carrying_the_headers_of_a_dead_letter_replay_is_handled_on_another_coroutine_like_any_send(array $headers): void
    {
        $handler = new class () {
            public array $handledOn = [];

            #[Asynchronous('async')]
            #[CommandHandler('handle', endpointId: 'handle.endpoint')]
            public function handle(string $payload): void
            {
                $this->handledOn[] = Coroutine::getCid();
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting([], [$handler], extensionObjects: [CoroutineMessageChannelBuilder::create('async')], licenceKey: LicenceTesting::VALID_LICENCE);
        $sender = 0;
        $handledWhenTheSendReturned = [];

        CoroutineTesting::runAtOnce(static function () use ($ecotone, $handler, $headers, &$sender, &$handledWhenTheSendReturned): void {
            $sender = Coroutine::getCid();
            $ecotone->sendCommandWithRouting('handle', 'payload', metadata: $headers);
            $handledWhenTheSendReturned = $handler->handledOn;
        });

        $this->assertSame([], $handledWhenTheSendReturned);
        $this->assertCount(1, $handler->handledOn);
        $this->assertNotSame($sender, $handler->handledOn[0]);
    }

    public static function headers_of_a_dead_letter_replay(): array
    {
        return [
            'the replay header' => [[ErrorContext::DLQ_MESSAGE_REPLIED => '1']],
            'the replay header and the channel as entrypoint' => [[ErrorContext::DLQ_MESSAGE_REPLIED => '1', 'ecotone.messaging.entrypoint' => 'async']],
        ];
    }

    public function test_a_failed_sender_starts_no_handler(): void
    {
        $handler = new class () {
            public array $handled = [];

            #[CommandHandler('send')]
            public function send(string $payload, #[Reference] CommandBus $commandBus): void
            {
                $commandBus->sendWithRouting('handle', $payload);
                throw new RuntimeException('sender failed');
            }

            #[Asynchronous('async')]
            #[CommandHandler('handle', endpointId: 'handle.endpoint')]
            public function handle(string $payload): void
            {
                $this->handled[] = $payload;
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting([], [$handler], extensionObjects: [CoroutineMessageChannelBuilder::create('async')], licenceKey: LicenceTesting::VALID_LICENCE);

        $failure = null;
        CoroutineTesting::runAtOnce(static function () use ($ecotone, &$failure): void {
            try {
                $ecotone->sendCommandWithRouting('send', 'payload');
            } catch (RuntimeException $exception) {
                $failure = $exception->getMessage();
            }
        });

        $this->assertSame('sender failed', $failure);
        $this->assertSame([], $handler->handled);
    }

    #[DataProvider('concurrency_limits')]
    public function test_concurrency_is_limited_and_waiting_messages_start_in_order(int $limit, int $messages): void
    {
        $handler = new class ($messages) {
            public int $active = 0;
            public int $maximum = 0;
            public array $started = [];
            public array $finished = [];
            public Coroutine\Channel $startedSignals;
            public Coroutine\Channel $release;
            public Coroutine\Channel $finishedSignals;

            public function __construct(int $messages)
            {
                $this->startedSignals = new Coroutine\Channel($messages);
                $this->release = new Coroutine\Channel($messages);
                $this->finishedSignals = new Coroutine\Channel($messages);
            }

            #[Asynchronous('async')]
            #[CommandHandler('handle', endpointId: 'handle.endpoint')]
            public function handle(int $payload): void
            {
                $this->started[] = $payload;
                $this->active++;
                $this->maximum = max($this->maximum, $this->active);
                $this->startedSignals->push(true);
                $this->release->pop();
                $this->active--;
                $this->finished[] = $payload;
                $this->finishedSignals->push(true);
            }
        };
        $default = CoroutineMessageChannelBuilder::create('async');
        $default->withCoroutines(1);
        $limited = $limit === CoroutineMessageChannelBuilder::DEFAULT_COROUTINES ? $default : $default->withCoroutines($limit);
        $ecotone = EcotoneLite::bootstrapFlowTesting([], [$handler], extensionObjects: [$limited], licenceKey: LicenceTesting::VALID_LICENCE);
        $saturation = [];

        CoroutineTesting::runAtOnce(static function () use ($ecotone, $handler, $limit, $messages, &$saturation): void {
            foreach (range(1, $limit) as $number) {
                $ecotone->sendCommandWithRouting('handle', $number);
            }
            foreach (range(1, $limit) as $number) {
                $handler->startedSignals->pop();
            }
            foreach (range($limit + 1, $messages) as $number) {
                $ecotone->sendCommandWithRouting('handle', $number);
            }
            $nextTick = new Coroutine\Channel();
            Event::defer(static fn () => $nextTick->push(true));
            $nextTick->pop();
            $saturation = [$handler->active, $handler->started];
            foreach (range(1, $messages) as $number) {
                $handler->release->push(true);
                $handler->finishedSignals->pop();
            }
        });

        $this->assertSame([$limit, range(1, $limit)], $saturation);
        $this->assertSame($limit, $handler->maximum);
        $this->assertSame(range(1, $messages), $handler->started);
        $this->assertCount($messages, $handler->finished);
    }

    public function test_failed_messages_are_logged_with_headers_and_dropped_while_others_continue(): void
    {
        $logger = new class () extends AbstractLogger {
            public array $errors = [];
            public array $messages = [];

            public function log($level, Stringable|string $message, array $context = []): void
            {
                if (isset($context['exception'])) {
                    $this->errors[] = $context;
                    $this->messages[] = (string) $message;
                }
            }
        };
        $handler = new class () {
            public array $handled = [];

            #[Asynchronous('async')]
            #[CommandHandler('handle', endpointId: 'handle.endpoint')]
            public function handle(string $payload): void
            {
                if ($payload === 'failed') {
                    throw new RuntimeException('handling failed');
                }
                $this->handled[] = $payload;
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting([], [$handler, 'logger' => $logger], extensionObjects: [CoroutineMessageChannelBuilder::create('async')->withCoroutines(1), InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false)], licenceKey: LicenceTesting::VALID_LICENCE);

        CoroutineTesting::runAtOnce(static function () use ($ecotone): void {
            $ecotone->sendCommandWithRouting('handle', 'failed', metadata: ['tenant' => 'failed-tenant']);
            $ecotone->sendCommandWithRouting('handle', 'accepted');
        });

        $this->assertContains("Coroutine message channel `async` dropped a message after endpoint `handle.endpoint` failed. Configure #[Asynchronous('async', asynchronousExecution: [new ErrorChannel('failures')])] or PollingMetadata::create('async')->withErrorChannelName('failures') to route failed messages to an error channel; connect that error channel to a durable queue or dead letter to keep them.", $logger->messages);
        $this->assertSame(['accepted'], $handler->handled);
        $this->assertNotEmpty($logger->errors);
        $this->assertStringContainsString('failed-tenant', json_encode($logger->errors));
    }

    public function test_message_a_handler_rejects_is_logged_as_dropped_at_error_level_and_not_handled_again(): void
    {
        $logger = new class () extends AbstractLogger {
            public array $logged = [];

            public function log($level, Stringable|string $message, array $context = []): void
            {
                $this->logged[] = [(string) $level, (string) $message];
            }
        };
        $handler = new class () {
            public array $received = [];

            #[Asynchronous('async')]
            #[CommandHandler('handle', endpointId: 'handle.endpoint')]
            public function handle(string $payload): void
            {
                $this->received[] = $payload;
                if ($payload === 'rejected') {
                    throw new RejectMessageException('not for this handler');
                }
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting([], [$handler, 'logger' => $logger], extensionObjects: [CoroutineMessageChannelBuilder::create('async')->withCoroutines(1), InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false)], licenceKey: LicenceTesting::VALID_LICENCE);

        CoroutineTesting::runAtOnce(static function () use ($ecotone): void {
            $ecotone->sendCommandWithRouting('handle', 'rejected');
            $ecotone->sendCommandWithRouting('handle', 'accepted');
        });

        $this->assertSame(['rejected', 'accepted'], $handler->received);
        $this->assertSame(
            [['error', 'Coroutine message channel `async` dropped a message after endpoint `handle.endpoint` failed.']],
            array_values(array_map(
                static fn (array $logged): array => [$logged[0], substr($logged[1], 0, 92)],
                array_filter($logger->logged, static fn (array $logged): bool => str_contains($logged[1], 'dropped a message')),
            )),
        );
    }

    public function test_sending_outside_a_coroutine_is_refused(): void
    {
        $ecotone = $this->bootstrap_channel();
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Coroutine message channel `async` cannot send outside a Swoole or OpenSwoole coroutine. This can happen in a console command, a consumer started without --coroutines, or an HTTP server with enable_coroutine disabled. For HTTP requests set enable_coroutine=true on the server; for a sending consumer run ecotone:run <source-channel> --coroutines=N; for a console command run the command inside a Swoole or OpenSwoole coroutine. In tests, limit the #[ServiceContext] method returning CoroutineMessageChannelBuilder to the environments that run on Swoole or OpenSwoole, for example #[Environment([\'prod\', \'dev\'])], register SimpleMessageChannelBuilder::createQueueChannel(\'async\') in the test environment and call the flow test support\'s run(\'async\'). An uncaught send failure rolls back the sender\'s Ecotone transaction.');

        $ecotone->sendCommandWithRouting('handle', 'payload');
    }

    public function test_polling_concurrency_is_refused_at_bootstrap(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Coroutine message channel `async` has no separate polling consumer to configure: it is consumed in the process that sent the message. Remove PollingMetadata::withCoroutines() for `async` and set concurrency with CoroutineMessageChannelBuilder::withCoroutines() instead.');

        $this->bootstrap_channel([PollingMetadata::create('async')->withCoroutines(2)]);
    }

    public function test_starting_a_consumer_is_refused(): void
    {
        $ecotone = $this->bootstrap_channel();
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Coroutine message channel `async` is consumed in the process that sent the message, so ecotone:run and ConfiguredMessagingSystem::run() cannot start a separate consumer for it. Send commands or publish events to handlers marked #[Asynchronous(\'async\')]; they start automatically in the sending process. Set concurrency with CoroutineMessageChannelBuilder::withCoroutines(), or register a queue channel under `async` to consume it with ecotone:run.');

        $ecotone->run('async', ExecutionPollingMetadata::createWithFinishWhenNoMessages());
    }

    public function test_delayed_message_waits_on_a_coroutine_timer(): void
    {
        $handler = new class () {
            public array $handled = [];

            #[Delayed(milliseconds: 500)]
            #[Asynchronous('async')]
            #[CommandHandler('handle', endpointId: 'handle.endpoint')]
            public function handle(string $payload, #[Reference] EcotoneClockInterface $clock): void
            {
                $this->handled[] = [$payload, $clock->now()->format('Y-m-d H:i:s.u')];
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting([], [$handler], extensionObjects: [CoroutineMessageChannelBuilder::create('async')], licenceKey: LicenceTesting::VALID_LICENCE, initialTime: new DateTimeImmutable('2026-03-01 12:00:00'));
        $waitsBeforeHandling = [];
        $ecotone->whenWaiting(static function (DateTimeImmutable $now) use ($ecotone, $handler, &$waitsBeforeHandling): void {
            if ($handler->handled === []) {
                $waitsBeforeHandling[] = $now->format('Y-m-d H:i:s.u');
                $ecotone->changeTimeTo($now);
            }
        });

        CoroutineTesting::runAtOnce(static fn () => $ecotone->sendCommandWithRouting('handle', 'payload'));

        $this->assertSame(['2026-03-01 12:00:00.500000'], $waitsBeforeHandling);
        $this->assertSame([['payload', '2026-03-01 12:00:00.500000']], $handler->handled);
    }

    public function test_delayed_retry_returns_to_the_coroutine_channel_after_its_delay(): void
    {
        $handler = new class () {
            public int $attempts = 0;
            public array $handled = [];

            #[Asynchronous('async', asynchronousExecution: [new DelayedRetry(initialDelayInMilliseconds: 500, maxRetries: 1)])]
            #[CommandHandler('handle', endpointId: 'handle.endpoint')]
            public function handle(string $payload, #[Reference] EcotoneClockInterface $clock): void
            {
                $this->attempts++;
                if ($this->attempts === 1) {
                    throw new RuntimeException('try later');
                }
                $this->handled[] = [$payload, $clock->now()->format('Y-m-d H:i:s.u')];
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting([], [$handler], extensionObjects: [CoroutineMessageChannelBuilder::create('async'), InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false)], licenceKey: LicenceTesting::VALID_LICENCE, initialTime: new DateTimeImmutable('2026-03-01 12:00:00'));
        $ecotone->whenWaiting(static function (DateTimeImmutable $now) use ($ecotone): void {
            $ecotone->changeTimeTo($now);
        });

        CoroutineTesting::runAtOnce(static fn () => $ecotone->sendCommandWithRouting('handle', 'payload'));

        $this->assertSame(2, $handler->attempts);
        $this->assertSame([['payload', '2026-03-01 12:00:00.500000']], $handler->handled);
    }

    public function test_command_bus_returns_before_even_a_non_yielding_handler_finishes(): void
    {
        $handler = new class () {
            public array $steps = [];

            #[Asynchronous('async')]
            #[CommandHandler('handle', endpointId: 'handle.endpoint')]
            public function handle(string $payload): void
            {
                $this->steps[] = 'handled';
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting([], [$handler], extensionObjects: [CoroutineMessageChannelBuilder::create('async')], licenceKey: LicenceTesting::VALID_LICENCE);

        CoroutineTesting::runAtOnce(static function () use ($ecotone, $handler): void {
            $ecotone->sendCommandWithRouting('handle', 'payload');
            $handler->steps[] = 'returned';
        });

        $this->assertSame(['returned', 'handled'], $handler->steps);
    }

    public function test_class_routed_objects_keep_their_identity_with_service_context_registration(): void
    {
        $handler = new class () {
            public array $handled = [];

            #[ServiceContext]
            public function channel(): CoroutineMessageChannelBuilder
            {
                return CoroutineMessageChannelBuilder::create('async');
            }

            #[Asynchronous('async')]
            #[CommandHandler(endpointId: 'handle.endpoint')]
            public function handle(CoroutineChannelCommand $command): void
            {
                $this->handled[] = $command;
            }
        };
        $command = new CoroutineChannelCommand(static fn () => 'not serializable');
        $ecotone = EcotoneLite::bootstrapFlowTesting([], [$handler], licenceKey: LicenceTesting::VALID_LICENCE);

        CoroutineTesting::runAtOnce(static fn () => $ecotone->sendCommand($command));

        $this->assertSame([$command], $handler->handled);
    }

    public function test_failed_gateway_discards_messages_and_successful_gateway_releases_them(): void
    {
        $handler = new class () {
            public bool $fail = true;
            public array $handled = [];

            #[InternalHandler('gateway.input')]
            public function dispatch(string $payload, #[Reference] CommandBus $bus): void
            {
                $bus->sendWithRouting('handle', $payload);
                if ($this->fail) {
                    throw new RuntimeException('gateway failed');
                }
            }

            #[Asynchronous('async')]
            #[CommandHandler('handle', endpointId: 'handle.endpoint')]
            public function handle(string $payload): void
            {
                $this->handled[] = $payload;
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting([CoroutineChannelGateway::class], [$handler], extensionObjects: [CoroutineMessageChannelBuilder::create('async')], licenceKey: LicenceTesting::VALID_LICENCE);
        $failure = null;

        CoroutineTesting::runAtOnce(static function () use ($ecotone, $handler, &$failure): void {
            try {
                $ecotone->getGateway(CoroutineChannelGateway::class)->dispatch('discarded');
            } catch (RuntimeException $exception) {
                $failure = $exception->getMessage();
            }
            $handler->fail = false;
            $ecotone->getGateway(CoroutineChannelGateway::class)->dispatch('released');
        });

        $this->assertSame('gateway failed', $failure);
        $this->assertSame(['released'], $handler->handled);
    }

    public function test_instant_retry_runs_in_the_ordinary_asynchronous_consumer(): void
    {
        $handler = new class () {
            public int $attempts = 0;
            public array $handled = [];

            #[Asynchronous('async')]
            #[CommandHandler('handle', endpointId: 'handle.endpoint')]
            public function handle(string $payload): void
            {
                $this->attempts++;
                if ($this->attempts === 1) {
                    throw new RuntimeException('retry now');
                }
                $this->handled[] = $payload;
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting([], [$handler], extensionObjects: [CoroutineMessageChannelBuilder::create('async'), InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(true, 1)], licenceKey: LicenceTesting::VALID_LICENCE);

        CoroutineTesting::runAtOnce(static fn () => $ecotone->sendCommandWithRouting('handle', 'payload'));

        $this->assertSame(2, $handler->attempts);
        $this->assertSame(['payload'], $handler->handled);
    }

    public function test_handler_scoped_error_channel_receives_the_failure_and_headers(): void
    {
        $handler = new class () {
            public array $failures = [];

            #[Asynchronous('async', asynchronousExecution: [new ErrorChannel('failures')])]
            #[CommandHandler('handle', endpointId: 'handle.endpoint')]
            public function handle(string $payload): void
            {
                throw new RuntimeException('handling failed');
            }

            #[InternalHandler('failures')]
            public function failed(string $payload, #[Header(ErrorContext::EXCEPTION_MESSAGE)] string $exception, #[Header('tenant')] string $tenant): void
            {
                $this->failures[] = [$payload, $exception, $tenant];
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting([], [$handler], extensionObjects: [CoroutineMessageChannelBuilder::create('async'), InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false)], licenceKey: LicenceTesting::VALID_LICENCE);

        CoroutineTesting::runAtOnce(static fn () => $ecotone->sendCommandWithRouting('handle', 'payload', metadata: ['tenant' => 'failed-tenant']));

        $this->assertSame([['payload', 'handling failed', 'failed-tenant']], $handler->failures);
    }

    public function test_polling_metadata_error_channel_receives_the_failure_and_headers(): void
    {
        $handler = new class () {
            public array $failures = [];

            #[Asynchronous('async')]
            #[CommandHandler('handle', endpointId: 'handle.endpoint')]
            public function handle(string $payload): void
            {
                throw new RuntimeException('handling failed');
            }

            #[InternalHandler('failures')]
            public function failed(string $payload, #[Header(ErrorContext::EXCEPTION_MESSAGE)] string $exception, #[Header('tenant')] string $tenant): void
            {
                $this->failures[] = [$payload, $exception, $tenant];
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting([], [$handler], extensionObjects: [CoroutineMessageChannelBuilder::create('async'), PollingMetadata::create('async')->withErrorChannelName('failures'), InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false)], licenceKey: LicenceTesting::VALID_LICENCE);

        CoroutineTesting::runAtOnce(static fn () => $ecotone->sendCommandWithRouting('handle', 'payload', metadata: ['tenant' => 'failed-tenant']));

        $this->assertSame([['payload', 'handling failed', 'failed-tenant']], $handler->failures);
    }

    public function test_console_consumer_start_is_refused(): void
    {
        $ecotone = $this->bootstrap_channel();
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Coroutine message channel `async` is consumed in the process that sent the message, so ecotone:run and ConfiguredMessagingSystem::run() cannot start a separate consumer for it. Send commands or publish events to handlers marked #[Asynchronous(\'async\')]; they start automatically in the sending process. Set concurrency with CoroutineMessageChannelBuilder::withCoroutines(), or register a queue channel under `async` to consume it with ecotone:run.');

        $ecotone->runConsoleCommand('ecotone:run', ['consumerName' => 'async', 'finishWhenNoMessages' => true, 'coroutines' => 2]);
    }

    #[DataProvider('delivery_delays')]
    public function test_delay_header_accepts_durations_and_an_absolute_time(mixed $delay): void
    {
        $handler = new class () {
            public array $handled = [];

            #[Asynchronous('async')]
            #[CommandHandler('handle', endpointId: 'handle.endpoint')]
            public function handle(string $payload, #[Reference] EcotoneClockInterface $clock): void
            {
                $this->handled[] = $clock->now()->format('Y-m-d H:i:s.u');
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting([], [$handler], extensionObjects: [CoroutineMessageChannelBuilder::create('async')], licenceKey: LicenceTesting::VALID_LICENCE, initialTime: new DateTimeImmutable('2026-03-01 12:00:00'));
        $ecotone->whenWaiting(static function (DateTimeImmutable $now) use ($ecotone): void {
            $ecotone->changeTimeTo($now);
        });

        CoroutineTesting::runAtOnce(static fn () => $ecotone->sendCommandWithRouting('handle', 'payload', metadata: [MessageHeaders::DELIVERY_DELAY => $delay]));

        $this->assertSame(['2026-03-01 12:00:00.500000'], $handler->handled);
    }

    public static function delivery_delays(): array
    {
        return [[500], [Duration::milliseconds(500)], [new DateTimeImmutable('2026-03-01 12:00:00.500000')]];
    }

    public function test_a_retried_sender_discards_messages_from_its_failed_attempt(): void
    {
        $handler = new class () {
            public int $attempts = 0;
            public array $handled = [];

            #[CommandHandler('send')]
            public function send(string $payload, #[Reference] CommandBus $bus): void
            {
                $this->attempts++;
                $bus->sendWithRouting('handle', $this->attempts);
                if ($this->attempts === 1) {
                    throw new RuntimeException('sender failed');
                }
            }

            #[Asynchronous('async')]
            #[CommandHandler('handle', endpointId: 'handle.endpoint')]
            public function handle(int $payload): void
            {
                $this->handled[] = $payload;
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting([], [$handler], extensionObjects: [CoroutineMessageChannelBuilder::create('async'), InstantRetryConfiguration::createWithDefaults()->withCommandBusRetry(true, 1)], licenceKey: LicenceTesting::VALID_LICENCE);

        CoroutineTesting::runAtOnce(static fn () => $ecotone->sendCommandWithRouting('send', 'payload'));

        $this->assertSame(2, $handler->attempts);
        $this->assertSame([2], $handler->handled);
    }

    public function test_retried_outer_command_discards_messages_from_its_failed_nested_call(): void
    {
        $handler = new class () {
            public int $attempts = 0;
            public int $outerAttempts = 0;
            public array $handled = [];

            #[CommandHandler('outer')]
            public function outer(string $payload, #[Reference] CommandBus $bus): void
            {
                $this->outerAttempts++;
                $bus->sendWithRouting('handle', 0);
                $bus->sendWithRouting('inner', $payload);
            }

            #[CommandHandler('inner')]
            public function inner(string $payload, #[Reference] CommandBus $bus): void
            {
                $this->attempts++;
                $bus->sendWithRouting('handle', $this->attempts);
                if ($this->attempts === 1) {
                    throw new RuntimeException('inner sender failed');
                }
            }

            #[Asynchronous('async')]
            #[CommandHandler('handle', endpointId: 'handle.endpoint')]
            public function handle(int $payload): void
            {
                $this->handled[] = $payload;
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting([], [$handler], extensionObjects: [CoroutineMessageChannelBuilder::create('async'), InstantRetryConfiguration::createWithDefaults()->withCommandBusRetry(true, 1)], licenceKey: LicenceTesting::VALID_LICENCE);

        CoroutineTesting::runAtOnce(static fn () => $ecotone->sendCommandWithRouting('outer', 'payload'));

        $this->assertSame(2, $handler->outerAttempts);
        $this->assertSame(2, $handler->attempts);
        $this->assertSame([0, 2], $handler->handled);
    }

    public function test_failed_console_command_starts_no_handler(): void
    {
        $handler = new class () {
            public array $handled = [];

            #[ConsoleCommand('send-and-fail')]
            public function send(#[Reference] CommandBus $bus): void
            {
                $bus->sendWithRouting('handle', 'from-console');
                throw new RuntimeException('console failed');
            }

            #[Asynchronous('async')]
            #[CommandHandler('handle', endpointId: 'handle.endpoint')]
            public function handle(string $payload): void
            {
                $this->handled[] = $payload;
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting([], [$handler], extensionObjects: [CoroutineMessageChannelBuilder::create('async')], licenceKey: LicenceTesting::VALID_LICENCE);
        $failure = null;

        CoroutineTesting::runAtOnce(static function () use ($ecotone, &$failure): void {
            try {
                $ecotone->runConsoleCommand('send-and-fail', []);
            } catch (RuntimeException $exception) {
                $failure = $exception->getMessage();
            }
        });

        $this->assertSame('console failed', $failure);
        $this->assertSame([], $handler->handled);
    }

    public function test_console_command_holds_messages_until_it_returns(): void
    {
        $handler = new class () {
            public array $handled = [];
            public array $handledBeforeReturn = [];

            #[ConsoleCommand('send.coroutine')]
            public function send(#[Reference] CommandBus $bus): void
            {
                $bus->sendWithRouting('handle', 'payload');
                $nextTick = new Coroutine\Channel();
                Event::defer(static fn () => $nextTick->push(true));
                $nextTick->pop();
                $this->handledBeforeReturn = $this->handled;
            }

            #[Asynchronous('async')]
            #[CommandHandler('handle', endpointId: 'console.held.endpoint')]
            public function handle(string $payload): void
            {
                $this->handled[] = $payload;
            }
        };
        $flow = EcotoneLite::bootstrapFlowTesting([], [$handler], extensionObjects: [CoroutineMessageChannelBuilder::create('async')], licenceKey: LicenceTesting::VALID_LICENCE);

        CoroutineTesting::runAtOnce(static fn () => $flow->runConsoleCommand('send.coroutine'));

        $this->assertSame([], $handler->handledBeforeReturn);
        $this->assertSame(['payload'], $handler->handled);
    }

    public function test_delayed_message_due_in_less_than_a_millisecond_is_handled_without_a_warning(): void
    {
        $clock = new class () implements ClockInterface {
            private int $microseconds = 0;

            public function now(): DateTimeImmutable
            {
                return (new DateTimeImmutable('2026-03-01 12:00:00'))->modify('+' . ++$this->microseconds . ' microseconds');
            }
        };
        $handler = new class () {
            public array $handled = [];

            #[Delayed(milliseconds: 1)]
            #[Asynchronous('async')]
            #[CommandHandler('handle', endpointId: 'handle.endpoint')]
            public function handle(string $payload): void
            {
                $this->handled[] = $payload;
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting([], [$handler, ClockInterface::class => $clock], extensionObjects: [CoroutineMessageChannelBuilder::create('async')], licenceKey: LicenceTesting::VALID_LICENCE);
        $warnings = [];
        set_error_handler(static function (int $level, string $warning) use (&$warnings): bool {
            $warnings[] = $warning;

            return true;
        });

        try {
            CoroutineTesting::runAtOnce(static fn () => $ecotone->sendCommandWithRouting('handle', 'payload'));
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $warnings);
        $this->assertSame(['payload'], $handler->handled);
    }

    public function test_delayed_message_with_hooks_off_does_not_stall_a_sibling_coroutine(): void
    {
        $clock = new class () implements ClockInterface {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('now');
            }
        };
        $handler = new class () {
            public array $handled = [];

            #[Delayed(milliseconds: 400)]
            #[Asynchronous('async')]
            #[CommandHandler('handle', endpointId: 'handle.endpoint')]
            public function handle(string $payload): void
            {
                $this->handled[] = $payload;
            }

            #[Asynchronous('async')]
            #[CommandHandler('sibling', endpointId: 'sibling.endpoint')]
            public function sibling(string $payload): void
            {
                $this->handled[] = $payload;
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting([], [$handler, ClockInterface::class => $clock], extensionObjects: [CoroutineMessageChannelBuilder::create('async')->withCoroutines(1)], licenceKey: LicenceTesting::VALID_LICENCE);

        CoroutineTesting::runAtOnceWithHooks(
            0,
            static function () use ($ecotone): void {
                $ecotone->sendCommandWithRouting('handle', 'payload');
            },
            static function () use ($ecotone): void {
                (new Coroutine\Channel())->pop(0.001);
                $ecotone->sendCommandWithRouting('sibling', 'sibling');
            },
        );

        $this->assertSame(['sibling', 'payload'], $handler->handled);
    }

    public function test_delays_wait_on_the_coroutine_timer_beside_a_sleep_service_of_the_application(): void
    {
        $sleep = new class () implements SleepInterface {
            public array $slept = [];

            public function sleep(Duration $duration): void
            {
                $this->slept[] = $duration->inMilliseconds();
            }
        };
        $handler = new class () {
            public array $handled = [];

            #[Delayed(milliseconds: 20)]
            #[Asynchronous('async')]
            #[CommandHandler('handle', endpointId: 'handle.endpoint')]
            public function handle(string $payload, #[Reference] SleepInterface $sleep): void
            {
                $this->handled[] = [$payload, $sleep];
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting([], [$handler, SleepInterface::class => $sleep], extensionObjects: [CoroutineMessageChannelBuilder::create('async')], licenceKey: LicenceTesting::VALID_LICENCE);

        CoroutineTesting::runAtOnce(static fn () => $ecotone->sendCommandWithRouting('handle', 'payload'));

        $this->assertSame([['payload', $sleep]], $handler->handled);
        $this->assertSame([], $sleep->slept);
    }

    public function test_consumer_stop_handlers_are_not_called_when_the_channel_is_drained(): void
    {
        $handler = new class () {
            public array $handled = [];
            public int $stops = 0;

            #[Asynchronous('async')]
            #[CommandHandler('handle', endpointId: 'handle.endpoint')]
            public function handle(string $payload): void
            {
                $this->handled[] = $payload;
            }

            #[OnConsumerStop]
            public function count_stop(): void
            {
                $this->stops++;
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting([], [$handler], extensionObjects: [CoroutineMessageChannelBuilder::create('async')->withCoroutines(2)], licenceKey: LicenceTesting::VALID_LICENCE);

        CoroutineTesting::runAtOnce(static function () use ($ecotone): void {
            $ecotone->sendCommandWithRouting('handle', 'first');
            $ecotone->sendCommandWithRouting('handle', 'second');
            $ecotone->sendCommandWithRouting('handle', 'third');
        });

        $this->assertSame(['first', 'second', 'third'], $handler->handled);
        $this->assertSame(0, $handler->stops);
    }

    public function test_consumer_stop_handlers_are_still_called_for_a_polling_consumer_beside_the_channel(): void
    {
        $handler = new class () {
            public array $stopped = [];

            #[Asynchronous('async')]
            #[CommandHandler('handle', endpointId: 'handle.endpoint')]
            public function handle(string $payload): void
            {
            }

            #[Asynchronous('queue')]
            #[CommandHandler('queued', endpointId: 'queued.endpoint')]
            public function queued(string $payload): void
            {
            }

            #[OnConsumerStop]
            public function record_stop(string $endpointId): void
            {
                $this->stopped[] = $endpointId;
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting([], [$handler], extensionObjects: [CoroutineMessageChannelBuilder::create('async'), SimpleMessageChannelBuilder::createQueueChannel('queue')], licenceKey: LicenceTesting::VALID_LICENCE);

        CoroutineTesting::runAtOnce(static function () use ($ecotone): void {
            $ecotone->sendCommandWithRouting('handle', 'payload');
            $ecotone->sendCommandWithRouting('queued', 'payload');
        });
        $ecotone->run('queue', ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        $this->assertSame(['queue'], $handler->stopped);
    }

    public function test_a_failed_asynchronous_sender_starts_no_handler(): void
    {
        $handler = new class () {
            public array $handled = [];

            #[Asynchronous('senders')]
            #[CommandHandler('send', endpointId: 'send.endpoint')]
            public function send(string $payload, #[Reference] CommandBus $bus): void
            {
                $bus->sendWithRouting('handle', $payload);
                throw new RuntimeException('sender failed');
            }

            #[Asynchronous('async')]
            #[CommandHandler('handle', endpointId: 'handle.endpoint')]
            public function handle(string $payload): void
            {
                $this->handled[] = $payload;
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting([], [$handler], extensionObjects: [CoroutineMessageChannelBuilder::create('async'), SimpleMessageChannelBuilder::createQueueChannel('senders'), InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false)], licenceKey: LicenceTesting::VALID_LICENCE);
        $ecotone->sendCommandWithRouting('send', 'payload');

        $failure = null;

        CoroutineTesting::runAtOnce(static function () use ($ecotone, &$failure): void {
            try {
                $ecotone->run('senders', ExecutionPollingMetadata::createWithFinishWhenNoMessages());
            } catch (RuntimeException $exception) {
                $failure = $exception->getMessage();
            }
        });

        $this->assertSame('sender failed', $failure);
        $this->assertSame([], $handler->handled);
    }

    public function test_an_aggregate_command_handler_runs_on_the_channel(): void
    {
        $aggregate = new #[Aggregate] class () {
            #[Identifier]
            public string $orderId = '';
            public bool $shipped = false;

            #[CommandHandler('order.place')]
            public static function place(string $orderId): self
            {
                $order = new self();
                $order->orderId = $orderId;

                return $order;
            }

            #[Asynchronous('async')]
            #[CommandHandler('order.ship', endpointId: 'order.ship.endpoint')]
            public function ship(): void
            {
                $this->shipped = true;
            }

            #[QueryHandler('order.is_shipped')]
            public function is_shipped(): bool
            {
                return $this->shipped;
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting([$aggregate::class], extensionObjects: [CoroutineMessageChannelBuilder::create('async')], licenceKey: LicenceTesting::VALID_LICENCE);
        $ecotone->sendCommandWithRouting('order.place', 'order-1');
        $shippedWhenTheSenderReturned = null;

        CoroutineTesting::runAtOnce(static function () use ($ecotone, &$shippedWhenTheSenderReturned): void {
            $ecotone->sendCommandWithRouting('order.ship', metadata: ['aggregate.id' => 'order-1']);
            $shippedWhenTheSenderReturned = $ecotone->sendQueryWithRouting('order.is_shipped', metadata: ['aggregate.id' => 'order-1']);
        });

        $this->assertFalse($shippedWhenTheSenderReturned);
        $this->assertTrue($ecotone->sendQueryWithRouting('order.is_shipped', metadata: ['aggregate.id' => 'order-1']));
    }

    public function test_a_saga_event_handler_runs_on_the_channel(): void
    {
        $saga = new #[Saga] class () {
            #[Identifier]
            public string $orderId = '';
            public array $steps = [];

            #[Asynchronous('async')]
            #[EventHandler(endpointId: 'fulfilment.start.endpoint')]
            public static function start(CoroutineChannelOrderWasPlaced $event): self
            {
                $saga = new self();
                $saga->orderId = $event->orderId;
                $saga->steps[] = 'started';

                return $saga;
            }

            #[Asynchronous('async')]
            #[EventHandler(endpointId: 'fulfilment.pay.endpoint')]
            public function pay(CoroutineChannelOrderWasPaid $event): void
            {
                $this->steps[] = 'paid';
            }

            #[QueryHandler('fulfilment.steps')]
            public function steps(): array
            {
                return $this->steps;
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting([$saga::class], extensionObjects: [CoroutineMessageChannelBuilder::create('async')], licenceKey: LicenceTesting::VALID_LICENCE);

        CoroutineTesting::runAtOnce(static fn () => $ecotone->publishEvent(new CoroutineChannelOrderWasPlaced('order-1')));
        CoroutineTesting::runAtOnce(static fn () => $ecotone->publishEvent(new CoroutineChannelOrderWasPaid('order-1')));

        $this->assertSame(['started', 'paid'], $ecotone->sendQueryWithRouting('fulfilment.steps', metadata: ['aggregate.id' => 'order-1']));
    }

    #[DataProvider('channel_chains')]
    public function test_a_handler_chaining_the_channel_with_another_channel_is_refused_at_bootstrap(object $handler, string $chain): void
    {
        $this->expectException(ConfigurationException::class);
        $class = $handler::class;
        $this->expectExceptionMessage("Coroutine message channel `async` cannot be chained with other channels in #[Asynchronous([{$chain}])] on handler `{$class}::handle` (endpoint `handle.endpoint`): it hands the message over inside the sending process, without the serialisation and acknowledgement a chain of channels relies on. List only `async` in #[Asynchronous], or use channels consumed with ecotone:run for the whole chain.");

        EcotoneLite::bootstrapFlowTesting([], [$handler], extensionObjects: [CoroutineMessageChannelBuilder::create('async'), SimpleMessageChannelBuilder::createQueueChannel('queue')], licenceKey: LicenceTesting::VALID_LICENCE);
    }

    public static function channel_chains(): array
    {
        return [
            [new class () {
                #[Asynchronous(['queue', 'async'])]
                #[CommandHandler('handle', endpointId: 'handle.endpoint')]
                public function handle(string $payload): void
                {
                }
            }, "'queue', 'async'"],
            [new class () {
                #[Asynchronous(['async', 'queue'])]
                #[CommandHandler('handle', endpointId: 'handle.endpoint')]
                public function handle(string $payload): void
                {
                }
            }, "'async', 'queue'"],
        ];
    }

    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function test_a_termination_signal_during_a_handler_reaches_the_handler_the_application_installed(): void
    {
        $handler = new class () {
            public array $handled = [];

            #[Asynchronous('async')]
            #[CommandHandler('handle', endpointId: 'handle.endpoint')]
            public function handle(string $payload): void
            {
                posix_kill(posix_getpid(), SIGTERM);
                $this->handled[] = $payload;
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting([], [$handler], extensionObjects: [CoroutineMessageChannelBuilder::create('async')], licenceKey: LicenceTesting::VALID_LICENCE);
        $signalsReceivedByTheApplication = 0;
        $asyncSignalsBefore = pcntl_async_signals(true);
        $handlerBefore = pcntl_signal_get_handler(SIGTERM);
        pcntl_signal(SIGTERM, static function () use (&$signalsReceivedByTheApplication): void {
            $signalsReceivedByTheApplication++;
        });

        try {
            CoroutineTesting::runAtOnce(static fn () => $ecotone->sendCommandWithRouting('handle', 'payload'));
        } finally {
            pcntl_signal(SIGTERM, $handlerBefore);
            pcntl_async_signals($asyncSignalsBefore);
        }

        $this->assertSame(['payload'], $handler->handled);
        $this->assertSame(1, $signalsReceivedByTheApplication);
    }

    public function test_a_handler_can_send_to_its_own_channel_with_one_coroutine(): void
    {
        $handler = new class () {
            public array $handled = [];

            #[Asynchronous('async')]
            #[CommandHandler('handle', endpointId: 'handle.endpoint')]
            public function handle(int $payload, #[Reference] CommandBus $bus): void
            {
                $this->handled[] = $payload;
                if ($payload < 3) {
                    $bus->sendWithRouting('handle', $payload + 1);
                }
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting([], [$handler], extensionObjects: [CoroutineMessageChannelBuilder::create('async')->withCoroutines(1)], licenceKey: LicenceTesting::VALID_LICENCE);

        CoroutineTesting::runAtOnce(static fn () => $ecotone->sendCommandWithRouting('handle', 1));

        $this->assertSame([1, 2, 3], $handler->handled);
    }

    #[DataProvider('unsupported_polling_limits')]
    public function test_polling_consumer_limits_are_refused_at_bootstrap(PollingMetadata $metadata, string $setting, string $reason): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("Coroutine message channel `async` cannot use PollingMetadata::{$setting}(): {$reason} Remove this setting, or use a channel consumed with ecotone:run.");

        $this->bootstrap_channel([$metadata]);
    }

    public static function unsupported_polling_limits(): array
    {
        $reason = 'it is consumed inside the sending process, so there is no separate consumer process to stop.';
        $schedule = 'it starts each message as soon as its sender succeeds, so there is no polling schedule to set.';
        return [
            [PollingMetadata::create('async')->withTestingSetup(), 'withStopOnError', $reason],
            [PollingMetadata::create('async')->withFinishWhenNoMessages(true), 'withFinishWhenNoMessages', $reason],
            [PollingMetadata::create('async')->withCron('* * * * *'), 'withCron', $schedule],
            [PollingMetadata::create('async')->withCronExpression("'* * * * *'"), 'withCronExpression', $schedule],
            [PollingMetadata::create('async')->withFixedRateInMilliseconds(5), 'withFixedRateInMilliseconds', $schedule],
            [PollingMetadata::create('async')->withFixedRateExpression('5'), 'withFixedRateExpression', $schedule],
            [PollingMetadata::create('async')->withInitialDelayInMilliseconds(5), 'withInitialDelayInMilliseconds', $schedule],
            [PollingMetadata::create('async')->withMaxMessagePerPoll(5), 'withMaxMessagePerPoll', $schedule],
            [PollingMetadata::create('async')->withTriggerReferenceName('trigger'), 'withTriggerReferenceName', $schedule],
            [PollingMetadata::create('async')->withTaskExecutorName('executor'), 'withTaskExecutorName', $schedule],
            [PollingMetadata::create('async')->withEnabledErrorChannel(false), 'withEnabledErrorChannel', 'a failed message goes to its error channel, or is logged and dropped: inside the sending process there is no consumer to stop with the failure.'],
            [PollingMetadata::create('async')->withConnectionRetryTemplate(RetryTemplateBuilder::fixedBackOff(1)), 'withConnectionRetryTemplate', 'it keeps its messages in the memory of the sending process, so there is no connection to retry.'],
            [PollingMetadata::create('async')->withPolledChannelName('other'), 'withPolledChannelName', 'it consumes only its own channel, in the process that sent the message.'],
            [PollingMetadata::create('async')->withStopOnError(true), 'withStopOnError', $reason],
            [PollingMetadata::create('async')->withHandledMessageLimit(1), 'withHandledMessageLimit', $reason],
            [PollingMetadata::create('async')->withExecutionTimeLimitInMilliseconds(1), 'withExecutionTimeLimitInMilliseconds', $reason],
            [PollingMetadata::create('async')->withExecutionAmountLimit(1), 'withExecutionAmountLimit', $reason],
            [PollingMetadata::create('async')->withMemoryLimitInMegaBytes(64), 'withMemoryLimitInMegaBytes', "it is consumed inside the sending process, which is not Ecotone's to stop when memory runs high."],
        ];
    }

    #[DataProvider('unsupported_delivery_attributes')]
    public function test_unsupported_delivery_attributes_are_refused_at_bootstrap(object $handler, string $attribute, string $reason): void
    {
        $this->expectException(ConfigurationException::class);
        $class = $handler::class;
        $this->expectExceptionMessage("Coroutine message channel `async` does not support #[{$attribute}] on handler `{$class}::handle` (endpoint `handle.endpoint`): {$reason} Remove #[{$attribute}] from this handler, or use a channel that honours it.");

        EcotoneLite::bootstrapFlowTesting([], [$handler], extensionObjects: [CoroutineMessageChannelBuilder::create('async')], licenceKey: LicenceTesting::VALID_LICENCE);
    }

    public static function unsupported_delivery_attributes(): array
    {
        return [
            [new class () {
                #[Priority(2)]
                #[Asynchronous('async')]
                #[CommandHandler('handle', endpointId: 'handle.endpoint')]
                public function handle(string $payload): void
                {
                }
            }, 'Priority', 'messages are handled in FIFO order.'],
            [new class () {
                #[TimeToLive(100)]
                #[Asynchronous('async')]
                #[CommandHandler('handle', endpointId: 'handle.endpoint')]
                public function handle(string $payload): void
                {
                }
            }, 'TimeToLive', 'messages in this queue do not expire.'],
            [new #[Priority(2)] class {
                #[Asynchronous('async')]
                #[CommandHandler('handle', endpointId: 'handle.endpoint')]
                public function handle(string $payload): void
                {
                }
            }, 'Priority', 'messages are handled in FIFO order.'],
            [new #[TimeToLive(100)] class {
                #[Asynchronous('async')]
                #[CommandHandler('handle', endpointId: 'handle.endpoint')]
                public function handle(string $payload): void
                {
                }
            }, 'TimeToLive', 'messages in this queue do not expire.'],
        ];
    }

    public function test_failure_logs_keep_each_coroutines_endpoint_and_headers(): void
    {
        $logger = new class () extends AbstractLogger {
            public array $records = [];
            private int $failures = 0;
            private Coroutine\Channel $release;

            public function __construct()
            {
                $this->release = new Coroutine\Channel();
            }

            public function log($level, Stringable|string $message, array $context = []): void
            {
                if (isset($context['endpoint_id'])) {
                    $this->records[$context['headers']['tenant']] = $context['endpoint_id'];
                } elseif ($level === 'critical' && isset($context['exception'])) {
                    $this->failures++;
                    if ($this->failures === 2) {
                        $this->release->close();
                    } else {
                        $this->release->pop();
                    }
                }
            }
        };
        $handler = new class () {
            #[Asynchronous('async')]
            #[CommandHandler('first', endpointId: 'first.endpoint')]
            public function first(string $payload): void
            {
                throw new RuntimeException('first failed');
            }

            #[Asynchronous('async')]
            #[CommandHandler('second', endpointId: 'second.endpoint')]
            public function second(string $payload): void
            {
                throw new RuntimeException('second failed');
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting([], [$handler, 'logger' => $logger], extensionObjects: [CoroutineMessageChannelBuilder::create('async')->withCoroutines(2), InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false)], licenceKey: LicenceTesting::VALID_LICENCE);

        CoroutineTesting::runAtOnce(static function () use ($ecotone): void {
            $ecotone->sendCommandWithRouting('first', 'payload', metadata: ['tenant' => 'a']);
            $ecotone->sendCommandWithRouting('second', 'payload', metadata: ['tenant' => 'b']);
        });

        ksort($logger->records);
        $this->assertSame(['a' => 'first.endpoint', 'b' => 'second.endpoint'], $logger->records);
    }

    public function test_parts_of_a_split_command_handler_run_on_the_channel(): void
    {
        $handler = new class () {
            public array $handled = [];

            #[CommandHandler('import', endpointId: 'import.handler', outputChannelName: 'import.row')]
            #[Splitter]
            public function import(array $rows): array
            {
                return $rows;
            }

            #[Asynchronous('rows')]
            #[InternalHandler('import.row', endpointId: 'import.row.handler')]
            public function row(int $row): void
            {
                $this->handled[$row] = Coroutine::getCid();
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting([], [$handler], extensionObjects: [CoroutineMessageChannelBuilder::create('rows')], enabledModules: [], licenceKey: LicenceTesting::VALID_LICENCE);
        $sender = 0;

        CoroutineTesting::runAtOnce(static function () use ($ecotone, &$sender): void {
            $sender = Coroutine::getCid();
            $ecotone->sendCommandWithRouting('import', [1, 2, 3]);
        });

        $this->assertSame([1, 2, 3], array_keys($handler->handled));
        $this->assertNotContains($sender, $handler->handled);
    }

    public function test_a_split_command_handler_on_the_channel_sends_its_parts_to_handlers_on_the_channel(): void
    {
        $handler = new class () {
            public array $handled = [];
            public int $splitter = 0;

            #[Asynchronous('imports')]
            #[CommandHandler('import', endpointId: 'import.handler', outputChannelName: 'import.row')]
            #[Splitter]
            public function import(array $rows): array
            {
                $this->splitter = Coroutine::getCid();

                return $rows;
            }

            #[Asynchronous('imports')]
            #[InternalHandler('import.row', endpointId: 'import.row.handler')]
            public function row(int $row): void
            {
                $this->handled[$row] = Coroutine::getCid();
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting([], [$handler], extensionObjects: [CoroutineMessageChannelBuilder::create('imports')], enabledModules: [], licenceKey: LicenceTesting::VALID_LICENCE);
        $sender = 0;

        CoroutineTesting::runAtOnce(static function () use ($ecotone, &$sender): void {
            $sender = Coroutine::getCid();
            $ecotone->sendCommandWithRouting('import', [1, 2, 3]);
        });

        $this->assertNotSame($sender, $handler->splitter);
        $this->assertSame([1, 2, 3], array_keys($handler->handled));
        $this->assertNotContains($sender, $handler->handled);
    }

    public function test_parts_of_a_split_command_handler_handled_on_the_channel_are_gathered_on_the_channel(): void
    {
        $handler = new class () {
            public array $gathered = [];
            public array $workers = [];

            #[CommandHandler('import', endpointId: 'import.handler', outputChannelName: 'import.row')]
            #[Splitter(gatherChannelName: 'import.summary')]
            public function import(array $rows): array
            {
                return $rows;
            }

            #[Asynchronous('rows')]
            #[InternalHandler('import.row', endpointId: 'import.row.handler')]
            public function row(int $row): int
            {
                $this->workers[] = Coroutine::getCid();
                usleep(1000 * (4 - $row % 4));

                return $row * 2;
            }

            #[Gathered]
            #[Asynchronous('summaries')]
            #[InternalHandler('import.summary', endpointId: 'import.summary.handler')]
            public function summarize(GatheredParts $parts): void
            {
                $this->gathered[] = [$parts->results, $parts->originalPayload, $parts->releaseReason, Coroutine::getCid()];
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting([], [$handler], extensionObjects: [CoroutineMessageChannelBuilder::create('rows'), CoroutineMessageChannelBuilder::create('summaries')], enabledModules: [], licenceKey: LicenceTesting::VALID_LICENCE);

        CoroutineTesting::runAtOnce(static fn () => $ecotone->sendCommandWithRouting('import', [1, 2, 3]));

        $this->assertCount(1, $handler->gathered);
        $this->assertSame([1 => 2, 2 => 4, 3 => 6], $handler->gathered[0][0]);
        $this->assertSame([1, 2, 3], $handler->gathered[0][1]);
        $this->assertSame(GatheredReleaseReason::ALL_PARTS_ACCOUNTED_FOR, $handler->gathered[0][2]);
        $this->assertNotContains($handler->gathered[0][3], $handler->workers);
    }

    public function test_a_split_command_handler_and_its_parts_share_the_channel_and_are_gathered_on_a_channel_of_one_coroutine(): void
    {
        $handler = new class () {
            public array $gathered = [];

            #[Asynchronous('imports')]
            #[CommandHandler('import', endpointId: 'import.handler', outputChannelName: 'import.row')]
            #[Splitter(gatherChannelName: 'import.summary')]
            public function import(array $rows): array
            {
                return $rows;
            }

            #[Asynchronous('imports')]
            #[InternalHandler('import.row', endpointId: 'import.row.handler')]
            public function row(int $row): int
            {
                usleep(1000 * (4 - $row % 4));

                return $row * 2;
            }

            #[Gathered]
            #[Asynchronous('summaries')]
            #[InternalHandler('import.summary', endpointId: 'import.summary.handler')]
            public function summarize(GatheredParts $parts): void
            {
                $this->gathered[] = $parts->results;
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting([], [$handler], extensionObjects: [CoroutineMessageChannelBuilder::create('imports'), CoroutineMessageChannelBuilder::create('summaries')->withCoroutines(1)], enabledModules: [], licenceKey: LicenceTesting::VALID_LICENCE);

        CoroutineTesting::runAtOnce(
            static fn () => $ecotone->sendCommandWithRouting('import', [1, 2, 3]),
            static fn () => $ecotone->sendCommandWithRouting('import', [10, 20]),
        );

        $this->assertEqualsCanonicalizing([[1 => 2, 2 => 4, 3 => 6], [1 => 20, 2 => 40]], $handler->gathered);
    }

    public function test_an_empty_split_of_a_command_handler_is_gathered_on_the_channel(): void
    {
        $handler = new class () {
            public array $gathered = [];

            #[CommandHandler('import', endpointId: 'import.handler', outputChannelName: 'import.row')]
            #[Splitter(gatherChannelName: 'import.summary')]
            public function import(array $rows): array
            {
                return $rows;
            }

            #[Asynchronous('rows')]
            #[InternalHandler('import.row', endpointId: 'import.row.handler')]
            public function row(int $row): int
            {
                return $row;
            }

            #[Gathered]
            #[Asynchronous('summaries')]
            #[InternalHandler('import.summary', endpointId: 'import.summary.handler')]
            public function summarize(GatheredParts $parts): void
            {
                $this->gathered[] = [$parts->size, $parts->results];
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting([], [$handler], extensionObjects: [CoroutineMessageChannelBuilder::create('rows'), CoroutineMessageChannelBuilder::create('summaries')], enabledModules: [], licenceKey: LicenceTesting::VALID_LICENCE);

        CoroutineTesting::runAtOnce(static fn () => $ecotone->sendCommandWithRouting('import', []));

        $this->assertSame([[0, []]], $handler->gathered);
    }

    public function test_a_gathering_timeout_on_the_channel_releases_the_parts_that_arrived(): void
    {
        $handler = new class () {
            public array $gathered = [];

            #[CommandHandler('import', endpointId: 'import.handler', outputChannelName: 'import.row')]
            #[Splitter(gatherChannelName: 'import.summary')]
            public function import(array $rows): array
            {
                return $rows;
            }

            #[Asynchronous('rows')]
            #[InternalHandler('import.row', endpointId: 'import.row.handler')]
            public function row(int $row): int
            {
                if ($row === 2) {
                    throw new RuntimeException('row failed');
                }

                return $row * 2;
            }

            #[Gathered(timeout: new Duration(milliseconds: 20))]
            #[Asynchronous('summaries')]
            #[InternalHandler('import.summary', endpointId: 'import.summary.handler')]
            public function summarize(GatheredParts $parts): void
            {
                $this->gathered[] = [$parts->results, $parts->missingPartNumbers, $parts->releaseReason];
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting([], [$handler], extensionObjects: [CoroutineMessageChannelBuilder::create('rows'), CoroutineMessageChannelBuilder::create('summaries'), InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false)], enabledModules: [], licenceKey: LicenceTesting::VALID_LICENCE);

        CoroutineTesting::runAtOnce(static fn () => $ecotone->sendCommandWithRouting('import', [1, 2, 3]));

        $this->assertSame([[[1 => 2, 3 => 6], [2], GatheredReleaseReason::TIMEOUT]], $handler->gathered);
    }

    public function test_the_channel_of_a_collected_handler_handles_one_message_at_a_time_when_no_coroutines_are_stated(): void
    {
        $handler = new class () {
            public int $active = 0;
            public int $mostAtOnce = 0;

            #[Asynchronous('search')]
            #[CommandHandler('archive', endpointId: 'archive.handler')]
            public function archive(): void
            {
                $this->active++;
                $this->mostAtOnce = max($this->mostAtOnce, $this->active);
                usleep(1000);
                $this->active--;
            }

            #[Collected(maxSize: 2)]
            #[Asynchronous('search')]
            #[CommandHandler('product.index', endpointId: 'product.index.handler')]
            public function index(CollectedMessages $changes): void
            {
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting([], [$handler], extensionObjects: [CoroutineMessageChannelBuilder::create('search')], enabledModules: [], licenceKey: LicenceTesting::VALID_LICENCE);

        CoroutineTesting::runAtOnce(...array_fill(0, 3, static fn () => $ecotone->sendCommandWithRouting('archive')));

        $this->assertSame(1, $handler->mostAtOnce);
    }

    public function test_a_projection_run_consuming_its_batch_reports_on_a_channel_of_several_coroutines_is_refused_at_bootstrap(): void
    {
        $projection = new #[Projection('orders'), FromStream('orders_stream'), ProjectionRebuild(asyncChannelName: 'rebuild_channel')] class () {
            #[EventHandler('order.placed')]
            public function whenPlaced(array $event): void
            {
            }
        };

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            "Projection 'orders' consumes the batch reports of its rebuild on coroutine message channel `rebuild_channel` (batchCompletionChannelName of #[ProjectionRebuild], or its asyncChannelName when that is left out), which handles up to 10 messages at once: "
            . "batches finishing together would be recorded in their run at the same time, all but one would lose the run's optimistic write on every attempt until the instant retries run out, and a run missing those batches never completes. "
            . "Register a channel of their own for the batch reports that handles one message at a time, CoroutineMessageChannelBuilder::create('rebuild_channel_batch_reports')->withCoroutines(1), and name it as batchCompletionChannelName of #[ProjectionRebuild]; "
            . 'or name a channel consumed with ecotone:run without its coroutines option there.'
        );

        EcotoneLite::bootstrapFlowTesting([], [$projection], extensionObjects: [CoroutineMessageChannelBuilder::create('rebuild_channel')], enabledModules: [], licenceKey: LicenceTesting::VALID_LICENCE);
    }

    public function test_a_projection_run_consuming_its_batch_reports_on_a_channel_of_one_coroutine_boots_beside_batches_executed_on_several(): void
    {
        $projection = new #[Projection('orders'), FromStream('orders_stream'), ProjectionRebuild(asyncChannelName: 'rebuild_channel', batchCompletionChannelName: 'rebuild_channel_batch_reports')] class () {
            #[EventHandler('order.placed')]
            public function whenPlaced(array $event): void
            {
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [],
            [$projection],
            extensionObjects: [CoroutineMessageChannelBuilder::create('rebuild_channel')->withCoroutines(4), CoroutineMessageChannelBuilder::create('rebuild_channel_batch_reports')->withCoroutines(1)],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $this->assertSame([], $ecotone->getGateway(GatheredGroups::class)->listOpenGroups('orders.rebuild'));
    }

    #[DataProvider('channels_registered_with_several_coroutines')]
    public function test_a_gathered_handler_on_a_channel_of_several_coroutines_is_refused_at_bootstrap(CoroutineMessageChannelBuilder $channel, int $coroutines): void
    {
        $handler = new class () {
            #[CommandHandler('import', endpointId: 'import.handler', outputChannelName: 'import.row')]
            #[Splitter(gatherChannelName: 'import.summary')]
            public function import(array $rows): array
            {
                return $rows;
            }

            #[InternalHandler('import.row', endpointId: 'import.row.handler')]
            public function row(int $row): int
            {
                return $row;
            }

            #[Gathered]
            #[Asynchronous('summaries')]
            #[InternalHandler('import.summary', endpointId: 'import.summary.handler')]
            public function summarize(GatheredParts $parts): void
            {
            }
        };

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("Coroutine message channel `summaries` is registered with withCoroutines({$coroutines}), which #[Gathered] handler `" . $handler::class . "::summarize` (endpoint `import.summary.handler`) cannot be consumed with: parts finishing together would be recorded in their group at the same time, all but one would lose the group's optimistic write on every attempt until the instant retries run out, and a group missing those parts is never gathered. Leave withCoroutines() out of CoroutineMessageChannelBuilder::create('summaries'), the channel of a gathered handler then handles one message at a time, and keep the handlers doing the work of the parts on another channel; or move the gathered handler to a channel consumed with ecotone:run without its coroutines option.");

        EcotoneLite::bootstrapFlowTesting([], [$handler], extensionObjects: [$channel], enabledModules: [], licenceKey: LicenceTesting::VALID_LICENCE);
    }

    public static function channels_registered_with_several_coroutines(): array
    {
        return [
            'two coroutines' => [CoroutineMessageChannelBuilder::create('summaries')->withCoroutines(2), 2],
            'as many as other channels run by default' => [CoroutineMessageChannelBuilder::create('summaries')->withCoroutines(CoroutineMessageChannelBuilder::DEFAULT_COROUTINES), CoroutineMessageChannelBuilder::DEFAULT_COROUTINES],
        ];
    }

    public function test_messages_sent_at_once_are_collected_on_the_channel_into_one_batch(): void
    {
        $handler = new class () {
            public array $batches = [];

            #[Collected(maxSize: 3)]
            #[Asynchronous('search')]
            #[CommandHandler('product.index', endpointId: 'product.index.handler')]
            public function index(CollectedMessages $changes): void
            {
                $this->batches[] = [array_column($changes->messages, 'payload'), $changes->releaseReason, Coroutine::getCid()];
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting([], [$handler], extensionObjects: [CoroutineMessageChannelBuilder::create('search')], enabledModules: [], licenceKey: LicenceTesting::VALID_LICENCE);
        $senders = [];

        CoroutineTesting::runAtOnce(...array_map(static fn (int $product) => static function () use ($ecotone, $product, &$senders): void {
            $senders[] = Coroutine::getCid();
            $ecotone->sendCommandWithRouting('product.index', $product);
        }, [1, 2, 3, 4]));

        $this->assertCount(1, $handler->batches);
        $this->assertSame([1, 2, 3], $handler->batches[0][0]);
        $this->assertSame(CollectedReleaseReason::MAX_SIZE_REACHED, $handler->batches[0][1]);
        $this->assertNotContains($handler->batches[0][2], $senders);
    }

    public function test_messages_collected_on_the_channel_are_released_when_the_waiting_limit_passes(): void
    {
        $handler = new class () {
            public array $batches = [];

            #[Collected(maxWait: new Duration(milliseconds: 20))]
            #[Asynchronous('search')]
            #[CommandHandler('product.index', endpointId: 'product.index.handler')]
            public function index(CollectedMessages $changes): void
            {
                $this->batches[] = [array_column($changes->messages, 'payload'), $changes->releaseReason];
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting([], [$handler], extensionObjects: [CoroutineMessageChannelBuilder::create('search')], enabledModules: [], licenceKey: LicenceTesting::VALID_LICENCE, initialTime: new DateTimeImmutable('2026-03-01 12:00:00'));
        $ecotone->whenWaiting(static function (DateTimeImmutable $now) use ($ecotone): void {
            $ecotone->changeTimeTo($now);
        });

        CoroutineTesting::runAtOnce(static function () use ($ecotone): void {
            $ecotone->sendCommandWithRouting('product.index', 1);
            $ecotone->sendCommandWithRouting('product.index', 2);
        });

        $this->assertSame([[[1, 2], CollectedReleaseReason::MAX_WAIT_REACHED]], $handler->batches);
    }

    public function test_messages_collected_on_the_channel_are_batched_by_their_key(): void
    {
        $handler = new class () {
            public array $batches = [];

            #[Collected(maxSize: 2, groupBy: "headers['tenant']")]
            #[Asynchronous('search')]
            #[CommandHandler('product.index', endpointId: 'product.index.handler')]
            public function index(CollectedMessages $changes): void
            {
                $this->batches[$changes->groupKey] = array_column($changes->messages, 'payload');
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting([], [$handler], extensionObjects: [CoroutineMessageChannelBuilder::create('search')], enabledModules: [], licenceKey: LicenceTesting::VALID_LICENCE);

        CoroutineTesting::runAtOnce(
            static fn () => $ecotone->sendCommandWithRouting('product.index', 1, metadata: ['tenant' => 'a']),
            static fn () => $ecotone->sendCommandWithRouting('product.index', 2, metadata: ['tenant' => 'b']),
            static fn () => $ecotone->sendCommandWithRouting('product.index', 3, metadata: ['tenant' => 'a']),
            static fn () => $ecotone->sendCommandWithRouting('product.index', 4, metadata: ['tenant' => 'b']),
        );

        $this->assertSame(['a' => [1, 3], 'b' => [2, 4]], $handler->batches);
    }

    public function test_a_collected_handler_on_a_channel_of_several_coroutines_is_refused_at_bootstrap(): void
    {
        $handler = new class () {
            #[Collected(maxSize: 3)]
            #[Asynchronous('search')]
            #[CommandHandler('product.index', endpointId: 'product.index.handler')]
            public function index(CollectedMessages $changes): void
            {
            }
        };

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Coroutine message channel `search` is registered with withCoroutines(3), which #[Collected] handler `' . $handler::class . "::index` (endpoint `product.index.handler`) cannot be consumed with: messages arriving together would be recorded in their batch at the same time, all but one would lose the batch's optimistic write on every attempt until the instant retries run out, and those messages are missing from the batch. Leave withCoroutines() out of CoroutineMessageChannelBuilder::create('search'), the channel of a collected handler then handles one message at a time, and keep handlers that should run at once on another channel; or move the collected handler to a channel consumed with ecotone:run without its coroutines option.");

        EcotoneLite::bootstrapFlowTesting([], [$handler], extensionObjects: [CoroutineMessageChannelBuilder::create('search')->withCoroutines(3)], enabledModules: [], licenceKey: LicenceTesting::VALID_LICENCE);
    }

    public function test_a_sender_on_a_fiber_outside_a_coroutine_is_refused(): void
    {
        $ecotone = $this->bootstrap_channel();
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Coroutine message channel `async` cannot send outside a Swoole or OpenSwoole coroutine. This can happen in a console command, a consumer started without --coroutines, or an HTTP server with enable_coroutine disabled. For HTTP requests set enable_coroutine=true on the server; for a sending consumer run ecotone:run <source-channel> --coroutines=N; for a console command run the command inside a Swoole or OpenSwoole coroutine. In tests, limit the #[ServiceContext] method returning CoroutineMessageChannelBuilder to the environments that run on Swoole or OpenSwoole, for example #[Environment([\'prod\', \'dev\'])], register SimpleMessageChannelBuilder::createQueueChannel(\'async\') in the test environment and call the flow test support\'s run(\'async\'). An uncaught send failure rolls back the sender\'s Ecotone transaction.');

        (new Fiber(static fn () => $ecotone->sendCommandWithRouting('handle', 'payload')))->start();
    }

    public static function concurrency_limits(): array
    {
        return [[2, 8], [CoroutineMessageChannelBuilder::DEFAULT_COROUTINES, CoroutineMessageChannelBuilder::DEFAULT_COROUTINES + 5]];
    }

    private function bootstrap_channel(array $extensions = []): FlowTestSupport
    {
        $handler = new class () {
            #[Asynchronous('async')]
            #[CommandHandler('handle', endpointId: 'handle.endpoint')]
            public function handle(string $payload): void
            {
            }
        };
        return EcotoneLite::bootstrapFlowTesting([], [$handler], extensionObjects: [CoroutineMessageChannelBuilder::create('async'), ...$extensions], licenceKey: LicenceTesting::VALID_LICENCE);
    }
}

final class CoroutineChannelOrderWasPlaced
{
    public function __construct(public readonly string $orderId)
    {
    }
}

final class CoroutineChannelOrderWasPaid
{
    public function __construct(public readonly string $orderId)
    {
    }
}

final class CoroutineChannelCommand
{
    public function __construct(public readonly Closure $callback)
    {
    }
}

interface CoroutineChannelGateway
{
    #[MessageGateway('gateway.input')]
    public function dispatch(string $payload): void;
}
