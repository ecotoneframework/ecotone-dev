<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Channel\Coroutine;

use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\PollingMetadata;
use Ecotone\Api\Messaging\Message;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Api\Messaging\MessagePoller;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Endpoint\EndpointRunner;
use Ecotone\Messaging\Endpoint\PollingConsumer\AsyncHandlerAnnotationRegistry;
use Ecotone\Messaging\Endpoint\PollingConsumer\InterceptedConsumerRunner;
use Ecotone\Messaging\Handler\Recoverability\DeadLetterChannels;
use Ecotone\Messaging\Support\MessageBuilder;

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 */
final class CoroutineMessageChannelConsumer implements EndpointRunner
{
    public const SERVICE_ID_PREFIX = 'ecotone.coroutine_message_channel.consumer.';
    public const REPLAY_CHANNEL_PREFIX = 'ecotone.coroutine_message_channel.replay.';
    public const REPLAY_HANDLER_ANNOTATIONS = 'ecotone.coroutine_message_channel.replay_handler_annotations';

    public function __construct(
        private string $channelName,
        private InterceptedConsumerRunner $runner,
        private MessagePoller $waitingMessages,
        private MessageBuilder $messageBuilder,
        private PollingMetadata $pollingMetadata,
        private AsyncHandlerAnnotationRegistry $handlerAnnotations,
        private PollingMetadata $replayPollingMetadata,
        private AsyncHandlerAnnotationRegistry $replayHandlerAnnotations,
        private CoroutineMessageChannelFailureLogger $failureLogger,
    ) {
    }

    public function runEndpointWithExecutionPollingMetadata(?ExecutionPollingMetadata $executionPollingMetadata = null): void
    {
        throw ConfigurationException::create("Coroutine message channel `{$this->channelName}` is consumed in the process that sent the message, so ecotone:run and ConfiguredMessagingSystem::run() cannot start a separate consumer for it. Send commands or publish events to handlers marked #[Asynchronous('{$this->channelName}')]; they start automatically in the sending process. Set concurrency with CoroutineMessageChannelBuilder::withCoroutines(), or register a queue channel under `{$this->channelName}` to consume it with ecotone:run.");
    }

    public static function goingOnAfterAFailure(PollingMetadata $pollingMetadata): PollingMetadata
    {
        return $pollingMetadata->withStopOnError(false);
    }

    public static function withoutDelayedRetries(PollingMetadata $pollingMetadata, DeadLetterChannels $deadLetterChannels): PollingMetadata
    {
        return self::goingOnAfterAFailure($pollingMetadata)->withErrorChannelName($deadLetterChannels->of($pollingMetadata->getErrorChannelName()));
    }

    public function consumeWaiting(): void
    {
        while (($message = $this->waitingMessages->receiveWithTimeout($this->pollingMetadata)) !== null) {
            $this->failureLogger->loggingADroppedMessage(
                channelName: $this->channelName,
                message: $message,
                handle: fn () => $this->runner->handleReceived(message: $message, pollingMetadata: $this->pollingMetadata, handlerAnnotations: $this->handlerAnnotations),
            );
        }
    }

    public function replay(Message $message): void
    {
        $this->runner->handleReceived(
            message: $this->messageBuilder->fromMessage($message)->setHeader(MessageHeaders::POLLED_CHANNEL_NAME, $this->channelName)->build(),
            pollingMetadata: $this->replayPollingMetadata,
            handlerAnnotations: $this->replayHandlerAnnotations,
        );
    }
}
