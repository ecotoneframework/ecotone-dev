<?php

declare(strict_types=1);

namespace Test\SqsDemo;

use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Enqueue\EnqueueOutboundChannelAdapter;
use Ecotone\Enqueue\OutboundMessageConverter;
use Enqueue\Sqs\SqsContext;
use Enqueue\Sqs\SqsDestination;
use Interop\Queue\Destination;

final class SqsOutboundChannelAdapter extends EnqueueOutboundChannelAdapter
{
    public function __construct(CachedConnectionFactory $connectionFactory, private string $queueName, bool $autoDeclare, OutboundMessageConverter $outboundMessageConverter)
    {
        parent::__construct(
            $connectionFactory,
            new SqsDestination($queueName),
            $autoDeclare,
            $outboundMessageConverter
        );
    }

    public function initialize(): void
    {
        /** @var SqsContext $context */
        $context = $this->connectionFactory->createContext();

        $context->declareQueue($context->createQueue($this->queueName));
    }
}