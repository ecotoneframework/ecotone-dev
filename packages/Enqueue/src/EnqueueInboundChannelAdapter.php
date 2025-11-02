<?php

declare(strict_types=1);

namespace Ecotone\Enqueue;

use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Endpoint\PollingConsumer\ConnectionException;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessagePoller;
use Ecotone\Messaging\Support\MessageBuilder;
use Exception;
use Interop\Queue\Message as EnqueueMessage;

use function spl_object_id;

/**
 * licence Apache-2.0
 */
abstract class EnqueueInboundChannelAdapter implements MessagePoller
{
    private array $initialized = [];

    public function __construct(
        protected CachedConnectionFactory         $connectionFactory,
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

    public function receiveWithTimeout(PollingMetadata $pollingMetadata): ?Message
    {
        try {
            $context = $this->connectionFactory->createContext();
            if ($this->declareOnStartup) {
                $contextId = spl_object_id($context);

                if (! isset($this->initialized[$contextId])) {
                    $this->initialize();
                    $this->initialized[$contextId] = true;
                }
            }

            $consumer = $this->connectionFactory->getConsumer(
                $context->createQueue($this->queueName)
            );

            /** @var EnqueueMessage $message */
            $timeoutInMilliseconds = $pollingMetadata->getExecutionTimeLimitInMilliseconds() ?: $this->receiveTimeoutInMilliseconds;
            $message = $consumer->receive($timeoutInMilliseconds);

            if (! $message) {
                return null;
            }

            $convertedMessage = $this->inboundMessageConverter->toMessage($message, $consumer, $this->conversionService, $this->connectionFactory);
            $convertedMessage = $this->enrichMessage($message, $convertedMessage);

            return $convertedMessage->build();
        } catch (Exception $exception) {
            if ($this->isConnectionException($exception) || ($exception->getPrevious() && $this->isConnectionException($exception->getPrevious()))) {
                try {
                    $this->connectionFactory->reconnect();
                } catch (Exception) {
                }

                throw new ConnectionException('There was a problem while polling message channel', 0, $exception);
            }

            throw $exception;
        }
    }

    abstract public function connectionException(): array;

    private function isConnectionException(Exception $exception): bool
    {
        foreach ($this->connectionException() as $connectionException) {
            if (is_subclass_of($exception, $connectionException) || $exception::class === $connectionException) {
                return true;
            }
        }

        return false;
    }

    public function getQueueName(): string
    {
        return $this->queueName;
    }

    public function onConsumerStop(): void
    {

    }
}
