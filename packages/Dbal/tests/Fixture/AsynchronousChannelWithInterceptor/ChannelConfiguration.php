<?php

namespace Test\Ecotone\Dbal\Fixture\AsynchronousChannelWithInterceptor;

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
    public function dbalConfig(): array
    {
        return [
            DbalConfiguration::createWithDefaults()
                ->withTransactionOnAsynchronousEndpoints(false)
                ->withTransactionOnCommandBus(false)
                ->withDocumentStore(false)
                ->withDeduplication(false)
                ->withDeadLetter(false),
            DbalBackedMessageChannelBuilder::create('orders')
                ->withReceiveTimeout(1),
            PollingMetadata::create('orders')
                ->withTestingSetup(),
        ];
    }
}
