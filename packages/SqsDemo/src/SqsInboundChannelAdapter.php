<?php

declare(strict_types=1);

namespace Test\SqsDemo;

use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Enqueue\InboundMessageConverter;
use Ecotone\Messaging\Endpoint\InboundChannelAdapterEntrypoint;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\Scheduling\TaskExecutor;
use Enqueue\Dbal\DbalDestination;
use Enqueue\Sqs\SqsContext;
use Enqueue\Sqs\SqsMessage;

final class SqsInboundChannelAdapter implements TaskExecutor
{
    public function __construct(
        private CachedConnectionFactory         $cachedConnectionFactory,
        private InboundChannelAdapterEntrypoint $entrypointGateway,
        private bool                            $initialized,
        private string                          $queueName,
        private int                             $receiveTimeoutInMilliseconds,
        private InboundMessageConverter         $inboundMessageConverter)
    {}

    public function execute(PollingMetadata $pollingMetadata): void
    {
        $message = $this->receiveMessage($pollingMetadata->getExecutionTimeLimitInMilliseconds());

        if ($message) {
            $this->entrypointGateway->executeEntrypoint($message);
        }
    }

    public function receiveMessage(int $timeout = 0): ?Message
    {
        if (!$this->initialized) {
            /** @var SqsContext $context */
            $context = $this->cachedConnectionFactory->createContext();

            $context->createQueue($this->queueName);
            $this->initialized = true;
        }

        $consumer = $this->cachedConnectionFactory->getConsumer(new DbalDestination($this->queueName));

        /** @var SqsMessage $message */
        $message = $consumer->receive($timeout ?: $this->receiveTimeoutInMilliseconds);

        if (!$message) {
            return null;
        }

        return $this->inboundMessageConverter->toMessage($message, $consumer)->build();
    }
}