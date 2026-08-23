<?php

namespace Test\Ecotone\Dbal\Fixture\AsynchronousChannelWithInterceptor;

use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Api\ExtensionObject\PollingMetadata;
use Ecotone\Dbal\Api\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Dbal\Api\ExtensionObject\DbalConfiguration;

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
