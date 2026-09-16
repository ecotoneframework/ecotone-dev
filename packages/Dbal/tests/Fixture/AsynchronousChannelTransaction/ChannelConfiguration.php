<?php

namespace Test\Ecotone\Dbal\Fixture\AsynchronousChannelTransaction;

use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\ExtensionObject\PollingMetadata;

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
