<?php

declare(strict_types=1);

namespace Ecotone\Sqs;

use Ecotone\Enqueue\EnqueueInboundChannelAdapter;
use Enqueue\Sqs\SqsContext;
use GuzzleHttp\Exception\ConnectException;

/**
 * licence Apache-2.0
 */
final class SqsInboundChannelAdapter extends EnqueueInboundChannelAdapter
{
    public function initialize(): void
    {
        /** @var SqsContext $context */
        $context = $this->connectionFactory->createContext();

        $context->declareQueue($context->createQueue($this->queueName));
    }

    public function connectionException(): string
    {
        return ConnectException::class;
    }
}
