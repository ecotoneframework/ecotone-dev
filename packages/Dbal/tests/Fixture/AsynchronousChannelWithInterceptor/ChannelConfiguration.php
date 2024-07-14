<?php

namespace Test\Ecotone\Dbal\Fixture\AsynchronousChannelWithInterceptor;

use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\Dbal\DbalBackedMessageChannelBuilder;
use Ecotone\Messaging\Attribute\ServiceContext;
use Ecotone\Messaging\Endpoint\PollingMetadata;

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
