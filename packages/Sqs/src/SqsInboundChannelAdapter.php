<?php

declare(strict_types=1);

namespace Ecotone\Sqs;

use Ecotone\Enqueue\EnqueueInboundChannelAdapter;
use Enqueue\Sqs\SqsContext;

final class SqsInboundChannelAdapter extends EnqueueInboundChannelAdapter
{
    public function initialize(): void
    {
        /** @var SqsContext $context */
        $context = $this->connectionFactory->createContext();

        $context->declareQueue($context->createQueue($this->queueName));
    }
}
