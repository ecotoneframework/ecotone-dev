<?php

namespace Ecotone\Messaging\Endpoint\PollOrThrow;

use Ecotone\Messaging\Endpoint\ConsumerLifecycle;
use Ecotone\Messaging\Endpoint\EndpointRunner;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\MessageDeliveryException;
use Ecotone\Messaging\MessageHandler;
use Ecotone\Messaging\PollableChannel;
use Ecotone\Messaging\Scheduling\TaskExecutor;

/**
 * Class PollingConsumer
 * @package Ecotone\Messaging\Endpoint
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 */
class PollOrThrowExceptionConsumer implements EndpointRunner
{
    public function __construct(private PollableChannel $pollableChannel, private MessageHandler $messageHandler)
    {
    }

    public static function createWithoutName(PollableChannel $pollableChannel, MessageHandler $messageHandler): self
    {
        return new self($pollableChannel, $messageHandler);
    }

    public static function create(PollableChannel $pollableChannel, MessageHandler $messageHandler): self
    {
        return new self($pollableChannel, $messageHandler);
    }

    /**
     * @inheritDoc
     */
    public function execute(PollingMetadata $pollingMetadata): void
    {
    }

    public function runEndpointWithExecutionPollingMetadata(string $endpointId, ?ExecutionPollingMetadata $executionPollingMetadata): void
    {
        $message = $this->pollableChannel->receive();
        if (is_null($message)) {
            throw MessageDeliveryException::create('Message was not delivered to ' . self::class);
        }

        $this->messageHandler->handle($message);
    }
}
