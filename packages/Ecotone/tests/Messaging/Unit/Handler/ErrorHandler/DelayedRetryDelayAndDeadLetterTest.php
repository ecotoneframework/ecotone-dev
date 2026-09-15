<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Handler\ErrorHandler;

use Ecotone\Api\Asynchronous;
use Ecotone\Api\CommandHandler;
use Ecotone\Api\DelayedRetry;
use Ecotone\Api\ExecutionPollingMetadata;
use Ecotone\Api\ServiceConfiguration;
use Ecotone\Api\SimpleMessageChannelBuilder;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Handler\MessageHandlingException;
use Ecotone\Messaging\Handler\Recoverability\ErrorContext;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\PollableChannel;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * licence Apache-2.0
 *
 * @internal
 */
final class DelayedRetryDelayAndDeadLetterTest extends TestCase
{
    public function test_delay_is_the_initial_delay_after_the_first_failure(): void
    {
        $ecotone = $this->bootstrapWithGrowingDelay();

        $ecotone->sendCommandWithRoutingKey(GrowingDelayHandler::ROUTING_KEY, 'payload');
        $ecotone->run(GrowingDelayHandler::ASYNC_CHANNEL, ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 1, failAtError: false));

        /** @var PollableChannel $channel */
        $channel = $ecotone->getMessageChannel(GrowingDelayHandler::ASYNC_CHANNEL);
        $requeuedMessage = $channel->receive();

        $this->assertNotNull($requeuedMessage);
        $this->assertSame(10, $requeuedMessage->getHeaders()->get(MessageHeaders::DELIVERY_DELAY));
    }

    public function test_delay_grows_by_the_multiplier_on_each_subsequent_failure(): void
    {
        $ecotone = $this->bootstrapWithGrowingDelay();

        $ecotone->sendCommandWithRoutingKey(GrowingDelayHandler::ROUTING_KEY, 'payload');
        $ecotone->run(GrowingDelayHandler::ASYNC_CHANNEL, ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 1, failAtError: false));
        $ecotone->run(GrowingDelayHandler::ASYNC_CHANNEL, ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 1, failAtError: false));

        /** @var PollableChannel $channel */
        $channel = $ecotone->getMessageChannel(GrowingDelayHandler::ASYNC_CHANNEL);
        $requeuedMessage = $channel->receive();

        $this->assertNotNull($requeuedMessage);
        $this->assertSame(20, $requeuedMessage->getHeaders()->get(MessageHeaders::DELIVERY_DELAY));
    }

    public function test_dead_letter_message_carries_full_exception_context_headers(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [DeadLetterRoutingHandler::class],
            [new DeadLetterRoutingHandler()],
            ServiceConfiguration::createWithDefaults()
                ->withModulePackages([])
                ->withExtensionObjects([
                    \Ecotone\Api\InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false),
                    SimpleMessageChannelBuilder::createQueueChannel(DeadLetterRoutingHandler::ASYNC_CHANNEL, delayable: false),
                    SimpleMessageChannelBuilder::createQueueChannel(DeadLetterRoutingHandler::DEAD_LETTER_CHANNEL, delayable: false),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotone->sendCommandWithRoutingKey(DeadLetterRoutingHandler::ROUTING_KEY, 'payload');
        $ecotone->run(DeadLetterRoutingHandler::ASYNC_CHANNEL, ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 1, failAtError: false));
        $ecotone->run(DeadLetterRoutingHandler::ASYNC_CHANNEL, ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 1, failAtError: false));

        /** @var PollableChannel $deadLetterChannel */
        $deadLetterChannel = $ecotone->getMessageChannel(DeadLetterRoutingHandler::DEAD_LETTER_CHANNEL);
        $deadLetterMessage = $deadLetterChannel->receive();

        $this->assertNotNull($deadLetterMessage);
        $headers = $deadLetterMessage->getHeaders();
        $this->assertSame(DeadLetterRoutingHandler::FAILURE_MESSAGE, $headers->get(ErrorContext::EXCEPTION_MESSAGE));
        $this->assertNotEmpty($headers->get(ErrorContext::EXCEPTION_STACKTRACE));
        $this->assertNotEmpty($headers->get(ErrorContext::EXCEPTION_CLASS));
        $this->assertNotEmpty($headers->get(ErrorContext::EXCEPTION_FILE));
        $this->assertIsInt($headers->get(ErrorContext::EXCEPTION_LINE));
        $this->assertIsInt($headers->get(ErrorContext::EXCEPTION_CODE));
    }

    public function test_throws_after_exhausting_retries_when_no_dead_letter_channel_is_configured(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [NoDeadLetterHandler::class],
            [new NoDeadLetterHandler()],
            ServiceConfiguration::createWithDefaults()
                ->withModulePackages([])
                ->withExtensionObjects([
                    \Ecotone\Api\InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false),
                    SimpleMessageChannelBuilder::createQueueChannel(NoDeadLetterHandler::ASYNC_CHANNEL, delayable: false),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotone->sendCommandWithRoutingKey(NoDeadLetterHandler::ROUTING_KEY, 'payload');
        $ecotone->run(NoDeadLetterHandler::ASYNC_CHANNEL, ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 1, failAtError: false));
        $ecotone->run(NoDeadLetterHandler::ASYNC_CHANNEL, ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 1, failAtError: false));

        $this->expectException(MessageHandlingException::class);
        $this->expectExceptionMessage('Message handling failed after 2 failed deliveries (1 initial + 1 retry)');

        $ecotone->run(NoDeadLetterHandler::ASYNC_CHANNEL, ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 1, failAtError: false));
    }

    private function bootstrapWithGrowingDelay()
    {
        return EcotoneLite::bootstrapFlowTesting(
            [GrowingDelayHandler::class],
            [new GrowingDelayHandler()],
            ServiceConfiguration::createWithDefaults()
                ->withModulePackages([])
                ->withExtensionObjects([
                    \Ecotone\Api\InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false),
                    SimpleMessageChannelBuilder::createQueueChannel(GrowingDelayHandler::ASYNC_CHANNEL, delayable: false),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }
}

/**
 * licence Enterprise
 *
 * @internal
 */
final class GrowingDelayHandler
{
    public const ASYNC_CHANNEL = 'growingDelayAsync';
    public const ROUTING_KEY = 'growingDelay.fail';

    #[Asynchronous(self::ASYNC_CHANNEL, asynchronousExecution: [
        new DelayedRetry(initialDelayMs: 10, multiplier: 2, maxRetries: 5),
    ])]
    #[CommandHandler(self::ROUTING_KEY, 'growingDelayHandler')]
    public function handle(string $payload): void
    {
        throw new RuntimeException('always-fails');
    }
}

/**
 * licence Enterprise
 *
 * @internal
 */
final class DeadLetterRoutingHandler
{
    public const ASYNC_CHANNEL = 'deadLetterContextAsync';
    public const DEAD_LETTER_CHANNEL = 'deadLetterContextDeadLetter';
    public const ROUTING_KEY = 'deadLetterContext.fail';
    public const FAILURE_MESSAGE = 'always-fails-with-context';

    #[Asynchronous(self::ASYNC_CHANNEL, asynchronousExecution: [
        new DelayedRetry(initialDelayMs: 1, multiplier: 1, maxRetries: 1, deadLetterChannel: self::DEAD_LETTER_CHANNEL),
    ])]
    #[CommandHandler(self::ROUTING_KEY, 'deadLetterContextHandler')]
    public function handle(string $payload): void
    {
        throw new RuntimeException(self::FAILURE_MESSAGE);
    }
}

/**
 * licence Enterprise
 *
 * @internal
 */
final class NoDeadLetterHandler
{
    public const ASYNC_CHANNEL = 'noDeadLetterAsync';
    public const ROUTING_KEY = 'noDeadLetter.fail';

    #[Asynchronous(self::ASYNC_CHANNEL, asynchronousExecution: [
        new DelayedRetry(initialDelayMs: 1, multiplier: 1, maxRetries: 1),
    ])]
    #[CommandHandler(self::ROUTING_KEY, 'noDeadLetterHandler')]
    public function handle(string $payload): void
    {
        throw new RuntimeException('always-fails');
    }
}
