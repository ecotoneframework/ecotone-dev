<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Monorepo\ExampleAppEventSourcing\EcotoneProjection;

use Ecotone\EventSourcing\EventSourcingConfiguration;
use Ecotone\EventSourcing\Prooph\Projecting\EventStoreAggregateStreamSourceBuilder;
use Ecotone\Messaging\Attribute\ServiceContext;
use Ecotone\Projecting\Config\ProjectingConfiguration;
use Monorepo\ExampleAppEventSourcing\Common\Product;

class EcotoneConfiguration
{
    #[ServiceContext]
    public function enableStreamSourceFromEventStore(): EventStoreAggregateStreamSourceBuilder
    {
        return new EventStoreAggregateStreamSourceBuilder(PriceChangeOverTimeProjectionWithEcotoneProjection::NAME, Product::class, Product::class);
    }

    #[ServiceContext]
    public function dbal(): ProjectingConfiguration
    {
        return ProjectingConfiguration::createDbal();
    }
}