<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\MetadataPropagationWithAsyncProjection;

use Ecotone\EventSourcing\ProjectionRunningConfiguration;
use Ecotone\Messaging\Attribute\ServiceContext;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Prooph\EventStore\Pdo\Projection\PdoEventStoreReadModelProjector;
use Prooph\EventStore\Projection\ReadModelProjector;

class OrderProjectionConfig
{
    #[ServiceContext]
    public function eventDrivenAuditPlanActionLogProjection(): ProjectionRunningConfiguration
    {
        return ProjectionRunningConfiguration::createEventDriven(OrderProjection::NAME)
            ->withOption(PdoEventStoreReadModelProjector::OPTION_LOAD_COUNT, 100)
            ->withOption(ReadModelProjector::OPTION_PERSIST_BLOCK_SIZE, 100)
        ;
    }

    #[ServiceContext]
    public function simpleAuditPlanActionLogProjectionChannel(): SimpleMessageChannelBuilder
    {
        return SimpleMessageChannelBuilder::createQueueChannel(OrderProjection::CHANNEL);
    }
}
