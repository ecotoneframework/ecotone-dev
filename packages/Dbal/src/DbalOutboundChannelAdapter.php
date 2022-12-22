<?php

declare(strict_types=1);

namespace Ecotone\Dbal;

use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Enqueue\EnqueueOutboundChannelAdapter;
use Ecotone\Enqueue\OutboundMessageConverter;
use Enqueue\Dbal\DbalContext;
use Enqueue\Dbal\DbalDestination;

class DbalOutboundChannelAdapter extends EnqueueOutboundChannelAdapter
{
    public function __construct(CachedConnectionFactory $connectionFactory, private string $queueName, bool $autoDeclare, OutboundMessageConverter $outboundMessageConverter)
    {
        parent::__construct(
            $connectionFactory,
            new DbalDestination($this->queueName),
            $autoDeclare,
            $outboundMessageConverter
        );
    }

    public function initialize(): void
    {
        /** @var DbalContext $context */
        $context = $this->connectionFactory->createContext();

        $context->createDataBaseTable();
        $context->createQueue($this->queueName);
    }
}
