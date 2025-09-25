<?php
/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Monorepo\ExampleAppEventSourcing\EcotoneProjection;

use Ecotone\EventSourcing\Projecting\StreamSource\EventStoreGlobalStreamSourceBuilder;
use Ecotone\Messaging\Attribute\ServiceContext;
use Ecotone\Projecting\Config\ProjectionComponentBuilder;
use Monorepo\ExampleAppEventSourcing\Common\Product;

class EcotoneConfiguration
{
    #[ServiceContext]
    public function enableStreamSourceFromEventStore(): ProjectionComponentBuilder
    {
        return new EventStoreGlobalStreamSourceBuilder(Product::class, [PriceChangeOverTimeProjectionWithEcotoneProjection::NAME]);
//        return new EventStoreAggregateStreamSourceBuilder(PriceChangeOverTimeProjectionWithEcotoneProjection::NAME, Product::class, Product::class);
    }
}