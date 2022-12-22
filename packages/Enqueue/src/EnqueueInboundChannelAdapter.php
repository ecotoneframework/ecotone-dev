<?php

declare(strict_types=1);

namespace Ecotone\Enqueue;

use Ecotone\Messaging\Endpoint\InboundChannelAdapterEntrypoint;
use Ecotone\Messaging\Endpoint\PollingConsumer\ConnectionException;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\Scheduling\TaskExecutor;
use Ecotone\Messaging\Support\MessageBuilder;
use Exception;
use Interop\Queue\Message as EnqueueMessage;

abstract class EnqueueInboundChannelAdapter implements TaskExecutor
{
    private bool $initialized = false;

    public function __construct(
        protected CachedConnectionFactory         $connectionFactory,
        protected InboundChannelAdapterEntrypoint $entrypointGateway,
        protected bool                            $declareOnStartup,
        protected string                          $queueName,
        protected int                             $receiveTimeoutInMilliseconds,
        protected InboundMessageConverter         $inboundMessageConverter,
    ) {
    }

    public function execute(PollingMetadata $pollingMetadata): void
    {
        $message = $this->receiveMessage($pollingMetadata->getExecutionTimeLimitInMilliseconds());

        if ($message) {
            $this->entrypointGateway->executeEntrypoint($message);
        }
    }

    abstract public function initialize(): void;

    public function enrichMessage(EnqueueMessage $sourceMessage, MessageBuilder $targetMessage): MessageBuilder
    {
        return $targetMessage;
    }

    public function receiveMessage(int $timeout = 0): ?Message
    {
        if ($this->declareOnStartup && $this->initialized === false) {
            $this->initialize();

            $this->initialized = true;
        }

        $consumer = $this->connectionFactory->getConsumer(
            $this->connectionFactory->createContext()->createQueue($this->queueName)
        );

        try {
            /** @var EnqueueMessage $message */
            $message = $consumer->receive($timeout ?: $this->receiveTimeoutInMilliseconds);
        } catch (Exception $exception) {
            throw new ConnectionException('There was a problem while polling channel', 0, $exception);
        }

        if (! $message) {
            return null;
        }

        $convertedMessage = $this->inboundMessageConverter->toMessage($message, $consumer);
        $convertedMessage = $this->enrichMessage($message, $convertedMessage);

        return $convertedMessage->build();
    }
}
