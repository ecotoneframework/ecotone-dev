<?php

declare(strict_types=1);

namespace Ecotone\Sqs;

use Ecotone\Enqueue\EnqueueInboundChannelAdapter;
use Enqueue\Sqs\SqsContext;
use GuzzleHttp\Exception\ConnectException;
use Interop\Queue\Consumer;
use Interop\Queue\Message as EnqueueMessage;

/**
 * licence Apache-2.0
 */
final class SqsInboundChannelAdapter extends EnqueueInboundChannelAdapter
{
    private const LONG_POLL_IN_MILLISECONDS_WHEN_FINISHING_ON_NO_MESSAGES = 1000;

    public function initialize(): void
    {
        /** @var SqsContext $context */
        $context = $this->connectionFactory->createContext();

        $context->declareQueue($context->createQueue($this->queueName));
    }

    public function connectionException(): array
    {
        return [ConnectException::class];
    }

    protected function receiveWithoutWaiting(Consumer $consumer): ?EnqueueMessage
    {
        return $consumer->receive(self::LONG_POLL_IN_MILLISECONDS_WHEN_FINISHING_ON_NO_MESSAGES);
    }
}
