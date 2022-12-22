<?php

namespace Ecotone\Dbal;

use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Enqueue\EnqueueInboundChannelAdapter;
use Ecotone\Enqueue\InboundMessageConverter;
use Ecotone\Messaging\Endpoint\InboundChannelAdapterEntrypoint;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\Scheduling\TaskExecutor;
use Enqueue\Dbal\DbalContext;
use Enqueue\Dbal\DbalDestination;
use Enqueue\Dbal\DbalMessage;
use Interop\Queue\Destination;
use Interop\Queue\Message as EnqueueMessage;

class DbalInboundChannelAdapter extends EnqueueInboundChannelAdapter
{
    public function initialize(): void
    {
        /** @var DbalContext $context */
        $context = $this->connectionFactory->createContext();

        $context->createDataBaseTable();
    }
}
