<?php

declare(strict_types=1);

namespace Ecotone\Enqueue;

use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Endpoint\InboundChannelAdapterEntrypoint;
use Ecotone\Messaging\Endpoint\MessagePoller\MessagePoller;
use Ecotone\Messaging\Endpoint\PollingConsumer\ConnectionException;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\PollableChannel;
use Ecotone\Messaging\Scheduling\TaskExecutor;
use Ecotone\Messaging\Support\MessageBuilder;
use Exception;
use Interop\Queue\Message as EnqueueMessage;

abstract class EnqueueInboundChannelAdapter implements MessagePoller
{
    private bool $initialized = false;

    public function __construct(
        protected CachedConnectionFactory         $connectionFactory,
        protected InboundChannelAdapterEntrypoint $entrypointGateway,
        protected bool                            $declareOnStartup,
        protected string                          $queueName,
        protected int                             $receiveTimeoutInMilliseconds,
        protected InboundMessageConverter         $inboundMessageConverter,
        protected ConversionService $conversionService
    ) {
    }

    abstract public function initialize(): void;

    public function enrichMessage(EnqueueMessage $sourceMessage, MessageBuilder $targetMessage): MessageBuilder
    {
        return $targetMessage;
    }

    public function receiveWithTimeout(int $timeoutInMilliseconds = 0): ?Message
    {
        try {
            if ($this->declareOnStartup && $this->initialized === false) {
                $this->initialize();

                $this->initialized = true;
            }

            $consumer = $this->connectionFactory->getConsumer(
                $this->connectionFactory->createContext()->createQueue($this->queueName)
            );

            /** @var EnqueueMessage $message */
            $message = $consumer->receive($timeoutInMilliseconds ?: $this->receiveTimeoutInMilliseconds);

            if (! $message) {
                return null;
            }

            $convertedMessage = $this->inboundMessageConverter->toMessage($message, $consumer, $this->conversionService);
            $convertedMessage = $this->enrichMessage($message, $convertedMessage);

            return $convertedMessage->build();
        } catch (Exception $exception) {
            if ($this->isConnectionException($exception) || ($exception->getPrevious() && $this->isConnectionException($exception->getPrevious()))) {
                throw new ConnectionException('There was a problem while polling message channel', 0, $exception);
            }

            throw $exception;
        }
    }

    abstract public function connectionException(): string;

    private function isConnectionException(Exception $exception): bool
    {
        return is_subclass_of($exception, $this->connectionException()) || $exception::class === $this->connectionException();
    }

    public function getQueueName(): string
    {
        return $this->queueName;
    }
}
