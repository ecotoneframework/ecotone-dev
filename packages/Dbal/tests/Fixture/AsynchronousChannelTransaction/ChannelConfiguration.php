<?php

namespace Test\Ecotone\Dbal\Fixture\AsynchronousChannelTransaction;

use Ecotone\Api\Dbal\DbalBackedMessageChannelBuilder;
use Ecotone\Api\Dbal\DbalConfiguration;
use Ecotone\Api\PollingMetadata;
use Ecotone\Api\ServiceContext;

/**
 * licence Apache-2.0
 */
class ChannelConfiguration
{
    #[ServiceContext]
    public function registerCommandChannel(): array
    {
        return [
            DbalBackedMessageChannelBuilder::create('orders', 'managerRegistry')
                ->withReceiveTimeout(1),
            PollingMetadata::create('orders')
                ->setHandledMessageLimit(1)
                ->setExecutionTimeLimitInMilliseconds(1000),
            DbalBackedMessageChannelBuilder::create('processOrders', 'managerRegistry')
                ->withReceiveTimeout(1),
            PollingMetadata::create('processOrders')
                ->setHandledMessageLimit(1)
                ->setExecutionTimeLimitInMilliseconds(1000),
            DbalConfiguration::createWithDefaults()
                ->withTransactionOnAsynchronousEndpoints(true)
                ->withTransactionOnCommandBus(true)
                ->withDefaultConnectionReferenceNames(['managerRegistry'])
                ->withDocumentStore(false)
                ->withDeduplication(false)
                ->withDeadLetter(false),
        ];
    }
}
