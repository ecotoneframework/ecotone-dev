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
    public function __construct(
        CachedConnectionFactory $cachedConnectionFactory,
        InboundChannelAdapterEntrypoint $entrypointGateway,
        bool $declareOnStartup,
        private string $queueName,
        int $receiveTimeoutInMilliseconds,
        InboundMessageConverter $inboundMessageConverter
    ) {
        parent::__construct(
            $cachedConnectionFactory,
            $entrypointGateway,
            $declareOnStartup,
            new DbalDestination($queueName),
            $receiveTimeoutInMilliseconds,
            $inboundMessageConverter
        );
    }

    public function initialize(): void
    {
        /** @var DbalContext $context */
        $context = $this->cachedConnectionFactory->createContext();

        $context->createDataBaseTable();
        $context->createQueue($this->queueName);
    }
}
